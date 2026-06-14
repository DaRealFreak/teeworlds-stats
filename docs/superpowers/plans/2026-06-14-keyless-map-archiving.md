# Keyless Map Archiving Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Archive the ~561 maps shown **Unavailable** on `/maps` (no `sha256`/`crc` recorded) by downloading them from a live host over UDP — 0.6 first, native 0.7 (sixup) second — and backfilling the content key from the verified bytes.

**Architecture:** `MapDownloadSession` already downloads with no pre-known key (it reads crc/size/sha256 from the server's `MAP_CHANGE`). We surface that observed identity to callers, fetch keyless maps from their most-recent recorded host, gate the result (server-reported name must match + bytes must be a real map datafile), and derive+backfill the key in `MapFileStore`. A new 0.7 session handles native sixup servers. The proactive archive queue and `data:update` scrape are unchanged in shape.

**Tech Stack:** PHP 8.3 / Laravel 13, PHPUnit (SQLite `:memory:`, `RefreshDatabase`, `Storage::fake`), the existing `App\TwStats\Connection` UDP stack (`GameConnection`, `ConnectionInterface`, `MapDownloadSession`, `Unpacker`, `VariableInt`).

**Phasing:** Tasks 1–5 are **Phase 1 (0.6, the ~560-map bulk)** and are independently mergeable. Tasks 6–9 are **Phase 2 (0.7, the 14-map tail)**. Task 10 finishes the branch.

**Reference facts (verified against `teeworlds/src/engine/server/server.cpp` + `src/game/version.h`):**
- 0.7 `GAME_NETVERSION` = `"0.7 802f1be60a05665f"`.
- 0.7 NETMSG ids: `INFO=1, MAP_CHANGE=2, MAP_DATA=3, READY=18, REQUEST_MAP_DATA=23`; framing `(msgId<<1)|1` (system bit set), same as 0.6.
- 0.7 `MAP_CHANGE` fields: `string name, int crc, int size, int chunksPerRequest, int chunkSize, raw sha256[32]`.
- 0.7 `MAP_DATA`: **raw bytes only** (no last/crc/chunk/size). Completion = bytes received ≥ size. Re-send `REQUEST_MAP_DATA` (no chunk arg) after each window.
- Teeworlds map datafile signature: first 4 bytes are `"DATA"` or `"ATAD"`, min length 36 (matches `resources/assets/js/map/datafile.ts:21-25`).
- crc column form: `sprintf('%08x', crc32($bytes) & 0xFFFFFFFF)`.

---

## Task 1: `MapDownloadResult` value object

**Files:**
- Create: `app/TwStats/Connection/MapDownloadResult.php`
- Test: `tests/Feature/Connection/MapDownloadResultTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\MapDownloadResult;
use PHPUnit\Framework\TestCase;

class MapDownloadResultTest extends TestCase
{
    public function testExposesBytesAndObservedIdentity(): void
    {
        $result = new MapDownloadResult(
            bytes: 'DATA....',
            name: 'ctf5',
            crc: -825302392,
            size: 8,
            sha256: str_repeat('a', 64),
        );

        self::assertSame('DATA....', $result->bytes);
        self::assertSame('ctf5', $result->name);
        self::assertSame(-825302392, $result->crc);
        self::assertSame(8, $result->size);
        self::assertSame(str_repeat('a', 64), $result->sha256);
    }

    public function testSha256IsNullableForVanilla06(): void
    {
        $result = new MapDownloadResult('DATA....', 'dm1', 1, 8, null);

        self::assertNull($result->sha256);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MapDownloadResultTest`
Expected: FAIL — `Class "App\TwStats\Connection\MapDownloadResult" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * The outcome of a successful map download: the verified bytes plus the identity the server
 * announced in MAP_CHANGE. The name lets a caller confirm the host is still serving the map it was
 * asked for; sha256 is null for vanilla 0.6 servers, which announce only a crc.
 */
final class MapDownloadResult
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $name,
        public readonly int $crc,
        public readonly int $size,
        public readonly ?string $sha256,
    ) {
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter MapDownloadResultTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Connection/MapDownloadResult.php tests/Feature/Connection/MapDownloadResultTest.php
git commit -m "feat(maps): add MapDownloadResult value object"
```

---

## Task 2: 0.6 session reports the observed identity

`MapDownloadSession::download()` currently returns only the bytes; it already parses the map name
in `parseMapChange()` but discards it. Add `downloadWithIdentity()` returning a `MapDownloadResult`
and keep `download()` as a thin bytes-only wrapper so the 11 existing byte-exact regression tests
stay untouched.

**Files:**
- Modify: `app/TwStats/Connection/MapDownloadSession.php`
- Test: `tests/Feature/Connection/MapDownloadSessionTest.php` (add one test; do not change existing ones)

- [ ] **Step 1: Write the failing test**

Add this method inside `class MapDownloadSessionTest` (it reuses the existing `FakeConnection`, `buildMapChange`, `buildMapData`, `makePayload` helpers in that file):

```php
    public function testDownloadWithIdentityReturnsObservedFields(): void
    {
        $payload = $this->makePayload(16);
        $crc     = crc32($payload);

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('ctf5', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $result = (new MapDownloadSession($conn))->downloadWithIdentity(timeoutMs: 5000);

        self::assertSame($payload, $result->bytes);
        self::assertSame('ctf5', $result->name);
        self::assertSame($this->toInt32($crc), $result->crc);
        self::assertSame(strlen($payload), $result->size);
        self::assertNull($result->sha256, 'vanilla 0.6 MAP_CHANGE announces no sha256');
    }
```

Add the import near the top of the test file (after the existing `use` lines):

```php
use App\TwStats\Connection\MapDownloadResult;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testDownloadWithIdentityReturnsObservedFields`
Expected: FAIL — `Call to undefined method ...::downloadWithIdentity()`.

- [ ] **Step 3: Implement — rename the body to `downloadWithIdentity`, capture the name, return a result, add a wrapper**

In `app/TwStats/Connection/MapDownloadSession.php`:

(a) Change the method signature from:

```php
    public function download(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
    ): string {
```

to:

```php
    public function downloadWithIdentity(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
    ): MapDownloadResult {
```

(b) Capture the map name. Change:

```php
                    [, $mapCrc, $mapSize, $mapSha256] = $parsed;
```

to:

```php
                    [$mapName, $mapCrc, $mapSize, $mapSha256] = $parsed;
```

(c) Initialise `$mapName` alongside the other accumulators (just below `$mapSha256 = null;`):

```php
        $mapSha256     = null;
        $mapName       = '';
```

(d) Change the success return inside the loop from:

```php
                    $this->verifyIntegrity($payload, $mapSize, $mapCrc, $mapSha256, $expectedSha256, $expectedCrc);
                    $this->conn->disconnect();

                    return $payload;
```

to:

```php
                    $this->verifyIntegrity($payload, $mapSize, $mapCrc, $mapSha256, $expectedSha256, $expectedCrc);
                    $this->conn->disconnect();

                    return new MapDownloadResult($payload, $mapName, $mapCrc, $mapSize, $mapSha256);
```

(e) Add a thin wrapper so existing callers/tests that want only the bytes are unchanged. Insert directly **above** `downloadWithIdentity`:

```php
    /**
     * Download and return only the raw map bytes. Thin wrapper over downloadWithIdentity() for
     * callers that do not need the observed identity (preserves the original return contract).
     */
    public function download(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
    ): string {
        return $this->downloadWithIdentity($expectedSha256, $expectedCrc, $timeoutMs)->bytes;
    }
```

(f) Add the import at the top of the file (after the existing `use` statements):

```php
use App\TwStats\Connection\MapDownloadResult;
```

(Same namespace, so the `use` is optional, but keep it explicit for clarity.)

- [ ] **Step 4: Run the whole session suite to verify nothing regressed**

Run: `vendor/bin/phpunit tests/Feature/Connection/MapDownloadSessionTest.php`
Expected: PASS — all existing tests plus `testDownloadWithIdentityReturnsObservedFields` (12 total).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Connection/MapDownloadSession.php tests/Feature/Connection/MapDownloadSessionTest.php
git commit -m "feat(maps): 0.6 session exposes observed map identity via downloadWithIdentity()"
```

---

## Task 3: `GameServerMapSource` fetches keyless maps, gated by name + datafile sanity

Drop the "no key → bail" guard, call `downloadWithIdentity`, and reject a result whose server-reported
name does not match the target map (host swapped maps) or whose bytes are not a real map datafile
(placeholder/troll content — the live spike pulled a 266-byte impostor).

**Files:**
- Modify: `app/Service/GameServerMapSource.php`
- Test: `tests/Feature/MapFileAcquisitionTest.php` (update one existing test's payload; add three tests)

- [ ] **Step 1: Write the failing tests**

First, the existing `test_udp_download_success_via_fake_factory` uses the payload `'FAKEMAPBYTES'`,
which is not a valid datafile and will now be rejected. **Change both payload lines in that test**
from `$payload = 'FAKEMAPBYTES';` to:

```php
        $payload = "DATA" . str_repeat('M', 40); // valid datafile: 'DATA' magic + ≥36 bytes
```

(The test computes crc/size from `$payload`, so the `maps/dm1_<crcHex>.map` assertions still hold.)

Then add these three tests to `MapFileAcquisitionTest` (they reuse the existing `buildMapChange`,
`buildMapData`, `seedHostedMap`, `toInt32` helpers and the `FakeGameConnection` at the bottom of the
file):

```php
    public function test_udp_downloads_a_keyless_map_when_name_matches(): void
    {
        $payload = "DATA" . str_repeat('K', 40);
        $crc     = crc32($payload);

        // keyless map (no sha256, no crc) but with a recorded host
        $map    = Map::create(['name' => 'kobra']);
        $mod    = Mod::create(['name' => 'dm']);
        $server = Server::factory()->create(['version' => '0.6.4', 'flavor' => 'vanilla_06']);
        ServerAddress::factory()->create([
            'server_id' => $server->id, 'ip' => '1.2.3.4', 'port' => 8303,
            'protocol' => 6, 'is_canonical' => true,
        ]);
        ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 10,
        ]);

        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange('kobra', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source = new GameServerMapSource(connectionFactory: static fn () => $conn);

        self::assertSame($payload, $source->fetch($map));
    }

    public function test_udp_rejects_when_server_reports_a_different_map(): void
    {
        $payload = "DATA" . str_repeat('X', 40);
        $crc     = crc32($payload);

        [$map] = $this->seedHostedMap('dm1', sprintf('%08x', $crc & 0xFFFFFFFF));

        // host has since swapped to 'ctf7' — name must not match 'dm1'
        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange('ctf7', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source = new GameServerMapSource(connectionFactory: static fn () => $conn);

        self::assertNull($source->fetch($map));
    }

    public function test_udp_rejects_non_datafile_bytes(): void
    {
        $payload = 'NOTADATAFILE-juststuff-0123456789012345'; // ≥36 bytes but no DATA/ATAD magic
        $crc     = crc32($payload);

        [$map] = $this->seedHostedMap('dm1', sprintf('%08x', $crc & 0xFFFFFFFF));

        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $source = new GameServerMapSource(connectionFactory: static fn () => $conn);

        self::assertNull($source->fetch($map));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter "test_udp_downloads_a_keyless_map_when_name_matches|test_udp_rejects_when_server_reports_a_different_map|test_udp_rejects_non_datafile_bytes"`
Expected: FAIL — keyless fetch returns `null` (old guard), and the gates don't exist yet.

- [ ] **Step 3: Implement the source changes**

In `app/Service/GameServerMapSource.php`, inside `doFetch()`:

(a) **Remove** the early guard:

```php
        // Nothing to verify against — skip.
        if ($crcHex === null && $sha256 === null) {
            return null;
        }
```

(b) Change the download call + return from:

```php
        return (new MapDownloadSession($conn))->download(
            expectedSha256: $sha256,
            expectedCrc: $expectedCrc,
            timeoutMs: $this->timeoutMs,
        );
```

to:

```php
        $result = (new MapDownloadSession($conn))->downloadWithIdentity(
            expectedSha256: $sha256,
            expectedCrc: $expectedCrc,
            timeoutMs: $this->timeoutMs,
        );

        // Name-match gate: the source picked a host from server_histories; if that host has since
        // switched maps it answers with a different name, and archiving its bytes as this map would
        // mislabel them. Reject the mismatch (the map is retried after the cooldown).
        if (trim($result->name) !== trim((string) $map->getAttribute('name'))) {
            return null;
        }

        // Datafile sanity: reject placeholder/troll content that is not a real Teeworlds map
        // (mirrors resources/assets/js/map/datafile.ts: 'DATA'/'ATAD' magic, ≥36 bytes).
        if (!$this->looksLikeMapDatafile($result->bytes)) {
            return null;
        }

        return $result->bytes;
```

(c) Add the helper method to the class:

```php
    /**
     * Whether the bytes are a Teeworlds map datafile: a 4-byte 'DATA'/'ATAD' magic and at least the
     * 36-byte header (4 magic + 7×4 header ints). Guards against archiving placeholder maps a swapped
     * host might still answer with.
     */
    private function looksLikeMapDatafile(string $bytes): bool
    {
        if (strlen($bytes) < 36) {
            return false;
        }
        $magic = substr($bytes, 0, 4);

        return $magic === 'DATA' || $magic === 'ATAD';
    }
```

- [ ] **Step 4: Run to verify pass (and no regression in the file)**

Run: `vendor/bin/phpunit tests/Feature/MapFileAcquisitionTest.php`
Expected: PASS — the updated success test, the three new tests, and all other tests in the file.

- [ ] **Step 5: Commit**

```bash
git add app/Service/GameServerMapSource.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): fetch keyless maps over UDP, gated by name match + datafile sanity"
```

---

## Task 4: `MapFileStore` derives + backfills the key for keyless maps

`ensure()` derives the cache path from the key and throws before reaching any source when a map has
none. Invert that for keyless maps: fetch bytes first, derive `sha256`/`crc`/`size` from them
(no-clobber), then store at `maps/<name>_<sha256>.map`.

**Files:**
- Modify: `app/Service/MapFileStore.php`
- Test: `tests/Feature/MapFileAcquisitionTest.php` (add two tests)

- [ ] **Step 1: Write the failing tests**

Add to `MapFileAcquisitionTest` (uses a tiny inline `MapSource` returning fixed datafile bytes, to
isolate the store from the UDP stack):

```php
    public function test_store_backfills_identity_and_caches_a_keyless_map(): void
    {
        Storage::fake('local');

        $body = "DATA" . str_repeat('Z', 40);
        $sha  = hash('sha256', $body);
        $map  = Map::create(['name' => 'keyless map']); // no sha256, no crc

        $source = new class($body) implements \App\Service\MapSource {
            public function __construct(private string $body) {}
            public function fetch(\App\Models\Map $map): ?string { return $this->body; }
        };

        $rel = (new MapFileStore([$source]))->ensure($map);

        $this->assertSame('maps/keyless_map_' . $sha . '.map', $rel);
        Storage::disk('local')->assertExists($rel);

        $fresh = $map->fresh();
        $this->assertSame($sha, $fresh->sha256);
        $this->assertSame(sprintf('%08x', crc32($body) & 0xFFFFFFFF), $fresh->crc);
        $this->assertSame(strlen($body), $fresh->size);
    }

    public function test_store_backfill_does_not_clobber_an_existing_size(): void
    {
        Storage::fake('local');

        $body = "DATA" . str_repeat('Q', 40);
        $map  = Map::create(['name' => 'sized', 'size' => 99999]); // size preset, still keyless

        $source = new class($body) implements \App\Service\MapSource {
            public function __construct(private string $body) {}
            public function fetch(\App\Models\Map $map): ?string { return $this->body; }
        };

        (new MapFileStore([$source]))->ensure($map);

        $this->assertSame(99999, $map->fresh()->size, 'a preset size must not be overwritten');
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter "test_store_backfills_identity_and_caches_a_keyless_map|test_store_backfill_does_not_clobber_an_existing_size"`
Expected: FAIL — `ensure()` throws `MapFileUnavailable` for the keyless map (path is null up front).

- [ ] **Step 3: Implement the restructured `ensure()` + `backfillIdentity()`**

Replace the body of `ensure()` in `app/Service/MapFileStore.php` with:

```php
    public function ensure(Map $map): string
    {
        $storage = Storage::disk($this->disk);

        // Fast path: a keyed map whose file is already cached.
        $relative = $this->relativePathFor($map);
        if ($relative !== null && $storage->exists($relative)) {
            return $relative;
        }

        foreach ($this->sources as $source) {
            $bytes = $source->fetch($map);
            if ($bytes === null) {
                continue;
            }

            // Keyless maps have no path until now: derive their content identity from the verified
            // bytes and record it (no-clobber) so relativePathFor() can name the file.
            if ($this->relativePathFor($map) === null) {
                $this->backfillIdentity($map, $bytes);
            }

            $relative = $this->relativePathFor($map);
            if ($relative === null) {
                // Still unkeyable (e.g. a pre-set but non-hex key) — cannot safely name the file.
                continue;
            }

            $storage->put($relative, $bytes);

            return $relative;
        }

        throw new MapFileUnavailable();
    }

    /**
     * Record a freshly-downloaded keyless map's content identity, setting only the columns that are
     * still null (no-clobber, mirroring UpdateData::updateMapIdentity). The derived sha256 is the true
     * content hash of the verified bytes, so it is a valid cache key even for maps DDNet never mirrors.
     * Only the changed columns are written, so a concurrently-stamped last_archive_attempt_at survives.
     */
    private function backfillIdentity(Map $map, string $bytes): void
    {
        $updates = [];
        if ($map->getAttribute('sha256') === null) {
            $updates['sha256'] = hash('sha256', $bytes);
        }
        if ($map->getAttribute('crc') === null) {
            $updates['crc'] = sprintf('%08x', crc32($bytes) & 0xFFFFFFFF);
        }
        if ($map->getAttribute('size') === null) {
            $updates['size'] = strlen($bytes);
        }

        if ($updates !== []) {
            $map->update($updates);
        }
    }
```

- [ ] **Step 4: Run to verify pass (and no regression in the file)**

Run: `vendor/bin/phpunit tests/Feature/MapFileAcquisitionTest.php`
Expected: PASS — the two new tests plus every existing store test (cache fast-path, no-source throw,
hex guardrail, etc.).

- [ ] **Step 5: Commit**

```bash
git add app/Service/MapFileStore.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): backfill content identity from bytes for keyless map archiving"
```

---

## Task 5: Treat "keyless but has a host" as archivable (status + selection)

Widen the three places that currently equate "no key" with "Unavailable / not selectable" so a keyless
map with a recorded host becomes Queued/Retrying and is enqueued; a keyless map with no host stays
Unavailable.

**Files:**
- Modify: `app/Models/Map.php`
- Modify: `app/Http/Controllers/MainController.php:147-155` (the `maps()` method)
- Test: `tests/Feature/MapArchiverTest.php` (add tests), `tests/Feature/MapListBadgeTest.php` (add a case)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/MapArchiverTest.php`:

```php
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
```

Add `use Illuminate\Support\Facades\Storage;` to that test file if not already imported.

Add to `tests/Feature/MapListBadgeTest.php` inside the existing test, before the assertions — seed a keyless hosted map and assert it does **not** read Unavailable by being counted as Queued. Simplest: extend the existing test with an extra map and rely on the existing `assertSee('Queued')`. Add after the `unavailable-map` line:

```php
        $hosted = Map::create(['name' => 'hosted-keyless-map']);
        $mod    = \App\Models\Mod::create(['name' => 'dm']);
        $server = \App\Models\Server::factory()->create(['flavor' => 'vanilla_06']);
        \App\Models\ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $hosted->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter "test_has_downloadable_file_true_for_keyless_map_with_a_host|test_needs_archiving_includes_keyless_hosted_excludes_orphan|test_status_for_keyless_hosted_is_queued_not_unavailable"`
Expected: FAIL — `hasDownloadableFile()` is false for keyless, scope excludes it, status is Unavailable.

- [ ] **Step 3: Implement the model changes**

In `app/Models/Map.php`, replace `hasDownloadableFile()` with:

```php
    /**
     * Whether a downloadable .map file can potentially be acquired for this map: it has a content key
     * (sha256/crc → DDNet HTTP or verified UDP), or it is keyless but was seen on a server we can
     * connect to and download it from over UDP, backfilling the key from the bytes.
     */
    public function hasDownloadableFile(): bool
    {
        return $this->getAttribute('sha256') !== null
            || $this->getAttribute('crc') !== null
            || $this->hasRecordedHost();
    }

    /**
     * Whether any server_histories row records a host for this map (a server we can attempt a UDP
     * download from). Honours an eager-loaded server_records_count to avoid an N+1 on list pages.
     */
    public function hasRecordedHost(): bool
    {
        $count = $this->getAttribute('server_records_count');
        if ($count !== null) {
            return (int) $count > 0;
        }

        return $this->serverRecords()->exists();
    }
```

And update `scopeNeedsArchiving()` — change the key predicate from:

```php
            ->where(fn (Builder $q) => $q->whereNotNull('sha256')->orWhereNotNull('crc'))
```

to:

```php
            ->where(fn (Builder $q) => $q->whereNotNull('sha256')
                ->orWhereNotNull('crc')
                ->orWhereHas('serverRecords'))
```

- [ ] **Step 4: Implement the controller eager-load**

In `app/Http/Controllers/MainController.php`, change the query in `maps()` from:

```php
        $maps = Map::orderBy('name')->paginate(50);
```

to:

```php
        $maps = Map::withCount('serverRecords')->orderBy('name')->paginate(50);
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit tests/Feature/MapArchiverTest.php tests/Feature/MapListBadgeTest.php`
Expected: PASS — new tests and the existing four-state badge test.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Map.php app/Http/Controllers/MainController.php tests/Feature/MapArchiverTest.php tests/Feature/MapListBadgeTest.php
git commit -m "feat(maps): keyless maps with a recorded host are archivable, not Unavailable"
```

**Phase 1 is now complete and independently mergeable.** Run the full suite once
(`vendor/bin/phpunit`) before continuing to Phase 2.

---

## Task 6: Extract shared verification + a session interface

Prepare for the 0.7 session: pull the protocol-independent integrity helpers into a trait both
sessions use, and define an interface so a factory and `GameServerMapSource` can treat 0.6 and 0.7
sessions uniformly.

**Files:**
- Create: `app/TwStats/Connection/MapDownloadVerification.php` (trait)
- Create: `app/TwStats/Connection/MapDownloadSessionInterface.php`
- Modify: `app/TwStats/Connection/MapDownloadSession.php`

- [ ] **Step 1: Create the trait — move `verifyIntegrity()` and `toInt32()` verbatim out of `MapDownloadSession`**

Create `app/TwStats/Connection/MapDownloadVerification.php`:

```php
<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Shared map-bytes integrity checks used by both the 0.6 and 0.7 download sessions: total size, the
 * server-announced CRC32, and (when present) SHA-256, plus caller expectations. Extracted so the two
 * protocol drivers cannot drift apart on verification.
 */
trait MapDownloadVerification
{
    private function verifyIntegrity(
        string $payload,
        int $announcedSize,
        int $announcedCrc,
        ?string $serverSha256Hex,
        ?string $expectedSha256,
        ?int $expectedCrc,
    ): void {
        $actual = strlen($payload);
        if ($actual !== $announcedSize) {
            throw new MapDownloadFailed(
                "Map size mismatch: announced {$announcedSize}, received {$actual}",
            );
        }

        $phpCrc = $this->toInt32(crc32($payload));
        $srvCrc = $this->toInt32($announcedCrc);

        if ($phpCrc !== $srvCrc) {
            throw new MapDownloadFailed(
                sprintf('CRC32 mismatch: server 0x%08x, computed 0x%08x', $srvCrc & 0xFFFFFFFF, $phpCrc & 0xFFFFFFFF),
            );
        }

        if ($expectedCrc !== null) {
            $expCrc = $this->toInt32($expectedCrc);
            if ($phpCrc !== $expCrc) {
                throw new MapDownloadFailed(
                    sprintf('CRC32 mismatch with caller expectation: expected 0x%08x, computed 0x%08x', $expCrc & 0xFFFFFFFF, $phpCrc & 0xFFFFFFFF),
                );
            }
        }

        if ($expectedSha256 !== null) {
            $actual256 = hash('sha256', $payload);
            if (!hash_equals($expectedSha256, $actual256)) {
                throw new MapDownloadFailed(
                    "SHA-256 mismatch: expected {$expectedSha256}, got {$actual256}",
                );
            }
        }

        if ($serverSha256Hex !== null && $expectedSha256 !== null) {
            if (!hash_equals($serverSha256Hex, $expectedSha256)) {
                throw new MapDownloadFailed(
                    "Server-announced SHA-256 {$serverSha256Hex} does not match caller expectation {$expectedSha256}",
                );
            }
        }
    }

    private function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }
}
```

- [ ] **Step 2: Create the interface**

Create `app/TwStats/Connection/MapDownloadSessionInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * A driver that downloads a map's bytes over an established connection and reports the identity the
 * server announced. Implemented per protocol flavour (0.6 and 0.7/sixup).
 */
interface MapDownloadSessionInterface
{
    /**
     * @throws MapDownloadFailed on timeout, malformed messages, or integrity failure.
     */
    public function downloadWithIdentity(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
    ): MapDownloadResult;
}
```

- [ ] **Step 3: Wire `MapDownloadSession` to the trait + interface, deleting the now-duplicated methods**

In `app/TwStats/Connection/MapDownloadSession.php`:
- Change the class declaration `final class MapDownloadSession` to
  `final class MapDownloadSession implements MapDownloadSessionInterface`.
- Add `use MapDownloadVerification;` as the first line inside the class body.
- **Delete** the `verifyIntegrity(...)` method and the `toInt32(...)` method now living in the trait.

- [ ] **Step 4: Run the session suite to verify behaviour is unchanged**

Run: `vendor/bin/phpunit tests/Feature/Connection/MapDownloadSessionTest.php`
Expected: PASS — all 12 tests (verification behaviour is byte-identical; it just lives in the trait now).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Connection/MapDownloadVerification.php app/TwStats/Connection/MapDownloadSessionInterface.php app/TwStats/Connection/MapDownloadSession.php
git commit -m "refactor(maps): share map-download verification via trait + session interface"
```

---

## Task 7: `SevenMapDownloadSession` — native 0.7 (sixup) download

**Files:**
- Create: `app/TwStats/Connection/SevenMapDownloadSession.php`
- Test: `tests/Feature/Connection/SevenMapDownloadSessionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\SevenMapDownloadSession;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

class SevenMapDownloadSessionTest extends TestCase
{
    private function packInt(int $msgId): string
    {
        return VariableInt::pack(($msgId << 1) | 1);
    }

    private function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    /** 0.7 MAP_CHANGE: name, crc, size, chunksPerRequest, chunkSize, raw sha256[32]. */
    private function buildMapChange07(string $name, int $crc, int $size, int $chunksPerRequest, int $chunkSize, string $sha256Raw): string
    {
        return $this->packInt(2)
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size)
            . VariableInt::pack($chunksPerRequest)
            . VariableInt::pack($chunkSize)
            . $sha256Raw;
    }

    /** 0.7 MAP_DATA: raw bytes only. */
    private function buildMapData07(string $data): string
    {
        return $this->packInt(3) . $data;
    }

    private function makePayload(int $size): string
    {
        $payload = '';
        for ($i = 0; $i < $size; $i++) {
            $payload .= chr($i & 0xFF);
        }

        return $payload;
    }

    public function testWindowedDownloadReassemblesAndReportsIdentity(): void
    {
        $chunkSize = 16;
        $payload   = $this->makePayload($chunkSize * 5); // 5 chunks
        $crc       = crc32($payload);
        $sha256Hex = hash('sha256', $payload);
        $sha256Raw = hex2bin($sha256Hex);

        $chunks = [];
        for ($i = 0; $i < 5; $i++) {
            $chunks[$i] = substr($payload, $i * $chunkSize, $chunkSize);
        }

        // Window size 2 → server delivers 2 chunks per REQUEST, forcing the session to re-request.
        $conn = new SevenWindowedFakeConnection(
            mapChange: $this->buildMapChange07('ctf5', $crc, strlen($payload), 2, $chunkSize, $sha256Raw),
            chunks: $chunks,
            windowSize: 2,
            buildMapData: fn (string $data) => $this->buildMapData07($data),
        );

        $result = (new SevenMapDownloadSession($conn))->downloadWithIdentity(timeoutMs: 5000);

        self::assertSame($payload, $result->bytes, '0.7 windowed download must reassemble the payload');
        self::assertSame('ctf5', $result->name);
        self::assertSame($sha256Hex, $result->sha256);
        self::assertTrue($conn->disconnected, 'disconnect() must be called after success');

        // First sent message is NETMSG_INFO (id=1) carrying the 0.7 version string.
        [$typeInt] = VariableInt::unpack($conn->sent[0], 0);
        self::assertSame(1, $typeInt >> 1, 'first sent message must be NETMSG_INFO');
        self::assertStringContainsString('0.7 802f1be60a05665f', $conn->sent[0]);

        // Subsequent sends are REQUEST_MAP_DATA (id=23); 5 chunks / window 2 → 3 requests.
        $requests = array_filter(
            array_slice($conn->sent, 1),
            static function (string $m): bool {
                [$t] = VariableInt::unpack($m, 0);
                return ($t >> 1) === 23;
            },
        );
        self::assertCount(3, $requests, 'must send one REQUEST_MAP_DATA per window (ceil(5/2)=3)');
    }

    public function testSizeMismatchThrows(): void
    {
        $payload   = $this->makePayload(16);
        $crc       = crc32($payload);
        $sha256Raw = hex2bin(hash('sha256', $payload));

        // Announce a larger size than will ever arrive → stall → timeout.
        $conn = new SevenWindowedFakeConnection(
            mapChange: $this->buildMapChange07('dm1', $crc, 999999, 4, 16, $sha256Raw),
            chunks: [$payload],
            windowSize: 4,
            buildMapData: fn (string $data) => $this->buildMapData07($data),
        );

        $this->expectException(\App\TwStats\Connection\MapDownloadFailed::class);
        (new SevenMapDownloadSession($conn))->downloadWithIdentity(timeoutMs: 1);
    }
}

/**
 * Models a 0.7 server's windowed delivery: MAP_CHANGE on the first receive(); thereafter each
 * REQUEST_MAP_DATA releases up to windowSize raw MAP_DATA chunks. Empty between windows so the
 * session must re-request to make progress.
 */
final class SevenWindowedFakeConnection implements ConnectionInterface
{
    /** @var string[] */
    public array $sent = [];

    public bool $disconnected = false;

    /** @var string[][] */
    private array $inbox = [];

    private bool $mapChangeSent = false;

    private int $nextChunk = 0;

    /** @param string[] $chunks */
    public function __construct(
        private readonly string $mapChange,
        private readonly array $chunks,
        private readonly int $windowSize,
        private readonly mixed $buildMapData,
    ) {
    }

    public function sendVital(string $message): void
    {
        $this->sent[] = $message;

        [$typeInt] = VariableInt::unpack($message, 0);
        if (($typeInt >> 1) !== 23) { // NETMSG_REQUEST_MAP_DATA
            return;
        }

        $window = [];
        $end    = min($this->nextChunk + $this->windowSize, count($this->chunks));
        for (; $this->nextChunk < $end; $this->nextChunk++) {
            $window[] = ($this->buildMapData)($this->chunks[$this->nextChunk]);
        }
        if ($window !== []) {
            $this->inbox[] = $window;
        }
    }

    public function flush(): void
    {
    }

    /** @return string[] */
    public function receive(int $timeoutMs): array
    {
        if (!$this->mapChangeSent) {
            $this->mapChangeSent = true;

            return [$this->mapChange];
        }

        if ($this->inbox === []) {
            return [];
        }

        return array_shift($this->inbox);
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    public function isOnline(): bool
    {
        return !$this->disconnected;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Connection/SevenMapDownloadSessionTest.php`
Expected: FAIL — `Class "App\TwStats\Connection\SevenMapDownloadSession" not found`.

- [ ] **Step 3: Implement the 0.7 session**

Create `app/TwStats/Connection/SevenMapDownloadSession.php`:

```php
<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

use App\TwStats\Protocol\Seven\Unpacker;
use App\TwStats\Protocol\Seven\VariableInt;

/**
 * Drives a native Teeworlds 0.7 (sixup) in-game map download. Verified against
 * teeworlds/src/engine/server/server.cpp:
 *
 *  1. Client → NETMSG_INFO (version "0.7 802f1be60a05665f", password, client-version int)
 *  2. Server → NETMSG_MAP_CHANGE (name, crc, size, chunksPerRequest, chunkSize, raw sha256[32])
 *  3. Client → NETMSG_REQUEST_MAP_DATA (no chunk arg — the server tracks its own counter)
 *  4. Server → up to chunksPerRequest × NETMSG_MAP_DATA (RAW BYTES ONLY, no per-chunk framing)
 *     … client re-requests after each window until bytes received ≥ size (there is no last flag).
 *
 * MAP_CHANGE and the first window may arrive bundled in one burst, so every message of every
 * receive() batch is processed uniformly (mirrors the 0.6 session's anti-stall handling).
 */
final class SevenMapDownloadSession implements MapDownloadSessionInterface
{
    use MapDownloadVerification;

    private const NETMSG_INFO = 1;
    private const NETMSG_MAP_CHANGE = 2;
    private const NETMSG_MAP_DATA = 3;
    private const NETMSG_REQUEST_MAP_DATA = 23;

    /** Must exactly equal the server's GAME_NETVERSION (version.h) or the server drops us. */
    private const GAME_NETVERSION = '0.7 802f1be60a05665f';

    private const SHA256_BYTES = 32;

    private const STALL_WINDOW_MS = 8000;

    public function __construct(private readonly ConnectionInterface $conn)
    {
    }

    public function downloadWithIdentity(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
    ): MapDownloadResult {
        $now      = hrtime(true) / 1_000_000;
        $deadline = $now + $timeoutMs;

        $this->conn->sendVital($this->buildInfo());
        $this->conn->flush();

        $haveMapChange    = false;
        $name             = '';
        $crc              = 0;
        $size             = 0;
        $chunksPerRequest = 1;
        $sha256Hex        = null;
        $payload          = '';
        $chunksInWindow   = 0;
        $stallDeadline    = $now + self::STALL_WINDOW_MS;

        while (true) {
            $now       = hrtime(true) / 1_000_000;
            $remaining = (int) min($deadline - $now, $stallDeadline - $now);
            if ($remaining <= 0) {
                throw new MapDownloadFailed(
                    $haveMapChange ? 'Timeout during 0.7 map data transfer' : 'Timeout waiting for 0.7 NETMSG_MAP_CHANGE',
                );
            }

            $messages = $this->conn->receive((int) min($remaining, 200));

            foreach ($messages as $msg) {
                if (!$haveMapChange) {
                    $parsed = $this->parseMapChange($msg);
                    if ($parsed === null) {
                        continue;
                    }

                    [$name, $crc, $size, $chunksPerRequest, , $sha256Hex] = $parsed;
                    $haveMapChange  = true;
                    $payload        = '';
                    $chunksInWindow = 0;
                    $stallDeadline  = hrtime(true) / 1_000_000 + self::STALL_WINDOW_MS;
                    $this->requestMapData();

                    continue;
                }

                $data = $this->parseMapData($msg);
                if ($data === null) {
                    continue;
                }

                $payload       .= $data;
                $chunksInWindow++;
                $stallDeadline  = hrtime(true) / 1_000_000 + self::STALL_WINDOW_MS;

                if (strlen($payload) >= $size) {
                    $this->verifyIntegrity($payload, $size, $crc, $sha256Hex, $expectedSha256, $expectedCrc);
                    $this->conn->disconnect();

                    return new MapDownloadResult($payload, $name, $crc, $size, $sha256Hex);
                }

                // End of a server window → ask for the next one (the server waits for this).
                if ($chunksInWindow >= $chunksPerRequest) {
                    $chunksInWindow = 0;
                    $this->requestMapData();
                }
            }
        }
    }

    private function buildInfo(): string
    {
        return VariableInt::pack((self::NETMSG_INFO << 1) | 1)
            . self::GAME_NETVERSION . "\x00"
            . "\x00"                       // empty password
            . VariableInt::pack(0);        // client-version int (server stores it, does not gate on it)
    }

    private function requestMapData(): void
    {
        // 0.7 REQUEST_MAP_DATA carries no chunk index; the server advances its own per-client counter.
        $this->conn->sendVital(VariableInt::pack((self::NETMSG_REQUEST_MAP_DATA << 1) | 1));
        $this->conn->flush();
    }

    /**
     * @return array{string, int, int, int, int, string}|null  [name, crc, size, chunksPerRequest, chunkSize, sha256Hex]
     */
    private function parseMapChange(string $msg): ?array
    {
        $u       = new Unpacker($msg);
        $typeInt = $u->getInt();
        if ($u->error() || ($typeInt >> 1) !== self::NETMSG_MAP_CHANGE || ($typeInt & 1) !== 1) {
            return null;
        }

        $name             = $u->getString();
        $crc              = $u->getInt();
        $size             = $u->getInt();
        $chunksPerRequest = $u->getInt();
        $chunkSize        = $u->getInt();
        $sha256Raw        = $u->getRaw(self::SHA256_BYTES);

        if ($u->error() || $size < 0 || $chunksPerRequest < 1) {
            return null;
        }

        return [$name, $crc, $size, $chunksPerRequest, $chunkSize, bin2hex($sha256Raw)];
    }

    /**
     * 0.7 MAP_DATA is the message header followed by raw map bytes only.
     */
    private function parseMapData(string $msg): ?string
    {
        $u       = new Unpacker($msg);
        $typeInt = $u->getInt();
        if ($u->error() || ($typeInt >> 1) !== self::NETMSG_MAP_DATA || ($typeInt & 1) !== 1) {
            return null;
        }

        $data = $u->getRaw($u->remaining());

        return $u->error() ? null : $data;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Connection/SevenMapDownloadSessionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Connection/SevenMapDownloadSession.php tests/Feature/Connection/SevenMapDownloadSessionTest.php
git commit -m "feat(maps): native 0.7 (sixup) in-game map download session"
```

---

## Task 8: `MapDownloadSessionFactory` — pick the session by flavour

**Files:**
- Create: `app/TwStats/Connection/MapDownloadSessionFactory.php`
- Test: `tests/Feature/Connection/MapDownloadSessionFactoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\MapDownloadSession;
use App\TwStats\Connection\MapDownloadSessionFactory;
use App\TwStats\Connection\SevenMapDownloadSession;
use App\TwStats\Discovery\FlavorClassifier;
use PHPUnit\Framework\TestCase;

class MapDownloadSessionFactoryTest extends TestCase
{
    private function conn(): ConnectionInterface
    {
        return new class implements ConnectionInterface {
            public function sendVital(string $message): void {}
            public function flush(): void {}
            public function receive(int $timeoutMs): array { return []; }
            public function disconnect(): void {}
            public function isOnline(): bool { return true; }
        };
    }

    public function testVanilla07GetsTheSevenSession(): void
    {
        $session = MapDownloadSessionFactory::for($this->conn(), FlavorClassifier::FLAVOR_VANILLA_07);

        self::assertInstanceOf(SevenMapDownloadSession::class, $session);
    }

    public function testDdnetAndVanilla06GetTheSixSession(): void
    {
        self::assertInstanceOf(
            MapDownloadSession::class,
            MapDownloadSessionFactory::for($this->conn(), FlavorClassifier::FLAVOR_DDNET),
        );
        self::assertInstanceOf(
            MapDownloadSession::class,
            MapDownloadSessionFactory::for($this->conn(), FlavorClassifier::FLAVOR_VANILLA_06),
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Connection/MapDownloadSessionFactoryTest.php`
Expected: FAIL — `Class "App\TwStats\Connection\MapDownloadSessionFactory" not found`.

- [ ] **Step 3: Implement the factory**

Create `app/TwStats/Connection/MapDownloadSessionFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

use App\TwStats\Discovery\FlavorClassifier;

/**
 * Selects the map-download driver for a connection by server flavour: native 0.7 (sixup) servers
 * stream raw MAP_DATA windows and need SevenMapDownloadSession; DDNet and vanilla 0.6 servers speak
 * the classic 0.6 per-chunk protocol (DDNet routes through 0.6, see GameConnection::isSixup()).
 */
final class MapDownloadSessionFactory
{
    public static function for(ConnectionInterface $conn, string $flavor): MapDownloadSessionInterface
    {
        return $flavor === FlavorClassifier::FLAVOR_VANILLA_07
            ? new SevenMapDownloadSession($conn)
            : new MapDownloadSession($conn);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Connection/MapDownloadSessionFactoryTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Connection/MapDownloadSessionFactory.php tests/Feature/Connection/MapDownloadSessionFactoryTest.php
git commit -m "feat(maps): factory selects 0.6/0.7 map-download session by flavour"
```

---

## Task 9: Route `GameServerMapSource` to the 0.7 session for sixup hosts

Pick the host address by flavour (protocol 7 for vanilla_07, else 6) and build the session via the
factory.

**Files:**
- Modify: `app/Service/GameServerMapSource.php`
- Test: `tests/Feature/MapFileAcquisitionTest.php` (add one test)

- [ ] **Step 1: Write the failing test**

Add to `MapFileAcquisitionTest`. It seeds a `vanilla_07` host with a protocol-7 address and scripts a
0.7 MAP_CHANGE + raw MAP_DATA; the source must use the 0.7 session to read it. Add the 0.7 builders to
the test as private helpers if not present:

```php
    private function buildMapChange07(string $name, int $crc, int $size, int $chunksPerRequest, int $chunkSize, string $sha256Raw): string
    {
        return VariableInt::pack((2 << 1) | 1)
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size)
            . VariableInt::pack($chunksPerRequest)
            . VariableInt::pack($chunkSize)
            . $sha256Raw;
    }

    private function buildMapData07(string $data): string
    {
        return VariableInt::pack((3 << 1) | 1) . $data;
    }

    public function test_udp_uses_the_07_session_for_a_sixup_host(): void
    {
        $payload   = "DATA" . str_repeat('S', 40);
        $crc       = crc32($payload);
        $sha256Raw = hex2bin(hash('sha256', $payload));

        // keyless map hosted on a vanilla_07 server reachable on a protocol-7 address
        $map    = Map::create(['name' => 'sixup-map']);
        $mod    = Mod::create(['name' => 'dm']);
        $server = Server::factory()->create(['version' => '0.7.5', 'flavor' => 'vanilla_07']);
        ServerAddress::factory()->create([
            'server_id' => $server->id, 'ip' => '9.9.9.9', 'port' => 8307,
            'protocol' => 7, 'is_canonical' => true,
        ]);
        ServerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 5,
        ]);

        $conn = new FakeGameConnection();
        $conn->enqueue([$this->buildMapChange07('sixup-map', $crc, strlen($payload), 4, 16, $sha256Raw)]);
        $conn->enqueue([$this->buildMapData07($payload)]);

        $captured = null;
        $source = new GameServerMapSource(
            connectionFactory: static function (string $ip, int $port, string $flavor) use ($conn, &$captured) {
                $captured = [$ip, $port, $flavor];
                return $conn;
            },
        );

        self::assertSame($payload, $source->fetch($map));
        self::assertSame(['9.9.9.9', 8307, 'vanilla_07'], $captured, 'must dial the protocol-7 address with the 0.7 flavor');
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_udp_uses_the_07_session_for_a_sixup_host`
Expected: FAIL — the source dials a protocol-6 address (none exists → null) and/or builds the 0.6
session, which cannot parse 0.7 raw MAP_DATA.

- [ ] **Step 3: Implement flavour-aware routing**

In `app/Service/GameServerMapSource.php`, `doFetch()`:

(a) Move the flavour read above address selection and select the address by preferred protocol.
Replace:

```php
        // Prefer a 0.6 address; MapDownloadSession implements the 0.6 per-chunk protocol.
        $address = $server->addresses
            ->where('protocol', 6)
            ->first();

        // Fall back to any address when no 0.6 address exists (best-effort).
        if ($address === null) {
            $address = $server->addresses->first();
        }

        if ($address === null) {
            return null;
        }

        $ip     = $address->ip;
        $port   = $address->port;
        $flavor = $server->getAttribute('flavor') ?? 'vanilla_06';
```

with:

```php
        $flavor = $server->getAttribute('flavor') ?? FlavorClassifier::FLAVOR_VANILLA_06;

        // Dial the address that matches the flavour's wire: protocol 7 for native sixup, else 0.6.
        $preferredProtocol = $flavor === FlavorClassifier::FLAVOR_VANILLA_07 ? 7 : 6;
        $address = $server->addresses->where('protocol', $preferredProtocol)->first()
            ?? $server->addresses->first();

        if ($address === null) {
            return null;
        }

        $ip   = $address->ip;
        $port = $address->port;
```

(b) Replace the session construction:

```php
        $result = (new MapDownloadSession($conn))->downloadWithIdentity(
```

with:

```php
        $result = MapDownloadSessionFactory::for($conn, $flavor)->downloadWithIdentity(
```

(c) Add imports at the top of the file:

```php
use App\TwStats\Connection\MapDownloadSessionFactory;
use App\TwStats\Discovery\FlavorClassifier;
```

Then remove the now-unused `use App\TwStats\Connection\MapDownloadSession;` import (the factory
constructs it; `GameConnection` is still imported by the default factory and stays).

- [ ] **Step 4: Run to verify pass (and no regression)**

Run: `vendor/bin/phpunit tests/Feature/MapFileAcquisitionTest.php`
Expected: PASS — the new 0.7 routing test plus all 0.6 tests (vanilla_06 still routes to protocol 6 +
the 0.6 session).

- [ ] **Step 5: Commit**

```bash
git add app/Service/GameServerMapSource.php tests/Feature/MapFileAcquisitionTest.php
git commit -m "feat(maps): route sixup hosts to the 0.7 download session"
```

---

## Task 10: Full verification + finish the branch

**Files:** none (verification + handoff).

- [ ] **Step 1: Run the entire PHP suite**

Run: `vendor/bin/phpunit`
Expected: PASS — the full suite green (the pre-existing tests plus everything added here).

- [ ] **Step 2: Lint / static checks unaffected by back-end-only changes**

Run: `npm run lint`
Expected: PASS (no front-end source changed; the badge view already existed). If the project also runs
`npm run type-check` in CI, run it too and expect PASS.

- [ ] **Step 3: Sanity-check the live effect (optional, non-blocking)**

Run: `php artisan tinker --execute='echo App\Models\Map::whereNull("sha256")->whereNull("crc")->whereHas("serverRecords")->count()." keyless maps now eligible\n";'`
Expected: a non-zero count (these were previously Unavailable and are now Queued).

- [ ] **Step 4: Finish the branch**

Use **superpowers:finishing-a-development-branch** to verify tests, present completion options, and
execute the chosen one (the prior map-archiving work merged to master locally).
