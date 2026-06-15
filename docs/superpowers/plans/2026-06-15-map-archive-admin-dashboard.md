# Map Archive Admin Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A login-gated `/admin` dashboard showing map-archive stats (downloads, failures, failing servers), backed by a new `map_acquisition_attempts` log written from the acquisition sources via an event.

**Architecture:** A `MapAcquisitionAttempted` event is fired by `DdnetHttpMapSource` and `GameServerMapSource` at each acquisition outcome; an auto-discovered listener persists one `map_acquisition_attempts` row (the write is wrapped in try/catch so telemetry never breaks a download). A read-only `MapArchiveStats` service aggregates that table plus derived `map_versions`/storage counts; `Admin\DashboardController` renders them with Bootstrap cards/tables and a Chart.js graph. Rows prune at 30 days.

**Tech Stack:** Laravel 13 (Eloquent, events/listeners auto-discovery, `Prunable`), Blade, Bootstrap 5.3, Chart.js 4 (via the existing `window.Chart` global), PHPUnit + SQLite `:memory:`.

**Conventions to honor:** comments explain *why* (present tense); commit only the listed files (never `git add -A`); end commit messages with the `Co-Authored-By` trailer; run `vendor/bin/phpunit` after each task. No front-end TS changes here, so `npm run` gates do not apply. The cache disk is `local` (`storage/app/private/maps`). Existing source tests pass `downloadFromHost(...)` with its current 7-arg signature, so new params there MUST be optional and trailing.

---

## File Structure

**Create:**
- `database/migrations/2026_06_15_000000_create_map_acquisition_attempts.php` — the log table.
- `app/Models/MapAcquisitionAttempt.php` — append-only model, `Prunable`, relations.
- `app/Events/MapAcquisitionAttempted.php` — data-carrier event.
- `app/Listeners/RecordMapAcquisitionAttempt.php` — persists a row (swallows throwables).
- `app/Service/MapArchiveStats.php` — read-only aggregation service.
- `app/Http/Controllers/Admin/DashboardController.php` — thin controller.
- `resources/views/admin/dashboard.blade.php` — the dashboard view.
- Tests: `tests/Feature/MapAcquisitionRecordingTest.php`, `tests/Feature/MapArchiveStatsTest.php`, `tests/Feature/AdminDashboardTest.php`, `tests/Feature/MapAcquisitionPruneTest.php`.

**Modify:**
- `app/Service/DdnetHttpMapSource.php` — fire `http_mirror` events.
- `app/Service/GameServerMapSource.php` — fire `udp`/`http_fastdl`/`no_hosts` events.
- `routes/web.php` — the `auth`-gated `/admin` route group.
- `resources/views/layouts/app.blade.php` — `@auth` sidebar link.
- `bootstrap/app.php` — schedule `model:prune`.

---

## Task 1: Migration + MapAcquisitionAttempt model

**Files:**
- Create: `database/migrations/2026_06_15_000000_create_map_acquisition_attempts.php`
- Create: `app/Models/MapAcquisitionAttempt.php`
- Test: `tests/Feature/MapAcquisitionModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapAcquisitionModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapAcquisitionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_an_attempt_with_relations_and_created_at_only(): void
    {
        $map = Map::create(['name' => 'Kobra']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = Server::factory()->create();

        $attempt = MapAcquisitionAttempt::create([
            'map_version_id' => $version->id,
            'server_id' => $server->id,
            'source' => 'udp',
            'outcome' => 'failure',
            'reason' => 'timeout',
            'bytes' => null,
        ]);

        $this->assertNotNull($attempt->fresh()->created_at);
        $this->assertNull($attempt->updated_at, 'append-only: no updated_at');
        $this->assertSame('Kobra', $attempt->mapVersion->map->name);
        $this->assertSame($server->id, $attempt->server->id);
    }

    public function test_allows_null_version_and_server(): void
    {
        // legacy fetch(Map) and no-host cases carry null context but are still valid rows
        $attempt = MapAcquisitionAttempt::create([
            'map_version_id' => null,
            'server_id' => null,
            'source' => 'http_mirror',
            'outcome' => 'failure',
            'reason' => 'not_mirrored_404',
        ]);

        $this->assertDatabaseCount('map_acquisition_attempts', 1);
        $this->assertNull($attempt->fresh()->server_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionModelTest.php`
Expected: FAIL — `Class "App\Models\MapAcquisitionAttempt" not found` (and no `map_acquisition_attempts` table).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_15_000000_create_map_acquisition_attempts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only telemetry: one row per acquisition attempt against one channel, so the admin
        // dashboard can show which versions downloaded, which failed, and which servers fail to serve.
        // map_version_id/server_id are nullable: the HTTP-mirror channel has no server, and the legacy
        // fetch(Map) / no-host cases have no version. created_at only — rows are never updated.
        Schema::create('map_acquisition_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('map_version_id')->nullable();
            $table->unsignedInteger('server_id')->nullable();
            $table->string('source', 16);   // http_mirror | udp | http_fastdl
            $table->string('outcome', 8);   // success | failure
            $table->string('reason', 32)->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['server_id', 'outcome']);
            $table->index(['outcome', 'created_at']);
            $table->index('source');
            $table->foreign('map_version_id')->references('id')->on('map_versions')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_acquisition_attempts');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/MapAcquisitionAttempt.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One recorded acquisition attempt against one channel (http_mirror | udp | http_fastdl). Append-only
 * (created_at only); written by App\Listeners\RecordMapAcquisitionAttempt off the MapAcquisitionAttempted
 * event the acquisition sources fire. Drives the map-archive admin dashboard.
 *
 * Reason vocabulary (closed set): not_mirrored_404, timeout, connect_failed, name_mismatch, not_datafile,
 * sha_mismatch, no_hosts, http_error, download_failed.
 */
class MapAcquisitionAttempt extends Model
{
    use Prunable;

    public const UPDATED_AT = null; // append-only: there is no updated_at column

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function mapVersion()
    {
        return $this->belongsTo(MapVersion::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    /** Keep the log bounded — telemetry older than 30 days is pruned by the scheduled model:prune. */
    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(30));
    }
}
```

- [ ] **Step 5: Run migration and test**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionModelTest.php`
Expected: PASS (2 tests). RefreshDatabase runs the migration against SQLite `:memory:` automatically.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_15_000000_create_map_acquisition_attempts.php app/Models/MapAcquisitionAttempt.php tests/Feature/MapAcquisitionModelTest.php
git commit -m "feat(admin): map_acquisition_attempts log table + model

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Event + Listener (recording infrastructure)

**Files:**
- Create: `app/Events/MapAcquisitionAttempted.php`
- Create: `app/Listeners/RecordMapAcquisitionAttempt.php`
- Test: `tests/Feature/MapAcquisitionRecordingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapAcquisitionRecordingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Events\MapAcquisitionAttempted;
use App\Models\Map;
use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapAcquisitionRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatching_the_event_writes_a_row(): void
    {
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('a', 64)]);

        // auto-discovered listener persists the row (Laravel 11+ registers app/Listeners by handle() type-hint)
        MapAcquisitionAttempted::dispatch($version->id, null, 'http_mirror', 'success', null, 4096);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id,
            'server_id' => null,
            'source' => 'http_mirror',
            'outcome' => 'success',
            'bytes' => 4096,
        ]);
    }

    public function test_listener_swallows_a_write_failure(): void
    {
        // a bogus server_id violates the FK; the listener must catch it so a download is never broken
        $listener = new \App\Listeners\RecordMapAcquisitionAttempt();
        $listener->handle(new MapAcquisitionAttempted(null, 999999, 'udp', 'failure', 'timeout', null));

        $this->assertDatabaseCount('map_acquisition_attempts', 0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionRecordingTest.php`
Expected: FAIL — `Class "App\Events\MapAcquisitionAttempted" not found`.

- [ ] **Step 3: Write the event**

Create `app/Events/MapAcquisitionAttempted.php`:

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by the map acquisition sources at each attempt outcome. Carries only scalars so it is cheap and
 * has no DB coupling; App\Listeners\RecordMapAcquisitionAttempt turns it into a map_acquisition_attempts row.
 */
class MapAcquisitionAttempted
{
    use Dispatchable;

    public function __construct(
        public readonly ?int $mapVersionId,
        public readonly ?int $serverId,
        public readonly string $source,   // http_mirror | udp | http_fastdl
        public readonly string $outcome,  // success | failure
        public readonly ?string $reason = null,
        public readonly ?int $bytes = null,
    ) {
    }
}
```

- [ ] **Step 4: Write the listener**

Create `app/Listeners/RecordMapAcquisitionAttempt.php`:

```php
<?php

namespace App\Listeners;

use App\Events\MapAcquisitionAttempted;
use App\Models\MapAcquisitionAttempt;

/**
 * Persists each MapAcquisitionAttempted as a row. The whole body is guarded: telemetry must never throw
 * into the acquisition path, so a logging failure (e.g. a transient DB error) is swallowed, not propagated.
 */
class RecordMapAcquisitionAttempt
{
    public function handle(MapAcquisitionAttempted $event): void
    {
        try {
            MapAcquisitionAttempt::create([
                'map_version_id' => $event->mapVersionId,
                'server_id' => $event->serverId,
                'source' => $event->source,
                'outcome' => $event->outcome,
                'reason' => $event->reason,
                'bytes' => $event->bytes,
            ]);
        } catch (\Throwable) {
            // never let telemetry break a download
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionRecordingTest.php`
Expected: PASS (2 tests). If `test_dispatching_the_event_writes_a_row` fails with 0 rows, listener auto-discovery is off — add to `bootstrap/app.php`'s `->withEvents(discover: ...)` is NOT needed in Laravel 11+; instead confirm the listener lives in `app/Listeners` and `handle()` type-hints the event (it does). As a fallback, register explicitly in a `boot()`: `Event::listen(MapAcquisitionAttempted::class, RecordMapAcquisitionAttempt::class);` in `app/Providers/AppServiceProvider.php`.

- [ ] **Step 6: Commit**

```bash
git add app/Events/MapAcquisitionAttempted.php app/Listeners/RecordMapAcquisitionAttempt.php tests/Feature/MapAcquisitionRecordingTest.php
git commit -m "feat(admin): event + listener that records acquisition attempts

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Instrument DdnetHttpMapSource (http_mirror outcomes)

**Files:**
- Modify: `app/Service/DdnetHttpMapSource.php`
- Test: `tests/Feature/MapAcquisitionRecordingTest.php` (add cases)

Current `download()` is `private function download(string $name, ?string $sha256, ?int $size): ?string` and is called by `fetch(Map)` and `fetchVersion(MapVersion)`. Thread a nullable `$mapVersionId` through it and fire events at each outcome. Skip the "no sha256" early return (not an attempt).

- [ ] **Step 1: Write the failing test (append to MapAcquisitionRecordingTest)**

Add these methods to `tests/Feature/MapAcquisitionRecordingTest.php` (and add the imports `use App\Models\MapVersion;` already present, plus `use App\Service\DdnetHttpMapSource;` and `use Illuminate\Support\Facades\Http;`):

```php
    public function test_http_mirror_success_is_recorded(): void
    {
        $bytes = 'DATA' . str_repeat("\x00", 64);
        $sha = hash('sha256', $bytes);
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => $sha, 'size' => strlen($bytes)]);

        Http::fake(['maps.ddnet.org/*' => Http::response($bytes, 200)]);

        (new DdnetHttpMapSource())->fetchVersion($version);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => null,
            'source' => 'http_mirror', 'outcome' => 'success', 'bytes' => strlen($bytes),
        ]);
    }

    public function test_http_mirror_404_is_recorded_as_failure(): void
    {
        $map = Map::create(['name' => 'Aim']);
        $version = MapVersion::create(['map_id' => $map->id, 'sha256' => str_repeat('b', 64)]);

        Http::fake(['maps.ddnet.org/*' => Http::response('nope', 404)]);

        (new DdnetHttpMapSource())->fetchVersion($version);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'source' => 'http_mirror',
            'outcome' => 'failure', 'reason' => 'not_mirrored_404',
        ]);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionRecordingTest.php`
Expected: FAIL — no rows recorded (the source does not yet fire events).

- [ ] **Step 3: Modify `download()` to thread the version id and fire events**

In `app/Service/DdnetHttpMapSource.php`, add the import at the top (after the existing `use Illuminate\Support\Facades\Http;`):

```php
use App\Events\MapAcquisitionAttempted;
```

Replace the two callers and the `download()` body. New `fetch()` / `fetchVersion()`:

```php
    public function fetch(Map $map): ?string
    {
        return $this->download(
            name: (string) $map->getAttribute('name'),
            sha256: $map->getAttribute('sha256'),
            size: $map->getAttribute('size'),
            mapVersionId: null,
        );
    }

    public function fetchVersion(MapVersion $version): ?string
    {
        return $this->download(
            name: (string) $version->map->getAttribute('name'),
            sha256: $version->getAttribute('sha256'),
            size: $version->getAttribute('size'),
            mapVersionId: (int) $version->getAttribute('id'),
        );
    }
```

New `download()` (fires `http_mirror` events; the missing-sha256 case is not an attempt so it stays silent):

```php
    private function download(string $name, ?string $sha256, ?int $size, ?int $mapVersionId): ?string
    {
        if ($sha256 === null) {
            return null;
        }

        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($name) . '_' . $sha256 . '.map';

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable) {
            $this->record($mapVersionId, 'failure', 'http_error', null);
            return null;
        }
        if (!$response->successful()) {
            $reason = $response->status() === 404 ? 'not_mirrored_404' : 'http_error';
            $this->record($mapVersionId, 'failure', $reason, null);
            return null;
        }

        $body = $response->body();
        // both a wrong length and a wrong hash mean "the bytes did not verify" — one reason code
        if (($size !== null && strlen($body) !== $size) || hash('sha256', $body) !== strtolower($sha256)) {
            $this->record($mapVersionId, 'failure', 'sha_mismatch', null);
            return null;
        }

        $this->record($mapVersionId, 'success', null, strlen($body));

        return $body;
    }

    /** Fire one http_mirror telemetry event (server is always null — the mirror is not a game server). */
    private function record(?int $mapVersionId, string $outcome, ?string $reason, ?int $bytes): void
    {
        MapAcquisitionAttempted::dispatch($mapVersionId, null, 'http_mirror', $outcome, $reason, $bytes);
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionRecordingTest.php`
Expected: PASS (now 4 tests).

- [ ] **Step 5: Run the existing acquisition tests to confirm no regression**

Run: `vendor/bin/phpunit tests/Feature/MapFileAcquisitionTest.php tests/Feature/MapFileStoreVersionTest.php`
Expected: PASS. (These now also fire events; the listener writes harmless rows. If any test asserts a strict whole-DB count, relax it to the table it means.)

- [ ] **Step 6: Commit**

```bash
git add app/Service/DdnetHttpMapSource.php tests/Feature/MapAcquisitionRecordingTest.php
git commit -m "feat(admin): record http-mirror acquisition outcomes

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Instrument GameServerMapSource (udp / http_fastdl / no_hosts)

**Files:**
- Modify: `app/Service/GameServerMapSource.php`
- Test: `tests/Feature/MapAcquisitionRecordingTest.php` (add cases)

Add optional trailing context args to `downloadFromHost`, fire events at each outcome, thread `mapVersionId` through `downloadFromCandidates`, and fire a `no_hosts` failure when no candidate server exists. `ConnectionFailed` is distinguished from other throwables.

- [ ] **Step 1: Write the failing test (append to MapAcquisitionRecordingTest)**

Add imports `use App\Models\Mod;`, `use App\Models\Server;`, `use App\Models\ServerAddress;`, `use App\Models\ServerHistory;`, `use App\Service\GameServerMapSource;`, `use App\TwStats\Connection\ConnectionInterface;`, `use Illuminate\Support\Facades\DB;`. Add:

```php
    public function test_udp_failure_is_recorded_per_server(): void
    {
        // a host that recently served the version, but the connection factory throws → udp failure row
        $map = Map::create(['name' => 'Kobra']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = Server::factory()->create(['flavor' => 'vanilla_06']);
        ServerAddress::factory()->create(['server_id' => $server->id, 'ip' => '10.0.0.9', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        $mod = Mod::firstOrCreate(['name' => 'DM']);
        $hist = ServerHistory::create(['weekday' => 1, 'hour' => 12, 'continuous' => true, 'server_id' => $server->id, 'map_id' => $map->id, 'map_version_id' => $version->id, 'mod_id' => $mod->id, 'minutes' => 10]);
        DB::table('server_histories')->where('id', $hist->id)->update(['updated_at' => now()]);

        $source = new GameServerMapSource(connectionFactory: function (): ConnectionInterface {
            throw new \App\TwStats\Connection\ConnectionFailed('refused');
        });
        $source->fetchVersion($version);

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => $server->id,
            'source' => 'udp', 'outcome' => 'failure', 'reason' => 'connect_failed',
        ]);
    }

    public function test_no_candidate_hosts_records_no_hosts(): void
    {
        $map = Map::create(['name' => 'Lonely']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'cafebabe']);

        (new GameServerMapSource())->fetchVersion($version); // no server_histories rows → no hosts

        $this->assertDatabaseHas('map_acquisition_attempts', [
            'map_version_id' => $version->id, 'server_id' => null,
            'source' => 'udp', 'outcome' => 'failure', 'reason' => 'no_hosts',
        ]);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionRecordingTest.php --filter "udp_failure|no_candidate"`
Expected: FAIL — no rows recorded.

- [ ] **Step 3: Modify `GameServerMapSource.php`**

Add the import near the top (after `use App\TwStats\Connection\MapDownloadSessionFactory;`):

```php
use App\Events\MapAcquisitionAttempted;
use App\TwStats\Connection\ConnectionFailed;
```

Change `fetchVersion()` and `fetch()` to pass the version id into `downloadFromCandidates` (new trailing arg):

```php
    public function fetch(Map $map): ?string
    {
        return $this->downloadFromCandidates(
            ServerHistory::where('map_id', $map->getAttribute('id')),
            $map->getAttribute('sha256'),
            $this->crcToInt32($map->getAttribute('crc')),
            (string) $map->getAttribute('name'),
            null,
        );
    }

    public function fetchVersion(MapVersion $version): ?string
    {
        return $this->downloadFromCandidates(
            ServerHistory::where('map_version_id', $version->getAttribute('id')),
            $version->getAttribute('sha256'),
            $this->crcToInt32($version->getAttribute('crc')),
            (string) $version->map->getAttribute('name'),
            (int) $version->getAttribute('id'),
        );
    }
```

Replace `downloadFromHost()` so it takes the optional context and fires events at every outcome (the public `?string` return is unchanged; new args are optional + trailing):

```php
    public function downloadFromHost(string $ip, int $port, string $flavor, ?string $expectedSha256, ?int $expectedCrc, string $expectedName, int $timeoutMs, ?int $serverId = null, ?int $mapVersionId = null): ?string
    {
        try {
            $factory = $this->connectionFactory ?? $this->defaultFactory();
            $conn    = ($factory)($ip, $port, $flavor);

            $result = MapDownloadSessionFactory::for($conn, $flavor)->downloadWithIdentity(
                expectedSha256: $expectedSha256,
                expectedCrc: $expectedCrc,
                timeoutMs: $timeoutMs,
            );

            // Name-match gate: a host that has since switched maps answers with a different name;
            // archiving its bytes here would mislabel them.
            if (trim($result->name) !== trim($expectedName)) {
                MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp', 'failure', 'name_mismatch', null);
                return null;
            }
            if (!$this->looksLikeMapDatafile($result->bytes)) {
                MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp', 'failure', 'not_datafile', null);
                return null;
            }

            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp', 'success', null, strlen($result->bytes));

            return $result->bytes;
        } catch (MapAvailableViaHttp $http) {
            // The server serves this map from its own HTTP mirror, not over UDP — fetch it there.
            $bytes = $this->fetchAdvertisedUrl($http, $expectedSha256, $expectedName);
            MapAcquisitionAttempted::dispatch(
                $mapVersionId, $serverId, 'http_fastdl',
                $bytes !== null ? 'success' : 'failure',
                $bytes !== null ? null : 'http_error',
                $bytes !== null ? strlen($bytes) : null,
            );
            return $bytes;
        } catch (ConnectionFailed) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp', 'failure', 'connect_failed', null);
            return null;
        } catch (\Throwable) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp', 'failure', 'download_failed', null);
            return null;
        }
    }
```

Replace `downloadFromCandidates()` to accept + thread the version id, pass `server_id` to `downloadFromHost`, and record `no_hosts`:

```php
    private function downloadFromCandidates(Builder $historyQuery, ?string $sha256, ?int $crc, string $name, ?int $mapVersionId): ?string
    {
        $serverIds = $historyQuery->getQuery()
            ->select('server_id')
            ->selectRaw('MAX(updated_at) as recent_at')
            ->groupBy('server_id')
            ->orderByDesc('recent_at')
            ->limit($this->maxHosts())
            ->pluck('server_id');

        if ($serverIds->isEmpty()) {
            // surface "we couldn't even find a host that serves this" as a distinct failure
            MapAcquisitionAttempted::dispatch($mapVersionId, null, 'udp', 'failure', 'no_hosts', null);
            return null;
        }

        $servers = Server::whereIn('id', $serverIds)->with('addresses')->get()->keyBy('id');

        foreach ($serverIds as $serverId) {
            $server = $servers->get($serverId);
            if ($server === null) {
                continue;
            }
            $host = $this->hostFor($server);
            if ($host === null) {
                continue;
            }
            $bytes = $this->downloadFromHost($host['ip'], $host['port'], $host['flavor'], $sha256, $crc, $name, $this->timeoutMs, (int) $serverId, $mapVersionId);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionRecordingTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Run the existing UDP-source tests for regressions**

Run: `vendor/bin/phpunit tests/Feature/MapFileAcquisitionTest.php tests/Feature/Connection/GameServerMapSourceHttpTest.php tests/Feature/InlineMapAcquisitionTest.php tests/Feature/MapArchiveBackfillTest.php`
Expected: PASS. (Direct `downloadFromHost(...)` calls in those tests use the original 7 args; the new optional args default to null, so they still compile and now fire null-context events — harmless.)

- [ ] **Step 6: Commit**

```bash
git add app/Service/GameServerMapSource.php tests/Feature/MapAcquisitionRecordingTest.php
git commit -m "feat(admin): record per-server udp/http-fastdl acquisition outcomes

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: MapArchiveStats aggregation service

**Files:**
- Create: `app/Service/MapArchiveStats.php`
- Test: `tests/Feature/MapArchiveStatsTest.php`

`DATE(created_at)` is used for day-bucketing — both MySQL and SQLite support it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapArchiveStatsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Models\Server;
use App\Service\MapArchiveStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MapArchiveStatsTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(array $attrs): void
    {
        $row = MapAcquisitionAttempt::create(array_merge([
            'source' => 'udp', 'outcome' => 'failure',
        ], $attrs));
        if (isset($attrs['created_at'])) {
            DB::table('map_acquisition_attempts')->where('id', $row->id)->update(['created_at' => $attrs['created_at']]);
        }
    }

    public function test_overview_counts_versions_and_cached_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('maps/A_aaaa.map', 'xx');
        Storage::disk('local')->put('maps/B_bbbb.map', 'yyyy');
        $map = Map::create(['name' => 'M']);
        MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        MapVersion::create(['map_id' => $map->id, 'crc' => 'feedface', 'last_acquire_attempt_at' => now()]);

        $o = (new MapArchiveStats('local'))->overview();

        $this->assertSame(2, $o['cachedFiles']);
        $this->assertSame(6, $o['cachedBytes']);
        $this->assertSame(2, $o['totalVersions']);
        $this->assertSame(1, $o['neverAttempted']);
        $this->assertSame(1, $o['coolingDown']);
    }

    public function test_downloads_per_day_is_zero_filled_and_counts_successes(): void
    {
        $this->attempt(['outcome' => 'success', 'created_at' => now()]);
        $this->attempt(['outcome' => 'success', 'created_at' => now()]);
        $this->attempt(['outcome' => 'failure', 'created_at' => now()]); // not counted

        $series = (new MapArchiveStats('local'))->downloadsPerDay(7);

        $this->assertCount(7, $series);
        $this->assertSame(2, $series[now()->toDateString()]);
        $this->assertSame(0, $series[now()->subDays(6)->toDateString()]);
    }

    public function test_failing_servers_ranks_by_failures_with_success_rate(): void
    {
        $busy = Server::factory()->create(['name' => 'Busy']);
        $okish = Server::factory()->create(['name' => 'Okish']);
        foreach (range(1, 3) as $_) { $this->attempt(['server_id' => $busy->id, 'outcome' => 'failure', 'reason' => 'timeout', 'created_at' => now()]); }
        $this->attempt(['server_id' => $okish->id, 'outcome' => 'failure', 'reason' => 'timeout', 'created_at' => now()]);
        $this->attempt(['server_id' => $okish->id, 'outcome' => 'success', 'created_at' => now()]);

        $servers = (new MapArchiveStats('local'))->failingServers(30, 10);

        $this->assertSame('Busy', $servers[0]['name']);
        $this->assertSame(3, $servers[0]['fails']);
        $this->assertSame('timeout', $servers[0]['lastReason']);
        $this->assertSame('Okish', $servers[1]['name']);
        $this->assertSame(50, (int) round($servers[1]['successRate']));
    }

    public function test_source_breakdown_counts_per_source(): void
    {
        $this->attempt(['source' => 'http_mirror', 'outcome' => 'success', 'created_at' => now()]);
        $this->attempt(['source' => 'http_mirror', 'outcome' => 'failure', 'created_at' => now()]);
        $this->attempt(['source' => 'udp', 'outcome' => 'failure', 'created_at' => now()]);

        $b = (new MapArchiveStats('local'))->sourceBreakdown(30);

        $this->assertSame(['success' => 1, 'failure' => 1], $b['http_mirror']);
        $this->assertSame(['success' => 0, 'failure' => 1], $b['udp']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/MapArchiveStatsTest.php`
Expected: FAIL — `Class "App\Service\MapArchiveStats" not found`.

- [ ] **Step 3: Write the service**

Create `app/Service/MapArchiveStats.php`:

```php
<?php

namespace App\Service;

use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Models\Server;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only aggregation for the map-archive admin dashboard. Every windowed method takes a $days span.
 * Day bucketing uses DATE(created_at), which both MySQL and SQLite support. Headline counts avoid an
 * O(versions) disk scan: cachedFiles/cachedBytes come from a single directory listing, the rest are
 * single COUNT queries.
 */
final class MapArchiveStats
{
    public function __construct(private readonly string $disk = 'local')
    {
    }

    /** @return array{cachedFiles:int, cachedBytes:int, totalVersions:int, neverAttempted:int, coolingDown:int} */
    public function overview(): array
    {
        $storage = Storage::disk($this->disk);
        $files = $storage->files('maps');
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += $storage->size($file);
        }

        return [
            'cachedFiles' => count($files),
            'cachedBytes' => $bytes,
            'totalVersions' => MapVersion::count(),
            'neverAttempted' => MapVersion::whereNull('last_acquire_attempt_at')->count(),
            'coolingDown' => MapVersion::where('last_acquire_attempt_at', '>=', now()->subHours($this->retryHours()))->count(),
        ];
    }

    /** @return array<string,int> ordered oldest→newest day => success count, zero-filled across the window */
    public function downloadsPerDay(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $counts = MapAcquisitionAttempt::query()
            ->where('outcome', 'success')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $series[$day] = (int) ($counts[$day] ?? 0);
        }

        return $series;
    }

    /** @return \Illuminate\Support\Collection<int, MapAcquisitionAttempt> latest successes with map+server loaded */
    public function recentDownloads(int $limit = 25)
    {
        return MapAcquisitionAttempt::with(['mapVersion.map', 'server'])
            ->where('outcome', 'success')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, MapAcquisitionAttempt> latest failures with map+server loaded */
    public function recentFailures(int $limit = 25)
    {
        return MapAcquisitionAttempt::with(['mapVersion.map', 'server'])
            ->where('outcome', 'failure')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Servers ranked by failed UDP/fastdl attempts in the window, with a success rate and last reason.
     *
     * @return array<int, array{id:int, name:string, address:string, fails:int, successRate:float, lastReason:?string, lastTriedAt:?string}>
     */
    public function failingServers(int $days = 30, int $limit = 20): array
    {
        $since = now()->subDays($days)->startOfDay();
        $rows = MapAcquisitionAttempt::query()
            ->whereNotNull('server_id')
            ->where('created_at', '>=', $since)
            ->selectRaw('server_id')
            ->selectRaw("SUM(CASE WHEN outcome = 'failure' THEN 1 ELSE 0 END) as fails")
            ->selectRaw("SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as oks")
            ->selectRaw('MAX(created_at) as last_at')
            ->groupBy('server_id')
            ->having('fails', '>', 0)
            ->orderByDesc('fails')
            ->limit($limit)
            ->get();

        $servers = Server::whereIn('id', $rows->pluck('server_id'))->get()->keyBy('id');

        return $rows->map(function ($row) use ($servers) {
            $server = $servers->get($row->server_id);
            $fails = (int) $row->fails;
            $oks = (int) $row->oks;
            $total = $fails + $oks;

            return [
                'id' => (int) $row->server_id,
                'name' => $server?->getAttribute('name') ?? '(deleted server)',
                'address' => $server ? $server->getAttribute('ip') . ':' . $server->getAttribute('port') : '',
                'fails' => $fails,
                'successRate' => $total > 0 ? round($oks / $total * 100, 1) : 0.0,
                'lastReason' => $this->lastReasonFor((int) $row->server_id),
                'lastTriedAt' => $row->last_at,
            ];
        })->all();
    }

    /** @return array<string, array{success:int, failure:int}> per-source success/failure tally in the window */
    public function sourceBreakdown(int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay();
        $rows = MapAcquisitionAttempt::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('source')
            ->selectRaw("SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as oks")
            ->selectRaw("SUM(CASE WHEN outcome = 'failure' THEN 1 ELSE 0 END) as fails")
            ->groupBy('source')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->source] = ['success' => (int) $row->oks, 'failure' => (int) $row->fails];
        }

        return $out;
    }

    private function lastReasonFor(int $serverId): ?string
    {
        return MapAcquisitionAttempt::where('server_id', $serverId)
            ->where('outcome', 'failure')
            ->latest('created_at')
            ->value('reason');
    }

    private function retryHours(): int
    {
        return (int) env('MAP_ACQUIRE_RETRY_HOURS', 6);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Feature/MapArchiveStatsTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Service/MapArchiveStats.php tests/Feature/MapArchiveStatsTest.php
git commit -m "feat(admin): MapArchiveStats aggregation service

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Route + Controller + nav (auth gate)

**Files:**
- Create: `app/Http/Controllers/Admin/DashboardController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/AdminDashboardTest.php`

The view is fully built in Task 7. This task wires routing/auth/nav and proves the gate. So the auth-gate test can pass now, the controller already renders `view('admin.dashboard', ...)` and we create a **minimal** `resources/views/admin/dashboard.blade.php` stub here (it only needs the "Map Archive" heading); Task 7 replaces that stub with the full dashboard.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AdminDashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Map Archive');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AdminDashboardTest.php`
Expected: FAIL — `/admin` 404s (no route).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Admin/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Service\MapArchiveStats;
use Illuminate\Contracts\View\View;

/**
 * The map-archive admin dashboard. Auth-gated (the app is admin-only, so any authenticated user is an
 * admin). Thin: it just hands MapArchiveStats output to the view.
 */
class DashboardController extends Controller
{
    public function index(MapArchiveStats $stats): View
    {
        $days = 30;

        return view('admin.dashboard', [
            'overview' => $stats->overview(),
            'downloadsPerDay' => $stats->downloadsPerDay($days),
            'recentDownloads' => $stats->recentDownloads(),
            'recentFailures' => $stats->recentFailures(),
            'failingServers' => $stats->failingServers($days),
            'sourceBreakdown' => $stats->sourceBreakdown($days),
            'days' => $days,
        ]);
    }
}
```

- [ ] **Step 4: Add the route group**

In `routes/web.php`, add the import at the top with the other controller imports:

```php
use App\Http\Controllers\Admin\DashboardController;
```

And add this group immediately before the `Auth::routes(['register' => false]);` line:

```php
// Admin area (auth-gated; the app is admin-only, so any authenticated user is an admin).
Route::middleware('auth')->prefix('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
});
```

- [ ] **Step 5: Create the minimal view stub**

Create `resources/views/admin/dashboard.blade.php` (expanded in Task 7 — this stub only needs to make the gate test pass):

```blade
@extends('layouts.app')

@section('content')
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Map Archive</h2>
        </div>
    </div>
@endsection
```

- [ ] **Step 6: Add the sidebar link**

In `resources/views/layouts/app.blade.php`, immediately before the `<span class="heading">Extras</span>` line, add:

```blade
            @auth
                <!-- Admin (only shown to logged-in admins) -->
                <span class="heading">Admin</span>
                <ul class="list-unstyled">
                    <li><a href="{{ route('admin.dashboard') }}"> <i class="fa fa-line-chart"></i>Map Archive </a></li>
                </ul>
            @endauth

```

- [ ] **Step 7: Run tests**

Run: `vendor/bin/phpunit tests/Feature/AdminDashboardTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/DashboardController.php routes/web.php resources/views/admin/dashboard.blade.php resources/views/layouts/app.blade.php tests/Feature/AdminDashboardTest.php
git commit -m "feat(admin): auth-gated /admin dashboard route, controller, nav link

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Dashboard view (cards, chart, tables)

**Files:**
- Modify: `resources/views/admin/dashboard.blade.php`
- Test: `tests/Feature/AdminDashboardTest.php` (add a content test)

- [ ] **Step 1: Write the failing test (append to AdminDashboardTest)**

Add imports `use App\Models\Map; use App\Models\MapAcquisitionAttempt; use App\Models\MapVersion; use App\Models\Server;` and this method:

```php
    public function test_dashboard_shows_failing_servers_and_sections(): void
    {
        $map = Map::create(['name' => 'Kobra']);
        $version = MapVersion::create(['map_id' => $map->id, 'crc' => 'deadbeef']);
        $server = Server::factory()->create(['name' => 'BadHost']);
        MapAcquisitionAttempt::create(['map_version_id' => $version->id, 'server_id' => $server->id, 'source' => 'udp', 'outcome' => 'failure', 'reason' => 'timeout']);

        $this->actingAs(\App\Models\User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Failing servers')
            ->assertSee('Downloads over time')
            ->assertSee('BadHost')
            ->assertSee('downloadsChart');
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/AdminDashboardTest.php --filter test_dashboard_shows_failing_servers_and_sections`
Expected: FAIL — the stub view shows none of these strings.

- [ ] **Step 3: Write the full view**

Replace `resources/views/admin/dashboard.blade.php` with:

```blade
@extends('layouts.app')

@section('content')
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Map Archive</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            {{-- Headline cards --}}
            <div class="row">
                @php
                    $cards = [
                        ['label' => 'Cached map files', 'value' => number_format($overview['cachedFiles'])],
                        ['label' => 'Cache size', 'value' => round($overview['cachedBytes'] / 1048576, 1) . ' MB'],
                        ['label' => 'Total versions', 'value' => number_format($overview['totalVersions'])],
                        ['label' => 'Never attempted', 'value' => number_format($overview['neverAttempted'])],
                        ['label' => 'Cooling down', 'value' => number_format($overview['coolingDown'])],
                    ];
                @endphp
                @foreach ($cards as $card)
                    <div class="col-lg col-md-4 col-6">
                        <div class="statistic-block block">
                            <div class="progress-details d-flex align-items-end justify-content-between">
                                <div class="title"><strong>{{ $card['label'] }}</strong></div>
                                <div class="number dashtext-1">{{ $card['value'] }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Downloads over time --}}
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Downloads over time (last {{ $days }} days)</strong></div>
                        <div class="block-body">
                            <canvas id="downloadsChart" height="80"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                {{-- Failing servers --}}
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Failing servers</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Server</th><th>Fails</th><th>Success rate</th><th>Last reason</th></tr></thead>
                                <tbody>
                                @forelse ($failingServers as $s)
                                    <tr>
                                        <td>{{ $s['name'] }}<br><small class="text-muted">{{ $s['address'] }}</small></td>
                                        <td>{{ $s['fails'] }}</td>
                                        <td>{{ $s['successRate'] }}%</td>
                                        <td>{{ $s['lastReason'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">No server failures recorded yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Source breakdown --}}
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">By source (last {{ $days }} days)</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Source</th><th>Success</th><th>Failure</th></tr></thead>
                                <tbody>
                                @forelse ($sourceBreakdown as $source => $counts)
                                    <tr><td>{{ $source }}</td><td>{{ $counts['success'] }}</td><td>{{ $counts['failure'] }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">No attempts recorded yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                {{-- Recent downloads --}}
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Recent downloads</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Map</th><th>Source</th><th>Size</th><th>When</th></tr></thead>
                                <tbody>
                                @forelse ($recentDownloads as $d)
                                    <tr>
                                        <td>{{ $d->mapVersion?->map?->name ?? '—' }}</td>
                                        <td>{{ $d->source }}</td>
                                        <td>{{ $d->bytes ? round($d->bytes / 1024) . ' KB' : '—' }}</td>
                                        <td>{{ $d->created_at?->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">No downloads recorded yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Recent failures --}}
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Recent failures</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Map</th><th>Source</th><th>Reason</th><th>Server</th><th>When</th></tr></thead>
                                <tbody>
                                @forelse ($recentFailures as $f)
                                    <tr>
                                        <td>{{ $f->mapVersion?->map?->name ?? '—' }}</td>
                                        <td>{{ $f->source }}</td>
                                        <td>{{ $f->reason }}</td>
                                        <td>{{ $f->server?->name ?? '—' }}</td>
                                        <td>{{ $f->created_at?->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted">No failures recorded yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            'use strict';
            Chart.defaults.color = '#75787c';

            // downloadsPerDay is an ordered {day: count} map from MapArchiveStats
            const series = {!! json_encode($downloadsPerDay) !!};
            new Chart(document.getElementById('downloadsChart'), {
                type: 'bar',
                data: {
                    labels: Object.keys(series),
                    datasets: [{
                        label: 'Successful downloads',
                        data: Object.values(series),
                        backgroundColor: 'rgba(113, 39, 172, 0.5)',
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: '#3f4145' } },
                        y: { beginAtZero: true, grid: { color: '#3f4145' }, ticks: { precision: 0 } },
                    },
                },
            });
        });
    </script>
@endsection
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Feature/AdminDashboardTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/dashboard.blade.php tests/Feature/AdminDashboardTest.php
git commit -m "feat(admin): map-archive dashboard view with chart + tables

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Retention — schedule model:prune

**Files:**
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/MapAcquisitionPruneTest.php`

The `Prunable` `prunable()` method (30 days) was defined in Task 1; this task wires the scheduled prune and proves it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapAcquisitionPruneTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MapAcquisitionAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MapAcquisitionPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_rows_older_than_thirty_days(): void
    {
        $old = MapAcquisitionAttempt::create(['source' => 'udp', 'outcome' => 'failure', 'reason' => 'timeout']);
        $fresh = MapAcquisitionAttempt::create(['source' => 'udp', 'outcome' => 'failure', 'reason' => 'timeout']);
        DB::table('map_acquisition_attempts')->where('id', $old->id)->update(['created_at' => now()->subDays(31)]);
        DB::table('map_acquisition_attempts')->where('id', $fresh->id)->update(['created_at' => now()->subDays(29)]);

        $this->artisan('model:prune', ['--model' => [MapAcquisitionAttempt::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('map_acquisition_attempts', ['id' => $old->id]);
        $this->assertDatabaseHas('map_acquisition_attempts', ['id' => $fresh->id]);
    }
}
```

- [ ] **Step 2: Run to verify it passes for pruning logic (schedule wiring is separate)**

Run: `vendor/bin/phpunit tests/Feature/MapAcquisitionPruneTest.php`
Expected: PASS — `Prunable` + `prunable()` from Task 1 already make `model:prune` work. (If it fails, confirm Task 1's `prunable()` method exists.)

- [ ] **Step 3: Schedule the daily prune**

In `bootstrap/app.php`, add the import at the top with the other use statements:

```php
use App\Models\MapAcquisitionAttempt;
```

And inside the `->withSchedule(function (Schedule $schedule): void { ... })` body, after the `queue:prune-failed` line, add:

```php
        // Keep the acquisition telemetry bounded (the model prunes rows older than 30 days).
        $schedule->command('model:prune', ['--model' => [MapAcquisitionAttempt::class]])->daily();
```

- [ ] **Step 4: Verify the schedule registered**

Run: `php artisan schedule:list`
Expected: a daily `php artisan model:prune --model=App\Models\MapAcquisitionAttempt` entry appears.

- [ ] **Step 5: Commit**

```bash
git add bootstrap/app.php tests/Feature/MapAcquisitionPruneTest.php
git commit -m "feat(admin): prune acquisition attempts older than 30 days

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Final verification

- [ ] **Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: all green (the prior 341 + the new tests). If any pre-existing acquisition test fails because it now also writes telemetry rows, fix by scoping its assertion to the table it actually means (not by suppressing events).

- [ ] **Manual smoke (optional, behind login)**

```bash
php artisan admin:create   # if no admin user exists yet
```
Then log in and open `/admin`; confirm the cards, chart, and tables render (tables show empty-state copy until the scrape/backfill records attempts).

---

## Notes for the implementer

- **No front-end TS changed** — `npm run type-check`/`lint` are not required for this work; the dashboard chart is an inline `@section('scripts')` block using the existing `window.Chart` global, exactly like `general.blade.php`.
- **Event auto-discovery:** Laravel 11+ auto-registers listeners in `app/Listeners` by their `handle()` type-hint — no `EventServiceProvider` edit needed. Task 2 Step 5 has the explicit-registration fallback if your install has discovery disabled.
- **Why instrument inside the sources** (not the acquirers): `InlineMapAcquirer`/`MapArchiveBackfiller` only see "did `ensureVersion` succeed" — they don't know which server was dialled or why a UDP attempt failed. Only the sources hold that context, so that's where the events fire.
- **Staging only listed files** (never `git add -A`): the repo has untracked reference dirs (`ddnet/`, `teeworlds/`, `.ddev/`, `map_peaks/`, etc.) that must never be committed.
```
