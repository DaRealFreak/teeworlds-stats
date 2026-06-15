<?php

namespace App\Service;

use App\Models\Map;
use Carbon\Carbon;

/**
 * Single brain for map archiving: decides a map's archive status (for the /maps badge) and performs
 * one map's download (for the queue job). The filesystem (MapFileStore::isCached) is the source of
 * truth for "is it archived"; last_archive_attempt_at only drives retry/backoff and the
 * Queued-vs-Retrying distinction, so the badge and the enqueuer always agree on what is eligible.
 */
final class MapArchiver
{
    public function __construct(
        private readonly MapFileStore $store = new MapFileStore(),
    ) {
    }

    public function statusFor(Map $map): MapArchiveStatus
    {
        if ($this->store->isCached($map)) {
            return MapArchiveStatus::Downloaded;
        }
        if (!$map->hasDownloadableFile()) {
            return MapArchiveStatus::Unavailable;
        }

        return $this->isCoolingDown($map) ? MapArchiveStatus::Retrying : MapArchiveStatus::Queued;
    }

    /**
     * Instant before which a prior attempt no longer blocks a re-attempt. A map attempted at or after
     * this instant is cooling down (Retrying); one attempted before it (or never) is eligible (Queued).
     */
    public function cooldownThreshold(): Carbon
    {
        return now()->subHours($this->retryHours());
    }

    /**
     * Stamp the attempt, then download. Uses the version-keyed path when a current version is
     * known (MapFileStore::ensureVersion), otherwise falls back to the map-keyed path
     * (MapFileStore::ensure). Throws MapFileUnavailable when no source can serve the map,
     * which lets the queue job retry and ultimately fail — the stamp keeps the map parked for the
     * cooldown window either way. The passed $map instance is not refreshed; callers re-inspecting
     * status afterwards should reload it (e.g. $map->fresh()).
     */
    public function download(Map $map): void
    {
        $this->markAttempted($map);
        $currentVersion = $map->currentVersion;
        if ($currentVersion !== null) {
            $this->store->ensureVersion($currentVersion);
        } else {
            $this->store->ensure($map);
        }
    }

    private function markAttempted(Map $map): void
    {
        // toBase() drops to the query builder, which does not auto-touch updated_at — map "recency"
        // semantics stay intact while we record the attempt.
        Map::whereKey($map->getKey())->toBase()->update(['last_archive_attempt_at' => now()]);
    }

    private function isCoolingDown(Map $map): bool
    {
        $attemptedAt = $map->getAttribute('last_archive_attempt_at');

        return $attemptedAt !== null && $attemptedAt >= $this->cooldownThreshold();
    }

    private function retryHours(): int
    {
        return (int) env('MAP_ARCHIVE_RETRY_HOURS', 24);
    }
}
