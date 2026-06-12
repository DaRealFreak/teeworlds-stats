# Tee list: AFK column + sortable columns — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an AFK % column to `/tees` and make Tee / Clan / Played time / AFK % sortable server-side across the whole paginated list.

**Architecture:** `MainController::players()` reads allowlisted `?sort=`/`?dir=`, builds the list with `leftJoinSub` grouped aggregates (played minutes, AFK sums, current clan) so sorting works over all ~31k players (and the per-row clan/played N+1 disappears), and paginates with `withQueryString()`. The view reads the joined attributes, adds an AFK % cell, and uses a new `<x-sortable-th>` component for the clickable headers. Mod/Map stay per-row info columns (null-guarded).

**Tech Stack:** PHP 8.3 / Laravel 13, Eloquent query builder (`leftJoinSub`, `orderByRaw`), Blade components, SCSS via Vite, Font Awesome 4.7. Tests: in-memory SQLite, `RefreshDatabase`.

---

## Background the engineer needs

- Route: `Route::get('/tees', [MainController::class, 'players'])` — Laravel auto-injects `Request` into `players(Request $request)`.
- `MainController` already imports `Clan`, `Map`, `Mod`, `Player`, `Server`, `PlayerHistory`, `ChartUtility`, `Carbon`. This work adds `use Illuminate\Http\Request;`, `use App\Models\PlayerSession;`, `use App\Models\PlayerClanHistory;`.
- `leftJoinSub($subquery, $alias, $first, $op, $second)` joins a derived table; the subquery may be an Eloquent builder. Selecting `players.*` plus aliased aggregate columns makes those aliases readable as model attributes in the view.
- `Player`/`Server`/`Clan` have factories; `Map`/`Mod` use `::create(['name' => …])`. SQLite enforces FKs, so history/session rows need real ids. `CRONTASK_INTERVAL=10`. Tests live in `tests/Feature`, extend `Tests\TestCase`, use `RefreshDatabase`.
- `ChartUtility::humanizeDuration($minutes)` formats minutes.
- `$response->assertSeeInOrder(['a', 'b'])` asserts substring order in the HTML.
- The current view (`resources/views/list/player.blade.php`) renders player names in the **Tee** column of `<tbody>` and computes `clan()`, `totalHoursOnline()`, `chartPlayedMods()`, `chartPlayedMaps()` per row.
- Blade components live in `resources/views/components/`. SCSS is edited in `resources/assets/sass/app.scss`; building (`npm run build`) changes the content-hashed bundle, so the response-cached `/serverbrowser` route needs `php artisan responsecache:clear` afterward (`/tees` itself is not cached).

## File structure

- Modify: `app/Http/Controllers/MainController.php` — `players(Request $request)`: sort allowlist + `leftJoinSub` query.
- Create: `resources/views/components/sortable-th.blade.php` — clickable sort header.
- Modify: `resources/views/list/player.blade.php` — AFK column, joined-attribute cells, null-guarded Mod/Map, sortable headers.
- Modify: `resources/assets/sass/app.scss` — `.sortable-th` styling.
- Test: `tests/Feature/TeeListSortTest.php` (new) — ordering, fallback, AFK cell, header.

---

## Task 1: Controller — sort params + aggregate-join query

**Files:**
- Modify: `app/Http/Controllers/MainController.php`
- Test: `tests/Feature/TeeListSortTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TeeListSortTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\PlayerSession;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeeListSortTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed a player with a play history (so the Mod/Map cells render), and optionally a session
     * carrying AFK counters and a current clan.
     */
    private function seedPlayer(string $name, int $playedMinutes = 10, ?int $afk = null, ?int $afkTracked = null, ?string $clan = null): Player
    {
        $player = Player::factory()->create(['name' => $name, 'last_seen' => Carbon::now()]);
        $server = Server::factory()->create();
        $map = Map::create(['name' => 'map_' . $name]);
        $mod = Mod::create(['name' => 'mod_' . $name]);

        PlayerHistory::create([
            'weekday' => 1, 'hour' => 12, 'continuous' => true,
            'server_id' => $server->id, 'player_id' => $player->id,
            'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => $playedMinutes,
        ]);

        if ($afkTracked !== null) {
            PlayerSession::create([
                'player_id' => $player->id, 'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
                'minutes' => $playedMinutes, 'afk_minutes' => $afk ?? 0, 'afk_tracked_minutes' => $afkTracked,
                'started_at' => Carbon::now()->subHour(), 'last_seen_at' => Carbon::now(), 'ended_at' => Carbon::now(),
            ]);
        }

        if ($clan !== null) {
            $clanModel = Clan::factory()->create(['name' => $clan]);
            PlayerClanHistory::create(['player_id' => $player->id, 'clan_id' => $clanModel->id, 'left_at' => null]);
        }

        return $player;
    }

    public function test_default_sort_is_by_name(): void
    {
        $this->seedPlayer('Zeta');
        $this->seedPlayer('Alpha');

        $this->get('/tees')->assertOk()->assertSeeInOrder(['Alpha', 'Zeta']);
    }

    public function test_sort_by_afk_orders_by_idle_share_and_keeps_no_data_last(): void
    {
        $this->seedPlayer('IdleHigh', afk: 80, afkTracked: 100); // 80%
        $this->seedPlayer('IdleLow', afk: 10, afkTracked: 100);  // 10%
        $this->seedPlayer('NoData');                              // no session → null ratio

        $this->get('/tees?sort=afk&dir=desc')->assertOk()->assertSeeInOrder(['IdleHigh', 'IdleLow', 'NoData']);
        // ascending still keeps the no-data player last
        $this->get('/tees?sort=afk&dir=asc')->assertOk()->assertSeeInOrder(['IdleLow', 'IdleHigh', 'NoData']);
    }

    public function test_sort_by_played_time(): void
    {
        $this->seedPlayer('Grinder', playedMinutes: 600);
        $this->seedPlayer('Casual', playedMinutes: 20);

        $this->get('/tees?sort=played&dir=desc')->assertOk()->assertSeeInOrder(['Grinder', 'Casual']);
    }

    public function test_sort_by_clan_name(): void
    {
        // names deliberately inverse to clan order, so a pass proves clan (not name) sorting
        $this->seedPlayer('Zeta', clan: 'AAA');
        $this->seedPlayer('Alpha', clan: 'ZZZ');

        $this->get('/tees?sort=clan&dir=asc')->assertOk()->assertSeeInOrder(['Zeta', 'Alpha']);
    }

    public function test_invalid_sort_falls_back_to_name_without_error(): void
    {
        $this->seedPlayer('Zeta');
        $this->seedPlayer('Alpha');

        $this->get('/tees?sort=garbage&dir=sideways')->assertOk()->assertSeeInOrder(['Alpha', 'Zeta']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter TeeListSortTest`
Expected: FAIL — `test_sort_by_afk_…` / `test_sort_by_played_time` / `test_sort_by_clan_name` fail their order assertions (the list is still hard-sorted by name).

- [ ] **Step 3: Implement the controller**

In `app/Http/Controllers/MainController.php`, add the imports under the existing `use App\Models\…` lines:

```php
use App\Models\PlayerClanHistory;
use App\Models\PlayerSession;
use Illuminate\Http\Request;
```

Replace the entire `players()` method with:

```php
    /**
     * The player list, sortable across the whole set. Played time, AFK share and the current
     * clan are joined as grouped aggregates so the sort works over every player (not just the
     * page) — which also drops the old per-row clan()/totalHoursOnline() lookups.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function players(Request $request)
    {
        $dir = strtolower((string) $request->query('dir')) === 'desc' ? 'desc' : 'asc';
        $sort = (string) $request->query('sort');

        $query = Player::query()
            ->leftJoinSub(
                PlayerHistory::query()->selectRaw('player_id, SUM(minutes) as minutes')->groupBy('player_id'),
                'played', 'played.player_id', '=', 'players.id'
            )
            ->leftJoinSub(
                PlayerSession::query()->selectRaw('player_id, SUM(afk_minutes) as afk_sum, SUM(afk_tracked_minutes) as afk_tracked')->groupBy('player_id'),
                'afk', 'afk.player_id', '=', 'players.id'
            )
            ->leftJoinSub(
                PlayerClanHistory::query()->whereNull('left_at')->selectRaw('player_id, MAX(clan_id) as clan_id')->groupBy('player_id'),
                'cur_clan', 'cur_clan.player_id', '=', 'players.id'
            )
            ->leftJoin('clans', 'clans.id', '=', 'cur_clan.clan_id')
            ->select('players.*', 'played.minutes as played_minutes', 'afk.afk_sum', 'afk.afk_tracked', 'clans.name as clan_name');

        // afk share over idle-tracked time; null when nothing was tracked → forced to the bottom
        $afkRatio = '(afk.afk_sum * 1.0 / NULLIF(afk.afk_tracked, 0))';

        match ($sort) {
            'played' => $query->orderByRaw('COALESCE(played.minutes, 0) ' . $dir),
            'afk'    => $query->orderByRaw($afkRatio . ' IS NULL ASC')->orderByRaw($afkRatio . ' ' . $dir),
            'clan'   => $query->orderByRaw('clans.name IS NULL ASC')->orderByRaw('clans.name ' . $dir),
            default  => $query->orderBy('players.name', $dir), // 'name' and any unknown sort
        };

        return view('list.player')->with('players', $query->paginate(50)->withQueryString());
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter TeeListSortTest`
Expected: PASS (5 tests). (The page still renders via the existing view; only the ordering changed.)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/MainController.php tests/Feature/TeeListSortTest.php
git commit -m "feat(tees): server-side sortable player list via aggregate joins

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: View — AFK column, joined cells, sortable headers + component

**Files:**
- Create: `resources/views/components/sortable-th.blade.php`
- Modify: `resources/views/list/player.blade.php`
- Modify: `resources/assets/sass/app.scss`
- Test: `tests/Feature/TeeListSortTest.php`

- [ ] **Step 1: Add the failing tests**

In `tests/Feature/TeeListSortTest.php`, add these methods inside the class:

```php
    public function test_afk_column_renders_percentage_and_dash(): void
    {
        $this->seedPlayer('IdleIvan', afk: 40, afkTracked: 80); // 50%
        $this->seedPlayer('FreshNed');                          // no session → no AFK data

        $response = $this->get('/tees');

        $response->assertOk();
        $response->assertSee('50%');
        $response->assertSee('—', false); // FreshNed's AFK cell (he has history, so Mod/Map are not dashes)
    }

    public function test_active_sort_header_shows_a_caret_and_sort_links(): void
    {
        $this->seedPlayer('Solo');

        $response = $this->get('/tees?sort=afk&dir=desc');

        $response->assertOk();
        $response->assertSee('fa-caret-down', false); // active AFK header, descending
        $response->assertSee('sort=afk', false);      // header links carry the sort param
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter TeeListSortTest`
Expected: FAIL — no `50%` / `fa-caret-down` in the current view.

- [ ] **Step 3: Create the sortable header component**

Create `resources/views/components/sortable-th.blade.php`:

```blade
@props(['column', 'label', 'defaultDir' => 'asc'])
@php
    $currentSort = request('sort');
    $currentDir = strtolower((string) request('dir')) === 'desc' ? 'desc' : 'asc';
    $active = $currentSort === $column;
    // when active, clicking flips the direction; when inactive, start at the column's default
    $nextDir = $active ? ($currentDir === 'asc' ? 'desc' : 'asc') : $defaultDir;
    $url = request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
@endphp
<th @class(['sortable-th--active' => $active])>
    <a href="{{ $url }}" class="sortable-th">
        {{ $label }}
        @if ($active)
            <i class="fa fa-caret-{{ $currentDir === 'asc' ? 'up' : 'down' }}"></i>
        @endif
    </a>
</th>
```

- [ ] **Step 4: Rewrite the list view**

Replace the entire contents of `resources/views/list/player.blade.php` with:

```blade
@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Player list</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>Keeping track of:</strong></div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="player_table">
                                <thead>
                                <tr>
                                    <x-sortable-th column="name" label="Tee" />
                                    <x-sortable-th column="clan" label="Clan" />
                                    <x-sortable-th column="played" label="Played time" default-dir="desc" />
                                    <x-sortable-th column="afk" label="AFK %" default-dir="desc" />
                                    <th>Most played mod</th>
                                    <th>Most played map</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($players as $playerEntry)
                                    @php
                                        $topMods = $playerEntry->chartPlayedMods();
                                        $topMaps = $playerEntry->chartPlayedMaps();
                                        $afkTracked = (int) $playerEntry->afk_tracked;
                                    @endphp
                                    <tr>
                                        <th scope="row">
                                            <a href="{{ url("tee", urlencode($playerEntry->name)) }}">{{ $playerEntry->name }}</a>
                                        </th>
                                        <td>
                                            @if ($playerEntry->clan_name)
                                                <a href="{{ url("clan", urlencode($playerEntry->clan_name)) }}">{{ $playerEntry->clan_name }}</a>
                                            @endif
                                        </td>
                                        <td>
                                            {{ \App\Utility\ChartUtility::humanizeDuration((int) $playerEntry->played_minutes) }}
                                        </td>
                                        <td>
                                            {{ $afkTracked > 0 ? round($playerEntry->afk_sum / $afkTracked * 100) . '%' : '—' }}
                                        </td>
                                        <td>
                                            @if ($topMods)
                                                <a href="{{ url("mod", urlencode(array_key_first($topMods))) }}">{{ array_key_first($topMods) }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if ($topMaps)
                                                <a href="{{ url("map", urlencode(array_key_first($topMaps))) }}">{{ array_key_first($topMaps) }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            {{ $players->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
```

- [ ] **Step 5: Add the header styling**

Append to the END of `resources/assets/sass/app.scss` (at top level, after the final closing brace):

```scss
/* clickable column headers on the sortable player list */
.sortable-th {
  color: inherit;
  text-decoration: none;
  white-space: nowrap;

  &:hover {
    text-decoration: underline;
  }

  .fa {
    margin-left: 4px;
    font-size: 0.8em;
  }
}

th.sortable-th--active .sortable-th {
  color: #fff;
}
```

- [ ] **Step 6: Run to verify they pass**

Run: `vendor/bin/phpunit --filter TeeListSortTest`
Expected: PASS (7 tests — the 5 ordering tests still pass, plus the 2 new).

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/sortable-th.blade.php resources/views/list/player.blade.php resources/assets/sass/app.scss tests/Feature/TeeListSortTest.php
git commit -m "feat(tees): AFK % column and sortable headers

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Build + verification

**Files:** none (build + verify only).

- [ ] **Step 1: Full PHP suite**

Run: `vendor/bin/phpunit`
Expected: PASS — no regressions; `TeeListSortTest` (7) green.

- [ ] **Step 2: Build the assets (SCSS changed)**

Run: `npm run build`
Expected: Vite build completes, emits a new `public/build/assets/app-*.css`.

- [ ] **Step 3: Clear the response cache**

Run: `php artisan responsecache:clear`
Expected: `Response cache cleared!` — so the cached `/serverbrowser` page stops pinning the old bundle name.

- [ ] **Step 4: Type-check + lint (unaffected, run for hygiene)**

Run: `npm run type-check && npm run lint`
Expected: PASS (no TS changed).

- [ ] **Step 5: Smoke-check the live page (optional)**

Run: `curl -sk "https://twstats.ddev.site/tees?sort=afk&dir=desc" -o /dev/null -w "%{http_code}\n"`
Expected: `200`.

---

## Self-review notes

- **Spec coverage:** sort allowlist + defaults + invalid fallback (Task 1, `test_invalid_sort…`); `leftJoinSub` aggregates for played/afk/clan with no-data-last ordering (Task 1); AFK % column with `—` (Task 2, `test_afk_column…`); sortable `<x-sortable-th>` headers with caret + default-dir, Mod/Map left as null-guarded info columns (Task 2); build + `responsecache:clear` (Task 3).
- **Type/name consistency:** the controller selects `played_minutes`, `afk_sum`, `afk_tracked`, `clan_name`; the view reads exactly those. The component props are `column`, `label`, `defaultDir` (kebab `default-dir` in markup). `seedPlayer(name, playedMinutes, afk, afkTracked, clan)` is used consistently across all tests.
- **No-data ordering:** `(ratio) IS NULL ASC` / `clans.name IS NULL ASC` prefixes force AFK-less / clanless players last in both directions — asserted by `test_sort_by_afk_…` (ascending still ends with `NoData`).
