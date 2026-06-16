<?php

namespace App\TwStats\Model;

/**
 * A normalized logical server produced by a source adapter. The address set is its identity
 * (mirroring DDNet's servers.json grouping); the Phase 3 merge engine dedups across sources.
 */
final class DiscoveredServer
{
    /**
     * @param DiscoveredAddress[] $addresses
     * @param DiscoveredClient[] $clients
     * @param ?string $mapSha256 DDNet map content hash (HTTP download identity); null for non-DDNet
     * @param ?int $mapSize expected .map byte size, for download validation
     * @param ?string $mapCrc 0.6/0.7 map crc (UDP download identity); populated in Phase E
     * @param int $flags server-info bitfield: SERVER_FLAG_PASSWORD (1) | SERVER_FLAG_TIMESCORE (2).
     *                   Defaulted so existing positional call sites keep compiling.
     */
    public function __construct(
        public readonly array $addresses,
        public readonly string $name,
        public readonly string $map,
        public readonly string $gametype,
        public readonly string $version,
        public readonly int $maxClients,
        public readonly int $maxPlayers,
        public readonly array $clients,
        public readonly ?string $location,
        public readonly string $flavor,
        public readonly ?string $mapSha256 = null,
        public readonly ?int $mapSize = null,
        public readonly ?string $mapCrc = null,
        public readonly int $flags = 0,
    ) {
    }
}
