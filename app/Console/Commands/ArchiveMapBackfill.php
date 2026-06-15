<?php

namespace App\Console\Commands;

use App\Service\MapArchiveBackfiller;
use Illuminate\Console\Command;

/**
 * Bulk-archive the uncached map-version backlog the inline acquirer cannot reach: HTTP-mirrored
 * versions are fetched regardless of liveness; UDP-only versions only when a server is serving them
 * right now (so a run never burns a connect timeout on a host that has moved on). Bounded by --limit
 * and --time-budget so a run stays predictable; safe to re-run (cooled-down failures are skipped
 * unless --include-cooldown).
 */
class ArchiveMapBackfill extends Command
{
    protected $signature = 'maps:archive-backfill
        {--limit=300 : Max versions to attempt this run (0 = no count cap, rely on the time budget)}
        {--time-budget=300 : Wall-clock budget in seconds}
        {--http-only : Only fetch HTTP-mirrored versions; never dial a game server}
        {--include-cooldown : Also retry versions still within the failure cooldown}';

    protected $description = 'Download uncached map versions into the local mirror (HTTP backlog + live UDP-only maps)';

    public function handle(MapArchiveBackfiller $backfiller): int
    {
        $backfiller->run(
            limit: (int) $this->option('limit'),
            timeBudgetS: (int) $this->option('time-budget'),
            includeCooldown: (bool) $this->option('include-cooldown'),
            httpOnly: (bool) $this->option('http-only'),
            log: fn (string $message) => $this->info($message),
        );

        return self::SUCCESS;
    }
}
