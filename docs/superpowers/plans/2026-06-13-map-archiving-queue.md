# Map Archiving via a Queue — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Archive every map's `.map` file asynchronously on a database-backed queue (decoupled from the `data:update` scrape), name cache files `maps/<name>_<hash>.map`, and show each map's archive state as a badge on `/maps`.

**Architecture:** A scheduled `maps:archive-enqueue` command selects maps that have a content key but no cached file (cooldown-filtered, never-attempted first) and dispatches unique `ArchiveMap` jobs onto a `maps` queue. A scheduled `queue:work --stop-when-empty` burst drains them; each job stamps `last_archive_attempt_at` and calls the existing `MapFileStore::ensure`. A shared `MapArchiver` service is the single brain for "eligible / cooling down / archived", reused by the job, the command, and the badge.

**Tech Stack:** PHP 8.3, Laravel 13, database queue driver (MySQL `jobs`/`failed_jobs`), PHPUnit (SQLite `:memory:`), Blade + Bootstrap 5 badges.

**Spec:** `docs/superpowers/specs/2026-06-13-map-archiving-queue-design.md`

**Conventions:** Tests use `RefreshDatabase` + `Map::create([...])` (Map has no factory). `Storage::fake('local')` for disk; `Queue::fake()` for dispatch. Run tests with `vendor/bin/phpunit`. No SCSS/JS changes in this plan, so **no `npm run build` / lint** is required. `/maps` is not response-cached, so no `responsecache:clear` is needed.

---

## File Structure

**New files**
- `app/Service/MapArchiveStatus.php` — enum of the four badge states + label/badge-class.
- `app/Service/MapArchiver.php` — shared brain: `statusFor`, `cooldownThreshold`, `download`.
- `app/Jobs/ArchiveMap.php` — unique queued job; downloads one map.
- `app/Console/Commands/EnqueueMapArchiveJobs.php` — `maps:archive-enqueue` command.
- `app/Service/LegacyMapCacheRenamer.php` — one-off renamer of old hash-only cache files.
- `database/migrations/2026_06_13_100000_add_last_archive_attempt_at_to_maps_table.php`
- `database/migrations/<generated>_create_jobs_table.php` (via `php artisan queue:table`)
- `database/migrations/<generated>_create_failed_jobs_table.php` (via `php artisan queue:failed-table`)
- `database/migrations/2026_06_13_110000_rename_cached_map_files_with_name_prefix.php`
- `tests/Unit/Service/MapFileStorePathTest.php`
- `tests/Feature/MapArchiverTest.php`
- `tests/Feature/ArchiveMapJobTest.php`
- `tests/Feature/EnqueueMapArchiveJobsTest.php`
- `tests/Feature/MapArchiveScheduleTest.php`
- `tests/Feature/MapListBadgeTest.php`
- `tests/Feature/LegacyMapCacheRenamerTest.php`

**Modified files**
- `app/Service/MapFileStore.php` — `relativePathFor` (public) + `nameSlug` + `isCached`; `ensure` reuses them.
- `app/Models/Map.php` — `last_archive_attempt_at` datetime cast + `scopeNeedsArchiving`.
- `app/Http/Controllers/MainController.php` — `maps()` builds `$statuses`.
- `resources/views/list/maps.blade.php` — Archive column + badge.
- `bootstrap/app.php` — schedule enqueue + worker + prune-failed.
- `.env` — `QUEUE_CONNECTION=database`.
- `tests/Feature/MapFileAcquisitionTest.php` — update 4 path assertions to the new scheme.

---

## Task 1: Human-readable cache filenames in MapFileStore

**Files:**
- Modify: `app/Service/MapFileStore.php`
- Test: `tests/Unit/Service/MapFileStorePathTest.php` (create)
- Test: `tests/Feature/MapFileAcquisitionTest.php` (update existing assertions)

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Service/MapFileStorePathTest.php`:

```php
<?php

namespace Tests\Unit\Service;

use App\Models\Map;
use App\Service\MapFileStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapFileStorePathTest extends TestCase
{
    public function test_relative_path_uses_sanitized_name_prefix_and_hash(): void
    {
        $sha = str_repeat('a', 64);
        $map = (new Map())->forceFill(['name' => 'Kobra 4', 'sha256' => $sha]);

        $this->assertSame('maps/Kobra_4_' . $sha . '.map', (new MapFileStore())->relativePathFor($map));
    }

    public function test_relative_path_falls_back_to_crc_when_no_sha(): void
    {
        $map = (new Map())->forceFill(['name' => 'dm1', 'crc' => 'aabbccdd']);

        $this->assertSame('maps/dm1_aabbccdd.map', (new MapFileStore())->relativePathFor($map));
    }

    public function test_slug_strips_traversal_and_unsafe_characters(): void
    {
        $sha = str_repeat('b', 64);
        $map = (new Map())->forceFill(['name' => '../../etc/pa ss.wd', 'sha256' => $sha]);

        $path = (new MapFileStore())->relativePathFor($map);

        $this->assertSame('maps/etc_pa_ss_wd_' . $sha . '.map', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('/etc/', $path);
    }

    public function test_all_unsafe_name_falls_back_to_hash_only_filename(): void
    {
        $sha = str_repeat('c', 64);
        $map = (new Map())->forceFill(['name' => '日本語', 'sha256' => $sha]);

        $this->assertSame('maps/' . $sha . '.map', (new MapFileStore())->relativePathFor($map));
    }

    public function test_relative_path_is_null_for_missing_or_non_hex_key(): void
    {
        $store = new MapFileStore();

        $this->assertNull($store->relativePathFor((new Map())->forceFill(['name' => 'no-key'])));
        $this->assertNull($store->relativePathFor((new Map())->forceFill(['name' => 'evil', 'crc' => '../etc'])));
    }

    public function test_is_cached_reflects_disk_presence(): void
    {
        Storage::fake('local');
        $sha = str_repeat('d', 64);
        $map = (new Map())->forceFill(['name' => 'dm2', 'sha256' => $sha]);
        $store = new MapFileStore();

        $this->assertFalse($store->isCached($map));

        Storage::disk('local')->put('maps/dm2_' . $sha . '.map', 'BYTES');
        $this->assertTrue($store->isCached($map));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MapFileStorePathTest`
Expected: FAIL — `Error: Call to undefined method App\Service\MapFileStore::relativePathFor()`

- [ ] **Step 3: Implement the new path methods and refactor `ensure`**

In `app/Service/MapFileStore.php`, replace the entire `ensure()` method (currently lines 29–59) with the following four methods:

```php
    public function ensure(Map $map): string
    {
        $relative = $this->relativePathFor($map);
        if ($relative === null) {
            throw new MapFileUnavailable();
        }

        $storage = Storage::disk($this->disk);
        if ($storage->exists($relative)) {
            return $relative;
        }

        foreach ($this->sources as $source) {
            $bytes = $source->fetch($map);
            if ($bytes !== null) {
                $storage->put($relative, $bytes);

                return $relative;
            }
        }

        throw new MapFileUnavailable();
    }

    /**
     * Path (relative to $this->disk) where this map's .map file is or would be cached, or null when
     * the map has no usable content key. The filename is <sanitized-name>_<sha256|crc>.map: the hash
     * keeps uniqueness and is the path-traversal guard (it must be pure hex); the name is a
     * best-effort, filesystem-safe prefix so the archive is browsable on disk. Lookup never parses
     * the filename back — callers always hold the Map — so a lossy slug is harmless.
     */
    public function relativePathFor(Map $map): ?string
    {
        $key = $map->getAttribute('sha256') ?? $map->getAttribute('crc');
        if ($key === null || !ctype_xdigit($key)) {
            return null;
        }

        $slug = $this->nameSlug((string) $map->getAttribute('name'));
        $prefix = $slug === '' ? '' : $slug . '_';

        return 'maps/' . $prefix . $key . '.map';
    }

    /**
     * Whether this map's .map file is already cached on disk.
     */
    public function isCached(Map $map): bool
    {
        $relative = $this->relativePathFor($map);

        return $relative !== null && Storage::disk($this->disk)->exists($relative);
    }

    /**
     * Filesystem-safe, human-readable slug for a (server-controlled) map name. The whitelist keeps
     * out '/', '.' and whitespace, so the slug can never contain a path separator or '..'; the dash
     * sits last in the class so 0-9-_ is not read as a range. Capped so name + hash stay well under
     * the 255-byte filename limit.
     */
    private function nameSlug(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);

        return rtrim(substr(trim($slug, '_'), 0, 64), '_');
    }
```

- [ ] **Step 4: Run the new test to verify it passes**

Run: `vendor/bin/phpunit --filter MapFileStorePathTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Update the existing path assertions in `MapFileAcquisitionTest`**

In `tests/Feature/MapFileAcquisitionTest.php`, make these exact replacements (the map names in those tests are `Kobra 4`, `cached-map`, `dm1` → slugs `Kobra_4`, `cached-map`, `dm1`):

- Line ~81: `$this->assertSame('maps/' . $sha . '.map', $rel);`
  → `$this->assertSame('maps/Kobra_4_' . $sha . '.map', $rel);`
- Line ~120: `Storage::disk('local')->put('maps/' . $sha . '.map', 'PRELOADED');`
  → `Storage::disk('local')->put('maps/cached-map_' . $sha . '.map', 'PRELOADED');`
- Line ~125: `$this->assertSame('maps/' . $sha . '.map', $rel);`
  → `$this->assertSame('maps/cached-map_' . $sha . '.map', $rel);`
- Line ~290: `$this->assertSame('maps/' . $crcHex . '.map', $rel);`
  → `$this->assertSame('maps/dm1_' . $crcHex . '.map', $rel);`

(The `test_hex_guardrail_throws_on_non_hex_key` assertion `assertMissing('maps/../etc.map')` stays as-is — the non-hex key makes `relativePathFor` return null, so nothing is written.)

- [ ] **Step 6: Run the affected suites to verify they pass**

Run: `vendor/bin/phpunit --filter MapFileAcquisitionTest`
Expected: PASS (all tests green)

- [ ] **Step 7: Commit**

```bash
git add app/Service/MapFileStore.php tests/Unit/Service/MapFileStorePathTest.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): human-readable <name>_<hash>.map cache filenames"
```

---

## Task 2: Add `last_archive_attempt_at` to the maps table

**Files:**
- Create: `database/migrations/2026_06_13_100000_add_last_archive_attempt_at_to_maps_table.php`

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
        Schema::table('maps', function (Blueprint $table) {
            // When archiving last attempted to fetch this map's .map file. Drives the re-enqueue
            // cooldown and the Queued-vs-Retrying badge; the cached file on disk (not this column)
            // is the source of truth for "is it archived".
            $table->timestamp('last_archive_attempt_at')->nullable()->after('crc');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn('last_archive_attempt_at');
        });
    }
};
```

- [ ] **Step 2: Run the migration against the dev DB**

Run: `php artisan migrate`
Expected: `DONE` for `..._add_last_archive_attempt_at_to_maps_table`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_13_100000_add_last_archive_attempt_at_to_maps_table.php
git commit -m "feat(maps): add last_archive_attempt_at column"
```

---

## Task 3: MapArchiveStatus enum

**Files:**
- Create: `app/Service/MapArchiveStatus.php`
- Test: `tests/Feature/MapArchiverTest.php` (create with the enum test; extended in Task 5)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapArchiverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Service\MapArchiveStatus;
use Tests\TestCase;

class MapArchiverTest extends TestCase
{
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
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MapArchiverTest`
Expected: FAIL — `Error: Class "App\Service\MapArchiveStatus" not found`

- [ ] **Step 3: Implement the enum**

Create `app/Service/MapArchiveStatus.php`:

```php
<?php

namespace App\Service;

/**
 * A map's archive state, surfaced as a badge on the map list. Downloaded means the .map file is on
 * disk; Queued/Retrying/Unavailable describe an uncached map (eligible now, cooling down after a
 * failed attempt, or having no content key to download at all).
 */
enum MapArchiveStatus: string
{
    case Downloaded = 'downloaded';
    case Queued = 'queued';
    case Retrying = 'retrying';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Downloaded => 'Downloaded',
            self::Queued => 'Queued',
            self::Retrying => 'Retrying',
            self::Unavailable => 'Unavailable',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Downloaded => 'bg-success',
            self::Queued => 'bg-info',
            self::Retrying => 'bg-warning',
            self::Unavailable => 'bg-secondary',
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter MapArchiverTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Service/MapArchiveStatus.php tests/Feature/MapArchiverTest.php
git commit -m "feat(maps): MapArchiveStatus enum"
```

---

## Task 4: Map cast + `scopeNeedsArchiving`

**Files:**
- Modify: `app/Models/Map.php`
- Test: `tests/Feature/MapArchiverTest.php` (add scope test)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/MapArchiverTest.php` — add `use App\Models\Map;` and `use Illuminate\Foundation\Testing\RefreshDatabase;` at the top, add `use RefreshDatabase;` inside the class, and add this method:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter test_needs_archiving_selects_keyed_uncooled_maps_never_attempted_first`
Expected: FAIL — `Call to undefined method ...::needsArchiving()` (or a BadMethodCallException for the scope)

- [ ] **Step 3: Implement the cast and the scope**

In `app/Models/Map.php`, add the cast method and the scope inside the `Map` class (after the `$guarded` property is fine). `Builder` (line 8) and `Carbon` (line 6) are already imported.

```php
    protected function casts(): array
    {
        return [
            'last_archive_attempt_at' => 'datetime',
        ];
    }

    /**
     * Maps that have a content key (sha256 or crc) but may still need a .map file fetched, ordered so
     * never-attempted maps come first and the rest by oldest attempt. The cooldown threshold excludes
     * maps attempted too recently (a failed download is parked for the cooldown window). This is a
     * SQL-only filter — it cannot see the filesystem, so callers skip already-cached maps separately.
     */
    public function scopeNeedsArchiving(Builder $query, Carbon $cooldownThreshold): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNotNull('sha256')->orWhereNotNull('crc'))
            ->where(fn (Builder $q) => $q->whereNull('last_archive_attempt_at')
                ->orWhere('last_archive_attempt_at', '<', $cooldownThreshold))
            ->orderByRaw('last_archive_attempt_at IS NOT NULL, last_archive_attempt_at');
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter MapArchiverTest`
Expected: PASS (enum + scope tests)

- [ ] **Step 5: Commit**

```bash
git add app/Models/Map.php tests/Feature/MapArchiverTest.php
git commit -m "feat(maps): needsArchiving scope + last_archive_attempt_at cast"
```

---

## Task 5: MapArchiver service (statusFor + download)

**Files:**
- Create: `app/Service/MapArchiver.php`
- Test: `tests/Feature/MapArchiverTest.php` (add statusFor + download tests)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/MapArchiverTest.php` — add these imports at the top:

```php
use App\Service\MapArchiver;
use App\Service\MapFileStore;
use App\Service\MapSource;
use Illuminate\Support\Facades\Storage;
```

and add these methods:

```php
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
        };
        $store = new MapFileStore([$source]);
        $archiver = new MapArchiver($store);

        $archiver->download($map);

        $this->assertTrue($store->isCached($map->fresh()));
        $fresh = $map->fresh();
        $this->assertNotNull($fresh->last_archive_attempt_at);
        $this->assertSame($past->toDateTimeString(), $fresh->updated_at->toDateTimeString());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter MapArchiverTest`
Expected: FAIL — `Error: Class "App\Service\MapArchiver" not found`

- [ ] **Step 3: Implement the service**

Create `app/Service/MapArchiver.php`:

```php
<?php

namespace App\Service;

use App\Models\Map;
use Carbon\Carbon;

/**
 * Single brain for map archiving: decides a map's archive status (for the /maps badge) and performs
 * one map's download (for the queue job). The filesystem (MapFileStore::isCached) is the source of
 * truth for "is it archived"; last_archive_attempt_at only drives retry/backoff and the
 * Queued-vs-Retrying distinction, so the badge and the enqueuer always agree on what is eligible.
 */
final class MapArchiver
{
    public function __construct(
        private readonly MapFileStore $store = new MapFileStore(),
    ) {
    }

    public function statusFor(Map $map): MapArchiveStatus
    {
        if ($this->store->isCached($map)) {
            return MapArchiveStatus::Downloaded;
        }
        if (!$map->hasDownloadableFile()) {
            return MapArchiveStatus::Unavailable;
        }

        return $this->isCoolingDown($map) ? MapArchiveStatus::Retrying : MapArchiveStatus::Queued;
    }

    /**
     * Instant before which a prior attempt no longer blocks a re-attempt. A map attempted at or after
     * this instant is cooling down (Retrying); one attempted before it (or never) is eligible (Queued).
     */
    public function cooldownThreshold(): Carbon
    {
        return now()->subHours($this->retryHours());
    }

    /**
     * Stamp the attempt, then download. Throws MapFileUnavailable when no source can serve the map,
     * which lets the queue job retry and ultimately fail — the stamp keeps the map parked for the
     * cooldown window either way.
     */
    public function download(Map $map): void
    {
        $this->markAttempted($map);
        $this->store->ensure($map);
    }

    private function markAttempted(Map $map): void
    {
        // toBase() drops to the query builder, which does not auto-touch updated_at — map "recency"
        // semantics stay intact while we record the attempt.
        Map::whereKey($map->getKey())->toBase()->update(['last_archive_attempt_at' => now()]);
    }

    private function isCoolingDown(Map $map): bool
    {
        $attemptedAt = $map->getAttribute('last_archive_attempt_at');

        return $attemptedAt !== null && $attemptedAt >= $this->cooldownThreshold();
    }

    private function retryHours(): int
    {
        return (int) env('MAP_ARCHIVE_RETRY_HOURS', 24);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter MapArchiverTest`
Expected: PASS (enum, scope, statusFor, download)

- [ ] **Step 5: Commit**

```bash
git add app/Service/MapArchiver.php tests/Feature/MapArchiverTest.php
git commit -m "feat(maps): MapArchiver service (statusFor + download)"
```

---

## Task 6: ArchiveMap job

**Files:**
- Create: `app/Jobs/ArchiveMap.php`
- Test: `tests/Feature/ArchiveMapJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ArchiveMapJobTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ArchiveMap;
use App\Models\Map;
use App\Service\MapArchiver;
use App\Service\MapFileStore;
use App\Service\MapFileUnavailable;
use App\Service\MapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveMapJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_id_is_the_map_id(): void
    {
        $map = Map::create(['name' => 'x', 'sha256' => str_repeat('a', 64)]);

        $this->assertSame((string) $map->id, (new ArchiveMap($map))->uniqueId());
    }

    public function test_handle_downloads_the_map(): void
    {
        Storage::fake('local');
        $body = 'JOBBYTES';
        $sha = hash('sha256', $body);
        $map = Map::create(['name' => 'job map', 'sha256' => $sha, 'size' => strlen($body)]);

        $source = new class($body) implements MapSource {
            public function __construct(private string $body) {}
            public function fetch(Map $map): ?string { return $this->body; }
        };
        $store = new MapFileStore([$source]);

        (new ArchiveMap($map))->handle(new MapArchiver($store));

        $this->assertTrue($store->isCached($map->fresh()));
        $this->assertNotNull($map->fresh()->last_archive_attempt_at);
    }

    public function test_handle_throws_and_parks_map_when_no_source_serves(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ghost', 'sha256' => str_repeat('a', 64)]);
        $archiver = new MapArchiver(new MapFileStore([]));

        try {
            (new ArchiveMap($map))->handle($archiver);
            $this->fail('expected MapFileUnavailable');
        } catch (MapFileUnavailable) {
            // expected
        }

        $this->assertNotNull($map->fresh()->last_archive_attempt_at);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ArchiveMapJobTest`
Expected: FAIL — `Error: Class "App\Jobs\ArchiveMap" not found`

- [ ] **Step 3: Implement the job**

Create `app/Jobs/ArchiveMap.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Map;
use App\Service\MapArchiver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Archives one map's .map file off the request/scrape path. Unique per map id so the enqueue command
 * (which runs every few minutes) never stacks duplicate jobs while one is pending; once it finishes,
 * the map's last_archive_attempt_at cooldown governs the next attempt. A map with no usable source
 * exhausts $tries and lands in failed_jobs, then waits out the cooldown.
 */
final class ArchiveMap implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    // Safety net: release the uniqueness lock after an hour even if a worker dies mid-job, so the map
    // can be re-enqueued. Comfortably longer than the backoff sequence below.
    public int $uniqueFor = 3600;

    public function __construct(public readonly Map $map)
    {
        $this->onQueue('maps');
    }

    public function uniqueId(): string
    {
        return (string) $this->map->getKey();
    }

    /**
     * @return int[] seconds to wait between retries (transient HTTP/UDP failures)
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(MapArchiver $archiver): void
    {
        $archiver->download($this->map);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ArchiveMapJobTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ArchiveMap.php tests/Feature/ArchiveMapJobTest.php
git commit -m "feat(maps): ArchiveMap queued job"
```

---

## Task 7: maps:archive-enqueue command

**Files:**
- Create: `app/Console/Commands/EnqueueMapArchiveJobs.php`
- Test: `tests/Feature/EnqueueMapArchiveJobsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/EnqueueMapArchiveJobsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ArchiveMap;
use App\Models\Map;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnqueueMapArchiveJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_enqueues_only_eligible_uncached_maps(): void
    {
        Storage::fake('local');
        Queue::fake();

        $queued = Map::create(['name' => 'queued-map', 'sha256' => str_repeat('a', 64)]); // eligible
        Map::create(['name' => 'no-key']); // excluded: no content key
        Map::create(['name' => 'cooling', 'sha256' => str_repeat('b', 64), 'last_archive_attempt_at' => now()]); // excluded: cooldown

        $cachedSha = str_repeat('c', 64);
        Map::create(['name' => 'cached', 'sha256' => $cachedSha]); // excluded: already on disk
        Storage::disk('local')->put('maps/cached_' . $cachedSha . '.map', 'BYTES');

        $this->artisan('maps:archive-enqueue')->assertSuccessful();

        Queue::assertPushed(ArchiveMap::class, 1);
        Queue::assertPushed(ArchiveMap::class, fn (ArchiveMap $job) => $job->map->is($queued));
    }

    public function test_limit_caps_dispatch_count(): void
    {
        Storage::fake('local');
        Queue::fake();

        Map::create(['name' => 'm1', 'sha256' => str_repeat('a', 64)]);
        Map::create(['name' => 'm2', 'sha256' => str_repeat('b', 64)]);
        Map::create(['name' => 'm3', 'sha256' => str_repeat('c', 64)]);

        $this->artisan('maps:archive-enqueue', ['--limit' => '1'])->assertSuccessful();

        Queue::assertPushed(ArchiveMap::class, 1);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter EnqueueMapArchiveJobsTest`
Expected: FAIL — command `maps:archive-enqueue` is not defined (`CommandNotFoundException`)

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/EnqueueMapArchiveJobs.php` (Laravel auto-discovers commands in this directory — no manual registration needed):

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter EnqueueMapArchiveJobsTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/EnqueueMapArchiveJobs.php tests/Feature/EnqueueMapArchiveJobsTest.php
git commit -m "feat(maps): maps:archive-enqueue command"
```

---

## Task 8: Queue infrastructure + scheduling

**Files:**
- Create: `database/migrations/<generated>_create_jobs_table.php`
- Create: `database/migrations/<generated>_create_failed_jobs_table.php`
- Modify: `.env`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/MapArchiveScheduleTest.php`

- [ ] **Step 1: Generate the jobs + failed_jobs migrations**

Run:
```bash
php artisan queue:table
php artisan queue:failed-table
```
Expected: two migration files created under `database/migrations/` (`..._create_jobs_table.php`, `..._create_failed_jobs_table.php`). Do not generate the batches table — this feature uses no batching.

- [ ] **Step 2: Switch the queue connection to database**

In **both** `.env` and `.env.example` (each has `QUEUE_CONNECTION=sync` — `.env.example` at line 24), change:
```
QUEUE_CONNECTION=sync
```
to:
```
QUEUE_CONNECTION=database
```

- [ ] **Step 3: Run the migrations**

Run: `php artisan migrate`
Expected: `DONE` for the jobs and failed_jobs migrations.

- [ ] **Step 4: Write the failing schedule test**

Create `tests/Feature/MapArchiveScheduleTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class MapArchiveScheduleTest extends TestCase
{
    public function test_archive_enqueue_worker_and_prune_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->filter()
            ->implode("\n");

        $this->assertStringContainsString('maps:archive-enqueue', $commands);
        $this->assertStringContainsString('queue:work --queue=maps', $commands);
        $this->assertStringContainsString('queue:prune-failed', $commands);
    }
}
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MapArchiveScheduleTest`
Expected: FAIL — assertions don't find the command strings.

- [ ] **Step 6: Add the scheduled commands**

In `bootstrap/app.php`, inside the `->withSchedule(function (Schedule $schedule): void { ... })` closure, after the existing `$schedule->command(UpdateData::class)...->after(...)` block, add:

```php
        $schedule->command('maps:archive-enqueue')->everyTenMinutes()->withoutOverlapping();

        $schedule->command('queue:work --queue=maps --stop-when-empty --max-time=240')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->before(fn () => \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('logs/maps_worker')))
            ->sendOutputTo(storage_path('logs/maps_worker/'.now()->timestamp.'.log'));

        $schedule->command('queue:prune-failed --hours=168')->weekly();
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter MapArchiveScheduleTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/*_create_jobs_table.php database/migrations/*_create_failed_jobs_table.php .env.example bootstrap/app.php tests/Feature/MapArchiveScheduleTest.php
# NOTE: .env is gitignored — the QUEUE_CONNECTION change there is local/deploy config, not committed.
git commit -m "feat(maps): database queue + scheduled enqueue/worker/prune"
```

---

## Task 9: Map-list archive badge

**Files:**
- Modify: `app/Http/Controllers/MainController.php`
- Modify: `resources/views/list/maps.blade.php`
- Test: `tests/Feature/MapListBadgeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapListBadgeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapListBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_list_shows_a_badge_for_each_archive_state(): void
    {
        Storage::fake('local');

        $downloadedSha = str_repeat('a', 64);
        Map::create(['name' => 'downloaded-map', 'sha256' => $downloadedSha]);
        Storage::disk('local')->put('maps/downloaded-map_' . $downloadedSha . '.map', 'BYTES');

        Map::create(['name' => 'queued-map', 'sha256' => str_repeat('b', 64)]);
        Map::create(['name' => 'retrying-map', 'sha256' => str_repeat('c', 64), 'last_archive_attempt_at' => now()]);
        Map::create(['name' => 'unavailable-map']);

        $this->get('/maps')
            ->assertOk()
            ->assertSee('Downloaded')
            ->assertSee('Queued')
            ->assertSee('Retrying')
            ->assertSee('Unavailable');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MapListBadgeTest`
Expected: FAIL — none of the badge labels are on the page yet.

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/MainController.php`, add the import near the other `use App\...` lines:

```php
use App\Service\MapArchiver;
```

Replace the existing `maps()` method:

```php
    public function maps()
    {
        return view('list.maps')
            ->with('maps', Map::orderBy('name')->paginate(50));
    }
```

with:

```php
    public function maps(MapArchiver $archiver)
    {
        $maps = Map::orderBy('name')->paginate(50);
        $statuses = $maps->getCollection()->mapWithKeys(
            fn (Map $map) => [$map->id => $archiver->statusFor($map)]
        );

        return view('list.maps', compact('maps', 'statuses'));
    }
```

- [ ] **Step 4: Add the badge column to the view**

In `resources/views/list/maps.blade.php`:

Add a header cell — change:
```blade
                                    <th>Total time played(players)</th>
                                </tr>
```
to:
```blade
                                    <th>Total time played(players)</th>
                                    <th>Archive</th>
                                </tr>
```

Add the badge cell — change the end of the row from:
```blade
                                        <td>
                                            @if($hourEntry = $mapEntry->totalHoursOnline()->first())
                                                {{ \App\Utility\ChartUtility::humanizeDuration($hourEntry->sum_minutes) }}
                                            @endif
                                        </td>
                                    </tr>
```
to:
```blade
                                        <td>
                                            @if($hourEntry = $mapEntry->totalHoursOnline()->first())
                                                {{ \App\Utility\ChartUtility::humanizeDuration($hourEntry->sum_minutes) }}
                                            @endif
                                        </td>
                                        <td>
                                            @php($status = $statuses[$mapEntry->id])
                                            <span class="badge {{ $status->badgeClass() }}">{{ $status->label() }}</span>
                                        </td>
                                    </tr>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter MapListBadgeTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MainController.php resources/views/list/maps.blade.php tests/Feature/MapListBadgeTest.php
git commit -m "feat(maps): archive-status badge on the map list"
```

---

## Task 10: Rename existing hash-only cache files

**Files:**
- Create: `app/Service/LegacyMapCacheRenamer.php`
- Create: `database/migrations/2026_06_13_110000_rename_cached_map_files_with_name_prefix.php`
- Test: `tests/Feature/LegacyMapCacheRenamerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LegacyMapCacheRenamerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Service\LegacyMapCacheRenamer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyMapCacheRenamerTest extends TestCase
{
    use RefreshDatabase;

    public function test_renames_legacy_files_skips_orphans_and_is_idempotent(): void
    {
        Storage::fake('local');

        $sha = str_repeat('a', 64);
        Map::create(['name' => 'Kobra 4', 'sha256' => $sha]);
        Storage::disk('local')->put('maps/' . $sha . '.map', 'LEGACY');

        $orphan = str_repeat('e', 64); // no matching Map row
        Storage::disk('local')->put('maps/' . $orphan . '.map', 'ORPHAN');

        $renamed = (new LegacyMapCacheRenamer())->run();

        $this->assertSame(1, $renamed);
        Storage::disk('local')->assertExists('maps/Kobra_4_' . $sha . '.map');
        Storage::disk('local')->assertMissing('maps/' . $sha . '.map');
        Storage::disk('local')->assertExists('maps/' . $orphan . '.map');

        $this->assertSame(0, (new LegacyMapCacheRenamer())->run());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter LegacyMapCacheRenamerTest`
Expected: FAIL — `Error: Class "App\Service\LegacyMapCacheRenamer" not found`

- [ ] **Step 3: Implement the renamer**

Create `app/Service/LegacyMapCacheRenamer.php`:

```php
<?php

namespace App\Service;

use App\Models\Map;
use Illuminate\Support\Facades\Storage;

/**
 * One-off transitional helper: renames cache files written under the old hash-only scheme
 * (maps/<sha256|crc>.map) to the current human-readable scheme (maps/<name>_<hash>.map). Driven by a
 * migration so each environment converts its own cached files exactly once. Idempotent; files with
 * no matching Map row (orphans) are left untouched.
 */
final class LegacyMapCacheRenamer
{
    public function __construct(
        private readonly MapFileStore $store = new MapFileStore(),
        private readonly string $disk = 'local',
    ) {
    }

    /**
     * @return int number of files renamed
     */
    public function run(): int
    {
        $storage = Storage::disk($this->disk);
        $renamed = 0;

        foreach ($storage->files('maps') as $path) {
            // legacy files are exactly maps/<hex>.map — the new scheme always has a name_ prefix
            // (which contains '_'), so it never matches this pattern.
            if (!preg_match('#^maps/([0-9a-fA-F]+)\.map$#', $path, $m)) {
                continue;
            }
            $key = $m[1];

            /** @var Map|null $map */
            $map = Map::where('sha256', $key)->orWhere('crc', $key)->first();
            if ($map === null) {
                continue; // orphan: no record to derive a name from
            }

            $target = $this->store->relativePathFor($map);
            if ($target === null || $target === $path || $storage->exists($target)) {
                continue;
            }

            $storage->move($path, $target);
            $renamed++;
        }

        return $renamed;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter LegacyMapCacheRenamerTest`
Expected: PASS

- [ ] **Step 5: Write the migration that calls it**

Create `database/migrations/2026_06_13_110000_rename_cached_map_files_with_name_prefix.php`:

```php
<?php

use App\Service\LegacyMapCacheRenamer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Rename existing hash-only cache files to the new <name>_<hash>.map scheme so the archive is
        // browsable on disk. Runs once per environment; a no-op when there are no legacy files (e.g.
        // a fresh test database, where no Map rows match the on-disk keys).
        app(LegacyMapCacheRenamer::class)->run();
    }

    public function down(): void
    {
        // The cache is regenerable; renaming back has no value.
    }
};
```

- [ ] **Step 6: Run the migration against the dev DB**

Run: `php artisan migrate`
Expected: `DONE` for `..._rename_cached_map_files_with_name_prefix`. (On the dev box this renames the existing `storage/app/private/maps/<hash>.map` files whose key matches a `maps` row.)

- [ ] **Step 7: Commit**

```bash
git add app/Service/LegacyMapCacheRenamer.php database/migrations/2026_06_13_110000_rename_cached_map_files_with_name_prefix.php tests/Feature/LegacyMapCacheRenamerTest.php
git commit -m "feat(maps): rename legacy hash-only cache files to <name>_<hash>.map"
```

---

## Task 11: Full-suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the entire test suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all tests green (new tests plus the updated `MapFileAcquisitionTest`).

- [ ] **Step 2: Smoke-test the enqueue command against the dev DB**

Run: `php artisan maps:archive-enqueue --limit=1`
Expected: prints `map archive enqueue: <n> dispatched, <m> already cached` and exits 0. (With `QUEUE_CONNECTION=database`, a row appears in the `jobs` table when something was dispatched.)

- [ ] **Step 3: Smoke-test a worker burst**

Run: `php artisan queue:work --queue=maps --stop-when-empty --max-time=60`
Expected: the dispatched `ArchiveMap` job runs and exits; on success a `maps/<name>_<hash>.map` file appears under `storage/app/private/maps/`. (If the job fails for a given map, it backs off / lands in `failed_jobs` — both are acceptable, the map is parked by its cooldown.)

- [ ] **Step 4: Final commit (if any working-tree changes remain)**

```bash
git status
# commit anything outstanding, e.g. a regenerated lockfile — otherwise nothing to do
```

---

## Notes for the implementer

- **Map has no factory** — always `Map::create(['name' => ...])`. `Map`/`Mod` are the exception to the factory rule.
- **`env()` in tests:** the command reads `MAP_ARCHIVE_ENQUEUE_LIMIT` via `env()`, but the limit test overrides it with the `--limit` option instead of touching the environment, so it stays deterministic. `MAP_ARCHIVE_RETRY_HOURS` defaults to 24; the cooldown tests use timestamps relative to 24h, so they don't override it.
- **No asset build:** the only view change uses stock Bootstrap `badge bg-*` classes, so there is no SCSS/JS to compile and no `responsecache:clear` (the `/maps` route is not response-cached).
- **The rename migration runs during the test suite** (against the real `local` disk, before any `Storage::fake`), but the fresh test `maps` table has no rows matching the on-disk keys, so it renames nothing. The renamer's behaviour is covered directly by `LegacyMapCacheRenamerTest`.
