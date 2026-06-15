<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Service\MapArchiveStatus;
use App\Service\MapArchiver;
use App\Service\MapFileStore;
use App\Service\MapFileUnavailable;
use App\Service\MapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_status_for_covers_all_four_states(): void
    {
        Storage::fake('local');
        $archiver = new MapArchiver();

        $downloadedSha = str_repeat('a', 64);
        $downloaded = Map::create(['name' => 'dl', 'sha256' => $downloadedSha]);
        Storage::disk('local')->put('maps/dl_' . $downloadedSha . '.map', 'BYTES');
        $this->assertSame(MapArchiveStatus::Downloaded, $archiver->statusFor($downloaded));

        $unavailable = Map::create(['name' => 'bare']);
        $this->assertSame(MapArchiveStatus::Unavailable, $archiver->statusFor($unavailable));

        $queued = Map::create(['name' => 'q', 'sha256' => str_repeat('b', 64)]);
        $this->assertSame(MapArchiveStatus::Queued, $archiver->statusFor($queued));

        $retrying = Map::create(['name' => 'r', 'sha256' => str_repeat('c', 64), 'last_archive_attempt_at' => now()]);
        $this->assertSame(MapArchiveStatus::Retrying, $archiver->statusFor($retrying));
    }

    public function test_download_stamps_attempt_without_bumping_updated_at_and_caches(): void
    {
        Storage::fake('local');
        $body = 'ARCHIVEDBYTES';
        $sha = hash('sha256', $body);
        $map = Map::create(['name' => 'kept', 'sha256' => $sha, 'size' => strlen($body)]);

        $past = now()->subDays(3);
        Map::whereKey($map->id)->toBase()->update(['updated_at' => $past]);

        $source = new class($body) implements MapSource {
            public function __construct(private string $body) {}
            public function fetch(Map $map): ?string { return $this->body; }
            public function fetchVersion(\App\Models\MapVersion $version): ?string { return $this->body; }
        };
        $store = new MapFileStore([$source]);
        $archiver = new MapArchiver($store);

        $archiver->download($map);

        $this->assertTrue($store->isCached($map->fresh()));
        $fresh = $map->fresh();
        $this->assertNotNull($fresh->last_archive_attempt_at);
        $this->assertSame($past->toDateTimeString(), $fresh->updated_at->toDateTimeString());
    }

    public function test_status_for_returns_queued_once_cooldown_has_elapsed(): void
    {
        Storage::fake('local');
        $archiver = new MapArchiver();

        // attempted well beyond the default 24h cooldown -> eligible again
        $elapsed = Map::create([
            'name' => 'elapsed',
            'sha256' => str_repeat('a', 64),
            'last_archive_attempt_at' => now()->subHours(25),
        ]);

        $this->assertSame(MapArchiveStatus::Queued, $archiver->statusFor($elapsed));
    }

    public function test_download_stamps_attempt_even_when_no_source_can_serve(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ghost', 'sha256' => str_repeat('a', 64)]);
        $store = new MapFileStore([]); // no sources can serve it
        $archiver = new MapArchiver($store);

        try {
            $archiver->download($map);
            $this->fail('expected MapFileUnavailable');
        } catch (MapFileUnavailable) {
            // expected — the stamp must survive the failure so the map is parked for the cooldown
        }

        $this->assertNotNull($map->fresh()->last_archive_attempt_at);
        $this->assertFalse($store->isCached($map->fresh()));
    }

    public function test_has_downloadable_file_true_for_keyless_map_with_a_host(): void
    {
        $hosted  = Map::create(['name' => 'hosted']);
        $mod     = \App\Models\Mod::create(['name' => 'dm']);
        $server  = \App\Models\Server::factory()->create(['flavor' => 'vanilla_06']);
        \App\Models\ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $hosted->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);

        $orphan = Map::create(['name' => 'orphan']); // keyless, no host

        $this->assertTrue($hosted->fresh()->hasDownloadableFile());
        $this->assertFalse($orphan->fresh()->hasDownloadableFile());
    }

    public function test_needs_archiving_includes_keyless_hosted_excludes_orphan(): void
    {
        $cooldown = now()->subHours(24);

        $hosted  = Map::create(['name' => 'hosted']);
        $mod     = \App\Models\Mod::create(['name' => 'dm']);
        $server  = \App\Models\Server::factory()->create(['flavor' => 'vanilla_06']);
        \App\Models\ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $hosted->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);

        Map::create(['name' => 'orphan']); // keyless, no host → excluded

        $names = Map::needsArchiving($cooldown)->pluck('name')->all();

        $this->assertContains('hosted', $names);
        $this->assertNotContains('orphan', $names);
    }

    public function test_status_for_keyless_hosted_is_queued_not_unavailable(): void
    {
        Storage::fake('local');

        $hosted  = Map::create(['name' => 'hosted']);
        $mod     = \App\Models\Mod::create(['name' => 'dm']);
        $server  = \App\Models\Server::factory()->create(['flavor' => 'vanilla_06']);
        \App\Models\ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $hosted->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);

        $this->assertSame(MapArchiveStatus::Queued, (new MapArchiver())->statusFor($hosted->fresh()));
    }
}
