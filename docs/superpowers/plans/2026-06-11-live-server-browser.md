# Live Server Browser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a cached `/serverbrowser` page that lists the currently-online ("last logged") servers with client-side filtering, where hovering a server's player count reveals the players on it.

**Architecture:** A new `MainController::liveServers()` loads servers seen in the last scrape window, eager-loading the current map/mod (`currentServerHistory`) and sorting by current player count (computed in PHP — `withCount` is unsafe here). A new Blade view renders a filterable table with each server's roster embedded (hidden) for a Bootstrap popover. A small vanilla-JS module does the filtering and popover init. The route uses Spatie's `CacheResponse` middleware; invalidation is already wired to the 10-minute scrape's `responsecache:clear`.

**Tech Stack:** Laravel 13, Blade, Bootstrap 5.3 (popovers), vanilla JS via Vite, `spatie/laravel-responsecache`, PHPUnit + sqlite `:memory:`.

**Spec:** `docs/superpowers/specs/2026-06-11-live-server-browser-design.md`

---

## Background the engineer needs

- **"Online" window:** a server/player is "online" if its timestamp is within
  `env('CRONTASK_INTERVAL') * 1.5` minutes of now. `phpunit.xml` sets
  `CRONTASK_INTERVAL=10`, so the window is 15 minutes in tests. `Server::online()` and
  `Server::currentPlayers()` already use this rule.
- **Why not `withCount('currentPlayers')`:** the `Server::players()` relation ends in
  `->groupBy('players.id')`. `withCount` builds a correlated `count(*)` subquery; a
  `group by` inside it makes the subquery return multiple rows → SQL error / wrong count.
  Likewise, eager-loading `currentPlayers` across many servers in one query mis-dedupes
  (the groupBy collapses a player who is on two servers into one). So we **lazy-load**
  `currentPlayers` per server (correct, and the result is cached on each model instance so
  the count and the roster reuse one query) and **sort in PHP**. The online set is bounded
  (tens to low hundreds) and the page is response-cached, so this is fine.
- **Current map/mod:** the scraper bumps a server's "current" `ServerHistory` row's
  `updated_at` every scrape, so the latest one reflects the live map/mod. A
  `hasOne(...)->latestOfMany('updated_at')` relation is safe to eager-load (no groupBy).
- **Test conventions:** tests in this repo use direct `Model::create([...])` (not
  `factory()`); `Map`/`Mod` are created with `::create(['name' => ...])`.
  `PlayerHistory`/`ServerHistory` require `weekday`, `hour`, `continuous`, `minutes` and
  the FK ids (`mod_original_id` is nullable). See `tests/Feature/RouteSmokeTest.php`.
- **Response cache in tests:** `phpunit.xml` sets `RESPONSE_CACHE_ENABLED=false`, so the
  `CacheResponse` middleware passes through — tests always see freshly-rendered output.
- **Verification commands:** `vendor/bin/phpunit` for tests, `npm run build` for assets.
  There is no lint script in this project.

---

## File Structure

- **Modify** `app/Models/Server.php` — add the `currentServerHistory()` relation.
- **Modify** `app/Http/Controllers/MainController.php` — add `liveServers()`.
- **Modify** `routes/web.php` — add the cached `/serverbrowser` route.
- **Create** `resources/views/list/live.blade.php` — the server browser view.
- **Create** `resources/assets/js/serverbrowser.js` — filtering + popover init.
- **Modify** `resources/assets/js/app.js` — import the new JS module.
- **Modify** `resources/views/layouts/app.blade.php` — sidebar nav entry.
- **Create** `tests/Feature/LiveServerBrowserTest.php` — feature + relation tests.
- **Modify** `tests/Feature/RouteSmokeTest.php` — add `/serverbrowser` to the smoke list.

---

## Task 1: `Server::currentServerHistory()` relation

**Files:**
- Create: `tests/Feature/LiveServerBrowserTest.php`
- Modify: `app/Models/Server.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LiveServerBrowserTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Server;
use App\Models\ServerHistory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveServerBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_server_history_returns_latest_map_and_mod(): void
    {
        $server = Server::create([
            'name' => 'Relation Server', 'version' => '0.7.5', 'ip' => '127.0.0.1', 'port' => 8303,
        ]);

        $oldMap = Map::create(['name' => 'dm1']);
        $oldMod = Mod::create(['name' => 'dm']);
        $newMap = Map::create(['name' => 'ctf5']);
        $newMod = Mod::create(['name' => 'ctf']);

        $old = ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $oldMap->id, 'mod_id' => $oldMod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        // backdate the old row's updated_at without the model touching it
        ServerHistory::where('id', $old->id)->update(['updated_at' => Carbon::now()->subHour()]);

        ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $newMap->id, 'mod_id' => $newMod->id,
            'weekday' => 1, 'hour' => 13, 'continuous' => 1, 'minutes' => 60,
        ]);

        $history = $server->currentServerHistory;

        $this->assertNotNull($history);
        $this->assertSame('ctf5', $history->map->name);
        $this->assertSame('ctf', $history->mod->name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_current_server_history_returns_latest_map_and_mod`
Expected: FAIL — `Call to undefined method App\Models\Server::currentServerHistory()`.

- [ ] **Step 3: Add the relation**

In `app/Models/Server.php`, add this method inside the `Server` class (e.g. just after
`currentPlayers()`):

```php
/**
 * the server_history row for the map/mod the server is running right now; the
 * scraper bumps this row's updated_at every scrape, so the latest one reflects
 * the server's current map and gametype
 *
 * @return \Illuminate\Database\Eloquent\Relations\HasOne
 */
public function currentServerHistory()
{
    return $this->hasOne(ServerHistory::class)->latestOfMany('updated_at');
}
```

(`ServerHistory` is already imported/used in this file.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter test_current_server_history_returns_latest_map_and_mod`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Server.php tests/Feature/LiveServerBrowserTest.php
git commit -m "feat(server): add currentServerHistory relation for the live map/mod"
```

---

## Task 2: Controller, route, and view

**Files:**
- Modify: `app/Http/Controllers/MainController.php`
- Modify: `routes/web.php`
- Create: `resources/views/list/live.blade.php`
- Modify: `tests/Feature/LiveServerBrowserTest.php`

- [ ] **Step 1: Write the failing feature test**

Add these two test methods to the `LiveServerBrowserTest` class created in Task 1:

```php
    /**
     * Seed one online server with a player, one online but empty server, and one
     * stale (offline) server. Returns their names for assertions.
     */
    private function seedBrowserFixture(): void
    {
        $map = Map::create(['name' => 'ctf5']);
        $mod = Mod::create(['name' => 'ctf']);

        // online server with one current player
        $online = Server::create([
            'name' => 'Online FFA Server', 'version' => '0.7.5', 'ip' => '10.0.0.1', 'port' => 8303,
            'last_seen' => Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $online->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        $player = \App\Models\Player::create(['name' => 'RosterTee', 'country' => 'DE']);
        \App\Models\PlayerHistory::create([
            'server_id' => $online->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);

        // online but empty server (no player histories)
        $empty = Server::create([
            'name' => 'Empty DM Server', 'version' => '0.7.5', 'ip' => '10.0.0.2', 'port' => 8303,
            'last_seen' => Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $empty->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);

        // stale server, last seen well outside the online window
        Server::create([
            'name' => 'Stale Offline Server', 'version' => '0.7.5', 'ip' => '10.0.0.3', 'port' => 8303,
            'last_seen' => Carbon::now()->subHours(2),
        ]);
    }

    public function test_browser_lists_online_servers_with_current_map_mod_and_roster(): void
    {
        $this->seedBrowserFixture();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('Online FFA Server');
        $response->assertSee('Empty DM Server');
        $response->assertSee('ctf5');                    // current map
        $response->assertSee('ctf');                     // current gametype
        $response->assertSee('RosterTee');               // embedded hover roster
        $response->assertSee('data-players="1"', false); // count on the populated server
        $response->assertSee('data-players="0"', false); // empty server still listed
    }

    public function test_browser_excludes_stale_servers(): void
    {
        $this->seedBrowserFixture();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertDontSee('Stale Offline Server');
    }
```

Also add the imports at the top of the file (next to the existing ones):

```php
use App\Models\Player;
use App\Models\PlayerHistory;
```

(and drop the fully-qualified `\App\Models\...` references in the snippet above if you
prefer — either works.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter LiveServerBrowserTest`
Expected: the two new tests FAIL — `GET /serverbrowser` returns 404 (route not defined).

- [ ] **Step 3: Add the controller method**

In `app/Http/Controllers/MainController.php`, add this method to the `MainController`
class (e.g. just after `servers()`):

```php
/**
 * the live server browser: the servers seen in the most recent scrape (online),
 * with their current map/mod eager-loaded and ordered by current player count.
 * currentPlayers is loaded lazily per server (its relation groups by players.id,
 * which makes withCount / cross-server eager loading unsafe) and the page is
 * response-cached, so the per-server count query is cheap in practice.
 *
 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
 */
public function liveServers()
{
    $servers = Server::where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5))
        ->with(['currentServerHistory.map', 'currentServerHistory.mod'])
        ->get()
        ->sortByDesc(fn (Server $server) => $server->currentPlayers->count())
        ->values();

    return view('list.live')->with('servers', $servers);
}
```

(`Server` and `Carbon` are already imported in this controller.)

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the `CacheResponse` import near the other `use` statements:

```php
use Spatie\ResponseCache\Middlewares\CacheResponse;
```

Then add the route in the "List routes" section (just below the `servers` route):

```php
// Live server browser (cached; invalidated by responsecache:clear after each scrape)
Route::get('/serverbrowser', [MainController::class, 'liveServers'])
    ->middleware(CacheResponse::class)
    ->name('serverbrowser');
```

- [ ] **Step 5: Create the view**

Create `resources/views/list/live.blade.php`:

```blade
@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Server browser</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>Servers online right now</strong></div>

                        {{-- client-side filter bar; serverbrowser.js reads these and shows/hides rows --}}
                        <div class="row g-2 mb-3" id="server_browser_filters">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="filter_name"
                                       placeholder="Filter by server name…" autocomplete="off">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="filter_mod">
                                    <option value="">All gametypes</option>
                                    @foreach ($servers->map(fn ($s) => optional($s->currentServerHistory)->mod?->name)->filter()->unique()->sort() as $modName)
                                        <option value="{{ $modName }}">{{ $modName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="filter_map">
                                    <option value="">All maps</option>
                                    @foreach ($servers->map(fn ($s) => optional($s->currentServerHistory)->map?->name)->filter()->unique()->sort() as $mapName)
                                        <option value="{{ $mapName }}">{{ $mapName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-center">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="filter_hide_empty">
                                    <label class="form-check-label" for="filter_hide_empty">Hide empty</label>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="server_browser_table">
                                <thead>
                                <tr>
                                    <th>Server</th>
                                    <th>Map</th>
                                    <th>Gametype</th>
                                    <th>Players</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($servers as $serverEntry)
                                    @php
                                        $history = $serverEntry->currentServerHistory;
                                        $mapName = optional($history)->map?->name;
                                        $modName = optional($history)->mod?->name;
                                        $players = $serverEntry->currentPlayers;
                                    @endphp
                                    <tr data-name="{{ \Illuminate\Support\Str::lower($serverEntry->name) }}"
                                        data-map="{{ $mapName }}"
                                        data-mod="{{ $modName }}"
                                        data-players="{{ $players->count() }}">
                                        <td>
                                            <a href="{{ url('server', [urlencode($serverEntry->id), urlencode($serverEntry->name)]) }}">{{ $serverEntry->name }}</a>
                                        </td>
                                        <td>
                                            @if ($mapName)
                                                <a href="{{ url('map', urlencode($mapName)) }}">{{ $mapName }}</a>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($modName)
                                                <a href="{{ url('mod', urlencode($modName)) }}">{{ $modName }}</a>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($players->count())
                                                <span class="badge bg-primary server-player-count"
                                                      tabindex="0" role="button">{{ $players->count() }}</span>
                                                <div class="server-players d-none">
                                                    @foreach ($players as $player)
                                                        @php $clan = $player->clan(); @endphp
                                                        <a href="{{ url('tee', urlencode($player->name)) }}" class="d-block">
                                                            {{ $player->name }}@if ($clan) <small class="text-muted">{{ $clan->name }}</small>@endif
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="badge bg-secondary">0</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter LiveServerBrowserTest`
Expected: all three tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/MainController.php routes/web.php \
        resources/views/list/live.blade.php tests/Feature/LiveServerBrowserTest.php
git commit -m "feat(serverbrowser): add cached /serverbrowser route, controller and view"
```

---

## Task 3: Client-side filtering and player-count popovers

**Files:**
- Create: `resources/assets/js/serverbrowser.js`
- Modify: `resources/assets/js/app.js`

There is no automated test for this step (it is browser behavior); verify by building the
assets and a manual smoke check.

- [ ] **Step 1: Create the JS module**

Create `resources/assets/js/serverbrowser.js`:

```js
// Server browser page: client-side filtering + a hover popover listing the players
// currently on each server. Vanilla JS (no jQuery), runs only on the browser page.
import { Popover } from 'bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('server_browser_table');
    if (!table) {
        return; // not the server browser page
    }

    // ---- player-count popovers: pull HTML from each row's hidden .server-players ----
    table.querySelectorAll('.server-player-count').forEach((trigger) => {
        const roster = trigger.parentElement.querySelector('.server-players');
        if (!roster) {
            return;
        }
        // eslint-disable-next-line no-new
        new Popover(trigger, {
            html: true,
            trigger: 'hover focus',
            container: 'body',
            content: () => roster.innerHTML,
        });
    });

    // ---- client-side filtering ----
    const nameInput = document.getElementById('filter_name');
    const modSelect = document.getElementById('filter_mod');
    const mapSelect = document.getElementById('filter_map');
    const hideEmpty = document.getElementById('filter_hide_empty');
    const rows = Array.from(table.querySelectorAll('tbody tr'));

    function applyFilters() {
        const name = nameInput.value.trim().toLowerCase();
        const mod = modSelect.value;
        const map = mapSelect.value;
        const empty = hideEmpty.checked;

        rows.forEach((row) => {
            const matchesName = !name || (row.dataset.name || '').includes(name);
            const matchesMod = !mod || row.dataset.mod === mod;
            const matchesMap = !map || row.dataset.map === map;
            const matchesEmpty = !empty || row.dataset.players !== '0';
            row.hidden = !(matchesName && matchesMod && matchesMap && matchesEmpty);
        });
    }

    [nameInput, modSelect, mapSelect].forEach((el) => el.addEventListener('input', applyFilters));
    hideEmpty.addEventListener('change', applyFilters);
});
```

- [ ] **Step 2: Import the module from `app.js`**

In `resources/assets/js/app.js`, add the import alongside the other local imports
(after `import './front';`):

```js
import './serverbrowser';
```

- [ ] **Step 3: Build the assets and verify success**

Run: `npm run build`
Expected: build completes with no errors and writes the manifest under `public/build/`.

- [ ] **Step 4: Commit**

```bash
git add resources/assets/js/serverbrowser.js resources/assets/js/app.js
git commit -m "feat(serverbrowser): client-side filtering and player-count hover popovers"
```

---

## Task 4: Navigation entry and route smoke coverage

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `tests/Feature/RouteSmokeTest.php`

- [ ] **Step 1: Add `/serverbrowser` to the smoke test (failing first)**

In `tests/Feature/RouteSmokeTest.php`, add an entry to the `publicRouteProvider()` array:

```php
            'serverbrowser' => ['/serverbrowser'],
```

(place it next to `'servers' => ['/servers'],`).

- [ ] **Step 2: Run the smoke test to confirm it already passes**

Run: `vendor/bin/phpunit --filter test_public_routes_return_ok`
Expected: PASS, including the new `serverbrowser` data set. (The route and view exist from
Task 2, and `seedMinimalData()` creates an online server with a `ServerHistory` row and a
recent `PlayerHistory`, so the page renders.) If it fails, fix the cause before continuing.

- [ ] **Step 3: Add the sidebar nav entry**

In `resources/views/layouts/app.blade.php`, inside the `Main` `<ul class="list-unstyled">`
(the one containing Home / Game Statistics / Search), add:

```blade
                <li><a href="{{ url('serverbrowser') }}"> <i class="fa fa-server"></i>Server Browser </a></li>
```

(`fa-server` exists in the bundled Font Awesome 4.7.)

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/app.blade.php tests/Feature/RouteSmokeTest.php
git commit -m "feat(serverbrowser): add sidebar nav entry and route smoke coverage"
```

---

## Task 5: Full verification and finish

- [ ] **Step 1: Run the entire test suite**

Run: `vendor/bin/phpunit`
Expected: all tests PASS (no regressions).

- [ ] **Step 2: Rebuild assets**

Run: `npm run build`
Expected: clean build.

- [ ] **Step 3: Manual smoke check (optional but recommended)**

Load `/serverbrowser` in a browser (or via curl against the DDEV site). Confirm: the table
lists online servers, the filter inputs hide/show rows, and hovering a player-count badge
shows the roster popover.

- [ ] **Step 4: Finish the branch**

Use the `superpowers:finishing-a-development-branch` skill to decide how to integrate
`feature/live-server-browser` (PR into `development`, merge, etc.).

---

## Self-review notes (for the implementer)

- The roster popover relies on Bootstrap's default HTML sanitizer allowlist, which permits
  `<a>` and `<small>` — the only tags we emit. No custom allowlist needed.
- `data-map`/`data-mod` filter values come from the same `currentServerHistory` source as
  the `<select>` options, so exact-match comparison in JS is reliable.
- If a freshly-seen server has no `ServerHistory` row yet, `currentServerHistory` is null
  and the Map/Gametype cells render empty — handled by the `@if ($mapName)` guards.
