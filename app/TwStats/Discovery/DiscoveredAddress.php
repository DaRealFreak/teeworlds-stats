<?php

namespace App\TwStats\Discovery;

/**
 * One protocol-tagged endpoint of a discovered server. Immutable; produced by the source
 * adapters and consumed by the Phase 3 merge engine.
 */
final class DiscoveredAddress
{
    public function __construct(
        public readonly string $ip,
        public readonly int $port,
        public readonly int $protocol, // 6 or 7
    ) {
    }

    /**
     * parse a DDNet master address such as "tw-0.6+udp://192.0.2.10:8303" or
     * "tw-0.7+udp://[2001:db8::5]:8310". Only the UDP Teeworlds 0.6/0.7 protocols are
     * tracked; anything else (or a malformed url) yields null so the caller can skip it.
     */
    public static function fromUrl(string $url): ?self
    {
        // host is either a bracketed IPv6 literal or a colon-free bare host, so a stray
        // extra ":port" can't be swallowed into the host capture (returns null instead).
        if (!preg_match('#^tw-0\.([67])\+udp://(\[.+?\]|[^:]+):(\d+)$#', $url, $m)) {
            return null;
        }

        $host = $m[2];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return new self($host, (int) $m[3], (int) $m[1]);
    }
}
