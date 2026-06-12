<?php

namespace App\TwStats\Protocol\Six;

use App\TwStats\Model\DiscoveredAddress;

/**
 * Parses a Teeworlds 0.6 `lis2` master payload into game-server addresses. Each entry is a
 * 16-byte IP (IPv4-mapped when bytes 0-11 are the `::ffff:` prefix) + a 2-byte big-endian port.
 * Same wire format as 0.7's lis2; tagged protocol 6.
 */
final class SixListCodec
{
    private const ENTRY_SIZE = 18;
    private const IPV4_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /**
     * @return DiscoveredAddress[]
     */
    public function parse(string $payload): array
    {
        $addresses = [];

        for ($offset = 0; $offset + self::ENTRY_SIZE <= strlen($payload); $offset += self::ENTRY_SIZE) {
            $ipBytes = substr($payload, $offset, 16);
            $port = (ord($payload[$offset + 16]) << 8) | ord($payload[$offset + 17]);

            if (str_starts_with($ipBytes, self::IPV4_PREFIX)) {
                $ip = inet_ntop(substr($ipBytes, 12, 4));
            } else {
                $ip = inet_ntop($ipBytes);
            }

            if ($ip === false) {
                continue;
            }

            $addresses[] = new DiscoveredAddress($ip, $port, 6);
        }

        return $addresses;
    }
}
