<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\DdnetHttpMapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DdnetHttpMapSourceRecheckTest extends TestCase
{
    use RefreshDatabase;

    private function versionWith(string $status, $checkedAt): MapVersion
    {
        $bytes = 'DATA' . str_repeat("\x00", 64);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create([
            'map_id' => $map->id,
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Set status + recheck timestamp via the base query so we control status_checked_at directly
        // (markStatus() would force it to now()).
        MapVersion::whereKey($version->id)->toBase()->update([
            'acquisition_status' => $status,
            'status_checked_at' => $checkedAt,
        ]);

        return $version->fresh();
    }

    public function test_skips_probe_when_udp_only_and_fresh(): void
    {
        $version = $this->versionWith(MapVersion::STATUS_UDP_ONLY, now());

        Http::fake();

        $this->assertNull((new DdnetHttpMapSource())->fetchVersion($version));
        Http::assertNothingSent();
    }

    public function test_reprobes_when_status_check_is_stale(): void
    {
        // Older than the 30-day recheck window → the mirror is probed again in case the version has
        // since been published; the 404 path then re-stamps the status via the listener.
        $version = $this->versionWith(MapVersion::STATUS_UDP_ONLY, now()->subDays(31));

        Http::fake(['maps.ddnet.org/*' => Http::response('nope', 404)]);

        $this->assertNull((new DdnetHttpMapSource())->fetchVersion($version));
        Http::assertSentCount(1);
    }

    public function test_pending_version_always_probes(): void
    {
        $version = $this->versionWith(MapVersion::STATUS_PENDING, null);

        Http::fake(['maps.ddnet.org/*' => Http::response('nope', 404)]);

        $this->assertNull((new DdnetHttpMapSource())->fetchVersion($version));
        Http::assertSentCount(1);
    }
}
