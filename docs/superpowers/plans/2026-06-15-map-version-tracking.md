# Map Version Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track every distinct content version of a map name in a `map_versions` child table and stamp each `server_histories` sighting with the version it served — the data foundation for listing/downloading versions and for reliable acquisition.

**Architecture:** A name-level `maps` row stays the stats/URL anchor; a new `map_versions` row per distinct `(sha256|crc)` hangs off it. The scraper records a version per sighting (match-by-either-key + backfill, merge duplicates), stamps `server_histories.map_version_id`, and updates `maps.sha256/crc/size` to point at the current (newest-seen) version. Play-time stays aggregated by name.

**Tech Stack:** Laravel 13, PHP 8.3+, MySQL (SQLite `:memory:` in tests), PHPUnit, Eloquent.

**Spec:** `docs/superpowers/specs/2026-06-15-map-version-tracking-design.md`

**Scope of this plan:** Phase 1 only (data model + scraper). Phases 2-4 (acquisition rework, per-version routes/download, UI) are summarized in the roadmap at the end and will each get their own detailed plan once Phase 1's signatures are concrete.

---

## File Structure (Phase 1)

- Create: `database/migrations/2026_06_15_100000_create_map_versions_table.php` — `map_versions` table + `server_histories.map_version_id`.
- Create: `app/Models/MapVersion.php` — the version model.
- Create: `database/factories/MapVersionFactory.php` — test factory.
- Modify: `app/Models/Map.php` — `versions()` + `currentVersion()` relations.
- Modify: `app/Models/ServerHistory.php` — `mapVersion()` relation.
- Create: `app/Service/MapVersionRecorder.php` — upsert/match/backfill/merge a version from a sighting.
- Create: `tests/Unit/Service/MapVersionRecorderTest.php`.
- Create: `app/Console/Commands/BackfillMapVersions.php` — one-shot idempotent backfill of legacy `maps` rows.
- Create: `tests/Feature/BackfillMapVersionsTest.php`.
- Modify: `app/Console/Commands/UpdateData.php` — inject the recorder; record a version per server sighting; stamp `server_histories.map_version_id`; repoint `maps.*` at the current version.
- Create: `tests/Feature/MapVersionScrapeTest.php`.

---

## Task 1: Schema migration

**Files:**
- Create: `database/migrations/2026_06_15_100000_create_map_versions_table.php`
- Test: `tests/Feature/MapVersionSchemaTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A map NAME is not unique: different servers host byte-different files under it. Each distinct
        // file is a map_versions row, identified by sha256 (DDNet) and/or crc (0.6/0.7). Both columns
        // live on one row so a file seen under two partial identities is one version, not two.
        Schema::create('map_versions', function (Blueprint $table) {
            $table->increments('id'); // matches maps.id (increments) so FKs line up
            $table->unsignedInteger('map_id');
            $table->char('sha256', 64)->nullable();
            $table->string('crc', 8)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_acquire_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['map_id', 'sha256']);
            $table->index(['map_id', 'crc']);
            $table->foreign('map_id')->references('id')->on('maps')->cascadeOnDelete();
        });

        // Which version a host was serving at this sighting — enables version-targeted host lookup and
        // the per-version "N servers" count. Stats still group by map_id; this does not split them.
        Schema::table('server_histories', function (Blueprint $table) {
            $table->unsignedInteger('map_version_id')->nullable()->after('map_id');
            $table->foreign('map_version_id')->references('id')->on('map_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('server_histories', function (Blueprint $table) {
            $table->dropForeign(['map_version_id']);
            $table->dropColumn('map_version_id');
        });
        Schema::dropIfExists('map_versions');
    }
};
```

- [ ] **Step 2: Write a schema smoke test**

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_versions_table_and_columns_exist(): void
    {
        $map = Map::create(['name' => 'FlipLipp30']);
        $version = MapVersion::create([
            'map_id' => $map->id,
            'crc' => '3421b577',
            'size' => 335351,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->assertDatabaseHas('map_versions', ['id' => $version->id, 'crc' => '3421b577']);
        $this->assertSame($map->id, $version->fresh()->map_id);
    }
}
```

(This test depends on Task 2's `MapVersion` model + factory; if running tasks strictly in order, write Task 2's model first, then return here. They are bundled because the schema is meaningless without the model.)

- [ ] **Step 3: Run the migration against the dev DB**

Run: `php artisan migrate`
Expected: `Migrating: 2026_06_15_100000_create_map_versions_table` … `DONE`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_15_100000_create_map_versions_table.php tests/Feature/MapVersionSchemaTest.php
git commit -m "feat(maps): add map_versions table + server_histories.map_version_id"
```

---

## Task 2: MapVersion model, relations, factory

**Files:**
- Create: `app/Models/MapVersion.php`
- Create: `database/factories/MapVersionFactory.php`
- Modify: `app/Models/Map.php`
- Modify: `app/Models/ServerHistory.php`
- Test: `tests/Unit/MapVersionRelationsTest.php`

- [ ] **Step 1: Write the failing relations test**

```php
<?php

namespace Tests\Unit;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_version_is_the_newest_by_last_seen(): void
    {
        $map = Map::create(['name' => 'FlipLipp30']);
        MapVersion::create(['map_id' => $map->id, 'crc' => 'aaaaaaaa', 'last_seen_at' => now()->subDay(), 'first_seen_at' => now()->subDay()]);
        $newest = MapVersion::create(['map_id' => $map->id, 'crc' => 'bbbbbbbb', 'last_seen_at' => now(), 'first_seen_at' => now()]);

        $this->assertCount(2, $map->versions);
        $this->assertTrue($map->currentVersion->is($newest));
    }

    public function test_content_key_prefers_sha256_then_crc(): void
    {
        $sha = str_repeat('a', 64);
        $this->assertSame($sha, (new MapVersion(['sha256' => $sha, 'crc' => 'deadbeef']))->contentKey());
        $this->assertSame('deadbeef', (new MapVersion(['crc' => 'deadbeef']))->contentKey());
        $this->assertNull((new MapVersion())->contentKey());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter MapVersionRelationsTest`
Expected: FAIL — `Class "App\Models\MapVersion" not found`.

- [ ] **Step 3: Write the MapVersion model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A distinct content version of a map NAME — one .map file, identified by its sha256 (DDNet) and/or
 * crc (0.6/0.7). Both keys live on one row; a file seen under two partial identities is merged into
 * one version (see App\Service\MapVersionRecorder). The parent Map stays the stats/URL anchor.
 */
class MapVersion extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_acquire_attempt_at' => 'datetime',
        ];
    }

    public function map()
    {
        return $this->belongsTo(Map::class);
    }

    public function serverRecords()
    {
        return $this->hasMany(ServerHistory::class);
    }

    /** The content key used in the cache filename and per-version URLs: sha256 if known, else crc. */
    public function contentKey(): ?string
    {
        return $this->getAttribute('sha256') ?? $this->getAttribute('crc');
    }
}
```

- [ ] **Step 4: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\MapVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MapVersion>
 */
class MapVersionFactory extends Factory
{
    protected $model = MapVersion::class;

    public function definition(): array
    {
        return [
            'crc' => dechex($this->faker->numberBetween(0x10000000, 0x7fffffff)),
            'size' => $this->faker->numberBetween(50_000, 800_000),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
```

- [ ] **Step 5: Add the Map relations** (`app/Models/Map.php`, after `serverRecords()`):

```php
    /** Every distinct content version observed for this map name (newest-first when ordered). */
    public function versions()
    {
        return $this->hasMany(MapVersion::class);
    }

    /** The current version = the most-recently-seen one; default for the viewer/download. */
    public function currentVersion()
    {
        return $this->hasOne(MapVersion::class)->latestOfMany('last_seen_at');
    }
```

- [ ] **Step 6: Add the ServerHistory relation** (`app/Models/ServerHistory.php`, after `map()`):

```php
    /** The map version this host was serving at this sighting (nullable for keyless/legacy rows). */
    public function mapVersion()
    {
        return $this->belongsTo(MapVersion::class);
    }
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'MapVersionRelationsTest|MapVersionSchemaTest'`
Expected: PASS (both classes green).

- [ ] **Step 8: Commit**

```bash
git add app/Models/MapVersion.php database/factories/MapVersionFactory.php app/Models/Map.php app/Models/ServerHistory.php tests/Unit/MapVersionRelationsTest.php
git commit -m "feat(maps): MapVersion model + Map/ServerHistory relations + factory"
```

---

## Task 3: MapVersionRecorder — create / match / backfill

**Files:**
- Create: `app/Service/MapVersionRecorder.php`
- Test: `tests/Unit/Service/MapVersionRecorderTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Service;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\MapVersionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionRecorderTest extends TestCase
{
    use RefreshDatabase;

    private MapVersionRecorder $recorder;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new MapVersionRecorder();
        $this->map = Map::create(['name' => 'FlipLipp30']);
    }

    public function test_keyless_sighting_records_nothing(): void
    {
        $this->assertNull($this->recorder->record($this->map, null, null, null));
        $this->assertDatabaseCount('map_versions', 0);
    }

    public function test_creates_a_version_for_a_new_crc(): void
    {
        $v = $this->recorder->record($this->map, null, '3421b577', 335351);

        $this->assertSame('3421b577', $v->crc);
        $this->assertSame(335351, $v->size);
        $this->assertNull($v->sha256);
        $this->assertDatabaseCount('map_versions', 1);
    }

    public function test_re_sighting_the_same_crc_bumps_last_seen_without_creating_a_row(): void
    {
        $first = $this->recorder->record($this->map, null, '3421b577', 335351);
        $first->update(['last_seen_at' => now()->subHour()]);

        $again = $this->recorder->record($this->map, null, '3421b577', 335351);

        $this->assertTrue($again->is($first));
        $this->assertDatabaseCount('map_versions', 1);
        $this->assertTrue($again->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_matches_by_sha256(): void
    {
        $sha = str_repeat('a', 64);
        $first = $this->recorder->record($this->map, $sha, null, 100);
        $again = $this->recorder->record($this->map, $sha, null, 100);

        $this->assertTrue($again->is($first));
        $this->assertDatabaseCount('map_versions', 1);
    }

    public function test_backfills_sha256_onto_a_crc_only_row(): void
    {
        $sha = str_repeat('b', 64);
        $row = $this->recorder->record($this->map, null, 'deadbeef', 200);
        // a later sighting (e.g. a ServerMerger-unified one) carries both keys
        $same = $this->recorder->record($this->map, $sha, 'deadbeef', 200);

        $this->assertTrue($same->is($row));
        $this->assertSame($sha, $same->fresh()->sha256);
        $this->assertDatabaseCount('map_versions', 1);
    }

    public function test_distinct_crcs_are_distinct_versions(): void
    {
        $this->recorder->record($this->map, null, '3421b577', 335351);
        $this->recorder->record($this->map, null, 'd6909b17', 281696);

        $this->assertDatabaseCount('map_versions', 2);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter MapVersionRecorderTest`
Expected: FAIL — `Class "App\Service\MapVersionRecorder" not found`.

- [ ] **Step 3: Write the recorder (create/match/backfill; merge added in Task 4)**

```php
<?php

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use Carbon\Carbon;

/**
 * Records the content version a map sighting served. A version is identified by sha256 (DDNet)
 * and/or crc (0.6/0.7); a sighting carries whatever its source knows. We match an existing version
 * by EITHER key, backfill the missing one, and (Task 4) merge two rows once a sighting proves they
 * are the same file. Keyless sightings record nothing — there is nothing to distinguish.
 */
class MapVersionRecorder
{
    /**
     * Record a sighting's version for $map and return it, or null when the sighting is keyless.
     */
    public function record(Map $map, ?string $sha256, ?string $crc, ?int $size): ?MapVersion
    {
        if ($sha256 === null && $crc === null) {
            return null;
        }

        $now = Carbon::now();

        $bySha = $sha256 !== null
            ? MapVersion::where('map_id', $map->getAttribute('id'))->where('sha256', $sha256)->first()
            : null;
        $byCrc = $crc !== null
            ? MapVersion::where('map_id', $map->getAttribute('id'))->where('crc', $crc)->first()
            : null;

        $version = $bySha ?? $byCrc;

        if ($version === null) {
            return MapVersion::create([
                'map_id' => $map->getAttribute('id'),
                'sha256' => $sha256,
                'crc' => $crc,
                'size' => $size,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        }

        $updates = ['last_seen_at' => $now];
        if ($version->getAttribute('sha256') === null && $sha256 !== null) {
            $updates['sha256'] = $sha256;
        }
        if ($version->getAttribute('crc') === null && $crc !== null) {
            $updates['crc'] = $crc;
        }
        if ($version->getAttribute('size') === null && $size !== null) {
            $updates['size'] = $size;
        }
        $version->update($updates);

        return $version;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter MapVersionRecorderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Service/MapVersionRecorder.php tests/Unit/Service/MapVersionRecorderTest.php
git commit -m "feat(maps): MapVersionRecorder records + matches + backfills versions"
```

---

## Task 4: MapVersionRecorder — merge duplicate rows

**Files:**
- Modify: `app/Service/MapVersionRecorder.php`
- Test: `tests/Unit/Service/MapVersionRecorderTest.php` (add a test)

- [ ] **Step 1: Add the failing merge test** (append to `MapVersionRecorderTest`):

```php
    public function test_merges_two_rows_when_a_sighting_carries_both_keys(): void
    {
        $sha = str_repeat('c', 64);
        // two partial rows recorded separately (DDNet sha-only host, 0.6 crc-only host)
        $shaRow = $this->recorder->record($this->map, $sha, null, 400);
        $crcRow = $this->recorder->record($this->map, null, 'cafef00d', 400);
        $crcRow->update(['first_seen_at' => now()->subDays(3)]);

        // a sighting carrying both keys proves they are the same file → one survives, with both keys
        $merged = $this->recorder->record($this->map, $sha, 'cafef00d', 400);

        $this->assertDatabaseCount('map_versions', 1);
        $this->assertSame($sha, $merged->fresh()->sha256);
        $this->assertSame('cafef00d', $merged->fresh()->crc);
        // surviving row keeps the earliest first_seen
        $this->assertTrue($merged->fresh()->first_seen_at->equalTo(now()->subDays(3)));
    }

    public function test_merge_repoints_server_histories(): void
    {
        $sha = str_repeat('d', 64);
        $shaRow = $this->recorder->record($this->map, $sha, null, 500);
        $crcRow = $this->recorder->record($this->map, null, 'beadfeed', 500);

        $server = \App\Models\Server::factory()->create();
        \App\Models\ServerHistory::create([
            'weekday' => 0, 'hour' => 0, 'continuous' => 0,
            'server_id' => $server->id, 'map_id' => $this->map->id,
            'map_version_id' => $crcRow->id, 'mod_id' => \App\Models\Mod::create(['name' => 'x'])->id,
        ]);

        $merged = $this->recorder->record($this->map, $sha, 'beadfeed', 500);

        $this->assertDatabaseHas('server_histories', ['map_version_id' => $merged->id]);
        $this->assertDatabaseMissing('server_histories', ['map_version_id' => $crcRow->id]);
    }
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `vendor/bin/phpunit --filter MapVersionRecorderTest`
Expected: FAIL — two rows remain (no merge yet); `map_versions` count is 2, not 1.

- [ ] **Step 3: Add the merge to the recorder**

In `record()`, replace the `$version = $bySha ?? $byCrc;` line with:

```php
        // Both keys resolve to two different rows → this sighting proves they are the same file; merge.
        if ($bySha !== null && $byCrc !== null && $bySha->isNot($byCrc)) {
            $version = $this->merge($bySha, $byCrc);
        } else {
            $version = $bySha ?? $byCrc;
        }
```

Then add the private method:

```php
    /**
     * Fold $loser into $winner: keep the earliest first_seen / latest last_seen, fill any missing
     * keys, re-point server_histories, and delete the loser. Returns the surviving row.
     *
     * Order matters: the (map_id, sha256) and (map_id, crc) unique indexes mean the loser's key must
     * leave the table BEFORE the winner adopts it — re-point histories and delete the loser first,
     * then update the winner — otherwise the key briefly sits on two rows and the insert/update fails.
     */
    private function merge(MapVersion $winner, MapVersion $loser): MapVersion
    {
        $firstSeen = $winner->getAttribute('first_seen_at')->lessThanOrEqualTo($loser->getAttribute('first_seen_at'))
            ? $winner->getAttribute('first_seen_at')
            : $loser->getAttribute('first_seen_at');
        $lastSeen = $winner->getAttribute('last_seen_at')->greaterThanOrEqualTo($loser->getAttribute('last_seen_at'))
            ? $winner->getAttribute('last_seen_at')
            : $loser->getAttribute('last_seen_at');
        $sha256 = $winner->getAttribute('sha256') ?? $loser->getAttribute('sha256');
        $crc = $winner->getAttribute('crc') ?? $loser->getAttribute('crc');
        $size = $winner->getAttribute('size') ?? $loser->getAttribute('size');

        \App\Models\ServerHistory::where('map_version_id', $loser->getAttribute('id'))
            ->update(['map_version_id' => $winner->getAttribute('id')]);
        $loser->delete();

        $winner->update([
            'first_seen_at' => $firstSeen,
            'last_seen_at' => $lastSeen,
            'sha256' => $sha256,
            'crc' => $crc,
            'size' => $size,
        ]);

        return $winner;
    }
```

- [ ] **Step 4: Run to verify all recorder tests pass**

Run: `vendor/bin/phpunit --filter MapVersionRecorderTest`
Expected: PASS (all cases incl. the two merge tests).

- [ ] **Step 5: Commit**

```bash
git add app/Service/MapVersionRecorder.php tests/Unit/Service/MapVersionRecorderTest.php
git commit -m "feat(maps): merge duplicate map versions when a sighting carries both keys"
```

---

## Task 5: Wire the recorder into UpdateData

**Files:**
- Modify: `app/Console/Commands/UpdateData.php`
- Test: `tests/Feature/MapVersionScrapeTest.php`

- [ ] **Step 1: Write the failing integration test**

```php
<?php

namespace Tests\Feature;

use App\Console\Commands\UpdateData;
use App\Models\Map;
use App\Models\Server;
use App\Models\ServerHistory;
use App\Service\MapVersionRecorder;
use App\TwStats\Model\DiscoveredServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionScrapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_sighting_records_a_version_and_stamps_history_and_pointer(): void
    {
        $server = Server::factory()->create();
        $sighting = new DiscoveredServer(
            addresses: [],
            name: 'Test Server',
            map: 'FlipLipp30',
            gametype: 'Gores',
            version: '0.6',
            maxClients: 16,
            maxPlayers: 16,
            clients: [],
            location: null,
            flavor: 'vanilla_06',
            mapSha256: null,
            mapSize: 335351,
            mapCrc: '3421b577',
        );

        // exercise the scraper's server-map handler in isolation via a public test seam
        app(UpdateData::class)->recordServerMap($server, $sighting);

        $map = Map::where('name', 'FlipLipp30')->firstOrFail();
        $this->assertSame('3421b577', $map->crc, 'maps.* points at the current version');
        $this->assertSame(335351, $map->size);

        $version = $map->currentVersion;
        $this->assertSame('3421b577', $version->crc);

        $history = ServerHistory::where('server_id', $server->id)->firstOrFail();
        $this->assertSame($version->id, $history->map_version_id);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter MapVersionScrapeTest`
Expected: FAIL — `Call to undefined method App\Console\Commands\UpdateData::recordServerMap()`.

- [ ] **Step 3: Inject the recorder** — add to the `UpdateData::__construct` parameter list (after `SessionRecorder $sessionRecorder`):

```php
        private readonly MapVersionRecorder $mapVersionRecorder = new MapVersionRecorder(),
```

And add the import near the other `use App\Service\...` lines:

```php
use App\Service\MapVersionRecorder;
use App\Models\MapVersion;
```

- [ ] **Step 4: Replace `updateServerMapAndMod` + `updateMapIdentity`** with version-aware logic and a test seam.

Replace the existing `updateServerMapAndMod(...)` and `updateMapIdentity(...)` methods (currently `app/Console/Commands/UpdateData.php:83-109`) with:

```php
    /**
     * resolve the server's current map and mod, then bump its server history
     */
    private function updateServerMapAndMod(Server $serverModel, DiscoveredServer $server): void
    {
        [$mapModel, $version] = $this->recordServerMap($serverModel, $server);
        /** @var Mod $modModel */
        [$modModel, $originalModModel] = $this->retrieveOrCreateMod($serverModel, $server->gametype);

        $this->updateServerHistory($serverModel, $mapModel, $version, $modModel, $originalModModel);
    }

    /**
     * Resolve the map for a sighting and record its content version: upsert the map_versions row,
     * and repoint maps.sha256/crc/size at that (current) version so the default viewer/download and
     * hasDownloadableFile() track the latest file. Public seam for tests.
     *
     * @return array{0: Map, 1: ?MapVersion}
     */
    public function recordServerMap(Server $serverModel, DiscoveredServer $server): array
    {
        /** @var Map $mapModel */
        $mapModel = Map::firstOrCreate(['name' => $server->map]);
        $version = $this->mapVersionRecorder->record(
            $mapModel,
            $server->mapSha256,
            $server->mapCrc,
            $server->mapSize,
        );

        // maps.* is a denormalized pointer to the current version (not no-clobber): it mirrors one
        // version's full identity, never a mix of two. Keyless sightings leave it unchanged.
        if ($version !== null) {
            $mapModel->update([
                'sha256' => $version->getAttribute('sha256'),
                'crc' => $version->getAttribute('crc'),
                'size' => $version->getAttribute('size'),
            ]);
        }

        return [$mapModel, $version];
    }
```

(Note: the `recordServerMap` first argument is `Server` to match the call site, but the body only needs `$server`. Keep the `Server` parameter so the signature reads naturally at the call site and matches the test.)

- [ ] **Step 5: Stamp `map_version_id` on the created history.**

In `updateServerHistory`, change the signature to accept the version and add the column to the `ServerHistory::create([...])` array. Change the method declaration (`app/Console/Commands/UpdateData.php:119`) to:

```php
    private function updateServerHistory(Server $serverModel, Map $mapModel, ?MapVersion $version, Mod $modModel, ?Mod $originalModModel)
```

And inside the `ServerHistory::create([...])` array, add (alongside `'map_id' => ...`):

```php
                    'map_version_id' => $version?->getAttribute('id'),
```

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit --filter MapVersionScrapeTest`
Expected: PASS.

- [ ] **Step 7: Run the existing scraper suite for regressions**

Run: `vendor/bin/phpunit --filter UpdateDataTest`
Expected: PASS (history creation still works; `map_version_id` is null for keyless fixtures, set where sightings carry identity).

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/UpdateData.php tests/Feature/MapVersionScrapeTest.php
git commit -m "feat(maps): record map version per server sighting + stamp server_histories"
```

---

## Task 6: One-shot backfill command for legacy maps

**Files:**
- Create: `app/Console/Commands/BackfillMapVersions.php`
- Test: `tests/Feature/BackfillMapVersionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Server;
use App\Models\ServerHistory;
use App\Models\Mod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillMapVersionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_one_version_per_identitied_map_and_links_histories(): void
    {
        $withId = Map::create(['name' => 'FlipLipp30', 'crc' => '3421b577', 'size' => 335351]);
        $keyless = Map::create(['name' => 'mysterymap']);
        $server = Server::factory()->create();
        $mod = Mod::create(['name' => 'Gores']);
        $history = ServerHistory::create([
            'weekday' => 0, 'hour' => 0, 'continuous' => 0,
            'server_id' => $server->id, 'map_id' => $withId->id, 'mod_id' => $mod->id,
        ]);

        $this->artisan('maps:backfill-versions')->assertSuccessful();

        $this->assertDatabaseCount('map_versions', 1); // only the identitied map
        $version = $withId->fresh()->currentVersion;
        $this->assertSame('3421b577', $version->crc);
        $this->assertSame($version->id, $history->fresh()->map_version_id);
        $this->assertNull($keyless->fresh()->currentVersion);

        // idempotent: a second run creates nothing new
        $this->artisan('maps:backfill-versions')->assertSuccessful();
        $this->assertDatabaseCount('map_versions', 1);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter BackfillMapVersionsTest`
Expected: FAIL — command `maps:backfill-versions` not found.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\ServerHistory;
use Illuminate\Console\Command;

/**
 * One-shot, idempotent backfill: turn each legacy map's single identity into a map_versions row and
 * link that map's server_histories to it. Run once after the map_versions migration ships; safe to
 * re-run (skips maps that already have a version).
 */
class BackfillMapVersions extends Command
{
    protected $signature = 'maps:backfill-versions';

    protected $description = 'Create a map_versions row from each legacy map identity and link histories';

    public function handle(): int
    {
        $count = 0;

        Map::query()
            ->where(fn ($q) => $q->whereNotNull('sha256')->orWhereNotNull('crc'))
            ->whereDoesntHave('versions')
            ->chunkById(500, function ($maps) use (&$count) {
                foreach ($maps as $map) {
                    $version = MapVersion::create([
                        'map_id' => $map->getAttribute('id'),
                        'sha256' => $map->getAttribute('sha256'),
                        'crc' => $map->getAttribute('crc'),
                        'size' => $map->getAttribute('size'),
                        'first_seen_at' => $map->getAttribute('updated_at'),
                        'last_seen_at' => $map->getAttribute('updated_at'),
                    ]);

                    ServerHistory::where('map_id', $map->getAttribute('id'))
                        ->whereNull('map_version_id')
                        ->update(['map_version_id' => $version->getAttribute('id')]);

                    $count++;
                }
            });

        $this->info("Backfilled {$count} map version(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter BackfillMapVersionsTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS (no regressions).

- [ ] **Step 6: Run the backfill against the dev DB**

Run: `php artisan maps:backfill-versions`
Expected: `Backfilled N map version(s).`

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/BackfillMapVersions.php tests/Feature/BackfillMapVersionsTest.php
git commit -m "feat(maps): one-shot idempotent backfill of legacy map versions"
```

---

## Phase 1 done — verification

Run: `vendor/bin/phpunit` → all green.
Manually: `php artisan migrate` + `php artisan maps:backfill-versions`, then confirm in tinker that a multi-version map (e.g. `FlipLipp30`) now has multiple `map_versions` rows after a scrape, and `server_histories.map_version_id` is populated for new sightings.

---

## Roadmap: Phases 2-4 (each its own plan, written when reached)

These depend on the Phase 1 models/columns above and will be detailed into their own plans once Phase 1 lands (so signatures match reality rather than guesses).

**Phase 2 — Acquisition rework.** Generalize `MapFileStore::ensure()` to take a `MapVersion` (cache already keyed by name+sha/crc, so per-version is natural); `backfillIdentity` writes onto the version and calls `MapVersionRecorder` to merge. Rewrite `GameServerMapSource` to (a) download from a *given* host (for inline grab) and (b) walk a version's recent hosts for the on-demand fallback. Add bounded inline acquisition to `UpdateData` after stats are written (config caps: `MAP_ACQUIRE_MAX_PER_RUN`, `MAP_ACQUIRE_TIME_BUDGET_S`; UDP-only, uncached, cooldown via `map_versions.last_acquire_attempt_at`). **Retire** `ArchiveMap`, `MapArchiver`, `EnqueueMapArchiveJobs`, the `maps:archive-enqueue` + `queue:work --queue=maps` scheduler entries; drop `maps.last_archive_attempt_at`; repurpose `MapArchiveStatus` → a "map availability" view-model for the `/maps` badge.

**Phase 3 — Per-version routes + download/view.** Routes `GET /map/{name}/file/{version?}` and `/download/{version?}`; `MapFileController` resolves a `MapVersion` by `sha256 = key OR crc = key` (scoped to the map), default = current; ensures via the generalized store with the on-demand HTTP→multi-host-UDP fallback; graceful 404 already handled by the viewer.

**Phase 4 — UI.** A "Versions" panel on `resources/views/detail/map.blade.php` (only when ≥2 versions): hash, size, last-seen, server count (from `server_histories` grouped by `map_version_id`), View + Download per version; "View" re-inits the WebGL viewer against `/map/{name}/file/{key}`.
```
