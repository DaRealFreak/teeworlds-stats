<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Computes DDNet extended-message UUIDs — the "<name>@<domain>" identifiers in
 * ddnet/src/engine/shared/protocol_ex_msgs.h. DDNet derives them as version-3 (MD5) UUIDs over a
 * fixed Teeworlds namespace; ported from CalculateUuid() in uuid_manager.cpp so our packets carry
 * the exact 16-byte id the server matches against (e.g. clientver@ddnet.tw, map-details@ddnet.tw).
 */
final class DdnetUuid
{
    /** "e05ddaaa-c4e6-4cfb-b642-5d48e80c0029" — TEEWORLDS_NAMESPACE in uuid_manager.cpp. */
    private const NAMESPACE_BYTES = "\xe0\x5d\xda\xaa\xc4\xe6\x4c\xfb\xb6\x42\x5d\x48\xe8\x0c\x00\x29";

    /** The 16 raw bytes of the extended-message UUID for a "<name>@<domain>" id. */
    public static function compute(string $name): string
    {
        // UUID v3 = MD5(namespace + name) with the version (3) and variant (RFC 4122) bits forced.
        $u = md5(self::NAMESPACE_BYTES . $name, true);
        $u[6] = chr((ord($u[6]) & 0x0f) | 0x30);
        $u[8] = chr((ord($u[8]) & 0x3f) | 0x80);

        return $u;
    }
}
