<?php

namespace App\Service;

use App\Models\MapVersion;
use App\Models\ServerHistory;
use Illuminate\Support\Collection;

/**
 * Drains the whole uncached map-version backlog into the local mirror, unbounded by count and ordered
 * newest-first, parallel to the scrape. The single status-aware acquisition path: it replaces the former
 * bounded inline acquire that ran synchronously inside the scrape and the former hourly HTTP-only backfill.
 * The first run or two classify the backlog (HTTP-mirrored versions cache, the rest flip to
 * udp_only/unavailable), then steady-state work shrinks to new maps plus any udp_only version that now has a
 * live host. The only run bound is the wall-clock budget; the schedule's withoutOverlapping resumes the drain
 * on the next tick.
 *
 * Two acquisition tiers chosen per version, so a run never wastes a UDP connect timeout on a host that has
 * long since rotated to a different map:
 *  - HTTP tier (default): the HTTP-only store fetches versions mirrored on maps.ddnet.org and cheaply drains
 *    the mirrorable majority whether or not any server still runs them.
 *  - UDP tier (gated): the full HTTP→UDP store is used ONLY for a version a *healthy* live host is serving
 *    right now — a server_histories row within the live window whose (server, map) is not in backoff. That is
 *    the only situation in which the UDP fallback can connect and pull the bytes, so it is the only situation
 *    in which we pay for it.
 *
 * It does NOT pre-stamp last_acquire_attempt_at: the reactive
 * ApplyMapAcquisitionState listener now stamps the per-map cooldown only on the reasons that warrant it (bad
 * mirror bytes), while a no-live-host outcome flips status to unavailable WITHOUT a cooldown so the version
 * stays a candidate the instant a host appears. Pre-stamping here would re-introduce the 6h park the overhaul
 * removed.
 */
final class MapArchiveFiller
{
    private readonly MapFileStore $fullStore;

    private readonly MapFileStore $httpStore;

    public function __construct(?MapFileStore $fullStore = null, ?MapFileStore $httpStore = null)
    {
        // Defaults built here (not as constant default params) so the HTTP-only store can name its single
        // source without relying on new-in-initializer constant-expression support.
        $this->fullStore = $fullStore ?? new MapFileStore();
        $this->httpStore = $httpStore ?? new MapFileStore(sources: [new DdnetHttpMapSource()]);
    }

    /**
     * @param int $limit max versions to attempt this run; 0 means no count cap (rely on the budget)
     * @param int $timeBudgetS wall-clock budget in seconds
     * @param callable|null $log fn(string): void — optional progress sink (e.g. the command output)
     * @return array{attempted:int, acquired:int, skippedCached:int, udpEligible:int}
     */
    public function run(int $limit, int $timeBudgetS, ?callable $log = null): array
    {
        $budgetMs = $timeBudgetS * 1000;
        $startMs = $this->nowMs();

        $candidateIds = $this->candidateIds($limit);
        $liveHostSet = $this->liveHostSet($candidateIds);

        $attempted = 0;
        $acquired = 0;
        $skippedCached = 0;
        $udpEligible = 0;

        foreach ($candidateIds as $id) {
            if (($limit > 0 && $attempted >= $limit) || ($this->nowMs() - $startMs) >= $budgetMs) {
                break;
            }

            /** @var MapVersion|null $version */
            $version = MapVersion::with('map')->find($id);
            if ($version === null) {
                continue;
            }
            // The inline path or a concurrent run may have cached it since we listed candidates.
            if ($this->fullStore->isCachedVersion($version)) {
                $skippedCached++;
                continue;
            }

            $eligible = isset($liveHostSet[$id]);
            $store = $eligible ? $this->fullStore : $this->httpStore;
            if ($eligible) {
                $udpEligible++;
            }

            $attempted++;
            try {
                $store->ensureVersion($version);
                $acquired++;
            } catch (\Throwable) {
                // best-effort: the reactive listener records the failure + the right cooldown/backoff
            }
        }

        if ($log !== null) {
            $log("map fill: {$acquired}/{$attempted} acquired ({$udpEligible} via live host, {$skippedCached} already cached)");
        }

        return compact('attempted', 'acquired', 'skippedCached', 'udpEligible');
    }

    /**
     * Candidate version ids: any version still needing acquisition (status != cached) that carries a content
     * key, freshest-seen first (most relevant, most likely still served), minus those still cooling down from
     * a recent failed attempt. $limit > 0 caps the list; 0 means no count cap.
     *
     * @return Collection<int, int>
     */
    private function candidateIds(int $limit): Collection
    {
        $threshold = now()->subHours($this->retryHours());

        $query = MapVersion::query()
            ->needingAcquisition()
            ->where(fn ($q) => $q->whereNotNull('sha256')->orWhereNotNull('crc'))
            ->where(fn ($q) => $q->whereNull('last_acquire_attempt_at')
                ->orWhere('last_acquire_attempt_at', '<', $threshold))
            ->orderByDesc('last_seen_at');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->pluck('id');
    }

    /**
     * The set of candidate version ids that have at least one HEALTHY live host — resolved in ONE query so the
     * filler avoids a per-version "is a host serving this?" probe across a large unavailable backlog every run
     * (those versions never stamp a cooldown, so they stay candidates every pass). A host is healthy when its
     * server_histories row for the version is within the live window AND its (server, version.map) pair is not
     * parked in server_map_backoffs — the same gate GameServerMapSource applies when picking a host to dial.
     *
     * @param Collection<int, int> $candidateIds
     * @return array<int, true> a fast isset() lookup keyed by map_version_id
     */
    private function liveHostSet(Collection $candidateIds): array
    {
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $liveThreshold = now()->subMinutes($this->liveWindowMinutes());

        $ids = ServerHistory::query()
            ->whereIn('map_version_id', $candidateIds)
            ->where('updated_at', '>=', $liveThreshold)
            ->whereNotExists(function ($sub) {
                $sub->from('server_map_backoffs')
                    ->whereColumn('server_map_backoffs.server_id', 'server_histories.server_id')
                    ->whereColumn('server_map_backoffs.map_id', 'server_histories.map_id')
                    ->where('server_map_backoffs.cooldown_until', '>', now());
            })
            ->distinct()
            ->pluck('map_version_id');

        return array_fill_keys($ids->all(), true);
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
