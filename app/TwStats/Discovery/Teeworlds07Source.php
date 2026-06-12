<?php

namespace App\TwStats\Discovery;

use App\TwStats\Model\DiscoveredAddress;
use App\TwStats\Model\DiscoveredServer;
use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Net\UdpTransport;
use App\TwStats\Protocol\Seven\SevenConnless;
use App\TwStats\Protocol\Seven\SevenInfoCodec;
use App\TwStats\Protocol\Seven\SevenListCodec;
use App\TwStats\Protocol\Seven\VariableInt;

/**
 * Discovers stock Teeworlds 0.7 servers — the population that registers only to teeworlds.com's
 * master and is therefore absent from the DDNet HTTP feed. Each query is a two-step 0.7 exchange:
 * obtain the peer's connless token via a NET_CTRLMSG_TOKEN handshake, then send the connless
 * request carrying that token. Runs in four drain phases: master handshake, master list (req2 →
 * lis2), per-server handshake, per-server info (gie3 → inf3). A dead master/server just drops out.
 */
final class Teeworlds07Source
{
    public const CLIENT_TOKEN = 0x5453_7473; // "TSts" — our fixed connless response token
    private const SERVERBROWSE_GETLIST = "\xff\xff\xff\xffreq2";
    private const SERVERBROWSE_LIST = "\xff\xff\xff\xfflis2";
    private const SERVERBROWSE_GETINFO = "\xff\xff\xff\xffgie3";
    private const SERVERBROWSE_INFO = "\xff\xff\xff\xffinf3";
    private const TOKEN_SIZE = 8; // the 8-byte SERVERBROWSE_* identifier prefix
    private const BROWSE_TOKEN = 0x1234;
    private const DRAIN_TIMEOUT_MS = 700;
    private const MAX_DRAIN_MS = 8000; // hard cap per drain so a flooding peer can't hang the scrape
    private const INFO_CHUNK = 256;

    /** @var array<int, array{ip: string, port: int}> */
    private array $masters;

    /**
     * @param array<int, array{ip: string, port: int}>|null $masters
     */
    public function __construct(
        private readonly UdpTransport $transport = new SocketUdpTransport(),
        ?array $masters = null,
    ) {
        $this->masters = $masters ?? self::defaultMasters();
    }

    /**
     * @return DiscoveredServer[]
     */
    public function fetch(): array
    {
        $masterTokens = $this->handshake($this->masters);
        $serverAddresses = $this->fetchServerList($masterTokens);

        return $this->queryServers($serverAddresses);
    }

    /**
     * Receive datagrams until the network falls quiet (a full receive timeout returns null) or an
     * overall deadline is hit. The deadline bounds the worst case so a peer flooding faster than the
     * quiet timeout cannot keep the loop — and the whole scrape — running indefinitely.
     *
     * @param callable(array{ip: string, port: int, data: string}): void $onPacket
     */
    private function drain(callable $onPacket): void
    {
        $deadline = hrtime(true) + self::MAX_DRAIN_MS * 1_000_000;
        while (hrtime(true) < $deadline) {
            $packet = $this->transport->receive(self::DRAIN_TIMEOUT_MS);
            if ($packet === null) {
                return;
            }
            $onPacket($packet);
        }
    }

    /**
     * @param array<int, array{ip: string, port: int}> $targets
     * @return array<string, array{ip: string, port: int, token: int}> keyed by "ip:port"
     */
    private function handshake(array $targets): array
    {
        foreach ($targets as $target) {
            $this->transport->send($target['ip'], $target['port'], SevenConnless::tokenRequest(self::CLIENT_TOKEN));
        }

        $tokens = [];
        $this->drain(function (array $packet) use (&$tokens) {
            $token = SevenConnless::parseTokenResponse($packet['data']);
            if ($token !== null) {
                $tokens[$packet['ip'] . ':' . $packet['port']] = [
                    'ip' => $packet['ip'],
                    'port' => $packet['port'],
                    'token' => $token,
                ];
            }
        });

        return $tokens;
    }

    /**
     * @param array<string, array{ip: string, port: int, token: int}> $masterTokens
     * @return array<int, array{ip: string, port: int}>
     */
    private function fetchServerList(array $masterTokens): array
    {
        foreach ($masterTokens as $master) {
            $packet = SevenConnless::connless($master['token'], self::CLIENT_TOKEN, self::SERVERBROWSE_GETLIST);
            $this->transport->send($master['ip'], $master['port'], $packet);
        }

        $listCodec = new SevenListCodec();
        $addresses = [];
        $this->drain(function (array $packet) use (&$addresses, $listCodec) {
            $parsed = SevenConnless::parseConnless($packet['data']);
            if ($parsed === null || !str_starts_with($parsed['data'], self::SERVERBROWSE_LIST)) {
                return;
            }
            foreach ($listCodec->parse(substr($parsed['data'], self::TOKEN_SIZE)) as $address) {
                $addresses[$address->ip . ':' . $address->port] = ['ip' => $address->ip, 'port' => $address->port];
            }
        });

        return array_values($addresses);
    }

    /**
     * @param array<int, array{ip: string, port: int}> $addresses
     * @return DiscoveredServer[]
     */
    private function queryServers(array $addresses): array
    {
        $infoCodec = new SevenInfoCodec();
        $query = self::SERVERBROWSE_GETINFO . VariableInt::pack(self::BROWSE_TOKEN);
        $servers = [];

        foreach (array_chunk($addresses, self::INFO_CHUNK) as $chunk) {
            $tokens = $this->handshake($chunk);

            foreach ($tokens as $server) {
                $packet = SevenConnless::connless($server['token'], self::CLIENT_TOKEN, $query);
                $this->transport->send($server['ip'], $server['port'], $packet);
            }

            $this->drain(function (array $packet) use (&$servers, $infoCodec) {
                $parsed = SevenConnless::parseConnless($packet['data']);
                if ($parsed === null || !str_starts_with($parsed['data'], self::SERVERBROWSE_INFO)) {
                    return;
                }
                $address = new DiscoveredAddress($packet['ip'], $packet['port'], 7);
                $server = $infoCodec->parse(substr($parsed['data'], self::TOKEN_SIZE), $address);
                if ($server !== null) {
                    $servers[] = $server;
                }
            });
        }

        return $servers;
    }

    /**
     * @return array<int, array{ip: string, port: int}>
     */
    private static function defaultMasters(): array
    {
        $masters = [];
        foreach (['master1.teeworlds.com', 'master2.teeworlds.com', 'master3.teeworlds.com', 'master4.teeworlds.com'] as $host) {
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                $masters[] = ['ip' => $ip, 'port' => 8283];
            }
        }

        return $masters;
    }
}
