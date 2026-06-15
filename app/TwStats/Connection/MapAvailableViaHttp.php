<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Thrown by the map-download session when the connected server advertises an HTTP download URL for
 * the current map in NETMSG_MAP_DETAILS. Fast-download DDNet servers serve their (often custom) maps
 * from their own mirror rather than streaming MAP_DATA over UDP, so the session abandons the UDP
 * transfer and hands the URL back to the caller, which fetches and verifies the bytes over HTTP.
 */
final class MapAvailableViaHttp extends \RuntimeException
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $sha256, // hex, as announced in MAP_DETAILS
        public readonly int $size,
        public readonly string $name,
    ) {
        parent::__construct('Map available via HTTP: ' . $url);
    }
}
