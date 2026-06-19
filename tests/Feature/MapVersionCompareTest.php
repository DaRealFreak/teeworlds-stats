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
        $v1 = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        $v2 = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);

        return [$map, $v1, $v2];
    }

    public function test_version_and_compare_query_params_resolve(): void
    {
        [, $v1, $v2] = $this->mapWithVersions();

        $this->get('/map/Linear?version=' . $v2->sha256 . '&compare=' . $v1->sha256)
            ->assertOk()
            ->assertViewHas('selected', fn ($v) => $v->is($v2))
            ->assertViewHas('compare', fn ($v) => $v !== null && $v->is($v1))
            ->assertViewHas('canCompare', true);
    }

    public function test_compare_ignores_an_unavailable_version(): void
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

    public function test_per_row_compare_keeps_the_viewed_version_as_base(): void
    {
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        $b = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        $c = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);
        $bad = MapVersion::factory()->for($map)->create([
            'sha256' => str_repeat('d', 64),
            'crc' => null,
            'acquisition_status' => MapVersion::STATUS_UNAVAILABLE,
        ]);

        // Viewing b: Compare on another row must KEEP b displayed (b stays ?version = the base) and
        // set the clicked row as ?compare (the target). Compare never switches the viewed map.
        $html = $this->get('/map/Linear?version=' . $b->sha256)->assertOk()->getContent();

        // the radio UI is gone
        $this->assertStringNotContainsString('name="cmpA"', $html);
        $this->assertStringNotContainsString('name="cmpB"', $html);
        // row c's Compare keeps the viewed version (b) as ?version and targets c via ?compare
        // (route() renders version-then-compare; Blade escapes & as &amp;)
        $this->assertStringContainsString('version=' . $b->sha256 . '&amp;compare=' . $c->sha256, $html);
        // the viewed version (b) is the base, never offered as a compare target
        $this->assertStringNotContainsString('compare=' . $b->sha256, $html);
        // the unavailable row (d) is not offered as a compare target
        $this->assertStringNotContainsString('compare=' . $bad->sha256, $html);
        // the viewer-area notice element is present
        $this->assertStringContainsString('id="mapCompareNotice"', $html);
    }

    public function test_active_comparison_marks_the_target_row_and_wires_the_overlay(): void
    {
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        $viewed = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        $other = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);

        // Viewing b, comparing against c: b stays the displayed base, c is the target.
        $html = $this->get('/map/Linear?version=' . $viewed->sha256 . '&compare=' . $other->sha256)
            ->assertOk()->getContent();

        // overlay wiring present; the diff base (displayed) is the viewed version's file
        $this->assertStringContainsString('window.MapCompare.run', $html);
        $this->assertStringContainsString('/map/Linear/file/' . $viewed->sha256, $html);
        // the compared-against row (c) is badged "comparing"; the old misplaced "base" badge is gone
        $this->assertStringContainsString('>comparing</span>', $html);
        $this->assertStringNotContainsString('>base</span>', $html);
        // notice element present
        $this->assertStringContainsString('id="mapCompareNotice"', $html);
    }
}
