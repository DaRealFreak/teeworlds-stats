<?php

namespace App\TwStats\Discovery;

use App\TwStats\Model\DiscoveredAddress;
use App\TwStats\Model\DiscoveredServer;
use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Net\UdpTransport;
use App\TwStats\Protocol\Six\SixConnless;
use App\TwStats\Protocol\Six\SixInfoCodec;
use App\TwStats\Protocol\Six\SixInfoPacket;
use App\TwStats\Protocol\Six\SixListCodec;

/**
 * Discovers stock Teeworlds 0.6 servers by querying the teeworlds.com:8300 master directly — the
 * resilience fallback for the 0.6 population if DDNet's HTTP master (its normal, richer source)
 * ever disappears. 0.6 is stateless: no token handshake, just two drain phases — master list
 * (req2 -> lis2), then per-server info (gie3 -> inf3/iext/iex+) reassembled by source address.
 */
final class Teeworlds06Source
{
    private const MASTER_PORT = 8300;
    private const LIST_TOKEN = "\x12\x34";   // echoed by the master; any 2 bytes work
    private const INFO_TOKEN = "\x01";        // echoed by servers in the info token field
    private const DRAIN_TIMEOUT_MS = 700;
    private const MAX_DRAIN_MS = 8000;        // hard cap per drain so a flooding peer can't hang the scrape
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
        $addresses = $this->fetchServerList();

        return $this->queryServers($addresses);
    }

    /**
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
     * @return array<int, array{ip: string, port: int}>
     */
    private function fetchServerList(): array
    {
        foreach ($this->masters as $master) {
            $this->transport->send($master['ip'], $master['port'], SixConnless::getList(self::LIST_TOKEN));
        }

        $listCodec = new SixListCodec();
        $addresses = [];
        $this->drain(function (array $packet) use (&$addresses, $listCodec) {
            $parsed = SixConnless::parse($packet['data']);
            if ($parsed === null || $parsed['command'] !== SixConnless::LIST) {
                return;
            }
            foreach ($listCodec->parse($parsed['payload']) as $address) {
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
        $infoCodec = new SixInfoCodec();
        $servers = [];

        foreach (array_chunk($addresses, self::INFO_CHUNK) as $chunk) {
            foreach ($chunk as $address) {
                $this->transport->send($address['ip'], $address['port'], SixConnless::getInfo(self::INFO_TOKEN, self::INFO_TOKEN));
            }

            // accumulate per source: the first header packet seeds the server, iex+ appends clients
            $pending = [];
            $this->drain(function (array $packet) use (&$pending, $infoCodec) {
                $parsed = SixConnless::parse($packet['data']);
                if ($parsed === null) {
                    return;
                }
                $info = $infoCodec->parse($parsed['payload'], $parsed['command']);
                if ($info === null) {
                    return;
                }
                $key = $packet['ip'] . ':' . $packet['port'];
                $pending[$key] ??= ['ip' => $packet['ip'], 'port' => $packet['port'], 'header' => null, 'clients' => []];
                if ($info->hasHeader && $pending[$key]['header'] === null) {
                    $pending[$key]['header'] = $info;
                }
                array_push($pending[$key]['clients'], ...$info->clients);
            });

            foreach ($pending as $entry) {
                /** @var SixInfoPacket|null $header */
                $header = $entry['header'];
                if ($header === null) {
                    continue; // continuations only, no header packet seen — cannot form a server
                }

                $servers[] = new DiscoveredServer(
                    addresses: [new DiscoveredAddress($entry['ip'], $entry['port'], 6)],
                    name: $header->name,
                    map: $header->map,
                    gametype: $header->gametype,
                    version: $header->version,
                    maxClients: $header->maxClients,
                    maxPlayers: $header->maxPlayers,
                    clients: $entry['clients'],
                    location: null,
                    flavor: FlavorClassifier::classify($header->version),
                );
            }
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
                $masters[] = ['ip' => $ip, 'port' => self::MASTER_PORT];
            }
        }

        return $masters;
    }
}
