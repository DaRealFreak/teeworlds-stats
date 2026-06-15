<?php

namespace App\Service;

use App\Models\MapVersion;
use App\Models\ServerHistory;
use Illuminate\Support\Carbon;

/**
 * Drains the historical backlog of uncached map versions into the local mirror — the bulk-archive
 * companion to InlineMapAcquirer, which only grabs maps as it sees them live during a scrape.
 *
 * Two acquisition tiers, chosen per version so a run never wastes a UDP connect timeout on a host
 * that has long since rotated to a different map:
 *  - HTTP tier (default): the HTTP-only store fetches versions mirrored on maps.ddnet.org. This
 *    cheaply drains the mirrorable majority whether or not any server still runs them.
 *  - UDP tier (gated): the full HTTP→UDP store is used ONLY for a version a server is serving RIGHT
 *    NOW — a server_histories row for that exact version, refreshed within the live window. That is
 *    the only situation in which the UDP fallback can actually connect and pull the bytes, so it is
 *    the only situation in which we pay for it. A UDP-only map nobody currently serves is left for a
 *    future run (or the inline acquirer) to catch the next time a host runs it.
 *
 * Failed attempts are stamped (last_acquire_attempt_at, shared with the inline acquirer) and parked
 * for the acquire cooldown, so repeated runs do not re-hammer maps that 404 on the mirror and have
 * no live host. Bounded by a per-run attempt cap and a wall-clock budget.
 */
final class MapArchiveBackfiller
{
    private readonly MapFileStore $fullStore;
    private readonly MapFileStore $httpStore;

    public function __construct(?MapFileStore $fullStore = null, ?MapFileStore $httpStore = null)
    {
        // Defaults built here (not as constant default params) so the HTTP-only store can name its
        // single source without relying on new-in-initializer constant-expression support.
        $this->fullStore = $fullStore ?? new MapFileStore();
        $this->httpStore = $httpStore ?? new MapFileStore(sources: [new DdnetHttpMapSource()]);
    }

    /**
     * @param int $limit max versions to attempt this run; 0 means no count cap (rely on the budget)
     * @param int $timeBudgetS wall-clock budget in seconds
     * @param bool $includeCooldown also retry versions still within the failure cooldown
     * @param bool $httpOnly never dial a game server, even for a currently-served version
     * @param callable|null $log fn(string): void — optional progress sink (e.g. the command output)
     * @return array{attempted:int, acquired:int, udpTargeted:int, skippedCached:int}
     */
    public function run(int $limit, int $timeBudgetS, bool $includeCooldown = false, bool $httpOnly = false, ?callable $log = null): array
    {
        $budgetMs = $timeBudgetS * 1000;
        $startMs = $this->nowMs();
        $liveThreshold = now()->subMinutes($this->liveWindowMinutes());

        $attempted = 0;
        $acquired = 0;
        $udpTargeted = 0;
        $skippedCached = 0;

        foreach ($this->candidateIds($includeCooldown) as $id) {
            if (($limit > 0 && $attempted >= $limit) || ($this->nowMs() - $startMs) >= $budgetMs) {
                break;
            }

            /** @var MapVersion|null $version */
            $version = MapVersion::with('map')->find($id);
            if ($version === null) {
                continue;
            }
            // The inline acquirer or a concurrent run may have cached it since we listed candidates.
            if ($this->fullStore->isCachedVersion($version)) {
                $skippedCached++;
                continue;
            }

            $liveServed = !$httpOnly && $this->isCurrentlyServed($version, $liveThreshold);
            if ($liveServed) {
                $udpTargeted++;
            }
            $store = $liveServed ? $this->fullStore : $this->httpStore;

            $attempted++;
            $this->markAttempted($version);
            try {
                $store->ensureVersion($version);
                $acquired++;
            } catch (\Throwable) {
                // best-effort: the attempt stamp parks it for the cooldown; retried next run
            }
        }

        if ($log !== null) {
            $log("map backfill: {$acquired}/{$attempted} acquired ({$udpTargeted} via live host, {$skippedCached} already cached)");
        }

        return compact('attempted', 'acquired', 'udpTargeted', 'skippedCached');
    }

    /**
     * Candidate version ids: any version carrying a content key, freshest-seen first (most relevant,
     * most likely still served), minus those still cooling down from a recent failed attempt.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function candidateIds(bool $includeCooldown)
    {
        $query = MapVersion::query()
            ->where(fn ($q) => $q->whereNotNull('sha256')->orWhereNotNull('crc'));

        if (!$includeCooldown) {
            $threshold = now()->subHours($this->retryHours());
            $query->where(fn ($q) => $q->whereNull('last_acquire_attempt_at')
                ->orWhere('last_acquire_attempt_at', '<', $threshold));
        }

        return $query->orderByDesc('last_seen_at')->pluck('id');
    }

    /** Whether a server is serving this exact version right now (a history row refreshed in the live window). */
    private function isCurrentlyServed(MapVersion $version, Carbon $liveThreshold): bool
    {
        return ServerHistory::where('map_version_id', $version->getAttribute('id'))
            ->where('updated_at', '>=', $liveThreshold)
            ->exists();
    }

    private function markAttempted(MapVersion $version): void
    {
        // query-builder update so the version's own updated_at (its recency signal) is left alone
        MapVersion::whereKey($version->getKey())->toBase()->update(['last_acquire_attempt_at' => now()]);
    }

    private function liveWindowMinutes(): int
    {
        // default: two scrape intervals, so a version seen on the previous scrape still counts as live
        return (int) env('MAP_BACKFILL_LIVE_WINDOW_MIN', max(1, (int) env('CRONTASK_INTERVAL', 10)) * 2);
    }

    private function retryHours(): int
    {
        return (int) env('MAP_ACQUIRE_RETRY_HOURS', 6);
    }

    private function nowMs(): int
    {
        return (int) (hrtime(true) / 1_000_000);
    }
}
