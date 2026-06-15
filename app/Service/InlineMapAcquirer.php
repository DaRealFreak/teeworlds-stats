<?php

namespace App\Service;

use App\Models\MapVersion;

/**
 * Grabs UDP-only map versions inline during the scrape, from the host just seen serving them — the
 * only reliable window (a vanilla 0.6/0.7 map has no HTTP mirror, and the host switches maps before
 * any deferred job could run). Candidates are collected during the server loop and downloaded in a
 * bounded, best-effort pass after stats are written, so the scrape never waits on a download.
 * HTTP-acquirable versions (those with a sha256, mirrored on maps.ddnet.org) are left for on-demand.
 */
final class InlineMapAcquirer
{
    /** @var array<int, MapVersion> eligible versions, deduped by version id */
    private array $candidates = [];

    public function __construct(
        private readonly MapFileStore $store = new MapFileStore(),
    ) {
    }

    /** Register a version for inline acquisition when it is UDP-only, not cooling down, and uncached. */
    public function consider(MapVersion $version): void
    {
        $id = (int) $version->getAttribute('id');
        if (isset($this->candidates[$id])) {
            return;
        }
        if ($version->getAttribute('sha256') !== null) {
            return; // HTTP-acquirable → fetched on demand, not inline
        }
        if ($this->inCooldown($version)) {
            return;
        }
        if ($this->store->isCachedVersion($version)) {
            return;
        }

        $this->candidates[$id] = $version;
    }

    /**
     * Bounded, best-effort acquisition pass. Each candidate is stamped attempted (so a failure parks
     * it for the cooldown) then acquired via the store; failures are swallowed. Stops at the per-run
     * count cap or the wall-clock budget.
     *
     * @param callable|null $log fn(string): void — optional progress sink (e.g. the command output)
     */
    public function run(?callable $log = null): void
    {
        $max = (int) env('MAP_ACQUIRE_MAX_PER_RUN', 25);
        $budgetMs = (int) env('MAP_ACQUIRE_TIME_BUDGET_S', 60) * 1000;
        $startMs = $this->nowMs();
        $acquired = 0;
        $attempted = 0;

        foreach ($this->candidates as $version) {
            if ($attempted >= $max || ($this->nowMs() - $startMs) >= $budgetMs) {
                break;
            }
            $attempted++;
            $this->markAttempted($version);
            try {
                $this->store->ensureVersion($version);
                $acquired++;
            } catch (\Throwable) {
                // best-effort — the attempt stamp parks it for the cooldown; retried next run
            }
        }

        if ($log !== null && $attempted > 0) {
            $log("inline map acquire: {$acquired}/{$attempted} acquired");
        }
    }

    private function inCooldown(MapVersion $version): bool
    {
        $attemptedAt = $version->getAttribute('last_acquire_attempt_at');

        return $attemptedAt !== null && $attemptedAt >= now()->subHours($this->retryHours());
    }

    private function markAttempted(MapVersion $version): void
    {
        // query-builder update so the version's own updated_at (not its recency signal) is left alone
        MapVersion::whereKey($version->getKey())->toBase()->update(['last_acquire_attempt_at' => now()]);
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
