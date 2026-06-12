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
    ) {
    }
}
