<?php

namespace App\Console\Commands;

use App\Jobs\ArchiveMap;
use App\Models\Map;
use App\Service\MapArchiveStatus;
use App\Service\MapArchiver;
use Illuminate\Console\Command;

class EnqueueMapArchiveJobs extends Command
{
    protected $signature = 'maps:archive-enqueue {--limit= : Max jobs to dispatch this run (defaults to MAP_ARCHIVE_ENQUEUE_LIMIT)}';

    protected $description = 'Queue ArchiveMap jobs for maps that have no cached .map file yet';

    public function handle(MapArchiver $archiver): int
    {
        if (!env('MAP_ARCHIVE_ENABLED', true)) {
            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?? env('MAP_ARCHIVE_ENQUEUE_LIMIT', 200));
        $threshold = $archiver->cooldownThreshold();
        $dispatched = 0;
        $skipped = 0;

        foreach (Map::needsArchiving($threshold)->lazy() as $map) {
            if ($dispatched >= $limit) {
                break;
            }
            // needsArchiving can't see the disk; skip maps already cached (e.g. via a web /file hit)
            // so we don't dispatch a no-op job.
            if ($archiver->statusFor($map) === MapArchiveStatus::Downloaded) {
                $skipped++;
                continue;
            }
            ArchiveMap::dispatch($map);
            $dispatched++;
        }

        $this->info("map archive enqueue: {$dispatched} dispatched, {$skipped} already cached");

        return self::SUCCESS;
    }
}
