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

    public function test_versions_panel_renders_per_row_compare_links_and_no_radios(): void
    {
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        $b = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        $c = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);
        $bad = MapVersion::factory()->for($map)->create([
            'sha256' => str_repeat('d', 64),
            'crc' => null,
            'acquisition_status' => MapVersion::STATUS_UNAVAILABLE,
        ]);

        // View version b explicitly so the "currently-viewed" base is deterministic.
        $html = $this->get('/map/Linear?version=' . $b->sha256)->assertOk()->getContent();

        // the radio UI is gone
        $this->assertStringNotContainsString('name="cmpA"', $html);
        $this->assertStringNotContainsString('name="cmpB"', $html);
        // a non-viewed, fetchable row (c) gets a Compare link with the viewed version (b) as the base
        // (route() renders the query string version-then-compare; Blade escapes & as &amp;)
        $this->assertStringContainsString('version=' . $c->sha256 . '&amp;compare=' . $b->sha256, $html);
        // the viewed row (b) has no Compare link of its own
        $this->assertStringNotContainsString('version=' . $b->sha256 . '&amp;compare=', $html);
        // the unavailable row (d) has no Compare link
        $this->assertStringNotContainsString('version=' . $bad->sha256 . '&amp;compare=', $html);
        // the viewer-area notice element is present
        $this->assertStringContainsString('id="mapCompareNotice"', $html);
    }

    public function test_compare_param_renders_base_badge_notice_and_overlay_wiring(): void
    {
        [, $base, $target] = $this->mapWithVersions();

        $html = $this->get('/map/Linear?version=' . $target->sha256 . '&compare=' . $base->sha256)
            ->assertOk()->getContent();

        // overlay wiring still points at the base version's file
        $this->assertStringContainsString('window.MapCompare.run', $html);
        $this->assertStringContainsString('/map/Linear/file/' . $base->sha256, $html);
        // the base row is badged, and the notice element exists
        $this->assertStringContainsString('>base</span>', $html);
        $this->assertStringContainsString('id="mapCompareNotice"', $html);
    }
}
