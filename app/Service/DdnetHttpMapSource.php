<?php

namespace App\Service;

use App\Models\Map;
use Illuminate\Support\Facades\Http;

/**
 * Downloads a DDNet map over HTTP from maps.ddnet.org. The URL mirrors the game client/server
 * (`%s_%s.map` of name + sha256, URL-escaped — see ddnet server.cpp:3006 / client.cpp:1266);
 * `_`, hex and `.` are RFC-3986 unreserved, so only the name needs escaping. Bytes are verified
 * against the advertised size and sha256 before being accepted; any failure returns null so the
 * store can fall through to another source.
 */
final class DdnetHttpMapSource implements MapSource
{
    public const BASE_URL = 'https://maps.ddnet.org';

    public function __construct(private readonly string $baseUrl = self::BASE_URL)
    {
    }

    public function fetch(Map $map): ?string
    {
        $sha256 = $map->getAttribute('sha256');
        if ($sha256 === null) {
            return null;
        }

        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($map->getAttribute('name')) . '_' . $sha256 . '.map';

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable) {
            // network failure — return null so the store can fall through to the next source
            return null;
        }
        if (!$response->successful()) {
            return null;
        }

        $body = $response->body();
        $size = $map->getAttribute('size');
        if ($size !== null && strlen($body) !== $size) {
            return null;
        }
        if (hash('sha256', $body) !== strtolower((string) $sha256)) {
            return null;
        }

        return $body;
    }
}
