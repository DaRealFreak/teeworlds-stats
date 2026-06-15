<?php

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Support\Facades\Http;

/**
 * Downloads a DDNet map over HTTP from maps.ddnet.org. The URL mirrors the game client/server
 * (`%s_%s.map` of name + sha256, URL-escaped — see ddnet server.cpp:3006 / client.cpp:1266);
 * `_`, hex and `.` are RFC-3986 unreserved, so only the name needs escaping. Bytes are verified
 * against the advertised size and sha256 before being accepted; any failure returns null so the
 * store can fall through to another source.
 *
 * Both the map-keyed (fetch) and version-keyed (fetchVersion) paths share a private download()
 * helper; only the identity attributes differ between the two callers.
 */
final class DdnetHttpMapSource implements MapSource
{
    public const BASE_URL = 'https://maps.ddnet.org';

    public function __construct(private readonly string $baseUrl = self::BASE_URL)
    {
    }

    public function fetch(Map $map): ?string
    {
        return $this->download(
            name: (string) $map->getAttribute('name'),
            sha256: $map->getAttribute('sha256'),
            size: $map->getAttribute('size'),
        );
    }

    public function fetchVersion(MapVersion $version): ?string
    {
        return $this->download(
            name: (string) $version->map->getAttribute('name'),
            sha256: $version->getAttribute('sha256'),
            size: $version->getAttribute('size'),
        );
    }

    /**
     * Fetch and verify a map file by name and sha256. Returns null when sha256 is absent (not
     * eligible for DDNet HTTP delivery), the server is unreachable, or the bytes fail verification.
     */
    private function download(string $name, ?string $sha256, ?int $size): ?string
    {
        if ($sha256 === null) {
            return null;
        }

        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($name) . '_' . $sha256 . '.map';

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
        if ($size !== null && strlen($body) !== $size) {
            return null;
        }
        if (hash('sha256', $body) !== strtolower($sha256)) {
            return null;
        }

        return $body;
    }
}
