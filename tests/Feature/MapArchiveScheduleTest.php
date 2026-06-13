<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class MapArchiveScheduleTest extends TestCase
{
    public function test_archive_enqueue_worker_and_prune_are_scheduled(): void
    {
        // withSchedule() registers via Artisan::starting(), so any artisan call is needed
        // to fire that hook before the Schedule singleton is inspected.
        $this->artisan('list');

        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->filter()
            ->implode("\n");

        $this->assertStringContainsString('maps:archive-enqueue', $commands);
        $this->assertStringContainsString('queue:work --queue=maps', $commands);
        $this->assertStringContainsString('queue:prune-failed', $commands);
    }
}
