<?php

namespace App\Console\Commands;

use App\Service\MapArchiveFiller;
use Illuminate\Console\Command;

/**
 * Drains the uncached map-version backlog into the local mirror, newest-first and unbounded by count:
 * HTTP-mirrored versions cache, the rest classify (udp_only/unavailable) or grab over UDP when a healthy
 * live host is serving them right now. The only bound is the wall-clock budget, so the scheduled run can be
 * resumed by withoutOverlapping on the next tick rather than capping how much it does per pass.
 */
class ArchiveMapFill extends Command
{
    protected $signature = 'maps:archive-fill
        {--limit=0 : Max versions to attempt this run (0 = no count cap, rely on the time budget)}
        {--time-budget= : Wall-clock budget in seconds (default MAP_FILL_TIME_BUDGET_S / 3600)}';

    protected $description = 'Download uncached map versions into the local mirror (unbounded, status-aware filler)';

    public function handle(MapArchiveFiller $filler): int
    {
        $budget = $this->option('time-budget') !== null
            ? (int) $this->option('time-budget')
            : (int) env('MAP_FILL_TIME_BUDGET_S', 3600);

        $filler->run(
            limit: (int) $this->option('limit'),
            timeBudgetS: $budget,
            log: fn (string $message) => $this->info($message),
        );

        return self::SUCCESS;
    }
}
