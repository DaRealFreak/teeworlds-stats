# Map Download & Viewer — Phase A (HTTP acquisition + download) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture each DDNet map's `sha256`/`size` during the scrape, lazily download the
real `.map` from `maps.ddnet.org`, cache it, and add a **Download .map** button to
`/map/{name}` — the acquisition foundation the later viewer phases consume.

**Architecture:** Map file acquisition sits behind a one-method `App\Service\MapSource`
interface. Phase A ships `DdnetHttpMapSource` (HTTP, needs a sha256); `App\Service\MapFileStore`
caches results on the `local` disk and is the single entry point a thin `MapFileController`
uses. The scraper's `DiscoveredServer` gains nullable map-identity fields; `UpdateData`
coalesces them onto the `maps` row without ever nulling a known value. The `crc` column is
added now but populated only in Phase E (UDP).

**Tech Stack:** PHP 8.3 / Laravel 13, `Illuminate\Support\Facades\Http` + `Storage`, PHPUnit
with `RefreshDatabase` + `Http::fake()` / `Storage::fake()`. SQLite `:memory:` in tests.

---

## File structure

| File | Responsibility |
| --- | --- |
| `database/migrations/2026_06_12_181000_add_map_identity_to_maps_table.php` (create) | add `sha256`, `size`, `crc` to `maps` |
| `app/Models/Map.php` (modify) | `hasDownloadableFile()` + docblock props |
| `app/TwStats/Model/DiscoveredServer.php` (modify) | nullable `mapSha256` / `mapSize` / `mapCrc` |
| `app/TwStats/Discovery/DdnetServerListParser.php` (modify) | read `map.sha256` / `map.size` |
| `app/TwStats/Discovery/ServerMerger.php` (modify) | coalesce map-identity fields in `combine()` |
| `app/Console/Commands/UpdateData.php` (modify) | `updateMapIdentity()` no-clobber upsert |
| `app/Service/MapSource.php` (create) | `fetch(Map): ?string` interface |
| `app/Service/DdnetHttpMapSource.php` (create) | HTTP fetch from `maps.ddnet.org` + verify |
| `app/Service/MapFileUnavailable.php` (create) | typed "no source could produce bytes" |
| `app/Service/MapFileStore.php` (create) | cache + source-chain; `ensure(Map): string` |
| `app/Http/Controllers/MapFileController.php` (create) | `file` (inline) + `download` (attachment) |
| `routes/web.php` (modify) | `/map/{map_name}/file` + `/map/{map_name}/download` |
| `resources/views/detail/map.blade.php` (modify) | Download button / "not available" note |
| `tests/Fixtures/ddnet_servers.json` (modify) | add `sha256`/`size` to the first map |
| `tests/Unit/Discovery/DdnetServerListParserTest.php` (modify) | assert captured sha256/size |
| `tests/Feature/MapFileAcquisitionTest.php` (create) | source + store + route + view |
| `tests/Feature/UpdateDataTest.php` (modify) | scrape captures + no-clobber |

**Convention reminders:** migrations use `return new class extends Migration`. `Map` is
`$guarded = ['id','created_at','updated_at']`, so `sha256`/`size`/`crc` are mass-assignable.
The `local` disk root is `storage_path('app/private')`. `/map` is **not** response-cached
(only `/serverbrowser` is), so no `responsecache:clear`. No SCSS/JS changes in Phase A → no
`npm run build`.

---

## Task 1: Schema + `Map::hasDownloadableFile()`

**Files:**
- Create: `database/migrations/2026_06_12_181000_add_map_identity_to_maps_table.php`
- Modify: `app/Models/Map.php`
- Test: `tests/Feature/MapFileAcquisitionTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapFileAcquisitionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapFileAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_stores_identity_columns_and_reports_downloadable(): void
    {
        $sha = str_repeat('a', 64);
        $map = Map::create(['name' => 'Kobra 4', 'sha256' => $sha, 'size' => 12345]);

        $fresh = $map->fresh();
        $this->assertSame($sha, $fresh->sha256);
        $this->assertSame(12345, $fresh->size);
        $this->assertTrue($fresh->hasDownloadableFile());

        $bare = Map::create(['name' => 'no-hash']);
        $this->assertFalse($bare->fresh()->hasDownloadableFile());
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit --filter test_map_stores_identity_columns_and_reports_downloadable`
Expected: FAIL — unknown column `sha256` / undefined method `hasDownloadableFile`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_12_181000_add_map_identity_to_maps_table.php`:

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
            // sha256 + size identify a DDNet map for HTTP download from maps.ddnet.org;
            // crc identifies a 0.6/0.7 map for the UDP download path (populated in Phase E).
            $table->char('sha256', 64)->nullable()->after('name');
            $table->unsignedInteger('size')->nullable()->after('sha256');
            $table->string('crc', 8)->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn(['sha256', 'size', 'crc']);
        });
    }
};
```

- [ ] **Step 4: Add the helper + docblock to `Map`**

In `app/Models/Map.php`, extend the class docblock property list with:

```php
 * @property string|null $sha256
 * @property int|null $size
 * @property string|null $crc
```

and add this method inside the class (after the `use HasPlayStatistics;` / `$guarded` lines):

```php
    /**
     * Whether a downloadable .map file can be acquired for this map. Phase A: only DDNet maps
     * (which advertise a sha256) are HTTP-retrievable; Phase E broadens this to any map with a
     * known online host.
     */
    public function hasDownloadableFile(): bool
    {
        return $this->getAttribute('sha256') !== null;
    }
```

- [ ] **Step 5: Run the test and verify it passes**

Run: `vendor/bin/phpunit --filter test_map_stores_identity_columns_and_reports_downloadable`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_12_181000_add_map_identity_to_maps_table.php \
        app/Models/Map.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): add sha256/size/crc identity columns + hasDownloadableFile"
```

---

## Task 2: Carry map identity on `DiscoveredServer` + capture it from the DDNet feed

**Files:**
- Modify: `app/TwStats/Model/DiscoveredServer.php`
- Modify: `app/TwStats/Discovery/DdnetServerListParser.php`
- Modify: `app/TwStats/Discovery/ServerMerger.php:75-87` (the `combine()` return)
- Modify: `tests/Fixtures/ddnet_servers.json`
- Test: `tests/Unit/Discovery/DdnetServerListParserTest.php`

- [ ] **Step 1: Add the captured fields to the fixture**

In `tests/Fixtures/ddnet_servers.json`, the first server's map object (currently
`"map": {"name": "Multeasymap"},`) becomes:

```json
        "map": {"name": "Multeasymap", "sha256": "aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899", "size": 654321},
```

Leave the other entries' map objects (`ctf1`, `dm1`, `y`) without sha256/size — they exercise
the null path.

- [ ] **Step 2: Write the failing test**

Add to `tests/Unit/Discovery/DdnetServerListParserTest.php` (inside the class):

```php
    public function test_captures_ddnet_map_identity_and_leaves_it_null_when_absent(): void
    {
        $servers = (new DdnetServerListParser())->parse($this->fixture());

        $this->assertSame('aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899', $servers[0]->mapSha256);
        $this->assertSame(654321, $servers[0]->mapSize);
        $this->assertNull($servers[0]->mapCrc);

        // the vanilla 0.7 server carries no sha256/size in the feed
        $this->assertNull($servers[1]->mapSha256);
        $this->assertNull($servers[1]->mapSize);
    }
```

- [ ] **Step 3: Run it and verify it fails**

Run: `vendor/bin/phpunit --filter test_captures_ddnet_map_identity_and_leaves_it_null_when_absent`
Expected: FAIL — undefined property `mapSha256`.

- [ ] **Step 4: Add the optional fields to `DiscoveredServer`**

In `app/TwStats/Model/DiscoveredServer.php`, append three optional promoted params **after**
`public readonly string $flavor,` (optional-last keeps every existing named-arg call valid):

```php
        public readonly string $flavor,
        public readonly ?string $mapSha256 = null,
        public readonly ?int $mapSize = null,
        public readonly ?string $mapCrc = null,
```

Also extend the class docblock:

```php
 * @param ?string $mapSha256 DDNet map content hash (HTTP download identity); null for non-DDNet
 * @param ?int $mapSize expected .map byte size, for download validation
 * @param ?string $mapCrc 0.6/0.7 map crc (UDP download identity); populated in Phase E
```

- [ ] **Step 5: Capture sha256/size in `DdnetServerListParser`**

In `app/TwStats/Discovery/DdnetServerListParser.php`, inside `parseServer()`, just before the
`return new DiscoveredServer(...)`, read the optional sub-fields off the already-validated
`$info['map']` array:

```php
        $mapInfo = $info['map'];
        $mapSha256 = isset($mapInfo['sha256']) && is_string($mapInfo['sha256']) ? strtolower($mapInfo['sha256']) : null;
        $mapSize = isset($mapInfo['size']) && is_int($mapInfo['size']) ? $mapInfo['size'] : null;
```

and pass them in the constructor call (add after `flavor: ...,`):

```php
            flavor: FlavorClassifier::classify((string) $info['version']),
            mapSha256: $mapSha256,
            mapSize: $mapSize,
```

- [ ] **Step 6: Preserve the fields through `ServerMerger::combine()`**

In `app/TwStats/Discovery/ServerMerger.php`, in the `combine()` `return new DiscoveredServer(...)`,
add after `flavor: $primary->flavor,` (coalesce so a sixup server's DDNet sha256 and a UDP
crc both survive the merge):

```php
            flavor: $primary->flavor,
            mapSha256: $primary->mapSha256 ?? $secondary->mapSha256,
            mapSize: $primary->mapSize ?? $secondary->mapSize,
            mapCrc: $primary->mapCrc ?? $secondary->mapCrc,
```

- [ ] **Step 7: Run the parser test (and the merger suite) to verify green**

Run: `vendor/bin/phpunit tests/Unit/Discovery/DdnetServerListParserTest.php tests/Unit/Discovery/ServerMergerTest.php`
Expected: PASS (all methods, including the pre-existing ones).

- [ ] **Step 8: Commit**

```bash
git add app/TwStats/Model/DiscoveredServer.php app/TwStats/Discovery/DdnetServerListParser.php \
        app/TwStats/Discovery/ServerMerger.php tests/Fixtures/ddnet_servers.json \
        tests/Unit/Discovery/DdnetServerListParserTest.php
git commit -m "feat(scrape): capture DDNet map sha256/size onto DiscoveredServer"
```

---

## Task 3: Persist map identity in `UpdateData` (no-clobber)

**Files:**
- Modify: `app/Console/Commands/UpdateData.php` (`updateServerMapAndMod()`)
- Test: `tests/Feature/UpdateDataTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/UpdateDataTest.php` (`use App\Models\Map;` at the top):

```php
    public function test_it_stores_ddnet_map_identity_from_the_feed(): void
    {
        $this->bindEmptySevenSource();
        $this->bindEmptySixSource();
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseHas('maps', [
            'name' => 'Multeasymap',
            'sha256' => 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899',
            'size' => 654321,
        ]);
    }

    public function test_a_udp_sighting_does_not_wipe_a_known_map_sha256(): void
    {
        // 'dm1' already has a sha256 from an earlier DDNet sighting
        Map::create(['name' => 'dm1', 'sha256' => str_repeat('b', 64), 'size' => 999]);

        Http::fake(['master1.ddnet.org/*' => Http::response('{"servers":[]}', 200)]);
        $this->bindEmptySevenSource();
        // the 0.6 source reports a server on map 'dm1' carrying no sha256
        $this->bindSixSourceWithServer('Vanilla 0.6 DM', 'Sixplayer');

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseHas('maps', ['name' => 'dm1', 'sha256' => str_repeat('b', 64)]);
    }
```

- [ ] **Step 2: Run them and verify the first fails**

Run: `vendor/bin/phpunit --filter "map_identity_from_the_feed|does_not_wipe_a_known_map_sha256"`
Expected: the first test FAILS — `maps` row has null sha256 (no upsert wiring yet). The second
test already PASSES (today nothing writes sha256, so `firstOrCreate` leaves it intact); it is a
**regression guard** that must stay green once the upsert in Step 3 is added — it would fail
against a naive `update(['sha256' => $server->mapSha256, ...])` that nulls the value.

- [ ] **Step 3: Add the no-clobber upsert**

In `app/Console/Commands/UpdateData.php`, in `updateServerMapAndMod()`, immediately after
`$mapModel = Map::firstOrCreate(['name' => $server->map]);` add a call, then define the helper:

```php
        $mapModel = Map::firstOrCreate(['name' => $server->map]);
        $this->updateMapIdentity($mapModel, $server);
```

```php
    /**
     * Refresh a map's download identity from a sighting, but only with values the sighting
     * actually carries — a UDP/0.6 source that knows the crc but not the sha256 must never null
     * out a sha256 a DDNet sighting already recorded (mirrors the afk/skin no-clobber rule).
     */
    private function updateMapIdentity(Map $mapModel, DiscoveredServer $server): void
    {
        $updates = array_filter(
            ['sha256' => $server->mapSha256, 'size' => $server->mapSize, 'crc' => $server->mapCrc],
            static fn ($value) => $value !== null,
        );

        if ($updates !== []) {
            $mapModel->update($updates);
        }
    }
```

- [ ] **Step 4: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter "map_identity_from_the_feed|does_not_wipe_a_known_map_sha256"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/UpdateData.php tests/Feature/UpdateDataTest.php
git commit -m "feat(scrape): persist map sha256/size onto maps without clobbering"
```

---

## Task 4: `MapSource` interface + `DdnetHttpMapSource`

**Files:**
- Create: `app/Service/MapSource.php`
- Create: `app/Service/DdnetHttpMapSource.php`
- Test: `tests/Feature/MapFileAcquisitionTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/MapFileAcquisitionTest.php` (`use App\Service\DdnetHttpMapSource;`,
`use Illuminate\Support\Facades\Http;`):

```php
    public function test_http_source_downloads_and_verifies_a_ddnet_map(): void
    {
        $body = 'DATAFILEMAPBYTES';
        $sha = hash('sha256', $body);
        $map = Map::create(['name' => 'Kobra 4', 'sha256' => $sha, 'size' => strlen($body)]);

        Http::fake(['maps.ddnet.org/*' => Http::response($body, 200)]);

        $bytes = (new DdnetHttpMapSource())->fetch($map);

        $this->assertSame($body, $bytes);
        Http::assertSent(fn ($req) => $req->url() === 'https://maps.ddnet.org/Kobra%204_' . $sha . '.map');
    }

    public function test_http_source_returns_null_on_hash_or_size_mismatch_or_no_sha(): void
    {
        Http::fake(['maps.ddnet.org/*' => Http::response('WRONGBYTES', 200)]);

        $bad = Map::create(['name' => 'x', 'sha256' => str_repeat('c', 64), 'size' => 10]);
        $this->assertNull((new DdnetHttpMapSource())->fetch($bad)); // sha mismatch

        $noSha = Map::create(['name' => 'y']);
        $this->assertNull((new DdnetHttpMapSource())->fetch($noSha)); // not eligible
    }
```

- [ ] **Step 2: Run them and verify they fail**

Run: `vendor/bin/phpunit --filter "http_source_downloads|http_source_returns_null"`
Expected: FAIL — class `DdnetHttpMapSource` not found.

- [ ] **Step 3: Create the interface**

Create `app/Service/MapSource.php`:

```php
<?php

namespace App\Service;

use App\Models\Map;

/**
 * A strategy that tries to produce the raw bytes of a map's .map file. Implementations return
 * the verified bytes on success or null if they cannot serve this map, so MapFileStore can try
 * the next source. Phase A ships DdnetHttpMapSource; Phase E adds the UDP GameServerMapSource.
 */
interface MapSource
{
    public function fetch(Map $map): ?string;
}
```

- [ ] **Step 4: Create `DdnetHttpMapSource`**

Create `app/Service/DdnetHttpMapSource.php`:

```php
<?php

namespace App\Service;

use App\Models\Map;
use Illuminate\Support\Facades\Http;

/**
 * Downloads a DDNet map over HTTP from maps.ddnet.org. The URL mirrors the game client/server
 * (`%s_%s.map` of name + sha256, URL-escaped — see ddnet server.cpp:3006 / client.cpp:1266);
 * `_`, hex and `.` are RFC-3986 unreserved, so only the name needs escaping. Bytes are verified
 * against the advertised size and sha256 before being accepted; any failure returns null so the
 * store can fall through to another source.
 */
final class DdnetHttpMapSource implements MapSource
{
    public const BASE_URL = 'https://maps.ddnet.org';

    public function __construct(private readonly string $baseUrl = self::BASE_URL)
    {
    }

    public function fetch(Map $map): ?string
    {
        $sha256 = $map->getAttribute('sha256');
        if ($sha256 === null) {
            return null;
        }

        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($map->getAttribute('name')) . '_' . $sha256 . '.map';

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (!$response->successful()) {
            return null;
        }

        $body = $response->body();
        $size = $map->getAttribute('size');
        if ($size !== null && strlen($body) !== $size) {
            return null;
        }
        if (hash('sha256', $body) !== strtolower((string) $sha256)) {
            return null;
        }

        return $body;
    }
}
```

- [ ] **Step 5: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter "http_source_downloads|http_source_returns_null"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Service/MapSource.php app/Service/DdnetHttpMapSource.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): DdnetHttpMapSource — verified HTTP .map download"
```

---

## Task 5: `MapFileStore` (cache + source chain)

**Files:**
- Create: `app/Service/MapFileUnavailable.php`
- Create: `app/Service/MapFileStore.php`
- Test: `tests/Feature/MapFileAcquisitionTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/MapFileAcquisitionTest.php` (`use App\Service\MapFileStore;`,
`use App\Service\MapFileUnavailable;`, `use Illuminate\Support\Facades\Storage;`):

```php
    public function test_store_downloads_once_then_serves_from_cache(): void
    {
        Storage::fake('local');
        $body = 'CACHEDBYTES';
        $sha = hash('sha256', $body);
        $map = Map::create(['name' => 'Kobra 4', 'sha256' => $sha, 'size' => strlen($body)]);
        Http::fake(['maps.ddnet.org/*' => Http::response($body, 200)]);

        $store = new MapFileStore();

        $rel = $store->ensure($map);
        $this->assertSame('maps/' . $sha . '.map', $rel);
        Storage::disk('local')->assertExists($rel);
        $this->assertSame($body, Storage::disk('local')->get($rel));

        $store->ensure($map); // second call must hit cache, not the network
        Http::assertSentCount(1);
    }

    public function test_store_throws_when_no_source_can_serve(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'no-hash']); // no sha256 → no eligible source

        $this->expectException(MapFileUnavailable::class);
        (new MapFileStore())->ensure($map);
    }
```

- [ ] **Step 2: Run them and verify they fail**

Run: `vendor/bin/phpunit --filter "store_downloads_once|store_throws_when_no_source"`
Expected: FAIL — class `MapFileStore` not found.

- [ ] **Step 3: Create the exception**

Create `app/Service/MapFileUnavailable.php`:

```php
<?php

namespace App\Service;

/**
 * Thrown when no MapSource can produce verified bytes for a map (e.g. a non-DDNet map in Phase A,
 * or every source failed). Callers translate this into a 404 / "not available" UI state.
 */
final class MapFileUnavailable extends \RuntimeException
{
}
```

- [ ] **Step 4: Create the store**

Create `app/Service/MapFileStore.php`:

```php
<?php

namespace App\Service;

use App\Models\Map;
use Illuminate\Support\Facades\Storage;

/**
 * The single entry point for acquiring a map's .map file. Returns the path (relative to its
 * disk) of a cached file, downloading it via the first MapSource that can serve it. Files are
 * keyed by content identity (sha256, or crc for the future UDP path), so identical maps share a
 * cache entry and a re-download only happens after a cache miss.
 */
final class MapFileStore
{
    /**
     * @param MapSource[] $sources tried in order; first non-null wins
     */
    public function __construct(
        private readonly array $sources = [new DdnetHttpMapSource()],
        public readonly string $disk = 'local',
    ) {
    }

    /**
     * @return string path of the cached .map relative to $this->disk
     * @throws MapFileUnavailable when no source can produce verified bytes
     */
    public function ensure(Map $map): string
    {
        $key = $map->getAttribute('sha256') ?? $map->getAttribute('crc');
        if ($key === null) {
            throw new MapFileUnavailable();
        }

        $relative = 'maps/' . $key . '.map';
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
}
```

- [ ] **Step 5: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter "store_downloads_once|store_throws_when_no_source"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Service/MapFileUnavailable.php app/Service/MapFileStore.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): MapFileStore — cache + MapSource fallthrough"
```

---

## Task 6: Routes + `MapFileController`

**Files:**
- Create: `app/Http/Controllers/MapFileController.php`
- Modify: `routes/web.php` (next to the existing `/map/...` routes)
- Test: `tests/Feature/MapFileAcquisitionTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/MapFileAcquisitionTest.php`:

```php
    public function test_download_route_streams_attachment_and_caches(): void
    {
        Storage::fake('local');
        $body = 'ROUTEBYTES';
        $sha = hash('sha256', $body);
        Map::create(['name' => 'Kobra 4', 'sha256' => $sha, 'size' => strlen($body)]);
        Http::fake(['maps.ddnet.org/*' => Http::response($body, 200)]);

        $response = $this->get('/map/' . rawurlencode('Kobra 4') . '/download');

        $response->assertOk();
        $response->assertDownload('Kobra 4.map'); // Symfony quotes the spaced filename; match via the helper
        $this->assertSame($body, $response->streamedContent());

        $this->get('/map/' . rawurlencode('Kobra 4') . '/file')->assertOk(); // inline variant
        Http::assertSentCount(1); // both requests served one cached file
    }

    public function test_file_route_404s_for_unavailable_and_unknown_maps(): void
    {
        Storage::fake('local');
        Map::create(['name' => 'no-hash']); // exists but not downloadable

        $this->get('/map/no-hash/file')->assertNotFound();
        $this->get('/map/does-not-exist/file')->assertNotFound();
    }
```

- [ ] **Step 2: Run them and verify they fail**

Run: `vendor/bin/phpunit --filter "download_route_streams|file_route_404s"`
Expected: FAIL — 404 for all (routes/controller absent).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/MapFileController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Service\MapFileStore;
use App\Service\MapFileUnavailable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a map's .map bytes: `file` inline (what the in-browser viewer fetches) and `download`
 * as an attachment (the Download button). Both lazily acquire + cache via MapFileStore and 404
 * when the file cannot be produced. Not response-cached — binary and potentially large.
 */
class MapFileController extends Controller
{
    public function __construct(private readonly MapFileStore $store)
    {
    }

    public function file(string $map_name): StreamedResponse
    {
        return $this->serve($map_name, attachment: false);
    }

    public function download(string $map_name): StreamedResponse
    {
        return $this->serve($map_name, attachment: true);
    }

    private function serve(string $map_name, bool $attachment): StreamedResponse
    {
        /** @var Map $map */
        $map = Map::where('name', urldecode($map_name))->firstOrFail();

        try {
            $relative = $this->store->ensure($map);
        } catch (MapFileUnavailable) {
            abort(404, 'Map file not available');
        }

        $disk = Storage::disk($this->store->disk);
        $filename = $map->getAttribute('name') . '.map';
        $headers = ['Content-Type' => 'application/octet-stream'];

        return $attachment
            ? $disk->download($relative, $filename, $headers)
            : $disk->response($relative, $filename, $headers, 'inline');
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, directly after the existing
`Route::get('/map/{map_name}/', [SearchController::class, 'searchMapByName'])->name('searchMapByName');`
line, add (and `use App\Http\Controllers\MapFileController;` at the top with the other controller imports):

```php
// Map file acquisition: inline bytes for the viewer + an attachment download. Not cached.
Route::get('/map/{map_name}/file', [MapFileController::class, 'file'])->name('mapFile');
Route::get('/map/{map_name}/download', [MapFileController::class, 'download'])->name('mapDownload');
```

- [ ] **Step 5: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter "download_route_streams|file_route_404s"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MapFileController.php routes/web.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): /map/{name}/file + /download routes via MapFileStore"
```

---

## Task 7: Download button on the map detail page

**Files:**
- Modify: `resources/views/detail/map.blade.php`
- Test: `tests/Feature/MapFileAcquisitionTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/MapFileAcquisitionTest.php`:

```php
    public function test_map_page_shows_download_button_only_when_downloadable(): void
    {
        Map::create(['name' => 'Kobra 4', 'sha256' => str_repeat('a', 64), 'size' => 10]);
        Map::create(['name' => 'no-hash']);

        $this->get('/map/' . rawurlencode('Kobra 4') . '/')
            ->assertOk()
            ->assertSee('Download .map');

        $this->get('/map/no-hash/')
            ->assertOk()
            ->assertDontSee('Download .map')
            ->assertSee('Map file not available');
    }
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit --filter test_map_page_shows_download_button_only_when_downloadable`
Expected: FAIL — "Download .map" not present.

- [ ] **Step 3: Add the button to the view**

In `resources/views/detail/map.blade.php`, replace the `@section('content')` block with:

```blade
@section('content')
    <div class="block">
        @if($map->hasDownloadableFile())
            <a href="{{ route('mapDownload', ['map_name' => $map->name]) }}" class="btn btn-primary">
                <i class="fa fa-download"></i> Download .map
            </a>
        @else
            <p class="text-muted mb-0">Map file not available.</p>
        @endif
    </div>
    @include('partials.play-stats')
@endsection
```

(`fa fa-download` is Font Awesome 4.7, already loaded. `route('mapDownload', ...)` URL-encodes
the name; the controller `urldecode`s it. `/map` is not response-cached, so no cache clear.)

- [ ] **Step 4: Run the test and verify it passes**

Run: `vendor/bin/phpunit --filter test_map_page_shows_download_button_only_when_downloadable`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/detail/map.blade.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): Download .map button on the map detail page"
```

---

## Task 8: Full-suite verification

- [ ] **Step 1: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no new failures. (Confirms the `DiscoveredServer` signature change and the
fixture edit didn't break `ServerMergerTest`, `Teeworlds06SourceTest`, `Teeworlds07SourceTest`,
`UpdateDataTest`, `RouteSmokeTest`, or `MapModDetailPageTest`.)

- [ ] **Step 2: Sanity-migrate the dev DB**

Run: `php artisan migrate`
Expected: the `add_map_identity_to_maps_table` migration runs cleanly.

- [ ] **Step 3: Final commit (only if anything is uncommitted)**

```bash
git status --short
```

---

## Self-review notes (spec coverage)

- **Spec A1 (schema)** → Task 1. **A2 (scraper capture + no-clobber)** → Tasks 2–3 (DDNet
  sha256/size; `crc` column exists but 0.6/0.7 population is intentionally deferred to Phase E,
  matching the spec's "captured opportunistically / ready later" framing — the no-clobber rule
  is still proven in Task 3). **A3 (MapFileStore)** → Task 5. **A4 (DdnetHttpMapSource + URL +
  verify)** → Task 4. **A5 (routes/controller, not cached)** → Task 6. **A6 (Download button /
  unavailable note)** → Task 7.
- **Deferred to later phases (out of scope here):** the TS datafile parser (B), the WebGL
  viewer + mapres vendoring (C), Playwright render check (D), and the UDP `GameServerMapSource`
  + `app/TwStats/Connection/` stack that populates `crc` and broadens `hasDownloadableFile()` (E).
- **Type consistency:** `MapSource::fetch(Map): ?string`, `MapFileStore::ensure(Map): string`
  (returns disk-relative path), `MapFileStore->disk` (public readonly), `Map::hasDownloadableFile(): bool`,
  `DiscoveredServer->{mapSha256,mapSize,mapCrc}` — referenced identically across Tasks 2–7.
