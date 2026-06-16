<?php

namespace App\Service;

use App\Events\MapAcquisitionAttempted;
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
            mapVersionId: null,
        );
    }

    public function fetchVersion(MapVersion $version): ?string
    {
        // A version already classified as not-on-the-mirror and still inside the recheck window is not
        // re-probed: returning null here skips both the HTTP round-trip and any telemetry, since no probe
        // actually happened. Stale or pending versions fall through and probe as usual.
        if ($this->isMirrorRecheckSuppressed($version)) {
            return null;
        }

        return $this->download(
            name: (string) $version->map->getAttribute('name'),
            sha256: $version->getAttribute('sha256'),
            size: $version->getAttribute('size'),
            mapVersionId: (int) $version->getAttribute('id'),
        );
    }

    /**
     * Whether the 30-day mirror recheck should suppress an HTTP probe for this version: true only when it
     * was already resolved as udp_only/unavailable (not on the mirror) and its status was checked more
     * recently than MAP_MIRROR_RECHECK_DAYS ago. A stale check re-probes in case the version has since
     * been published; the resulting 404 re-stamps the status via the acquisition-state listener.
     */
    private function isMirrorRecheckSuppressed(MapVersion $version): bool
    {
        $status = $version->getAttribute('acquisition_status');
        if ($status !== MapVersion::STATUS_UDP_ONLY && $status !== MapVersion::STATUS_UNAVAILABLE) {
            return false;
        }

        $checkedAt = $version->getAttribute('status_checked_at');
        if ($checkedAt === null) {
            return false;
        }

        $recheckDays = (int) env('MAP_MIRROR_RECHECK_DAYS', 30);

        return $checkedAt->greaterThan(now()->subDays($recheckDays));
    }

    /**
     * Fetch and verify a map file by name and sha256. Returns null when sha256 is absent (not
     * eligible for DDNet HTTP delivery), the server is unreachable, or the bytes fail verification.
     */
    private function download(string $name, ?string $sha256, ?int $size, ?int $mapVersionId): ?string
    {
        if ($sha256 === null) {
            return null;
        }

        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($name) . '_' . $sha256 . '.map';

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable) {
            $this->record($mapVersionId, 'failure', 'http_error', null);
            return null;
        }
        if (!$response->successful()) {
            $reason = $response->status() === 404 ? 'not_mirrored_404' : 'http_error';
            $this->record($mapVersionId, 'failure', $reason, null);
            return null;
        }

        $body = $response->body();
        // both a wrong length and a wrong hash mean "the bytes did not verify" — one reason code
        if (($size !== null && strlen($body) !== $size) || hash('sha256', $body) !== strtolower($sha256)) {
            $this->record($mapVersionId, 'failure', 'sha_mismatch', null);
            return null;
        }

        $this->record($mapVersionId, 'success', null, strlen($body));

        return $body;
    }

    /** Fire one http_mirror telemetry event (server is always null — the mirror is not a game server). */
    private function record(?int $mapVersionId, string $outcome, ?string $reason, ?int $bytes): void
    {
        MapAcquisitionAttempted::dispatch($mapVersionId, null, 'http_mirror', $outcome, $reason, $bytes);
    }
}
