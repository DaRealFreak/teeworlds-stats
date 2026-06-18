# UDP Served-Version Capture & Phantom Throttle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a UDP map download serves a self-consistent map that isn't the version we asked for, archive it under its true in-game name instead of discarding it, and throttle re-attempts of the requested version only after confirming the server still advertises it.

**Architecture:** `GameServerMapSource::runSession` downloads with self-consistency-only verification (no caller-expectation throw), then compares served-vs-requested identity itself. An exact match returns bytes as before; a "served other" result is captured via a new `CapturedMapVersionRecorder` (keyed off the in-game `MAP_CHANGE` name, stored through the shared `MapCachePath` helper) and the requested version is throttled via a new `served_other_version` telemetry reason — but only if a `CurrentServerMapResolver` (DDNet HTTP master for ddnet, connless `gie3` for 0.6) confirms the server still advertises the requested map, falling back to the freshest `server_histories` record.

**Tech Stack:** PHP 8.3, Laravel 13, PHPUnit (SQLite `:memory:` for tests), `Storage::fake`, `Http::fake`, the existing `App\TwStats` protocol layer.

## Global Constraints

- Comments explain *why* the current code exists (present tense), never narrate changes. (CLAUDE.md)
- Tests: `vendor/bin/phpunit`; SQLite `:memory:`. `Map`/`Mod` have no factory — create with `::create(['name' => ...])`. `Player`/`Server`/`Clan` have factories. Keep `CRONTASK_INTERVAL` set (phpunit.xml).
- Run inside the DDEV web container: bare `php artisan`, `vendor/bin/phpunit`, `npm`.
- `MapAcquisitionAttempt.reason` is a documented closed set — update the docblock whenever a reason is added.
- New `App\Service` classes need no `use` import from within `GameServerMapSource` (same namespace).
- Commit after every task. Do not push.

---

## File Structure

- `app/Service/MapCachePath.php` — **new**. Pure static cache-path computation (extracted from `MapFileStore`).
- `app/Service/CapturedMapVersionRecorder.php` — **new**. Persists a served-but-different map (record version + store bytes). No telemetry.
- `app/Service/CurrentServerMapResolverInterface.php` — **new**. One-method seam: `currentMapName(Server): ?string`.
- `app/Service/CurrentServerMapResolver.php` — **new**. Live current-map lookup via the correct channel per flavor (memoized DDNet master snapshot + connless 0.6); `null` for 0.7/unknown.
- `app/Service/MapFileStore.php` — **modify**. Delegate path computation to `MapCachePath`.
- `app/Service/GameServerMapSource.php` — **modify**. Self-consistency-only download + served-vs-requested routing + capture + phantom gate.
- `app/Listeners/ApplyMapAcquisitionState.php` — **modify**. `served_other_version` → throttle the requested version.
- `app/Models/MapAcquisitionAttempt.php` — **modify**. Reason-vocabulary docblock.
- `tests/Feature/Connection/ScriptsMapDownloads.php` — **new** trait. Shared wire-message builders.
- `tests/Feature/Connection/GameServerMapSourceHttpTest.php` — **modify**. Use the trait; remove the now-obsolete caller-crc-mismatch test.
- `tests/Feature/Connection/GameServerMapSourceCaptureTest.php` — **new**. Capture + throttle + gate integration tests.
- `tests/Unit/Service/MapCachePathTest.php`, `tests/Feature/Service/CapturedMapVersionRecorderTest.php`, `tests/Feature/Service/CurrentServerMapResolverTest.php`, `tests/Feature/MapAcquisitionStateTest.php` (extend) — **new/modify** tests.

---

## Task 1: Commit the prerequisite case-insensitive name-gate fix

The case-insensitive `nameMatches()` helper (and its two regression tests) are already implemented in the working tree from the preceding investigation; the new code depends on `nameMatches()`. Land it as its own commit so the feature work starts from a clean tree.

**Files:**
- Modify: `app/Service/GameServerMapSource.php` (already changed)
- Modify: `tests/Feature/Connection/GameServerMapSourceHttpTest.php` (already changed)

- [ ] **Step 1: Confirm the existing tests pass**

Run: `vendor/bin/phpunit --filter 'differs_only_in_case' tests/Feature/Connection/GameServerMapSourceHttpTest.php`
Expected: PASS (2 tests)

- [ ] **Step 2: Confirm the full suite is green**

Run: `vendor/bin/phpunit`
Expected: PASS (all)

- [ ] **Step 3: Commit only the bug-fix files**

```bash
git add app/Service/GameServerMapSource.php tests/Feature/Connection/GameServerMapSourceHttpTest.php
git commit -m "fix(map-archive): match map names case-insensitively in the UDP name gate

A case-sensitive gate rejected byte-perfect downloads whose stored name and
in-game MAP_CHANGE name differ only in case (e.g. savannux vs Savannux),
producing a 0% success rate against such hosts.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Extract `MapCachePath` pure helper

**Files:**
- Create: `app/Service/MapCachePath.php`
- Modify: `app/Service/MapFileStore.php` (replace private `pathForIdentity`/`nameSlug` usage with `MapCachePath::forIdentity`)
- Test: `tests/Unit/Service/MapCachePathTest.php`

**Interfaces:**
- Produces: `MapCachePath::forIdentity(string $name, ?string $sha256, ?string $crc): ?string` — `'maps/<slug>_<sha256|crc>.map'`, or `null` when no key or a non-hex key.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\MapCachePath;
use PHPUnit\Framework\TestCase;

class MapCachePathTest extends TestCase
{
    public function test_prefers_sha256_and_slugs_the_name(): void
    {
        self::assertSame(
            'maps/My_Map_' . str_repeat('a', 64) . '.map',
            MapCachePath::forIdentity('My Map', str_repeat('a', 64), 'deadbeef'),
        );
    }

    public function test_falls_back_to_crc_when_no_sha(): void
    {
        self::assertSame('maps/dm1_deadbeef.map', MapCachePath::forIdentity('dm1', null, 'deadbeef'));
    }

    public function test_null_when_no_key(): void
    {
        self::assertNull(MapCachePath::forIdentity('dm1', null, null));
    }

    public function test_null_when_key_is_not_hex(): void
    {
        self::assertNull(MapCachePath::forIdentity('dm1', '../etc/passwd', null));
    }

    public function test_empty_slug_yields_no_prefix(): void
    {
        self::assertSame('maps/deadbeef.map', MapCachePath::forIdentity('***', null, 'deadbeef'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/MapCachePathTest.php`
Expected: FAIL with `Class "App\Service\MapCachePath" not found`

- [ ] **Step 3: Create the helper**

```php
<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Computes the on-disk cache path for a map identity, shared by every writer/reader so a file cached
 * via one acquisition path is found by the others. The key is sha256 if available, else crc; the name
 * is a best-effort, filesystem-safe prefix (lookups never parse it back). The hex-only key is the
 * path-traversal guard.
 */
final class MapCachePath
{
    public static function forIdentity(string $name, ?string $sha256, ?string $crc): ?string
    {
        $key = $sha256 ?? $crc;
        if ($key === null || !ctype_xdigit($key)) {
            return null;
        }

        $slug = self::nameSlug($name);
        $prefix = $slug === '' ? '' : $slug . '_';

        return 'maps/' . $prefix . $key . '.map';
    }

    /**
     * Filesystem-safe, human-readable slug for a (server-controlled) map name. The whitelist keeps out
     * '/', '.' and whitespace, so the slug can never contain a path separator or '..'; the dash sits
     * last in the class so 0-9-_ is not read as a range. Capped so name + hash stay under the 255-byte
     * filename limit.
     */
    private static function nameSlug(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);

        return rtrim(substr(trim($slug, '_'), 0, 64), '_');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/MapCachePathTest.php`
Expected: PASS

- [ ] **Step 5: Refactor `MapFileStore` to delegate**

In `app/Service/MapFileStore.php`, change the two public path methods to call the helper and delete the now-unused private `pathForIdentity` and `nameSlug` methods.

`relativePathFor`:

```php
    public function relativePathFor(Map $map): ?string
    {
        return MapCachePath::forIdentity(
            (string) $map->getAttribute('name'),
            $map->getAttribute('sha256'),
            $map->getAttribute('crc'),
        );
    }
```

`relativePathForVersion`:

```php
    public function relativePathForVersion(MapVersion $version): ?string
    {
        return MapCachePath::forIdentity(
            (string) $version->map->getAttribute('name'),
            $version->getAttribute('sha256'),
            $version->getAttribute('crc'),
        );
    }
```

Then delete the private `pathForIdentity(...)` and `nameSlug(...)` methods entirely. (`MapCachePath` is in the same `App\Service` namespace, so no `use` is needed.)

- [ ] **Step 6: Run the store tests to verify parity**

Run: `vendor/bin/phpunit tests/Feature/MapFileStoreVersionTest.php tests/Feature/MapFileAcquisitionTest.php tests/Feature/MapArchiveFillTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Service/MapCachePath.php app/Service/MapFileStore.php tests/Unit/Service/MapCachePathTest.php
git commit -m "refactor(map-archive): extract MapCachePath path helper from MapFileStore

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `CapturedMapVersionRecorder` (pure persistence)

**Files:**
- Create: `app/Service/CapturedMapVersionRecorder.php`
- Test: `tests/Feature/Service/CapturedMapVersionRecorderTest.php`

**Interfaces:**
- Consumes: `MapCachePath::forIdentity(...)` (Task 2); `MapVersionRecorder::record(Map $map, ?string $sha256, ?string $crc, ?int $size): ?MapVersion`; `MapVersion::markStatus(string)`, `MapVersion::STATUS_CACHED`.
- Produces: `CapturedMapVersionRecorder::capture(string $wireName, string $bytes, ?string $servedCrcHex): ?MapVersion` — records + caches the served map, returns the `MapVersion`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\CapturedMapVersionRecorder;
use App\Service\MapCachePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CapturedMapVersionRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_new_version_and_stores_the_bytes(): void
    {
        Storage::fake('local');
        $bytes = 'DATA' . str_repeat("\x00", 40);
        $crcHex = sprintf('%08x', crc32($bytes) & 0xFFFFFFFF);
        $sha = hash('sha256', $bytes);

        $version = (new CapturedMapVersionRecorder(disk: 'local'))->capture('Gridania', $bytes, $crcHex);

        self::assertNotNull($version);
        self::assertSame('Gridania', $version->map->name);
        self::assertSame($crcHex, $version->crc);
        self::assertSame($sha, $version->sha256);
        self::assertSame(MapVersion::STATUS_CACHED, $version->acquisition_status);

        $path = MapCachePath::forIdentity('Gridania', $sha, $crcHex);
        Storage::disk('local')->assertExists($path);
        self::assertSame($bytes, Storage::disk('local')->get($path));
    }

    public function test_reuses_existing_map_and_is_idempotent(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $bytes = 'DATA' . str_repeat("\x01", 40);
        $crcHex = sprintf('%08x', crc32($bytes) & 0xFFFFFFFF);

        $recorder = new CapturedMapVersionRecorder(disk: 'local');
        $first = $recorder->capture('ctf4_old', $bytes, $crcHex);
        $second = $recorder->capture('ctf4_old', $bytes, $crcHex);

        self::assertTrue($first->is($second), 'a re-capture returns the same version row');
        self::assertSame(1, MapVersion::where('map_id', $map->id)->count());
        self::assertSame(1, Map::where('name', 'ctf4_old')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Service/CapturedMapVersionRecorderTest.php`
Expected: FAIL with `Class "App\Service\CapturedMapVersionRecorder" not found`

- [ ] **Step 3: Create the service**

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Support\Facades\Storage;

/**
 * Persists a map we downloaded over UDP that turned out to be a different (but self-consistent) map or
 * content version than the one requested. The served bytes are a real map regardless of why we got
 * them, so we record their true identity — keyed off the in-game MAP_CHANGE name — and cache the file.
 *
 * Pure persistence: the caller (GameServerMapSource) owns all acquisition telemetry, so this records
 * no MapAcquisitionAttempted events. It must not construct a MapFileStore (which would build a
 * GameServerMapSource and cycle), so it writes through the shared MapCachePath helper directly.
 */
final class CapturedMapVersionRecorder
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly MapVersionRecorder $recorder = new MapVersionRecorder(),
    ) {
    }

    /**
     * Record + cache the served map. $wireName is the in-game MAP_CHANGE name; $servedCrcHex is the
     * server-announced crc as 8-hex (equal to crc32 of the verified bytes). Returns the recorded
     * MapVersion, or null only if it could not be keyed.
     */
    public function capture(string $wireName, string $bytes, ?string $servedCrcHex): ?MapVersion
    {
        $map = Map::firstOrCreate(['name' => $wireName]);

        $version = $this->recorder->record(
            $map,
            hash('sha256', $bytes),
            $servedCrcHex ?? sprintf('%08x', crc32($bytes) & 0xFFFFFFFF),
            strlen($bytes),
        );
        if ($version === null) {
            return null;
        }

        $path = MapCachePath::forIdentity(
            (string) $map->getAttribute('name'),
            $version->getAttribute('sha256'),
            $version->getAttribute('crc'),
        );
        $storage = Storage::disk($this->disk);
        if ($path !== null && !$storage->exists($path)) {
            $storage->put($path, $bytes);
        }

        if ($version->getAttribute('acquisition_status') !== MapVersion::STATUS_CACHED) {
            $version->markStatus(MapVersion::STATUS_CACHED);
        }

        return $version;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Service/CapturedMapVersionRecorderTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Service/CapturedMapVersionRecorder.php tests/Feature/Service/CapturedMapVersionRecorderTest.php
git commit -m "feat(map-archive): add CapturedMapVersionRecorder for served-but-different maps

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `CurrentServerMapResolver` + interface

**Files:**
- Create: `app/Service/CurrentServerMapResolverInterface.php`
- Create: `app/Service/CurrentServerMapResolver.php`
- Test: `tests/Feature/Service/CurrentServerMapResolverTest.php`

**Interfaces:**
- Consumes: `DdnetHttpSource::fetch(): DiscoveredServer[]` (each has `->addresses` of `DiscoveredAddress{ip,port,protocol}` and `->map`); `SixConnless::getInfo(string,string): string`, `SixConnless::parse(string): ?array{command,payload}`, `SixInfoCodec::parse(string $payload, string $command): ?SixInfoPacket` (`->hasHeader`, `->map`); `UdpTransport::send`, `UdpTransport::receive(int): ?array{ip,port,data}`; `FlavorClassifier::FLAVOR_DDNET|FLAVOR_VANILLA_06`.
- Produces: `CurrentServerMapResolverInterface::currentMapName(Server $server): ?string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Models\Server;
use App\Models\ServerAddress;
use App\Service\CurrentServerMapResolver;
use App\TwStats\Discovery\DdnetHttpSource;
use App\TwStats\Net\UdpTransport;
use App\TwStats\Protocol\Six\SixConnless;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrentServerMapResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_ddnet_server_resolves_from_the_master_snapshot_by_address(): void
    {
        Http::fake(['https://a/servers.json' => Http::response(file_get_contents(base_path('tests/Fixtures/ddnet_servers.json')), 200)]);

        $server = Server::factory()->create(['flavor' => 'ddnet']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '192.0.2.10', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $server->load('addresses');

        $resolver = new CurrentServerMapResolver(ddnetSource: new DdnetHttpSource(mirrors: ['https://a/servers.json']));

        self::assertSame('Multeasymap', $resolver->currentMapName($server));
    }

    public function test_ddnet_server_not_in_master_returns_null(): void
    {
        Http::fake(['https://a/servers.json' => Http::response(file_get_contents(base_path('tests/Fixtures/ddnet_servers.json')), 200)]);

        $server = Server::factory()->create(['flavor' => 'ddnet']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '203.0.113.99', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $server->load('addresses');

        $resolver = new CurrentServerMapResolver(ddnetSource: new DdnetHttpSource(mirrors: ['https://a/servers.json']));

        self::assertNull($resolver->currentMapName($server));
    }

    public function test_vanilla_06_server_resolves_from_a_connless_gie3_reply(): void
    {
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '198.51.100.7', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $server->load('addresses');

        // a transport that replies to the gie3 request with a scripted inf3 packet announcing "dm2"
        $transport = new class implements UdpTransport {
            public bool $sent = false;
            public function send(string $ip, int $port, string $data): void { $this->sent = true; }
            public function receive(int $timeoutMs): ?array
            {
                if (!$this->sent) { return null; }
                $this->sent = false; // one reply
                return ['ip' => '198.51.100.7', 'port' => 8303, 'data' => self::inf3('dm2')];
            }

            /** Build a connless inf3 reply: 6-byte framing, \xff\xff\xff\xff + "inf3", then the
             *  NUL-terminated fields token,version,name,map,gametype,... — we only need up to map. */
            private static function inf3(string $map): string
            {
                $framing = str_repeat("\xff", 6);
                $command = "\xff\xff\xff\xff" . 'inf3';
                $fields = implode("\x00", ['1', '0.6.4', 'Test Server', $map, 'DM', '0', '0', '16', '16']) . "\x00";
                return $framing . $command . $fields;
            }
        };

        $resolver = new CurrentServerMapResolver(transport: $transport);

        self::assertSame('dm2', $resolver->currentMapName($server));
    }

    public function test_unsupported_flavor_returns_null(): void
    {
        $server = Server::factory()->create(['flavor' => 'vanilla_07']);
        $server->load('addresses');

        self::assertNull((new CurrentServerMapResolver())->currentMapName($server));
    }
}
```

> Note: confirm the inf3 field order/offsets against `SixInfoCodec::parseHeaderPacket` while implementing; adjust the scripted `inf3()` token/version/name ordering if the codec expects a different leading layout. The test must drive the real codec.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Service/CurrentServerMapResolverTest.php`
Expected: FAIL with `Class "App\Service\CurrentServerMapResolver" not found`

- [ ] **Step 3: Create the interface**

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Server;

/**
 * Resolves a server's CURRENT master-advertised map name via the channel that originally produced it
 * (DDNet HTTP master for ddnet, connless info for 0.6/0.7), or null when it cannot be determined.
 * Used to confirm a "phantom" version before throttling it.
 */
interface CurrentServerMapResolverInterface
{
    public function currentMapName(Server $server): ?string;
}
```

- [ ] **Step 4: Create the resolver**

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Server;
use App\TwStats\Discovery\DdnetHttpSource;
use App\TwStats\Discovery\FlavorClassifier;
use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Net\UdpTransport;
use App\TwStats\Protocol\Six\SixConnless;
use App\TwStats\Protocol\Six\SixInfoCodec;

/**
 * Live current-map lookup. Channels must match how each name was discovered: a DDNet server's name in
 * our DB comes from the HTTP master (a connless query to the same host can report a different map — an
 * MMO/city server answers gie3 with a hub name while the master lists its public name), so ddnet
 * resolves from a memoized master snapshot and only 0.6 uses a direct connless query. Any failure
 * returns null so the caller falls back to the freshest ingested record.
 */
final class CurrentServerMapResolver implements CurrentServerMapResolverInterface
{
    /** @var array<string, string>|null "ip:port" => current map name, from the DDNet master */
    private ?array $ddnetSnapshot = null;

    private float $snapshotAtMs = 0.0;

    public function __construct(
        private readonly DdnetHttpSource $ddnetSource = new DdnetHttpSource(),
        private readonly UdpTransport $transport = new SocketUdpTransport(),
        private readonly int $snapshotTtlMs = 120_000,
        private readonly int $connlessTimeoutMs = 1_500,
    ) {
    }

    public function currentMapName(Server $server): ?string
    {
        try {
            return match ($server->getAttribute('flavor')) {
                FlavorClassifier::FLAVOR_DDNET => $this->fromDdnetMaster($server),
                FlavorClassifier::FLAVOR_VANILLA_06 => $this->fromConnless06($server),
                default => null, // 0.7 / unknown → caller falls back to the ingested record
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromDdnetMaster(Server $server): ?string
    {
        $snapshot = $this->ddnetSnapshot();
        foreach ($server->addresses as $address) {
            $key = $address->ip . ':' . $address->port;
            if (isset($snapshot[$key])) {
                return $snapshot[$key];
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function ddnetSnapshot(): array
    {
        $nowMs = hrtime(true) / 1_000_000;
        if ($this->ddnetSnapshot !== null && ($nowMs - $this->snapshotAtMs) < $this->snapshotTtlMs) {
            return $this->ddnetSnapshot;
        }

        $snapshot = [];
        foreach ($this->ddnetSource->fetch() as $discovered) {
            foreach ($discovered->addresses as $address) {
                $snapshot[$address->ip . ':' . $address->port] = $discovered->map;
            }
        }

        $this->ddnetSnapshot = $snapshot;
        $this->snapshotAtMs = $nowMs;

        return $snapshot;
    }

    private function fromConnless06(Server $server): ?string
    {
        $address = $server->addresses->firstWhere('protocol', 6) ?? $server->addresses->first();
        if ($address === null) {
            return null;
        }

        $this->transport->send((string) $address->ip, (int) $address->port, SixConnless::getInfo("\x01", "\x01"));

        $codec = new SixInfoCodec();
        $deadlineMs = hrtime(true) / 1_000_000 + $this->connlessTimeoutMs;
        while (hrtime(true) / 1_000_000 < $deadlineMs) {
            $packet = $this->transport->receive(300);
            if ($packet === null) {
                continue;
            }
            $parsed = SixConnless::parse($packet['data']);
            if ($parsed === null) {
                continue;
            }
            $info = $codec->parse($parsed['payload'], $parsed['command']);
            if ($info !== null && $info->hasHeader && $info->map !== null) {
                return $info->map;
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Service/CurrentServerMapResolverTest.php`
Expected: PASS (adjust the scripted `inf3()` layout per the codec if the 0.6 case fails to parse)

- [ ] **Step 6: Commit**

```bash
git add app/Service/CurrentServerMapResolverInterface.php app/Service/CurrentServerMapResolver.php tests/Feature/Service/CurrentServerMapResolverTest.php
git commit -m "feat(map-archive): add CurrentServerMapResolver for phantom confirmation

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `served_other_version` throttle in the listener

**Files:**
- Modify: `app/Listeners/ApplyMapAcquisitionState.php`
- Modify: `app/Models/MapAcquisitionAttempt.php` (vocabulary docblock)
- Test: `tests/Feature/MapAcquisitionStateTest.php` (add two tests)

**Interfaces:**
- Consumes: `MapAcquisitionAttempted($mapVersionId, $serverId, $source, $outcome, $reason, $bytes)`.
- Produces: a `udp`/`udp_fallback` failure with reason `served_other_version` stamps the requested `MapVersion.last_acquire_attempt_at` and creates no `ServerMapBackoff`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MapAcquisitionStateTest.php` (match the file's existing namespace/imports; add `use App\Models\ServerMapBackoff;` and `use App\Models\MapVersion;`/`Map` if not present):

```php
    public function test_served_other_version_throttles_the_requested_version_without_a_backoff(): void
    {
        $map = Map::create(['name' => 'Multiworld']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);
        $server = Server::factory()->create(['flavor' => 'ddnet']);

        \App\Events\MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'failure', 'served_other_version', null);

        self::assertNotNull($version->fresh()->last_acquire_attempt_at, 'the requested version is throttled');
        self::assertSame(0, ServerMapBackoff::where('server_id', $server->id)->count(), 'a served-other host is healthy — no backoff');
    }

    public function test_plain_crc_mismatch_still_penalises_nothing(): void
    {
        $map = Map::create(['name' => 'dm1']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);

        \App\Events\MapAcquisitionAttempted::dispatch($version->id, $server->id, 'udp', 'failure', 'crc_mismatch', null);

        self::assertNull($version->fresh()->last_acquire_attempt_at);
        self::assertSame(0, ServerMapBackoff::where('server_id', $server->id)->count());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'served_other_version|plain_crc_mismatch' tests/Feature/MapAcquisitionStateTest.php`
Expected: FAIL — `test_served_other_version...` fails (`last_acquire_attempt_at` is null); the crc test passes already.

- [ ] **Step 3: Add the throttle branch**

In `app/Listeners/ApplyMapAcquisitionState.php`, inside the UDP block, add a branch for the new reason (place it alongside the existing `no_hosts` branch):

```php
            if (in_array($e->source, self::UDP_SOURCES, true)) {
                if (in_array($e->reason, self::HOST_HEALTH_REASONS, true) && $e->serverId && $mapId) {
                    ServerMapBackoff::recordFailure($e->serverId, $mapId);
                } elseif ($e->reason === 'no_hosts') {
                    $version?->markStatus(MapVersion::STATUS_UNAVAILABLE);
                } elseif ($e->reason === 'served_other_version' && $version) {
                    // The host served a different self-consistent map than this version (we captured it
                    // under its own name). The host is healthy, so do NOT back off the (host, map) pair;
                    // just cool this version down so the filler stops re-pulling the wrong bytes every
                    // pass. Query-builder stamp so updated_at (the recheck recency signal) is untouched.
                    MapVersion::whereKey($version->getKey())->toBase()->update(['last_acquire_attempt_at' => now()]);
                }
                // any other reason (name_mismatch / crc_mismatch / not_datafile) → the host has simply
                // moved on to a different map; the live-gate handles it, so penalise nothing.
            }
```

- [ ] **Step 4: Update the reason-vocabulary docblock**

In `app/Models/MapAcquisitionAttempt.php`, extend the closed-set line:

```php
 * Reason vocabulary (closed set): not_mirrored_404, timeout, connect_failed, name_mismatch, not_datafile,
 * sha_mismatch, no_hosts, http_error, download_failed, crc_mismatch, served_other_version.
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionStateTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Listeners/ApplyMapAcquisitionState.php app/Models/MapAcquisitionAttempt.php tests/Feature/MapAcquisitionStateTest.php
git commit -m "feat(map-archive): throttle the requested version on served_other_version

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Extract shared wire-message test trait

**Files:**
- Create: `tests/Feature/Connection/ScriptsMapDownloads.php`
- Modify: `tests/Feature/Connection/GameServerMapSourceHttpTest.php` (use the trait; delete its local copies of the four moved helpers)

**Interfaces:**
- Produces (trait methods): `toInt32(int): int`, `mapChange(string $name, int $crc, int $size): string`, `mapData(int $last, int $crc, int $chunk, string $data): string`, `connectionYielding(string ...$messages): ConnectionInterface`.

- [ ] **Step 1: Create the trait (copy the existing helper bodies verbatim)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Protocol\Seven\VariableInt;

/**
 * Builders for scripted 0.6 map-download wire messages, shared by the GameServerMapSource tests so the
 * two suites cannot drift on the framing.
 */
trait ScriptsMapDownloads
{
    /** Normalise to signed int32 — the wire representation the server packs CRCs in. */
    protected function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    protected function mapChange(string $name, int $crc, int $size): string
    {
        return VariableInt::pack((2 << 1) | 1)          // NETMSG_MAP_CHANGE = 2
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size);
    }

    protected function mapData(int $last, int $crc, int $chunk, string $data): string
    {
        return VariableInt::pack((3 << 1) | 1)          // NETMSG_MAP_DATA = 3
            . VariableInt::pack($last)
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($chunk)
            . VariableInt::pack(strlen($data))
            . $data;
    }

    /** A connection whose single receive() batch carries the given pre-built messages. */
    protected function connectionYielding(string ...$messages): ConnectionInterface
    {
        return new class($messages) implements ConnectionInterface {
            public bool $disconnected = false;
            /** @var array<int, string[]> */
            private array $inbox;

            /** @param string[] $batch */
            public function __construct(array $batch)
            {
                $this->inbox = [$batch];
            }

            public function sendVital(string $message): void {}
            public function flush(): void {}
            public function receive(int $timeoutMs): array { return $this->inbox === [] ? [] : array_shift($this->inbox); }
            public function disconnect(): void { $this->disconnected = true; }
            public function isOnline(): bool { return !$this->disconnected; }
        };
    }
}
```

- [ ] **Step 2: Use the trait in `GameServerMapSourceHttpTest`**

Add `use ScriptsMapDownloads;` inside the class body, and **delete** the now-duplicated `toInt32`, `mapChange`, `mapData`, and `connectionYielding` methods from that file. Leave `fakeConnection`, `mapDetails`, `sourceReturning`, and `sourceDialling` in place.

- [ ] **Step 3: Run the HTTP test to verify parity**

Run: `vendor/bin/phpunit tests/Feature/Connection/GameServerMapSourceHttpTest.php`
Expected: PASS (no behavior change)

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Connection/ScriptsMapDownloads.php tests/Feature/Connection/GameServerMapSourceHttpTest.php
git commit -m "test(map-archive): extract shared map-download wire builders into a trait

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Capture + phantom-gate routing in `GameServerMapSource`

**Files:**
- Modify: `app/Service/GameServerMapSource.php`
- Modify: `tests/Feature/Connection/GameServerMapSourceHttpTest.php` (remove the obsolete caller-crc-mismatch test)
- Test: `tests/Feature/Connection/GameServerMapSourceCaptureTest.php` (new)

**Interfaces:**
- Consumes: `CapturedMapVersionRecorder::capture(...)` (Task 3); `CurrentServerMapResolverInterface::currentMapName(...)` (Task 4); `MapCachePath::forIdentity(...)` (Task 2); `Server`, `ServerHistory`, `MapAcquisitionAttempted`, `nameMatches()` (Task 1).
- Produces: new constructor params `capturer: ?CapturedMapVersionRecorder`, `mapResolver: ?CurrentServerMapResolverInterface` (both nullable, lazily defaulted); unchanged `downloadFromHost(...)`/`fetchVersion(...)` return contracts.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\ServerHistory;
use App\Service\CapturedMapVersionRecorder;
use App\Service\CurrentServerMapResolverInterface;
use App\Service\GameServerMapSource;
use App\Service\MapCachePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GameServerMapSourceCaptureTest extends TestCase
{
    use RefreshDatabase;
    use ScriptsMapDownloads;

    /** A resolver stub that always reports the given current map name (or null). */
    private function resolver(?string $currentMap): CurrentServerMapResolverInterface
    {
        return new class($currentMap) implements CurrentServerMapResolverInterface {
            public function __construct(private readonly ?string $name) {}
            public function currentMapName(Server $server): ?string { return $this->name; }
        };
    }

    private function source(\App\TwStats\Connection\ConnectionInterface $conn, CurrentServerMapResolverInterface $resolver): GameServerMapSource
    {
        return new GameServerMapSource(
            connectionFactory: fn () => $conn,
            capturer: new CapturedMapVersionRecorder(disk: 'local'),
            mapResolver: $resolver,
        );
    }

    public function test_same_name_new_crc_is_captured_and_the_phantom_is_throttled(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $requested = MapVersion::create(['map_id' => $map->id, 'crc' => '64bbdea4']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);

        $payload = 'DATA' . str_repeat("\x00", 40);
        $servedCrc = crc32($payload);
        $servedCrcHex = sprintf('%08x', $servedCrc & 0xFFFFFFFF);
        $conn = $this->connectionYielding($this->mapChange('ctf4_old', $servedCrc, strlen($payload)), $this->mapData(1, $servedCrc, 0, $payload));

        // master still advertises ctf4_old → confirmed phantom
        $result = $this->source($conn, $this->resolver('ctf4_old'))
            ->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, (int) hexdec('64bbdea4'), 'ctf4_old', 5000, $server->id, $requested->id);

        self::assertNull($result, 'the requested version was not served');

        $served = MapVersion::where('map_id', $map->id)->where('crc', $servedCrcHex)->first();
        self::assertNotNull($served, 'the served version is recorded under the same map');
        self::assertSame(MapVersion::STATUS_CACHED, $served->acquisition_status);
        Storage::disk('local')->assertExists(MapCachePath::forIdentity('ctf4_old', $served->sha256, $servedCrcHex));

        self::assertNotNull($requested->fresh()->last_acquire_attempt_at, 'the phantom is throttled');
        $this->assertDatabaseHas('map_acquisition_attempts', ['map_version_id' => $requested->id, 'source' => 'udp', 'outcome' => 'failure', 'reason' => 'served_other_version']);
        $this->assertDatabaseHas('map_acquisition_attempts', ['map_version_id' => $served->id, 'source' => 'udp', 'outcome' => 'success']);
    }

    public function test_different_name_city_server_is_captured_under_its_real_name(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'Multiworld']);
        $requested = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);
        $server = Server::factory()->create(['flavor' => 'ddnet']);

        $payload = 'DATA' . str_repeat("\x02", 40);
        $crc = crc32($payload);
        $conn = $this->connectionYielding($this->mapChange('Gridania', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        $result = $this->source($conn, $this->resolver('Multiworld')) // master still says Multiworld → phantom
            ->downloadFromHost('9.9.9.9', 8303, 'ddnet', str_repeat('a', 64), null, 'Multiworld', 5000, $server->id, $requested->id);

        self::assertNull($result);
        $gridania = Map::where('name', 'Gridania')->first();
        self::assertNotNull($gridania, 'the served map is recorded under its real wire name');
        self::assertSame(1, MapVersion::where('map_id', $gridania->id)->where('acquisition_status', MapVersion::STATUS_CACHED)->count());
        self::assertNotNull($requested->fresh()->last_acquire_attempt_at);
    }

    public function test_served_other_but_server_moved_on_is_captured_without_a_throttle(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $requested = MapVersion::create(['map_id' => $map->id, 'crc' => '64bbdea4']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);

        $payload = 'DATA' . str_repeat("\x03", 40);
        $crc = crc32($payload);
        $conn = $this->connectionYielding($this->mapChange('ctf4_old', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        // master now reports a DIFFERENT map → the host genuinely cycled, not a phantom
        $result = $this->source($conn, $this->resolver('AnotherMap'))
            ->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, (int) hexdec('64bbdea4'), 'ctf4_old', 5000, $server->id, $requested->id);

        self::assertNull($result);
        self::assertNotNull(MapVersion::where('map_id', $map->id)->where('crc', sprintf('%08x', $crc & 0xFFFFFFFF))->first(), 'still captured');
        self::assertNull($requested->fresh()->last_acquire_attempt_at, 'a cycled host is not throttled');
        $this->assertDatabaseHas('map_acquisition_attempts', ['map_version_id' => $requested->id, 'reason' => 'crc_mismatch']);
    }

    public function test_resolver_failure_falls_back_to_the_freshest_record(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $requested = MapVersion::create(['map_id' => $map->id, 'crc' => '64bbdea4']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        $mod = Mod::firstOrCreate(['name' => 'DM']);
        $hist = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $server->id, 'map_id' => $map->id, 'map_version_id' => $requested->id, 'mod_id' => $mod->id, 'minutes' => 10]);
        DB::table('server_histories')->where('id', $hist->id)->update(['updated_at' => now()]);

        $payload = 'DATA' . str_repeat("\x04", 40);
        $crc = crc32($payload);
        $conn = $this->connectionYielding($this->mapChange('ctf4_old', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        // resolver returns null → fall back to the freshest server_histories row, which still references
        // the requested version → confirmed phantom → throttle.
        $result = $this->source($conn, $this->resolver(null))
            ->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, (int) hexdec('64bbdea4'), 'ctf4_old', 5000, $server->id, $requested->id);

        self::assertNull($result);
        self::assertNotNull($requested->fresh()->last_acquire_attempt_at);
    }

    public function test_exact_match_returns_bytes_and_does_not_consult_the_resolver(): void
    {
        Storage::fake('local');
        $map = Map::create(['name' => 'ctf4_old']);
        $payload = 'DATA' . str_repeat("\x05", 40);
        $crc = crc32($payload);
        $crcHex = sprintf('%08x', $crc & 0xFFFFFFFF);
        $requested = MapVersion::create(['map_id' => $map->id, 'crc' => $crcHex]);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);

        $conn = $this->connectionYielding($this->mapChange('ctf4_old', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        // resolver would throw if consulted — proves the happy path never reaches the gate
        $throwingResolver = new class implements CurrentServerMapResolverInterface {
            public function currentMapName(Server $server): ?string { throw new \RuntimeException('must not be called on exact match'); }
        };
        $source = new GameServerMapSource(connectionFactory: fn () => $conn, capturer: new CapturedMapVersionRecorder(disk: 'local'), mapResolver: $throwingResolver);

        $result = $source->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, (int) hexdec($crcHex), 'ctf4_old', 5000, $server->id, $requested->id);

        self::assertSame($payload, $result);
        $this->assertDatabaseHas('map_acquisition_attempts', ['map_version_id' => $requested->id, 'source' => 'udp', 'outcome' => 'success']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/Connection/GameServerMapSourceCaptureTest.php`
Expected: FAIL — `GameServerMapSource::__construct` has no `capturer`/`mapResolver` params yet (or the served-other path still emits `crc_mismatch`/returns the wrong thing).

- [ ] **Step 3: Add constructor params**

In `app/Service/GameServerMapSource.php`, extend the constructor (keep the existing `$timeoutMs` assignment):

```php
    public function __construct(
        private readonly mixed $connectionFactory = null,
        ?int $timeoutMs = null,
        private readonly ?CapturedMapVersionRecorder $capturer = null,
        private readonly ?CurrentServerMapResolverInterface $mapResolver = null,
    ) {
        $this->timeoutMs = $timeoutMs ?? ((int) env('MAP_UDP_TIMEOUT_S', 120)) * 1000;
    }
```

- [ ] **Step 4: Rewrite `runSession` for self-consistency-only download + routing**

Replace the body of `runSession(...)` (keep its signature) with:

```php
        $factory = $this->connectionFactory ?? $this->defaultFactory();
        $conn    = ($factory)($ip, $port, $flavor);

        // Self-consistency only: the session still verifies the bytes against the server-announced
        // crc/size (a corrupt or mid-download-cycled transfer fails there), but the caller-expectation
        // comparison happens HERE so a served-but-different self-consistent map can be captured under
        // its true name instead of thrown away.
        $result = MapDownloadSessionFactory::for($conn, $flavor)->downloadWithIdentity(
            expectedSha256: null,
            expectedCrc: null,
            timeoutMs: $timeoutMs,
            ignoreAdvertisedHttp: $ignoreAdvertisedHttp,
        );

        if (!$this->looksLikeMapDatafile($result->bytes)) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, $udpSource, 'failure', 'not_datafile', null);
            return null;
        }

        $servedSha    = hash('sha256', $result->bytes);
        $servedCrcHex = sprintf('%08x', crc32($result->bytes) & 0xFFFFFFFF);

        // Exactly the version we asked for → the normal happy path.
        if ($this->matchesRequest($result->name, $servedSha, $servedCrcHex, $expectedName, $expectedSha256, $expectedCrc)) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, $udpSource, 'success', null, strlen($result->bytes));
            return $result->bytes;
        }

        // A different, self-consistent map than requested. Capture it under its true wire name (the
        // bytes are a real map regardless of why we got them), then decide whether the requested
        // version is a confirmed phantom worth throttling.
        $served = $this->capturer()->capture($result->name, $result->bytes, $servedCrcHex);
        if ($served !== null) {
            MapAcquisitionAttempted::dispatch($served->getKey(), $serverId, $udpSource, 'success', null, strlen($result->bytes));
        }

        MapAcquisitionAttempted::dispatch(
            $mapVersionId,
            $serverId,
            $udpSource,
            'failure',
            $this->servedOtherReason($result->name, $expectedName, $serverId, $mapVersionId),
            null,
        );

        return null;
```

- [ ] **Step 5: Add the helper methods**

Add these private methods to `GameServerMapSource` (next to `nameMatches`):

```php
    /**
     * Whether the served map is exactly the version we requested: the names must match (case-insensitive)
     * and, when we hold a content key, the content must match it — sha256 if known, else crc. A keyless
     * map fetch has only the name to go on.
     */
    private function matchesRequest(string $wireName, string $servedSha, string $servedCrcHex, string $expectedName, ?string $expectedSha256, ?int $expectedCrc): bool
    {
        if (!$this->nameMatches($wireName, $expectedName)) {
            return false;
        }
        if ($expectedSha256 !== null) {
            return hash_equals(strtolower($expectedSha256), $servedSha);
        }
        if ($expectedCrc !== null) {
            return sprintf('%08x', $expectedCrc & 0xFFFFFFFF) === $servedCrcHex;
        }

        return true;
    }

    /**
     * Classify a served-other outcome for the requested version. A confirmed phantom (the server still
     * advertises the requested map in the current master state) earns the throttling reason; otherwise
     * the host has merely moved on, so we keep the descriptive no-penalty reason.
     */
    private function servedOtherReason(string $wireName, string $expectedName, ?int $serverId, ?int $mapVersionId): string
    {
        $movedOn = $this->nameMatches($wireName, $expectedName) ? 'crc_mismatch' : 'name_mismatch';

        if ($serverId === null || $mapVersionId === null) {
            return $movedOn; // no server/version context (direct call) → never throttle
        }

        return $this->isPhantom($serverId, $expectedName, $mapVersionId) ? 'served_other_version' : $movedOn;
    }

    /**
     * Whether the requested version is a phantom: the server STILL advertises the requested map in the
     * current master state (live re-fetch via the correct channel), or — when that re-fetch is
     * unavailable — its freshest ingested history row still references the requested version.
     */
    private function isPhantom(int $serverId, string $expectedName, int $mapVersionId): bool
    {
        $server = Server::with('addresses')->find($serverId);
        if ($server !== null) {
            $current = $this->mapResolver()->currentMapName($server);
            if ($current !== null) {
                return $this->nameMatches($current, $expectedName);
            }
        }

        $freshest = ServerHistory::where('server_id', $serverId)
            ->orderByDesc('updated_at')
            ->value('map_version_id');

        return $freshest !== null && (int) $freshest === $mapVersionId;
    }

    private function capturer(): CapturedMapVersionRecorder
    {
        return $this->capturer ?? new CapturedMapVersionRecorder();
    }

    private function mapResolver(): CurrentServerMapResolverInterface
    {
        return $this->mapResolver ?? new CurrentServerMapResolver();
    }
```

> `Server`, `ServerHistory` are already imported in this file; `CapturedMapVersionRecorder`, `CurrentServerMapResolver`, `CurrentServerMapResolverInterface`, and `MapCachePath` are in the same `App\Service` namespace (no `use` needed).

- [ ] **Step 6: Remove the obsolete caller-crc-mismatch test**

In `tests/Feature/Connection/GameServerMapSourceHttpTest.php`, delete `test_classifies_a_caller_crc_mismatch_as_crc_mismatch` — its scenario (name matches, crc differs) is now a capture, covered by `GameServerMapSourceCaptureTest`. (That HTTP test has no database and would now fault on the capture write.)

- [ ] **Step 7: Run the new + adjacent tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/Connection/GameServerMapSourceCaptureTest.php tests/Feature/Connection/GameServerMapSourceHttpTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Service/GameServerMapSource.php tests/Feature/Connection/GameServerMapSourceCaptureTest.php tests/Feature/Connection/GameServerMapSourceHttpTest.php
git commit -m "feat(map-archive): capture served-but-different UDP maps + gate phantom throttle

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full PHP suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all green)

- [ ] **Step 2: Run the linter (no front-end change expected, but CLAUDE.md requires it green)**

Run: `npm run lint`
Expected: no errors

- [ ] **Step 3: If anything failed, fix and re-run before proceeding.** Do not mark the plan complete with a red suite.

- [ ] **Step 4: Final review commit (only if fixes were needed)**

```bash
git add -A
git commit -m "test(map-archive): verification fixes for served-version capture

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review Notes

- **Spec coverage:** Mechanism 1 capture → Tasks 3, 7. Capture under true wire name → Task 7 (`capture($result->name, …)`). Self-consistency-only download → Task 7 Step 4. Phantom gate (live + record fallback, correct channel) → Tasks 4, 7. Throttle → Task 5. `MapCachePath` (no construction cycle) → Task 2. Telemetry vocabulary → Task 5. Tests for every shape (same-name, different-name, cycled/no-throttle, resolver-fallback, happy path) → Task 7; resolver + listener + path units → Tasks 2, 4, 5.
- **Out of scope (per spec), intentionally not implemented:** aliasing; permanent observation-based suppression; snapshot/MOTD/timer parsing; 0.7 live resolution (falls back to record); the `MapAvailableViaHttp` advertised-different-name path is left unchanged.
- **Type consistency:** `capture(string,string,?string): ?MapVersion`, `currentMapName(Server): ?string`, `MapCachePath::forIdentity(string,?string,?string): ?string`, and the `served_other_version` reason are used identically across producer and consumer tasks.
