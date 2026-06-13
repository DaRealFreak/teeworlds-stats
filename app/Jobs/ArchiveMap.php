<?php

namespace App\Jobs;

use App\Models\Map;
use App\Service\MapArchiver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Archives one map's .map file off the request/scrape path. Unique per map id so the enqueue command
 * (which runs every few minutes) never stacks duplicate jobs while one is pending; once it finishes,
 * the map's last_archive_attempt_at cooldown governs the next attempt. A map with no usable source
 * exhausts $tries and lands in failed_jobs, then waits out the cooldown.
 */
final class ArchiveMap implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    // Safety net: release the uniqueness lock after an hour even if a worker dies mid-job, so the map
    // can be re-enqueued. Comfortably longer than the backoff sequence below.
    public int $uniqueFor = 3600;

    public function __construct(public readonly Map $map)
    {
        $this->onQueue('maps');
    }

    public function uniqueId(): string
    {
        return (string) $this->map->getKey();
    }

    /**
     * @return int[] seconds to wait between retries (transient HTTP/UDP failures)
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(MapArchiver $archiver): void
    {
        $archiver->download($this->map);
    }
}
