<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionCompareTest extends TestCase
{
    use RefreshDatabase;

    private function mapWithVersions(): array
    {
        // The Map row carries a key so hasDownloadableFile() is true (the viewer/compare gate).
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        $base = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        $target = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);

        return [$map, $base, $target];
    }

    public function test_compare_query_param_resolves_to_a_pickable_base_version(): void
    {
        [, $base, $target] = $this->mapWithVersions();

        $this->get('/map/Linear?version=' . $target->sha256 . '&compare=' . $base->sha256)
            ->assertOk()
            ->assertViewHas('compare', fn ($v) => $v !== null && $v->is($base))
            ->assertViewHas('selected', fn ($v) => $v->is($target))
            ->assertViewHas('canCompare', true);
    }

    public function test_compare_ignores_an_unavailable_base_version(): void
    {
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        $bad = MapVersion::factory()->for($map)->create([
            'sha256' => str_repeat('d', 64),
            'crc' => null,
            'acquisition_status' => MapVersion::STATUS_UNAVAILABLE,
        ]);

        $this->get('/map/Linear?compare=' . $bad->sha256)
            ->assertOk()
            ->assertViewHas('compare', null);
    }
}
