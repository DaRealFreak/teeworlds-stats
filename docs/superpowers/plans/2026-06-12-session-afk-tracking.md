# Session AFK Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Accumulate per-session AFK time and surface it on the player page as an exact "X% afk (Yh) on servers tracking idle status" stat plus per-session annotations.

**Architecture:** `player_sessions` gains two counters — `afk_minutes` (observed idle) and `afk_tracked_minutes` (time the source reported idle status at all). `SessionRecorder` accrues both each scrape from the observation's `afk` (true → both, false → tracked only, null → neither). `Player` exposes session-based aggregates; the player Blade page renders an AFK tile (gated on having tracked data) and per-session annotations. No SCSS/JS — reuses existing `.stat-tile` / `session-item__meta` and Font Awesome 4.7 `fa-bed`.

**Tech Stack:** PHP 8.3 / Laravel 13, Eloquent, Blade, PHPUnit (in-memory SQLite, `CRONTASK_INTERVAL=10`).

---

## Background the engineer needs

- `App\Service\SessionRecorder::record(Player, Server, Map, Mod)` runs once per player per scrape from `UpdateData::updatePlayers`. It extends the open session (adds `CRONTASK_INTERVAL` to `minutes`) on the same server, else closes it and opens a new one.
- `afk` comes only from the DDNet feed: `App\TwStats\Model\DiscoveredClient::$afk` is `true`/`false` for feed sightings, `null` for UDP-only. In `UpdateData::updatePlayers` the client is `$client` and the recorder is called near the end as `$this->sessionRecorder->record($playerModel, $serverModel, $mapModel, $modModel);`.
- Tests: DB-backed tests live in `tests/Feature`, extend `Tests\TestCase`, use `RefreshDatabase`. `Map`/`Mod` are made with `::create(['name' => …])`; `Player`/`Server` have factories. SQLite enforces foreign keys, so a `PlayerSession` needs real player/server/map/mod ids. `CRONTASK_INTERVAL=10`, so one scrape = 10 minutes.
- `SessionRecorderTest` `setUp()` provides `$this->recorder` (`new SessionRecorder()`), `$this->player`, `$this->server`, `$this->map`, `$this->mod`, and `tearDown()` calls `Carbon::setTestNow()` to reset. Existing tests call `record($player, $server, $map, $mod)` with no afk argument.
- `ChartUtility::humanizeDuration($minutes)` formats minutes (used in the view via the `$humanize` closure already defined at the top of `player.blade.php`).
- Font Awesome 4.7 ships `fa-bed`. The player page already uses `.stat-tile`/`.stat-tile__label`/`.stat-tile__value`/`.stat-tile__hint` and `.session-item__meta`. **No** `resources/assets` changes, so no `npm run build`.

## File structure

- Create: `database/migrations/*_add_afk_columns_to_player_sessions_table.php` — the two counters.
- Modify: `app/Models/PlayerSession.php` — docblock `@property` lines for the new columns.
- Modify: `app/Service/SessionRecorder.php` — `record()` gains `?bool $afk = null`; accrue both counters.
- Modify: `app/Console/Commands/UpdateData.php` — pass `$client->afk` into `record()`.
- Modify: `app/Models/Player.php` — `totalAfkMinutes`, `afkTrackedMinutes`, `afkPercent`, `hasAfkData`.
- Modify: `resources/views/detail/player.blade.php` — AFK tile + per-session annotation.
- Test: `tests/Feature/PlayerSessionSchemaTest.php` (new), `tests/Feature/SessionRecorderTest.php`, `tests/Feature/UpdateDataTest.php`, `tests/Feature/PlayerAfkTest.php` (new).

---

## Task 1: Schema — add `afk_minutes` and `afk_tracked_minutes`

**Files:**
- Create: `database/migrations/*_add_afk_columns_to_player_sessions_table.php`
- Modify: `app/Models/PlayerSession.php`
- Test: `tests/Feature/PlayerSessionSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerSessionSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlayerSessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_sessions_has_afk_counter_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('player_sessions', 'afk_minutes'));
        $this->assertTrue(Schema::hasColumn('player_sessions', 'afk_tracked_minutes'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter PlayerSessionSchemaTest`
Expected: FAIL — `Failed asserting that false is true` (columns absent).

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration add_afk_columns_to_player_sessions_table --table=player_sessions`

Replace the generated file's body so `up()`/`down()` read exactly:

```php
public function up(): void
{
    Schema::table('player_sessions', function (Blueprint $table) {
        // afk_minutes: minutes observed idle; afk_tracked_minutes: minutes the source reported
        // idle status at all (afk != null). afk_minutes <= afk_tracked_minutes <= minutes.
        $table->unsignedInteger('afk_minutes')->default(0)->after('minutes');
        $table->unsignedInteger('afk_tracked_minutes')->default(0)->after('afk_minutes');
    });
}

public function down(): void
{
    Schema::table('player_sessions', function (Blueprint $table) {
        $table->dropColumn(['afk_minutes', 'afk_tracked_minutes']);
    });
}
```

(Keep the generated `<?php`, `use Illuminate\Database\Migrations\Migration;`, `use Illuminate\Database\Schema\Blueprint;`, `use Illuminate\Support\Facades\Schema;`, and the `return new class extends Migration` wrapper.)

- [ ] **Step 4: Sync the model docblock**

In `app/Models/PlayerSession.php`, add two `@property` lines to the class docblock immediately after `* @property int $minutes`:

```php
 * @property int $afk_minutes
 * @property int $afk_tracked_minutes
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter PlayerSessionSchemaTest`
Expected: PASS (1 test).

- [ ] **Step 6: Apply the migration to the dev DB**

Run: `php artisan migrate`
Expected: the new migration runs (`DONE`).

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/PlayerSession.php tests/Feature/PlayerSessionSchemaTest.php
git commit -m "feat(sessions): add afk_minutes & afk_tracked_minutes columns"
```

---

## Task 2: SessionRecorder accrues both counters; UpdateData feeds it `afk`

**Files:**
- Modify: `app/Service/SessionRecorder.php`
- Modify: `app/Console/Commands/UpdateData.php`
- Test: `tests/Feature/SessionRecorderTest.php`, `tests/Feature/UpdateDataTest.php`

- [ ] **Step 1: Write the failing recorder tests**

In `tests/Feature/SessionRecorderTest.php`, add these methods inside the class (after the existing `test_consecutive_observations_extend_the_same_session`):

```php
    public function test_afk_true_accrues_both_afk_and_tracked_minutes(): void
    {
        Carbon::setTestNow('2026-06-11 20:00:00');
        $first = $this->recorder->record($this->player, $this->server, $this->map, $this->mod, afk: true);
        $this->assertSame(10, $first->afk_minutes);
        $this->assertSame(10, $first->afk_tracked_minutes);

        Carbon::setTestNow('2026-06-11 20:10:00');
        $second = $this->recorder->record($this->player, $this->server, $this->map, $this->mod, afk: true);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(20, $second->minutes);
        $this->assertSame(20, $second->afk_minutes);
        $this->assertSame(20, $second->afk_tracked_minutes);
    }

    public function test_afk_false_accrues_only_tracked_minutes(): void
    {
        Carbon::setTestNow('2026-06-11 20:00:00');
        $session = $this->recorder->record($this->player, $this->server, $this->map, $this->mod, afk: false);

        $this->assertSame(10, $session->minutes);
        $this->assertSame(0, $session->afk_minutes);
        $this->assertSame(10, $session->afk_tracked_minutes);
    }

    public function test_unknown_afk_accrues_neither_counter(): void
    {
        Carbon::setTestNow('2026-06-11 20:00:00');
        $session = $this->recorder->record($this->player, $this->server, $this->map, $this->mod, afk: null);

        $this->assertSame(10, $session->minutes);
        $this->assertSame(0, $session->afk_minutes);
        $this->assertSame(0, $session->afk_tracked_minutes);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter SessionRecorderTest`
Expected: FAIL — `record()` does not accept an `afk` argument / `afk_minutes` is `0`/undefined.

- [ ] **Step 3: Implement the recorder change**

In `app/Service/SessionRecorder.php`, change the `record()` signature and body. Replace the whole method with:

```php
    public function record(Player $player, Server $server, Map $map, Mod $mod, ?bool $afk = null): PlayerSession
    {
        $interval = $this->interval();

        // an interval counts as idle only on a positive afk=true observation; false and null
        // (UDP, unknown) are active. tracked counts every interval the source reported afk at all.
        $afkDelta = $afk === true ? $interval : 0;
        $trackedDelta = $afk !== null ? $interval : 0;

        $open = PlayerSession::where('player_id', $player->getAttribute('id'))
            ->whereNull('ended_at')
            ->orderByDesc('last_seen_at')
            ->first();

        // extend the running session when it is the same server and still fresh
        if ($open
            && (int)$open->getAttribute('server_id') === (int)$server->getAttribute('id')
            && $open->getAttribute('last_seen_at') >= $this->staleThreshold()
        ) {
            $open->setAttribute('minutes', $open->getAttribute('minutes') + $interval);
            $open->setAttribute('afk_minutes', $open->getAttribute('afk_minutes') + $afkDelta);
            $open->setAttribute('afk_tracked_minutes', $open->getAttribute('afk_tracked_minutes') + $trackedDelta);
            $open->setAttribute('last_seen_at', Carbon::now());
            $open->setAttribute('map_id', $map->getAttribute('id'));
            $open->setAttribute('mod_id', $mod->getAttribute('id'));
            $open->save();

            return $open;
        }

        // a stale or different-server session is finished off before a fresh one starts
        if ($open) {
            $this->close($open);
        }

        return PlayerSession::create([
            'player_id' => $player->getAttribute('id'),
            'server_id' => $server->getAttribute('id'),
            'map_id' => $map->getAttribute('id'),
            'mod_id' => $mod->getAttribute('id'),
            'minutes' => $interval,
            'afk_minutes' => $afkDelta,
            'afk_tracked_minutes' => $trackedDelta,
            'started_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }
```

- [ ] **Step 4: Run to verify the recorder tests pass**

Run: `vendor/bin/phpunit --filter SessionRecorderTest`
Expected: PASS (all existing + 3 new).

- [ ] **Step 5: Write the failing UpdateData wiring test**

In `tests/Feature/UpdateDataTest.php`, add `use App\Models\PlayerSession;` with the other `use App\Models\…` imports, then add this method inside the class:

```php
    public function test_sessions_record_afk_from_the_feed_observation(): void
    {
        $this->bindEmptySevenSource();
        $this->bindEmptySixSource();
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        // glow is reported afk=true on DDNet GER10 → one idle interval, tracked
        $glow = Player::where('name', 'glow')->first();
        $glowSession = PlayerSession::where('player_id', $glow->id)->first();
        $this->assertSame(10, $glowSession->afk_minutes);
        $this->assertSame(10, $glowSession->afk_tracked_minutes);

        // vin is reported afk=false → tracked but not idle
        $vin = Player::where('name', 'vin')->first();
        $vinSession = PlayerSession::where('player_id', $vin->id)->first();
        $this->assertSame(0, $vinSession->afk_minutes);
        $this->assertSame(10, $vinSession->afk_tracked_minutes);
    }
```

- [ ] **Step 6: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_sessions_record_afk_from_the_feed_observation`
Expected: FAIL — `afk_minutes`/`afk_tracked_minutes` are `0` (afk not yet passed through).

- [ ] **Step 7: Wire `afk` through in UpdateData**

In `app/Console/Commands/UpdateData.php`, find the call in `updatePlayers`:

```php
            $this->sessionRecorder->record($playerModel, $serverModel, $mapModel, $modModel);
```

Replace it with:

```php
            // feed the observation's afk into the session so AFK time accrues per scrape
            $this->sessionRecorder->record($playerModel, $serverModel, $mapModel, $modModel, $client->afk);
```

- [ ] **Step 8: Run to verify it passes**

Run: `vendor/bin/phpunit --filter UpdateDataTest`
Expected: PASS (all existing + 1 new).

- [ ] **Step 9: Commit**

```bash
git add app/Service/SessionRecorder.php app/Console/Commands/UpdateData.php tests/Feature/SessionRecorderTest.php tests/Feature/UpdateDataTest.php
git commit -m "feat(sessions): accrue AFK and idle-tracked minutes per scrape"
```

---

## Task 3: Player AFK aggregates

**Files:**
- Modify: `app/Models/Player.php`
- Test: `tests/Feature/PlayerAfkTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerAfkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerSession;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerAfkTest extends TestCase
{
    use RefreshDatabase;

    private function session(Player $player, int $minutes, int $afk, int $tracked): void
    {
        $server = Server::factory()->create();
        $map = Map::create(['name' => 'map' . $minutes . '_' . $afk . '_' . $tracked]);
        $mod = Mod::create(['name' => 'mod' . $minutes . '_' . $afk . '_' . $tracked]);

        PlayerSession::create([
            'player_id' => $player->id,
            'server_id' => $server->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => $minutes,
            'afk_minutes' => $afk,
            'afk_tracked_minutes' => $tracked,
            'started_at' => Carbon::now()->subHour(),
            'last_seen_at' => Carbon::now(),
            'ended_at' => Carbon::now(),
        ]);
    }

    public function test_afk_percent_is_computed_over_tracked_time(): void
    {
        $player = Player::factory()->create();
        // 30 idle of 60 tracked (120 total minutes) → 50%, NOT 25%
        $this->session($player, minutes: 120, afk: 30, tracked: 60);

        $this->assertSame(30, $player->totalAfkMinutes());
        $this->assertSame(60, $player->afkTrackedMinutes());
        $this->assertSame(50, $player->afkPercent());
        $this->assertTrue($player->hasAfkData());
    }

    public function test_player_with_only_untracked_sessions_has_no_afk_data(): void
    {
        $player = Player::factory()->create();
        $this->session($player, minutes: 60, afk: 0, tracked: 0);

        $this->assertSame(0, $player->afkPercent());
        $this->assertFalse($player->hasAfkData());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter PlayerAfkTest`
Expected: FAIL — `Call to undefined method App\Models\Player::totalAfkMinutes()`.

- [ ] **Step 3: Implement the aggregates**

In `app/Models/Player.php`, add these methods immediately after the existing `averageSessionMinutes()` method:

```php
    /**
     * total minutes the player was observed idle across all sessions
     */
    public function totalAfkMinutes(): int
    {
        return (int) $this->sessions()->sum('afk_minutes');
    }

    /**
     * total minutes spent on servers that reported idle status at all — the honest
     * denominator for the AFK percentage
     */
    public function afkTrackedMinutes(): int
    {
        return (int) $this->sessions()->sum('afk_tracked_minutes');
    }

    /**
     * idle share of the idle-tracked time (0 when no idle-tracking server was ever seen)
     */
    public function afkPercent(): int
    {
        $tracked = $this->afkTrackedMinutes();

        return $tracked > 0 ? (int) round($this->totalAfkMinutes() / $tracked * 100) : 0;
    }

    /**
     * whether any session was on an idle-tracking server — gates the AFK display
     */
    public function hasAfkData(): bool
    {
        return $this->afkTrackedMinutes() > 0;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter PlayerAfkTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Player.php tests/Feature/PlayerAfkTest.php
git commit -m "feat(player): AFK aggregates over idle-tracked session time"
```

---

## Task 4: Player page — AFK tile + per-session annotation

**Files:**
- Modify: `resources/views/detail/player.blade.php`
- Test: `tests/Feature/PlayerAfkTest.php`

- [ ] **Step 1: Write the failing page tests**

In `tests/Feature/PlayerAfkTest.php`, add these two methods inside the class:

```php
    public function test_player_page_shows_the_afk_tile_and_session_annotation(): void
    {
        $player = Player::factory()->create(['name' => 'Idle Ivan', 'last_seen' => Carbon::now()]);
        $server = Server::factory()->create(['name' => 'Idle Server']);
        $map = Map::create(['name' => 'idlemap']);
        $mod = Mod::create(['name' => 'idlemod']);

        // 30 idle of 120 tracked → 25%
        PlayerSession::create([
            'player_id' => $player->id,
            'server_id' => $server->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => 120,
            'afk_minutes' => 30,
            'afk_tracked_minutes' => 120,
            'started_at' => Carbon::now()->subDay()->setTime(20, 0),
            'last_seen_at' => Carbon::now()->subDay()->setTime(22, 0),
            'ended_at' => Carbon::now()->subDay()->setTime(22, 0),
        ]);

        $response = $this->get('/tee/' . urlencode('Idle Ivan'));

        $response->assertOk();
        $response->assertSee('on servers tracking idle status');
        $response->assertSee('25%');
        // per-session annotation (lowercase "afk" only appears on the session row)
        $response->assertSee('afk', false);
    }

    public function test_player_page_hides_the_afk_tile_without_tracked_data(): void
    {
        $player = Player::factory()->create(['name' => 'No Idle Ned', 'last_seen' => Carbon::now()]);
        $server = Server::factory()->create();
        $map = Map::create(['name' => 'plainmap']);
        $mod = Mod::create(['name' => 'plainmod']);

        PlayerSession::create([
            'player_id' => $player->id,
            'server_id' => $server->id,
            'map_id' => $map->id,
            'mod_id' => $mod->id,
            'minutes' => 60,
            'afk_minutes' => 0,
            'afk_tracked_minutes' => 0,
            'started_at' => Carbon::now()->subHour(),
            'last_seen_at' => Carbon::now(),
            'ended_at' => Carbon::now(),
        ]);

        $response = $this->get('/tee/' . urlencode('No Idle Ned'));

        $response->assertOk();
        $response->assertDontSee('on servers tracking idle status');
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter PlayerAfkTest`
Expected: FAIL — `Failed asserting that … contains "on servers tracking idle status"`.

- [ ] **Step 3: Add the AFK tile**

In `resources/views/detail/player.blade.php`, find the Sessions stat-tile (it is the block containing `<div class="stat-tile__label">Sessions</div>`):

```blade
                <div class="stat-tile">
                    <div class="stat-tile__label">Sessions</div>
                    <div class="stat-tile__value"><i class="fa fa-history"></i>{{ number_format($player->totalSessions()) }}</div>
                    @if ($player->totalSessions() > 0)
                        <div class="stat-tile__hint">avg {{ $humanize($player->averageSessionMinutes()) }} · longest {{ $humanize($player->longestSessionMinutes()) }}</div>
                    @endif
                </div>
```

Immediately **after** that closing `</div>`, insert:

```blade
                @if ($player->hasAfkData())
                    <div class="stat-tile">
                        <div class="stat-tile__label">AFK</div>
                        <div class="stat-tile__value"><i class="fa fa-bed"></i>{{ $player->afkPercent() }}%</div>
                        <div class="stat-tile__hint">{{ $humanize($player->totalAfkMinutes()) }} on servers tracking idle status</div>
                    </div>
                @endif
```

- [ ] **Step 4: Add the per-session annotation**

In the same file, find the `session-item__meta` block:

```blade
                                            <div class="session-item__meta">
                                                {{ $session->started_at->format('H:i') }}–{{ ($session->ended_at ?? $session->last_seen_at)->format('H:i') }}
                                                @if ($session->map)
                                                    · {{ $session->map->name }}
                                                @endif
                                            </div>
```

Insert the AFK annotation just before the closing `</div>` (after the `@endif` for the map):

```blade
                                                @if ($session->afk_minutes > 0)
                                                    · <i class="fa fa-bed"></i> {{ $humanize($session->afk_minutes) }} afk
                                                @endif
```

- [ ] **Step 5: Run to verify they pass**

Run: `vendor/bin/phpunit --filter PlayerAfkTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add resources/views/detail/player.blade.php tests/Feature/PlayerAfkTest.php
git commit -m "feat(player): show AFK share and per-session AFK on the player page"
```

---

## Task 5: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the entire suite**

Run: `vendor/bin/phpunit`
Expected: PASS — no regressions; the new schema/recorder/aggregate/page tests are green.

- [ ] **Step 2: Confirm no front-end build is needed**

Run: `git status --porcelain resources/assets`
Expected: empty (no SCSS/TS touched; the AFK UI reuses existing classes + `fa-bed`).

- [ ] **Step 3: Lint/type-check sanity (optional, unaffected)**

Run: `npm run type-check && npm run lint`
Expected: PASS unchanged (no TS touched).

---

## Self-review notes

- **Spec coverage:** schema (Task 1), recorder accrual true/false/null + UpdateData wiring (Task 2), aggregates incl. ÷0 guard and `hasAfkData` (Task 3), gated tile + per-session annotation (Task 4). The general-disclaimer alternative was dropped in the spec; the tile hint "on servers tracking idle status" + the `hasAfkData()` gate carry the meaning.
- **Type consistency:** the view reads `$player->afkPercent()`, `$player->totalAfkMinutes()`, `$player->hasAfkData()`, and `$session->afk_minutes` — all defined in Tasks 1/3. `record(..., ?bool $afk = null)` is additive, so existing `SessionRecorderTest` calls and any other caller stay valid.
- **Invariant:** each scrape adds `interval` to `minutes`, ≤ `interval` to `afk_tracked_minutes`, ≤ that to `afk_minutes`, so `afk_minutes ≤ afk_tracked_minutes ≤ minutes` and `afkPercent ≤ 100`.
