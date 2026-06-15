# Map Version Tracking — Phase 2 (Acquisition Rework) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Acquire map files where they actually live — grab UDP-only (vanilla) maps **inline during the scrape** from the host serving them, fetch HTTP (DDNet) maps **on demand**, make the store **version-aware**, and **retire the racy deferred archive queue**.

**Architecture:** `MapFileStore` keys/serves files per `MapVersion`. A new bounded acquisition step in `UpdateData` (after stats are written) downloads newly-seen uncached UDP-only versions from the exact host just seen serving them. `GameServerMapSource` gains a download-from-a-given-host mode (inline) and a version-targeted multi-host mode (on-demand fallback). The `ArchiveMap`/`MapArchiver`/`maps:archive-enqueue` queue machinery is deleted; the `/maps` badge is repurposed to a cache/availability view-model.

**Tech Stack:** Laravel 13, PHP 8.3+, the existing `App\TwStats` UDP stack, PHPUnit (SQLite `:memory:`), `Http::fake`, `FakeUdpTransport`.

**Spec:** `docs/superpowers/specs/2026-06-15-map-version-tracking-design.md` §3, §4(partial), §6. **Builds on Phase 1** (`map_versions`, `MapVersion`, `Map::currentVersion()`, `ServerHistory.map_version_id`, `MapVersionRecorder`).

**Design decisions locked for this phase:**
- A version is **HTTP-acquirable** iff it has a `sha256` (DDNet mirror). Otherwise it is **UDP-only**.
- Inline acquisition runs **after** the scrape's stat writes, is **best-effort**, and is bounded by `MAP_ACQUIRE_MAX_PER_RUN` (default 25) and `MAP_ACQUIRE_TIME_BUDGET_S` (default 60). Per-version cooldown via `map_versions.last_acquire_attempt_at` (`MAP_ACQUIRE_RETRY_HOURS`, default 6).
- HTTP versions are **not** grabbed inline — fetched on demand (Phase 3) from `maps.ddnet.org`.
- The `/maps` badge becomes: **Cached** (file on disk) / **Downloadable** (has a version or recorded host) / **Unavailable** (neither).

---

## Unit 2a — `MapFileStore` becomes version-aware

**Files:**
- Modify: `app/Service/MapFileStore.php`
- Modify: `app/Service/MapArchiver.php` (interim: keep it compiling until Unit 2d deletes it — its `download()` will call the new `ensureVersion`)
- Test: `tests/Feature/MapFileStoreVersionTest.php`

**What changes:** Add `ensureVersion(MapVersion $version): string` — the version-keyed acquire. `relativePathFor`/`isCached` gain `MapVersion` overloads via a shared private `pathForIdentity(string $name, ?string $sha256, ?string $crc)`. After a successful download, identity is backfilled onto the **version** (via `MapVersionRecorder::record`, which also merges) instead of onto the `Map`. The existing `ensure(Map)` delegates to `ensureVersion($map->currentVersion)` when a current version exists, else falls back to today's map-identity path (so the still-Phase-1 `MapFileController` keeps working until Phase 3).

- [ ] **Step 1 — failing test** `tests/Feature/MapFileStoreVersionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\MapFileStore;
use App\TwStats\Discovery\DdnetHttpMapSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapFileStoreVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_ensure_version_caches_an_http_map_under_the_version_key(): void
    {
        // a real-ish DDNet map: 36-byte minimum datafile header with DATA magic
        $bytes = 'DATA' . str_repeat("\x00", 64);
        $sha = hash('sha256', $bytes);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'size' => strlen($bytes), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        Http::fake(['maps.ddnet.org/*' => Http::response($bytes, 200)]);
        $store = new MapFileStore(sources: [new DdnetHttpMapSource()]);

        $relative = $store->ensureVersion($version);

        $this->assertStringContainsString($sha, $relative);
        $this->assertTrue(Storage::disk('local')->exists($relative));
        $this->assertSame($bytes, Storage::disk('local')->get($relative));
        $this->assertTrue($store->isCachedVersion($version));
    }

    public function test_ensure_version_returns_the_cached_file_without_refetching(): void
    {
        $bytes = 'DATA' . str_repeat("\x01", 64);
        $sha = hash('sha256', $bytes);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'size' => strlen($bytes), 'first_seen_at' => now(), 'last_seen_at' => now()]);

        Http::fake(['maps.ddnet.org/*' => Http::response($bytes, 200)]);
        $store = new MapFileStore(sources: [new DdnetHttpMapSource()]);
        $store->ensureVersion($version);

        Http::fake(['maps.ddnet.org/*' => Http::response('', 500)]); // would fail if refetched
        $this->assertNotEmpty($store->ensureVersion($version));
    }
}
```

- [ ] **Step 2 — run** `vendor/bin/phpunit --filter MapFileStoreVersionTest` → FAIL (`ensureVersion` undefined).

- [ ] **Step 3 — implement.** In `app/Service/MapFileStore.php`:
  - Add `use App\Models\MapVersion;` and `use App\Service\MapVersionRecorder;`.
  - Add a constructor param `private readonly MapVersionRecorder $versionRecorder = new MapVersionRecorder()` (after the existing params).
  - Add:

```php
    /**
     * Acquire a specific version's .map file, keyed by its content identity. Mirrors ensure(Map) but
     * for one MapVersion: cache hit returns immediately; otherwise the sources are tried (HTTP when the
     * version has a sha256, then UDP), the bytes are verified by the source, cached under the version's
     * key, and both derived keys are backfilled onto the version (merging duplicates) via the recorder.
     *
     * @throws MapFileUnavailable when no source can produce verified bytes
     */
    public function ensureVersion(MapVersion $version): string
    {
        $storage = Storage::disk($this->disk);

        $relative = $this->relativePathForVersion($version);
        if ($relative !== null && $storage->exists($relative)) {
            return $relative;
        }

        foreach ($this->sources as $source) {
            $bytes = $source->fetchVersion($version);
            if ($bytes === null) {
                continue;
            }

            // derive the true identity from the verified bytes and backfill onto the version (no-clobber
            // + merge of any duplicate row) so the cache key and the version row agree going forward.
            $this->versionRecorder->record(
                $version->map,
                hash('sha256', $bytes),
                sprintf('%08x', crc32($bytes) & 0xFFFFFFFF),
                strlen($bytes),
            );
            $version->refresh();

            $relative = $this->relativePathForVersion($version);
            if ($relative === null) {
                continue;
            }
            $storage->put($relative, $bytes);

            return $relative;
        }

        throw new MapFileUnavailable();
    }

    /** Path (relative to the disk) for a version's .map, or null when it has no usable content key. */
    public function relativePathForVersion(MapVersion $version): ?string
    {
        return $this->pathForIdentity(
            (string) $version->map->getAttribute('name'),
            $version->getAttribute('sha256'),
            $version->getAttribute('crc'),
        );
    }

    public function isCachedVersion(MapVersion $version): bool
    {
        $relative = $this->relativePathForVersion($version);

        return $relative !== null && Storage::disk($this->disk)->exists($relative);
    }
```

  - Refactor the existing `relativePathFor(Map $map)` to delegate to a new private `pathForIdentity(string $name, ?string $sha256, ?string $crc): ?string` that holds the current key+slug logic (`$key = $sha256 ?? $crc; if null or !ctype_xdigit → null; 'maps/'.slug.'_'.$key.'.map'`). `relativePathFor(Map)` calls `pathForIdentity($map->name, $map->sha256, $map->crc)`.

  This unit also requires each `MapSource` to expose `fetchVersion(MapVersion): ?string`. Do that in Step 4.

- [ ] **Step 4 — add `fetchVersion` to the MapSource interface + implementations.**
  - `app/Service/MapSource.php` (interface): add `public function fetchVersion(MapVersion $version): ?string;`
  - `DdnetHttpMapSource`: implement `fetchVersion` = today's `fetch` logic but reading `$version->sha256`/`$version->size`/`$version->map->name` (return null when sha256 is null). Keep the existing `fetch(Map)` delegating to a shared private by-identity method, OR have `fetch(Map)` build from `$map` and `fetchVersion` from `$version` — both call a private `download(string $name, ?string $sha256, ?int $size): ?string`.
  - `GameServerMapSource`: implement `fetchVersion` — for now delegate to a method that resolves hosts from `server_histories` where `map_version_id = $version->id` (Unit 2b fully implements multi-host; for 2a a minimal "most recent host for this version" is enough, mirroring today's single-host logic but filtered by `map_version_id`). Verify against `$version->sha256`/`$version->crc`.

- [ ] **Step 5 — keep `MapArchiver` compiling.** Change `MapArchiver::download(Map $map)` to call `$this->store->ensureVersion($map->currentVersion)` when `currentVersion` exists, else `$this->store->ensure($map)`. (MapArchiver is deleted in 2d; this just keeps the suite green between units.)

- [ ] **Step 6 — run** `vendor/bin/phpunit --filter 'MapFileStoreVersionTest|MapFileAcquisitionTest'` → PASS. Then full suite green.

- [ ] **Step 7 — commit** (stage the touched `app/Service/*` + the new test):
```bash
git add app/Service/MapFileStore.php app/Service/MapSource.php app/Service/DdnetHttpMapSource.php app/Service/GameServerMapSource.php app/Service/MapArchiver.php tests/Feature/MapFileStoreVersionTest.php
git commit -m "feat(maps): version-aware MapFileStore::ensureVersion + per-version cache key"
```

---

## Unit 2b — `GameServerMapSource`: given-host + version-targeted multi-host

**Files:**
- Modify: `app/Service/GameServerMapSource.php`
- Test: `tests/Unit/Service/GameServerMapSourceVersionTest.php` (uses the injected connection factory + a scripted `FakeConnection`, mirroring the existing GameServerMapSource tests)

**What changes:**
- `downloadFromHost(string $ip, int $port, string $flavor, ?string $sha256, ?int $crcSigned, int $timeoutMs): ?string` — the connect+download+verify+name/identity-gate logic, extracted from today's `doFetch`, parameterized by an explicit host. Used by inline acquisition (Unit 2c) with the exact seen host.
- `fetchVersion(MapVersion): ?string` — gather candidate hosts from `ServerHistory::where('map_version_id', $version->id)->orderByDesc('updated_at')` (distinct host+protocol via the server's addresses), and try each via `downloadFromHost` until one returns verified bytes; stop at the first success. Bounded by a per-call host cap (`MAP_UDP_MAX_HOSTS`, default 5).
- Keep `fetch(Map)` working (delegates to a host-list derived from the map's `serverRecords`, as today) for any residual caller.

Tests: a scripted multi-host scenario — first host's `FakeConnection` yields a name/identity mismatch (→ skipped), second yields the matching map (→ returned); and a given-host download returns verified bytes. Reuse the connection-factory injection pattern already in `GameServerMapSource`/its tests.

(Full task steps + code authored at dispatch time against the Unit 2a result.)

---

## Unit 2c — Bounded inline acquisition in `UpdateData`

**Files:**
- Create: `app/Service/InlineMapAcquirer.php` — collects `(version, host)` candidates and runs the bounded best-effort download pass.
- Modify: `app/Console/Commands/UpdateData.php` — collect candidates during the server loop; run the acquirer after `closeStale()`.
- Modify: `app/Models/MapVersion.php` — a `scopeNeedsAcquisition` / helper, or keep the eligibility logic in the acquirer.
- Test: `tests/Feature/InlineMapAcquisitionTest.php`.

**Behavior:** during the loop, when a sighting's resolved version is **UDP-only** (`sha256 === null`), **not cached** (`MapFileStore::isCachedVersion`), and **not in cooldown** (`last_acquire_attempt_at` older than `MAP_ACQUIRE_RETRY_HOURS`), record a candidate `(version, ip, port, flavor)`. After stats, iterate candidates up to `MAP_ACQUIRE_MAX_PER_RUN` / `MAP_ACQUIRE_TIME_BUDGET_S`: stamp `last_acquire_attempt_at = now()`, call `GameServerMapSource::downloadFromHost(...)` for the exact host, and on verified bytes cache via `MapFileStore` (writing under the version key) + backfill identity. Best-effort: failures are swallowed (logged via the command output), skipped candidates retried next run. HTTP-acquirable versions are never collected.

Test: a scripted sighting of a UDP-only uncached version → after `recordServerSighting` + the acquire pass (with a scripted FakeConnection returning matching bytes), the version's file is cached; assert the per-run cap and cooldown are honored; assert an HTTP (sha256) version is NOT acquired inline.

(Full task steps + code authored at dispatch time.)

---

## Unit 2d — Retire the deferred queue; repurpose the badge

**Files:**
- Delete: `app/Jobs/ArchiveMap.php`, `app/Service/MapArchiver.php`, `app/Console/Commands/EnqueueMapArchiveJobs.php`.
- Modify: `bootstrap/app.php` — remove the `maps:archive-enqueue` and `queue:work --queue=maps` schedule entries.
- Create: `app/Service/MapAvailability.php` (enum or small service) replacing `MapArchiveStatus`/`MapArchiver::statusFor` — **Cached** (`MapFileStore::isCachedVersion` on currentVersion, or any cached version) / **Downloadable** (`hasDownloadableFile()`) / **Unavailable**. Delete `MapArchiveStatus` (or rename + repoint).
- Modify: `app/Http/Controllers/MainController.php::maps` — compute availability without `MapArchiver`.
- Modify: `resources/views/list/maps.blade.php` — badge uses the new view-model (label + class).
- Migration: `database/migrations/2026_06_15_120000_drop_last_archive_attempt_at_from_maps.php` — drop `maps.last_archive_attempt_at` (its role moved to `map_versions.last_acquire_attempt_at`). Also remove the now-unused `Map::scopeNeedsArchiving` + `Map::hasRecordedHost` usages that referenced it (keep `hasRecordedHost`; only drop `scopeNeedsArchiving` if nothing else uses it).
- Delete the archiving tests that target the removed classes; add/adjust a `/maps` badge test.

**Verification:** full suite green; `php artisan schedule:list` shows the maps queue entries gone; `/maps` renders the new badge.

(Full task steps + code authored at dispatch time, after 2a–2c land.)

---

## Phase 2 done — verification

- Full suite green.
- Manually: run `php artisan data:update` (dev) and confirm a freshly-seen UDP-only map gets a cached file under `storage/app/private/maps/<name>_<crc>.map`; confirm no `maps` queue jobs are scheduled; `/maps` badge shows Cached/Downloadable/Unavailable.

## Notes / sequencing

Units are sequential (2b depends on 2a's `fetchVersion` seam; 2c depends on 2b's `downloadFromHost`; 2d removes the scaffolding 2a kept alive). Each unit ends green and committed. Units 2b–2d get their full bite-sized task steps authored at dispatch time against the realized signatures from the prior unit (same approach Phase 1 used for later phases).
