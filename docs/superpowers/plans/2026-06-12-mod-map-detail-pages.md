# Mod & Map detail pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fill the empty `/mod/{name}` and `/map/{name}` detail pages with play-time stats, activity charts, and clan/player/server rankings (issue #15).

**Architecture:** A shared `HasPlayStatistics` trait on the sibling `Map`/`Mod` models factors out identical aggregate queries over `PlayerHistory`/`ServerHistory`; each model supplies a scoped base-query hook (Map = `map_id`; Mod = `mod_id OR mod_original_id`, wrapped in a closure). Two thin Blade views feed one shared partial pair that reuses the existing theme components (`.stat-tiles`, `.toplist`, `ChartHelper`).

**Tech Stack:** PHP 8.3 / Laravel 13, Eloquent, Blade, Chart.js (via the already-built `ChartHelper` global). No SCSS/TS/JS changes, so **no `npm run build`** is required.

---

## Background the engineer needs

- **History tables** store *aggregated minutes* per row: `player_histories` (`weekday, hour, continuous, server_id, player_id, map_id, mod_id, mod_original_id?, minutes`) and `server_histories` (same minus `player_id`). `mod_original_id` is nullable and points a *variant* mod at its parent.
- A mod's records are everything matching `mod_id = X OR mod_original_id = X`. A map's records are `map_id = X`.
- Clan membership: `player_clan_histories` (`player_id, clan_id, joined_at, left_at?`); current members have `left_at IS NULL`.
- **Reusable theme markup already exists** — do NOT add CSS:
  - KPI tiles: `<div class="stat-tiles"><div class="stat-tile"><div class="stat-tile__label">…</div><div class="stat-tile__value"><i class="fa fa-…"></i>…</div></div>…</div>`
  - Ranking lists: `<ul class="toplist toplist--servers"><li><div class="toplist__row"><a class="toplist__name" href>…</a><span class="toplist__val">…</span></div><div class="toplist__bar"><span style="width: N%"></span></div></li></ul>`
  - Charts: `.line-chart.block.chart` + `<canvas>`, `.pie-chart.chart.block` + `.radar-chart.chart` wrapper + `<canvas>`. Wire via `ChartHelper.lineChart(el, labels, data)` / `ChartHelper.pieChart(el, labels, data)` / `ChartHelper.chartColors(chart, {0:[…],100:[…]})` inside a `DOMContentLoaded` handler, passing PHP data through `blade({!! json_encode(...) !!})`. See `resources/views/detail/server.blade.php` for the canonical example.
  - Player avatar: `<x-player-tee :player="$player" :size="28" />`.
- `App\Utility\ChartUtility::humanizeDuration($minutes)` turns minutes into "1d 4h"; `ChartUtility::applyLimits(&$results, $amount, $displayOthers)` folds a `[name => minutes]` map to top-N + "others".
- Tests: DB-backed tests live in `tests/Feature`, extend `Tests\TestCase`, use `RefreshDatabase`. `Map`/`Mod` have NO `HasFactory` trait → create with `Mod::create(['name' => …])`. `Player`/`Server`/`Clan` DO have factories. SQLite enforces foreign keys, so every `*_id` must reference a row you created. `phpunit.xml` sets `CRONTASK_INTERVAL`.

## File structure

- Create: `app/Models/Concerns/HasPlayStatistics.php` — the shared aggregate trait.
- Modify: `app/Models/Map.php` — add trait + hooks + `chartPlayedMods()`.
- Modify: `app/Models/Mod.php` — add trait + hooks + `chartPlayedMaps()`.
- Create: `resources/views/partials/play-stats.blade.php` — shared page body.
- Create: `resources/views/partials/play-stats-scripts.blade.php` — shared inline chart wiring.
- Rewrite: `resources/views/detail/mod.blade.php` — thin wrapper.
- Rewrite: `resources/views/detail/map.blade.php` — thin wrapper.
- Create: `tests/Feature/MapModStatisticsTest.php` — trait/model logic.
- Create: `tests/Feature/MapModDetailPageTest.php` — HTTP rendering.

No controller or route changes (`SearchController::searchModByName`/`searchMapByName` already pass `$mod`/`$map`).

---

## Task 1: `HasPlayStatistics` trait + Map/Mod model wiring

**Files:**
- Create: `app/Models/Concerns/HasPlayStatistics.php`
- Modify: `app/Models/Map.php`
- Modify: `app/Models/Mod.php`
- Test: `tests/Feature/MapModStatisticsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapModStatisticsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\Server;
use App\Models\ServerHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapModStatisticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * create a player_histories row, filling every NOT NULL column.
     */
    private function playRecord(array $overrides): PlayerHistory
    {
        return PlayerHistory::create(array_merge([
            'weekday'    => 1,
            'hour'       => 20,
            'continuous' => true,
            'minutes'    => 60,
        ], $overrides));
    }

    public function test_total_player_minutes_sums_the_maps_records(): void
    {
        $map = Map::create(['name' => 'ctf1']);
        $mod = Mod::create(['name' => 'ctf']);
        $server = Server::factory()->create();
        $player = Player::factory()->create();

        $this->playRecord(['server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 40]);
        $this->playRecord(['server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 75]);

        $this->assertSame(115, $map->totalPlayerMinutes());
    }

    public function test_mod_aggregates_variant_records_via_mod_original_id(): void
    {
        $parent  = Mod::create(['name' => 'race']);
        $variant = Mod::create(['name' => 'fastrace']);
        $map     = Map::create(['name' => 'dm1']);
        $server  = Server::factory()->create();
        $player  = Player::factory()->create();

        // a direct record on the parent mod
        $this->playRecord(['server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $parent->id, 'minutes' => 70]);
        // a record on the variant that points back at the parent via mod_original_id
        $this->playRecord(['server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $variant->id, 'mod_original_id' => $parent->id, 'minutes' => 50]);

        $this->assertSame(120, $parent->totalPlayerMinutes());
    }

    public function test_top_players_orders_by_summed_minutes(): void
    {
        $mod    = Mod::create(['name' => 'dm']);
        $map    = Map::create(['name' => 'dm2']);
        $server = Server::factory()->create();
        $alpha  = Player::factory()->create(['name' => 'Alpha']);
        $bravo  = Player::factory()->create(['name' => 'Bravo']);

        $this->playRecord(['server_id' => $server->id, 'player_id' => $alpha->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 30]);
        $this->playRecord(['server_id' => $server->id, 'player_id' => $bravo->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 90]);

        $top = $mod->topPlayers(10);

        $this->assertSame($bravo->id, (int) $top->first()->player_id);
        $this->assertSame(90, (int) $top->first()->sum_minutes);
        $this->assertSame('Bravo', $top->first()->player->name);
    }

    public function test_top_clans_excludes_players_who_left(): void
    {
        $mod     = Mod::create(['name' => 'ctf']);
        $map     = Map::create(['name' => 'ctf2']);
        $server  = Server::factory()->create();
        $stayer  = Player::factory()->create();
        $leaver  = Player::factory()->create();
        $current = Clan::factory()->create(['name' => 'CurrentClan']);
        $former  = Clan::factory()->create(['name' => 'FormerClan']);

        PlayerClanHistory::create(['player_id' => $stayer->id, 'clan_id' => $current->id, 'left_at' => null]);
        PlayerClanHistory::create(['player_id' => $leaver->id, 'clan_id' => $former->id, 'left_at' => now()]);

        $this->playRecord(['server_id' => $server->id, 'player_id' => $stayer->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 80]);
        $this->playRecord(['server_id' => $server->id, 'player_id' => $leaver->id, 'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 200]);

        $topClans = $mod->topClans(10);

        $this->assertCount(1, $topClans);
        $this->assertSame($current->id, $topClans->first()['clan']->id);
        $this->assertSame(80, $topClans->first()['minutes']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MapModStatisticsTest`
Expected: FAIL — `Error: Call to undefined method App\Models\Map::totalPlayerMinutes()`.

- [ ] **Step 3: Create the trait**

Create `app/Models/Concerns/HasPlayStatistics.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Models\Clan;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\ServerHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared play-time aggregates for the sibling Map/Mod models. Each model supplies the
 * two scoped base-query hooks; everything else is computed identically.
 */
trait HasPlayStatistics
{
    /**
     * a PlayerHistory query already constrained to this record. Implementations MUST wrap
     * any OR condition in a nested closure so the AND-wheres added below cannot leak past it.
     */
    abstract protected function playerHistoryQuery(): Builder;

    /**
     * a ServerHistory query already constrained to this record (same nesting contract).
     */
    abstract protected function serverHistoryQuery(): Builder;

    /**
     * total minutes players accumulated on this record
     */
    public function totalPlayerMinutes(): int
    {
        return (int) $this->playerHistoryQuery()->sum('minutes');
    }

    /**
     * total minutes this record was live on servers
     */
    public function totalServerMinutes(): int
    {
        return (int) $this->serverHistoryQuery()->sum('minutes');
    }

    /**
     * distinct players with a record on this record
     */
    public function distinctPlayersCount(): int
    {
        return $this->playerHistoryQuery()->distinct()->count('player_id');
    }

    /**
     * distinct servers this record was live on
     */
    public function distinctServersCount(): int
    {
        return $this->serverHistoryQuery()->distinct()->count('server_id');
    }

    /**
     * top players by accumulated minutes; rows carry `sum_minutes` and an eager-loaded `player`.
     *
     * @return \Illuminate\Database\Eloquent\Collection|PlayerHistory[]
     */
    public function topPlayers(int $limit = 10)
    {
        return $this->playerHistoryQuery()
            ->selectRaw('player_id, SUM(minutes) as sum_minutes')
            ->groupBy('player_id')
            ->orderByDesc('sum_minutes')
            ->with('player')
            ->limit($limit)
            ->get();
    }

    /**
     * top servers by accumulated minutes this record was live; rows carry `sum_minutes`
     * and an eager-loaded `server`.
     *
     * @return \Illuminate\Database\Eloquent\Collection|ServerHistory[]
     */
    public function topServers(int $limit = 10)
    {
        return $this->serverHistoryQuery()
            ->selectRaw('server_id, SUM(minutes) as sum_minutes')
            ->groupBy('server_id')
            ->orderByDesc('sum_minutes')
            ->with('server')
            ->limit($limit)
            ->get();
    }

    /**
     * top clans by accumulated minutes their *current* members played on this record.
     * Returns a normalised shape because PlayerHistory has no clan relation.
     *
     * @return Collection<int, array{clan: Clan, minutes: int}>
     */
    public function topClans(int $limit = 10): Collection
    {
        $history = (new PlayerHistory)->getTable();
        $clans   = (new PlayerClanHistory)->getTable();

        $rows = $this->playerHistoryQuery()
            ->join($clans, "$history.player_id", '=', "$clans.player_id")
            ->whereNull("$clans.left_at")
            ->selectRaw("$clans.clan_id as clan_id, SUM($history.minutes) as sum_minutes")
            ->groupBy("$clans.clan_id")
            ->orderByDesc('sum_minutes')
            ->limit($limit)
            ->get();

        $clanModels = Clan::whereIn('id', $rows->pluck('clan_id'))->get()->keyBy('id');

        return $rows
            ->map(fn ($row) => [
                'clan'    => $clanModels->get($row->clan_id),
                'minutes' => (int) $row->sum_minutes,
            ])
            ->filter(fn ($entry) => $entry['clan'] !== null)
            ->values();
    }

    /**
     * server-online minutes per weekday (0=Mon … 6=Sun), scaled to 0–100 of the busiest slot.
     *
     * @return array<int, float|int>
     */
    public function chartOnlineDays(): array
    {
        return $this->normalisedDistribution('weekday', 7);
    }

    /**
     * server-online minutes per hour (0–23), scaled to 0–100 of the busiest slot.
     *
     * @return array<int, float|int>
     */
    public function chartOnlineHours(): array
    {
        return $this->normalisedDistribution('hour', 24);
    }

    /**
     * sum server-online minutes grouped by $column, fill all $slots, scale to 0–100.
     *
     * @return array<int, float|int>
     */
    private function normalisedDistribution(string $column, int $slots): array
    {
        $entries = $this->serverHistoryQuery()
            ->selectRaw("$column as slot, SUM(minutes) as sum_minutes")
            ->groupBy($column)
            ->get();

        $results = array_fill(0, $slots, 0);
        $max = (int) $entries->max('sum_minutes') ?: 1;

        foreach ($entries as $entry) {
            $slot = (int) $entry->getAttribute('slot');
            if ($slot < 0 || $slot >= $slots) {
                continue;
            }
            $results[$slot] = round($entry->getAttribute('sum_minutes') / $max * 100, 2);
        }

        return $results;
    }
}
```

- [ ] **Step 4: Wire the trait into `Map`**

In `app/Models/Map.php`, add the imports below the existing `use` lines (after `use Illuminate\Database\Eloquent\Model;`):

```php
use App\Models\Concerns\HasPlayStatistics;
use App\Utility\ChartUtility;
use Illuminate\Database\Eloquent\Builder;
```

Add the trait to the class body (just after the opening `{` / the `protected $guarded` line is fine too):

```php
    use HasPlayStatistics;
```

Append these methods inside the `Map` class (before the closing `}`):

```php
    /**
     * a PlayerHistory query scoped to this map
     */
    protected function playerHistoryQuery(): Builder
    {
        return PlayerHistory::where('map_id', $this->getAttribute('id'));
    }

    /**
     * a ServerHistory query scoped to this map
     */
    protected function serverHistoryQuery(): Builder
    {
        return ServerHistory::where('map_id', $this->getAttribute('id'));
    }

    /**
     * most-played mods on this map, as a [mod name => minutes] map for the cross chart
     *
     * @return array<string, int>
     */
    public function chartPlayedMods($amount = 10, $displayOthers = true): array
    {
        $playedMods = $this->playerHistoryQuery()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`minutes`) as `sum_minutes`')
            ->groupBy('mod_id')
            ->orderByDesc('sum_minutes')
            ->get();

        $results = [];
        /** @var PlayerHistory $playedMod */
        foreach ($playedMods as $playedMod) {
            $results[$playedMod->mod->getAttribute('name')] = (int) $playedMod->getAttribute('sum_minutes');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }
```

- [ ] **Step 5: Wire the trait into `Mod`**

In `app/Models/Mod.php`, add the imports below `use Illuminate\Database\Eloquent\Model;`:

```php
use App\Models\Concerns\HasPlayStatistics;
use App\Utility\ChartUtility;
use Illuminate\Database\Eloquent\Builder;
```

Add the trait to the class body:

```php
    use HasPlayStatistics;
```

Append these methods inside the `Mod` class (before the closing `}`). The OR is wrapped in a closure so the trait's later AND-wheres (e.g. the clan join's `left_at` filter) stay correctly grouped:

```php
    /**
     * a PlayerHistory query scoped to this mod, counting variant records (mod_original_id).
     * The OR is wrapped so trait-added AND-wheres don't leak past it.
     */
    protected function playerHistoryQuery(): Builder
    {
        $id = $this->getAttribute('id');

        return PlayerHistory::where(function (Builder $query) use ($id) {
            $query->where('mod_id', $id)->orWhere('mod_original_id', $id);
        });
    }

    /**
     * a ServerHistory query scoped to this mod, counting variant records (mod_original_id).
     */
    protected function serverHistoryQuery(): Builder
    {
        $id = $this->getAttribute('id');

        return ServerHistory::where(function (Builder $query) use ($id) {
            $query->where('mod_id', $id)->orWhere('mod_original_id', $id);
        });
    }

    /**
     * most-played maps on this mod, as a [map name => minutes] map for the cross chart
     *
     * @return array<string, int>
     */
    public function chartPlayedMaps($amount = 10, $displayOthers = true): array
    {
        $playedMaps = $this->playerHistoryQuery()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`minutes`) as `sum_minutes`')
            ->groupBy('map_id')
            ->orderByDesc('sum_minutes')
            ->get();

        $results = [];
        /** @var PlayerHistory $playedMap */
        foreach ($playedMaps as $playedMap) {
            $results[$playedMap->map->getAttribute('name')] = (int) $playedMap->getAttribute('sum_minutes');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter MapModStatisticsTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Models/Concerns/HasPlayStatistics.php app/Models/Map.php app/Models/Mod.php tests/Feature/MapModStatisticsTest.php
git commit -m "feat(models): play-time aggregates for maps & mods (#15)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Detail views + shared partials

**Files:**
- Create: `resources/views/partials/play-stats.blade.php`
- Create: `resources/views/partials/play-stats-scripts.blade.php`
- Rewrite: `resources/views/detail/mod.blade.php`
- Rewrite: `resources/views/detail/map.blade.php`
- Test: `tests/Feature/MapModDetailPageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MapModDetailPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\Server;
use App\Models\ServerHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapModDetailPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * seed one map, one mod, and a player (in a clan) + server with both history kinds,
     * then return [map, mod] for the page under test.
     *
     * @return array{0: Map, 1: Mod}
     */
    private function seedPlayData(): array
    {
        $map    = Map::create(['name' => 'RankedMap']);
        $mod    = Mod::create(['name' => 'RankedMod']);
        $server = Server::factory()->create(['name' => 'RankedServer']);
        $player = Player::factory()->create(['name' => 'RankedTee']);
        $clan   = Clan::factory()->create(['name' => 'RankedClan']);

        PlayerClanHistory::create(['player_id' => $player->id, 'clan_id' => $clan->id, 'left_at' => null]);

        PlayerHistory::create([
            'weekday' => 1, 'hour' => 20, 'continuous' => true,
            'server_id' => $server->id, 'player_id' => $player->id,
            'map_id' => $map->id, 'mod_id' => $mod->id, 'minutes' => 120,
        ]);

        ServerHistory::create([
            'weekday' => 1, 'hour' => 20, 'continuous' => true,
            'server_id' => $server->id, 'map_id' => $map->id,
            'mod_id' => $mod->id, 'minutes' => 300,
        ]);

        return [$map, $mod];
    }

    public function test_mod_detail_page_renders_stats_and_rankings(): void
    {
        $this->seedPlayData();

        $response = $this->get('/mod/' . urlencode('RankedMod') . '/');

        $response->assertStatus(200);
        $response->assertSee('Mod: RankedMod');
        $response->assertSee('Top players');
        $response->assertSee('Top clans');
        $response->assertSee('Top servers');
        $response->assertSee('RankedTee');
        $response->assertSee('RankedClan');
        $response->assertSee('RankedServer');
    }

    public function test_map_detail_page_renders_stats_and_rankings(): void
    {
        $this->seedPlayData();

        $response = $this->get('/map/' . urlencode('RankedMap') . '/');

        $response->assertStatus(200);
        $response->assertSee('Map: RankedMap');
        $response->assertSee('Top players');
        $response->assertSee('RankedTee');
        $response->assertSee('RankedClan');
        $response->assertSee('RankedServer');
    }

    public function test_empty_map_detail_page_still_renders(): void
    {
        Map::create(['name' => 'LonelyMap']);

        $response = $this->get('/map/' . urlencode('LonelyMap') . '/');

        $response->assertStatus(200);
        $response->assertSee('Map: LonelyMap');
        $response->assertSee('No player activity recorded yet.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MapModDetailPageTest`
Expected: FAIL — the stub views return 200 but `assertSee('Top players')` fails ("Unable to find … 'Top players'").

- [ ] **Step 3: Create the shared body partial**

Create `resources/views/partials/play-stats.blade.php`:

```blade
@php
    use App\Utility\ChartUtility;

    // $entity, $kind, $crossTitle, $crossChart are provided by the including view.
    $humanize = fn ($minutes) => ChartUtility::humanizeDuration((int) $minutes);

    $topPlayers = $entity->topPlayers(10);
    $topClans   = $entity->topClans(10);
    $topServers = $entity->topServers(10);

    $topPlayersMax = $topPlayers->max('sum_minutes') ?: 1;
    $topClansMax   = $topClans->max('minutes') ?: 1;
    $topServersMax = $topServers->max('sum_minutes') ?: 1;
@endphp

<!-- Page Header-->
<div class="page-header no-margin-bottom">
    <div class="container-fluid">
        <h2 class="h5 no-margin-bottom">{{ $kind }}: {{ $entity->name }}</h2>
    </div>
</div>

<section class="section-content">
    <div class="container-fluid">

        <!-- Summary tiles -->
        <div class="stat-tiles">
            <div class="stat-tile">
                <div class="stat-tile__label">Played by players</div>
                <div class="stat-tile__value"><i class="fa fa-clock-o"></i>{{ $humanize($entity->totalPlayerMinutes()) }}</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile__label">Online on servers</div>
                <div class="stat-tile__value"><i class="fa fa-server"></i>{{ $humanize($entity->totalServerMinutes()) }}</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile__label">Players</div>
                <div class="stat-tile__value"><i class="fa fa-users"></i>{{ number_format($entity->distinctPlayersCount()) }}</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile__label">Servers</div>
                <div class="stat-tile__value"><i class="fa fa-hdd-o"></i>{{ number_format($entity->distinctServersCount()) }}</div>
            </div>
        </div>

        <!-- Activity charts -->
        <div class="row">
            <div class="col-lg-6">
                <div class="line-chart block chart">
                    <div class="title"><strong>{{ $entity->name }}'s online probability</strong></div>
                    <canvas id="onlineLineChartDays"></canvas>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="line-chart block chart">
                    <div class="title"><strong>{{ $entity->name }}'s online probability per day</strong></div>
                    <canvas id="onlineLineChartHours"></canvas>
                </div>
            </div>
        </div>

        <!-- Cross chart -->
        <div class="row">
            <div class="col-lg-6">
                <div class="pie-chart chart block">
                    <div class="title"><strong>{{ $crossTitle }}</strong></div>
                    @if (count($crossChart))
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="crossChart"></canvas>
                        </div>
                    @else
                        <p class="text-small" style="color: #75787f">No play data yet.</p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Rankings -->
        <div class="row">
            <div class="col-lg-4">
                <div class="block">
                    <div class="title"><strong>Top players</strong></div>
                    @if ($topPlayers->count())
                        <ul class="toplist toplist--servers">
                            @foreach ($topPlayers as $row)
                                @continue(!$row->player)
                                <li>
                                    <div class="toplist__row">
                                        <a class="toplist__name d-flex align-items-center gap-2"
                                           href="{{ url('tee', urlencode($row->player->name)) }}">
                                            <x-player-tee :player="$row->player" :size="28" />
                                            {{ $row->player->name }}
                                        </a>
                                        <span class="toplist__val">{{ $humanize($row->sum_minutes) }}</span>
                                    </div>
                                    <div class="toplist__bar">
                                        <span style="width: {{ round($row->sum_minutes / $topPlayersMax * 100) }}%"></span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-small" style="color: #75787f">No player activity recorded yet.</p>
                    @endif
                </div>
            </div>
            <div class="col-lg-4">
                <div class="block">
                    <div class="title"><strong>Top clans</strong></div>
                    @if ($topClans->count())
                        <ul class="toplist toplist--servers">
                            @foreach ($topClans as $row)
                                <li>
                                    <div class="toplist__row">
                                        <a class="toplist__name"
                                           href="{{ url('clan', urlencode($row['clan']->name)) }}">{{ $row['clan']->name }}</a>
                                        <span class="toplist__val">{{ $humanize($row['minutes']) }}</span>
                                    </div>
                                    <div class="toplist__bar">
                                        <span style="width: {{ round($row['minutes'] / $topClansMax * 100) }}%"></span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-small" style="color: #75787f">No clan activity recorded yet.</p>
                    @endif
                </div>
            </div>
            <div class="col-lg-4">
                <div class="block">
                    <div class="title"><strong>Top servers</strong></div>
                    @if ($topServers->count())
                        <ul class="toplist toplist--servers">
                            @foreach ($topServers as $row)
                                @continue(!$row->server)
                                <li>
                                    <div class="toplist__row">
                                        <a class="toplist__name"
                                           href="{{ url('server', [urlencode($row->server->id), urlencode($row->server->name)]) }}">{{ $row->server->name }}</a>
                                        <span class="toplist__val">{{ $humanize($row->sum_minutes) }}</span>
                                    </div>
                                    <div class="toplist__bar">
                                        <span style="width: {{ round($row->sum_minutes / $topServersMax * 100) }}%"></span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-small" style="color: #75787f">No server activity recorded yet.</p>
                    @endif
                </div>
            </div>
        </div>

    </div>
</section>
```

- [ ] **Step 4: Create the shared scripts partial**

Create `resources/views/partials/play-stats-scripts.blade.php` (mirrors `detail/server.blade.php`'s script block; `$entity`/`$crossChart` come from the including view):

```blade
<script>
    document.addEventListener('DOMContentLoaded', function () {

        ChartHelper.lineChart(document.getElementById('onlineLineChartDays'),
            ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
            blade({!! json_encode($entity->chartOnlineDays()) !!})
        );

        ChartHelper.lineChart(document.getElementById('onlineLineChartHours'),
            ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
            blade({!! json_encode($entity->chartOnlineHours()) !!})
        );

        @if (count($crossChart))
        let crossChart = ChartHelper.pieChart(document.getElementById('crossChart'),
            blade({!! json_encode(array_keys($crossChart)) !!}),
            blade({!! json_encode(array_values($crossChart)) !!})
        );
        ChartHelper.chartColors(crossChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
        @endif

    });
</script>
```

- [ ] **Step 5: Rewrite `detail/mod.blade.php`**

Replace the entire contents of `resources/views/detail/mod.blade.php` with:

```blade
@extends('layouts.app')

@php
    // view-model for the shared play-stats partial
    $entity     = $mod;
    $kind       = 'Mod';
    $crossTitle = 'Most played maps';
    $crossChart = $mod->chartPlayedMaps();
@endphp

@section('content')
    @include('partials.play-stats')
@endsection

@section('scripts')
    @include('partials.play-stats-scripts')
@endsection
```

- [ ] **Step 6: Rewrite `detail/map.blade.php`**

Replace the entire contents of `resources/views/detail/map.blade.php` with:

```blade
@extends('layouts.app')

@php
    // view-model for the shared play-stats partial
    $entity     = $map;
    $kind       = 'Map';
    $crossTitle = 'Most played mods';
    $crossChart = $map->chartPlayedMods();
@endphp

@section('content')
    @include('partials.play-stats')
@endsection

@section('scripts')
    @include('partials.play-stats-scripts')
@endsection
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter MapModDetailPageTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add resources/views/partials/play-stats.blade.php resources/views/partials/play-stats-scripts.blade.php resources/views/detail/mod.blade.php resources/views/detail/map.blade.php tests/Feature/MapModDetailPageTest.php
git commit -m "feat(frontend): fill mod & map detail pages with stats, charts & rankings (#15)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the entire PHP test suite**

Run: `vendor/bin/phpunit`
Expected: PASS — no regressions; the two new test classes (7 tests) are green.

- [ ] **Step 2: Confirm no front-end build is needed**

No `.ts`/`.scss` files changed, so the content-hashed bundle is unchanged and `npm run build` / `responsecache:clear` are not required. The new views only use already-shipped globals (`ChartHelper`, `blade`, `Chart`) and existing CSS classes. (Sanity check, optional:)

Run: `git status --porcelain resources/assets`
Expected: empty (no asset sources touched).

- [ ] **Step 3: Lint/type-check sanity (optional, should be unaffected)**

Run: `npm run type-check && npm run lint`
Expected: PASS unchanged (no TS touched). Skip if the container's node deps aren't installed.

---

## Self-review notes

- **Spec coverage:** "played by players (time)" → `totalPlayerMinutes` tile + top-players list; "online on servers (time)" → `totalServerMinutes` tile + weekday/hour activity charts; "ranking by clans/players/servers" → three `toplist` blocks; cross chart (`chartPlayedMaps`/`chartPlayedMods`) is the agreed extra. All spec sections map to Task 1/Task 2.
- **Mod OR-scoping** is implemented via the nested-closure hook and guarded by `test_mod_aggregates_variant_records_via_mod_original_id`.
- **Empty data** path is covered by `test_empty_map_detail_page_still_renders` and the `max ?: 1` guard in `normalisedDistribution`.
- **Type consistency:** `topPlayers`/`topServers` return history rows read as `$row->sum_minutes` + `$row->player`/`$row->server`; `topClans` returns `['clan' => Clan, 'minutes' => int]` read as `$row['clan']`/`$row['minutes']` — the partial uses exactly these shapes.
```
