# Mod & Map detail pages (issue #15)

## Problem

The detail pages for mods (`/mod/{name}`) and maps (`/map/{name}`) are empty stubs —
they render only a page header. The list pages, the navbar global search, and the
server/player/clan detail pages already link into them, so the dead ends are reachable
but show nothing.

Issue #15 asks each page to show:

- time played by players,
- time online on servers,
- rankings by clans / players / servers.

The rest of the site (server/clan/player detail pages) is chart-heavy. The decision for
this work is to **match that richness** rather than ship a minimal three-list page.

## Goals

A mod page and a map page, near-identical in shape, each showing:

- **KPI tiles** — total player play-time, total server-online time, distinct players,
  distinct servers.
- **Activity charts** — server-online minutes distributed across weekday and across hour
  (two line charts, normalised 0–100, like the server page's "online probability").
- **Cross chart** — most-played *maps on this mod* (mod page) / most-played *mods on this
  map* (map page), as the existing pie/radar chart.
- **Three ranking toplists** — top players, top clans, top servers, ranked by accumulated
  play-time minutes.

## Non-goals

- No date-range / duration filtering UI (helpers default to all-time, `duration = 0`).
- No new chart *types* — reuse `ChartHelper.lineChart` / `pieChart`.
- No controller or route changes; no changes to the existing list pages.
- Not fixing the pre-existing `Mod::totalHoursOnline` OR-scoping latent bug (out of scope;
  the new helpers simply avoid the pattern).

## Data model context

`PlayerHistory` and `ServerHistory` store **aggregated `minutes`** keyed by
`weekday` / `hour` / `map_id` / `mod_id` (+ nullable `mod_original_id` for variant mods).

- `Map` history is filtered by `map_id = X`.
- `Mod` history is filtered by `mod_id = X OR mod_original_id = X` — a variant mod's rows
  count toward the parent mod.
- Clan membership lives in `player_clan_histories` (`clan_id`, `player_id`, `left_at`);
  current members have `left_at IS NULL`.

## Design

### 1. `App\Models\Concerns\HasPlayStatistics` trait

`Map` and `Mod` are siblings that differ only in how a history query is scoped to "this
record". The trait factors out the identical aggregate logic; each model supplies two hooks
that return a **correctly-scoped base query**:

```php
// Map
protected function playerHistoryQuery(): Builder { return PlayerHistory::where('map_id', $this->id); }
protected function serverHistoryQuery(): Builder { return ServerHistory::where('map_id', $this->id); }

// Mod — the OR is wrapped in a nested closure so any later AND-where the trait adds
// (e.g. the clan join's left_at filter) does NOT leak past the OR and over-count.
protected function playerHistoryQuery(): Builder {
    return PlayerHistory::where(fn ($q) => $q->where('mod_id', $this->id)->orWhere('mod_original_id', $this->id));
}
protected function serverHistoryQuery(): Builder {
    return ServerHistory::where(fn ($q) => $q->where('mod_id', $this->id)->orWhere('mod_original_id', $this->id));
}
```

Trait methods (all built on the two hooks):

| Method | Returns | Notes |
| --- | --- | --- |
| `totalPlayerMinutes(): int` | summed `minutes` of player histories | KPI tile |
| `totalServerMinutes(): int` | summed `minutes` of server histories | KPI tile |
| `distinctPlayersCount(): int` | `distinct count(player_id)` | KPI tile |
| `distinctServersCount(): int` | `distinct count(server_id)` | KPI tile |
| `topPlayers(int $limit = 10)` | `PlayerHistory` rows, `player_id` + `SUM(minutes) as sum_minutes`, `desc`, `with('player')` | top-players toplist |
| `topServers(int $limit = 10)` | `ServerHistory` rows, `server_id` + `sum_minutes`, `with('server')` | top-servers toplist |
| `topClans(int $limit = 10)` | collection of `['clan' => Clan, 'minutes' => int]` | joins `player_clan_histories` on `left_at IS NULL`, groups `clan_id`, resolves the `Clan` models in one `whereIn` (no N+1) |
| `chartOnlineDays(): array` | 7 values 0–100 | server-history minutes per weekday, normalised by max (`max ?: 1` guard) |
| `chartOnlineHours(): array` | 24 values 0–100 | server-history minutes per hour, same normalisation |

`topPlayers`/`topServers` mirror the shape of `Player::favoriteServers` (history rows
carrying a `sum_minutes` attribute and an eager-loaded relation), so the view can read
`$row->sum_minutes` and `$row->player` / `$row->server`. `topClans` has no clan relation on
the history row, so it returns a normalised `['clan', 'minutes']` shape instead.

### 2. Per-model cross chart

Each model keeps its own cross-chart helper (the trait can't, because the dimension
differs), reusing `ChartUtility::applyLimits` exactly like the existing
`chartPlayedMaps`/`chartPlayedMods` on `Server`/`Player`/`Clan`:

- `Mod::chartPlayedMaps($amount = 10)` — accumulated player minutes per `map_id`, keyed by
  map name.
- `Map::chartPlayedMods($amount = 10)` — accumulated player minutes per `mod_id`, keyed by
  mod name; `ksort` when `>= 3` for the radar layout (matching the existing convention).

### 3. View layer

`detail/mod.blade.php` and `detail/map.blade.php` become thin (~15-line) wrappers that set
a small view-model and include shared partials:

```blade
@extends('layouts.app')
@php
    $entity     = $mod;            // or $map
    $kind       = 'Mod';           // or 'Map'
    $crossTitle = 'Most played maps';
    $crossChart = $mod->chartPlayedMaps();   // or $map->chartPlayedMods()
@endphp
@section('content') @include('partials.play-stats') @endsection
@section('scripts') @include('partials.play-stats-scripts') @endsection
```

- `partials/play-stats.blade.php` — page header, KPI tile row, two activity line-chart
  canvases, the cross pie/radar canvas, and three `<ul class="toplist">` rankings (top
  players show their tee via `<x-player-tee>`; clans/servers are linked names). Each
  toplist has an empty-state line (`No … recorded yet`) like the player page.
- `partials/play-stats-scripts.blade.php` — the inline `ChartHelper` wiring (`lineChart`
  ×2, `pieChart` ×1) using the `blade()` / `Chart` / `ChartHelper` globals and
  `json_encode`, exactly like `detail/server.blade.php`.

Reuses existing components/classes: `.block`, `.line-chart`, `.pie-chart`, `.toplist`
(+ `toplist__row/__name/__val/__bar`), `<x-player-tee>`, `ChartUtility::humanizeDuration`.
A small stat-tile rule is added to `dark_admin_bootstrapius.scss` **only if** an existing
class can't carry the KPI row; followed by `npm run build`. (`/mod` and `/map` are not
response-cached — only `/serverbrowser` is — so no `responsecache:clear` is required.)

### 4. Controller & routes

No change. `SearchController::searchModByName` / `searchMapByName` already resolve the model
(or redirect to fuzzy suggestions) and pass `$mod` / `$map` to these views.

## Testing

Per the repo's regression-test convention for features in own code with a test environment.
`Map`/`Mod` have no factory — create them with `::create(['name' => …])`; `Player`/`Server`/
`Clan` have factories; `PlayerHistory`/`ServerHistory`/`PlayerClanHistory` rows are created
directly. `phpunit.xml` already sets `CRONTASK_INTERVAL`.

- **Feature** (`tests/Feature`): `GET /mod/{name}` and `GET /map/{name}` return 200 and the
  rendered HTML contains a top-ranked player/clan/server name. A missing name redirects to
  search (existing behaviour — assert it still holds).
- **Unit** (`tests/Unit`): `topPlayers` orders by summed minutes and respects the limit;
  `totalPlayerMinutes` sums correctly; **`mod_original_id` aggregation** — a `PlayerHistory`
  row whose `mod_original_id` points at the parent mod is counted in the parent's totals and
  rankings; `topClans` excludes players who have left (`left_at` set).

## Risks / edge cases

- **Empty data** — a mod/map with no history: KPI tiles show `0`, charts get the `max ?: 1`
  guard, toplists show their empty-state line. Covered by rendering the page for a freshly
  `::create`d record in a feature test.
- **Mod OR-scoping** — addressed by wrapping the OR in a nested closure in the hook; the
  `mod_original_id` unit test guards against regression.
- **Query count** — ~8–10 grouped queries per page, in line with the other detail pages.
```
