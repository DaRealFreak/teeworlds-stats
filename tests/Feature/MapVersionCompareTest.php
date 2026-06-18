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
            ->assertViewHas('compare', null)
            ->assertViewHas('canCompare', false);
    }

    public function test_versions_panel_renders_compare_radios_and_disables_unavailable(): void
    {
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);
        $bad = MapVersion::factory()->for($map)->create([
            'sha256' => str_repeat('d', 64),
            'crc' => null,
            'acquisition_status' => MapVersion::STATUS_UNAVAILABLE,
        ]);

        $html = $this->get('/map/Linear')->assertOk()->getContent();

        $this->assertStringContainsString('name="cmpA"', $html);
        $this->assertStringContainsString('name="cmpB"', $html);
        $this->assertStringContainsString('id="mapCompareBtn"', $html);
        $this->assertStringContainsString('id="mapCompareResult"', $html);
        // the unavailable version's radio is disabled
        $this->assertMatchesRegularExpression('/value="' . $bad->sha256 . '"[^>]*disabled/', $html);
    }

    public function test_compare_param_renders_base_file_url_for_the_overlay(): void
    {
        [, $base, $target] = $this->mapWithVersions();

        $html = $this->get('/map/Linear?version=' . $target->sha256 . '&compare=' . $base->sha256)
            ->assertOk()->getContent();

        $this->assertStringContainsString('window.MapCompare.run', $html);
        $this->assertStringContainsString('/map/Linear/file/' . $base->sha256, $html);
        $this->assertMatchesRegularExpression('/name="cmpA" value="' . $base->sha256 . '"[^>]*checked/', $html);
    }
}
