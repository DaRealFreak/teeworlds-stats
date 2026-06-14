<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * The outcome of a successful map download: the verified bytes plus the identity the server
 * announced in MAP_CHANGE. The name lets a caller confirm the host is still serving the map it was
 * asked for; sha256 is null for vanilla 0.6 servers, which announce only a crc.
 */
final class MapDownloadResult
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $name,
        public readonly int $crc,
        public readonly int $size,
        public readonly ?string $sha256,
    ) {
    }
}
