<?php

namespace App\TwStats\Protocol\Six;

use App\TwStats\Model\DiscoveredClient;

/**
 * One parsed 0.6 info datagram. A header-bearing packet (inf3 / iext first packet) carries the
 * server fields; an iex+ continuation carries only more clients (hasHeader === false). The source
 * reassembles continuations onto the header packet by source address.
 */
final class SixInfoPacket
{
    /**
     * @param DiscoveredClient[] $clients
     * @param ?string $mapCrc normalized 8-hex string (e.g. 'deadbeef'), or null when the iext crc
     *                        field is absent (inf3 / iex+ continuations) or non-numeric
     * @param int $flags SERVER_FLAG_PASSWORD (1) | SERVER_FLAG_TIMESCORE (2); only the header packet
     *                   carries it, so iex+ continuations leave it at the default 0
     */
    public function __construct(
        public readonly bool $hasHeader,
        public readonly ?string $version,
        public readonly ?string $name,
        public readonly ?string $map,
        public readonly ?string $gametype,
        public readonly ?int $maxPlayers,
        public readonly ?int $maxClients,
        public readonly array $clients,
        public readonly ?string $mapCrc = null,
        public readonly int $flags = 0,
    ) {
    }
}
