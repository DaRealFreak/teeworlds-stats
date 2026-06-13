<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Service\MapArchiveStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapArchiverTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_labels_and_badge_classes(): void
    {
        $this->assertSame('Downloaded', MapArchiveStatus::Downloaded->label());
        $this->assertSame('Queued', MapArchiveStatus::Queued->label());
        $this->assertSame('Retrying', MapArchiveStatus::Retrying->label());
        $this->assertSame('Unavailable', MapArchiveStatus::Unavailable->label());

        $this->assertSame('bg-success', MapArchiveStatus::Downloaded->badgeClass());
        $this->assertSame('bg-info', MapArchiveStatus::Queued->badgeClass());
        $this->assertSame('bg-warning', MapArchiveStatus::Retrying->badgeClass());
        $this->assertSame('bg-secondary', MapArchiveStatus::Unavailable->badgeClass());
    }

    public function test_needs_archiving_selects_keyed_uncooled_maps_never_attempted_first(): void
    {
        $cooldown = now()->subHours(24);

        Map::create(['name' => 'bare']); // no key -> excluded
        Map::create(['name' => 'cooling', 'sha256' => str_repeat('a', 64), 'last_archive_attempt_at' => now()]); // within cooldown -> excluded
        Map::create(['name' => 'never', 'sha256' => str_repeat('b', 64)]); // null attempt -> first
        Map::create(['name' => 'old', 'crc' => 'aabbccdd', 'last_archive_attempt_at' => now()->subDays(2)]); // beyond cooldown -> after 'never'

        $names = Map::needsArchiving($cooldown)->pluck('name')->all();

        $this->assertSame(['never', 'old'], $names);
    }
}
