<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Server;
use App\TwStats\Discovery\DdnetHttpSource;
use App\TwStats\Discovery\FlavorClassifier;
use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Net\UdpTransport;
use App\TwStats\Protocol\Six\SixConnless;
use App\TwStats\Protocol\Six\SixInfoCodec;

/**
 * Live current-map lookup. Channels must match how each name was discovered: a DDNet server's name in
 * our DB comes from the HTTP master (a connless query to the same host can report a different map — an
 * MMO/city server answers gie3 with a hub name while the master lists its public name), so ddnet
 * resolves from a memoized master snapshot and only 0.6 uses a direct connless query. Any failure
 * returns null so the caller falls back to the freshest ingested record.
 */
final class CurrentServerMapResolver implements CurrentServerMapResolverInterface
{
    /** @var array<string, string>|null "ip:port" => current map name, from the DDNet master */
    private ?array $ddnetSnapshot = null;

    private float $snapshotAtMs = 0.0;

    public function __construct(
        private readonly DdnetHttpSource $ddnetSource = new DdnetHttpSource(),
        private readonly UdpTransport $transport = new SocketUdpTransport(),
        private readonly int $snapshotTtlMs = 120_000,
        private readonly int $connlessTimeoutMs = 1_500,
    ) {
    }

    public function currentMapName(Server $server): ?string
    {
        try {
            return match ($server->getAttribute('flavor')) {
                FlavorClassifier::FLAVOR_DDNET => $this->fromDdnetMaster($server),
                FlavorClassifier::FLAVOR_VANILLA_06 => $this->fromConnless06($server),
                default => null, // 0.7 / unknown → caller falls back to the ingested record
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromDdnetMaster(Server $server): ?string
    {
        $snapshot = $this->ddnetSnapshot();
        foreach ($server->addresses as $address) {
            $key = $address->ip . ':' . $address->port;
            if (isset($snapshot[$key])) {
                return $snapshot[$key];
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function ddnetSnapshot(): array
    {
        $nowMs = hrtime(true) / 1_000_000;
        if ($this->ddnetSnapshot !== null && ($nowMs - $this->snapshotAtMs) < $this->snapshotTtlMs) {
            return $this->ddnetSnapshot;
        }

        $snapshot = [];
        foreach ($this->ddnetSource->fetch() as $discovered) {
            foreach ($discovered->addresses as $address) {
                $snapshot[$address->ip . ':' . $address->port] = $discovered->map;
            }
        }

        $this->ddnetSnapshot = $snapshot;
        $this->snapshotAtMs = $nowMs;

        return $snapshot;
    }

    private function fromConnless06(Server $server): ?string
    {
        $address = $server->addresses->firstWhere('protocol', 6) ?? $server->addresses->first();
        if ($address === null) {
            return null;
        }

        $this->transport->send((string) $address->ip, (int) $address->port, SixConnless::getInfo("\x01", "\x01"));

        $codec = new SixInfoCodec();
        $deadlineMs = hrtime(true) / 1_000_000 + $this->connlessTimeoutMs;
        while (hrtime(true) / 1_000_000 < $deadlineMs) {
            $packet = $this->transport->receive(300);
            if ($packet === null) {
                continue;
            }
            $parsed = SixConnless::parse($packet['data']);
            if ($parsed === null) {
                continue;
            }
            $info = $codec->parse($parsed['payload'], $parsed['command']);
            if ($info !== null && $info->hasHeader && $info->map !== null) {
                return $info->map;
            }
        }

        return null;
    }
}
