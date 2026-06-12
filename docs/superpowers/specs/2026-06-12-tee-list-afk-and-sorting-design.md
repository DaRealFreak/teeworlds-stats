# Tee list: AFK column + server-side sortable columns

## Problem

`/tees` (the player list) is `Player::orderBy('name')->paginate(50)` over ~31,000 players,
with each row computing its clan, played time, and top mod/map per-row (N+1). It has a fixed
name sort and no AFK information. We want to add an **AFK %** column and make the meaningful
columns **sortable across the whole list** (not just the current page).

## Goals

- Add an AFK % column to the tee list, consistent with the player detail page's honesty
  (exact share over idle-tracked time; `—` when there is no idle-tracked data).
- Make **Tee, Clan, Played time, AFK %** sortable server-side (the sort reorders all ~31k
  players and resets to page 1, carried in the URL so paging preserves it).
- Compute the sortable aggregates in SQL via joins, which also removes the current per-row
  `clan()` / `totalHoursOnline()` N+1.

## Non-goals

- **Mod / Map columns stay non-sortable info columns.** Sorting by a player's top mod/map is
  low-value here (more of a live-statistics feature) and out of scope.
- **No denormalization.** Aggregates are computed via joined grouped subqueries per request.
  If the list ever gets slow, a follow-up can denormalize totals onto `players` (scraper-
  maintained) — explicitly deferred (YAGNI).
- No change to other lists (`/clans`, `/servers`, `/mods`, `/maps`).

## Design

### 1. Sort parameters (allowlisted)

`MainController::players(Request $request)` reads `?sort=` and `?dir=`:

- `sort ∈ {name, played, afk, clan}`; anything else (or absent) → `name`.
- `dir ∈ {asc, desc}`; anything else → `asc`.

Invalid values fall back silently (no error, 200). Pagination uses `->withQueryString()` so
`sort`/`dir` persist across pages.

### 2. Query (aggregates via joins, no N+1 for the sortable columns)

The paginated query left-joins three grouped derived tables and the `clans` table:

```php
Player::query()
    ->leftJoinSub(
        PlayerHistory::query()->selectRaw('player_id, SUM(minutes) as minutes')->groupBy('player_id'),
        'played', 'played.player_id', '=', 'players.id'
    )
    ->leftJoinSub(
        PlayerSession::query()->selectRaw('player_id, SUM(afk_minutes) as afk_sum, SUM(afk_tracked_minutes) as afk_tracked')->groupBy('player_id'),
        'afk', 'afk.player_id', '=', 'players.id'
    )
    ->leftJoinSub(
        PlayerClanHistory::query()->selectRaw('player_id, MAX(clan_id) as clan_id')->whereNull('left_at')->groupBy('player_id'),
        'cur_clan', 'cur_clan.player_id', '=', 'players.id'
    )
    ->leftJoin('clans', 'clans.id', '=', 'cur_clan.clan_id')
    ->select('players.*', 'played.minutes as played_minutes', 'afk.afk_sum', 'afk.afk_tracked', 'clans.name as clan_name')
```

Each derived table is one row per player, so no row multiplication and `paginate()`'s count
stays correct. `MAX(clan_id)` deterministically picks one clan in the rare multi-active case.
The Player models gain `played_minutes`, `afk_sum`, `afk_tracked`, `clan_name` attributes.

**Ordering** (a `match($sort)` building the `orderBy`), with no-data rows always last:

- `name`  → `ORDER BY players.name <dir>`
- `played`→ `ORDER BY COALESCE(played.minutes, 0) <dir>`
- `afk`   → `ORDER BY (afk.afk_sum / NULLIF(afk.afk_tracked, 0)) IS NULL ASC, (afk.afk_sum / NULLIF(afk.afk_tracked, 0)) <dir>`
- `clan`  → `ORDER BY clan_name IS NULL ASC, clan_name <dir>`

(The `… IS NULL ASC` prefix forces players with no AFK data / no clan to the bottom for both
directions.) Built with `orderByRaw` where an expression is needed; `<dir>` is the validated
`asc`/`desc`.

### 3. View — `resources/views/list/player.blade.php`

Columns: **Tee · Clan · Played time · AFK % · Mod · Map**. The first four headers use a new
sortable-header component; Mod/Map are plain `<th>`.

Row cells now read the joined attributes (dropping the per-row helpers for clan/played):
- **Clan:** `@if ($playerEntry->clan_name)` link to the clan page, else blank.
- **Played time:** `ChartUtility::humanizeDuration((int) $playerEntry->played_minutes)`.
- **AFK %:** `(int) $playerEntry->afk_tracked > 0 ? round($playerEntry->afk_sum / $playerEntry->afk_tracked * 100) . '%' : '—'`.
- **Mod / Map:** keep `chartPlayedMods()` / `chartPlayedMaps()` per-row, but **null-guarded**
  with `array_key_first()` — render `—` when the player has no history (this feature surfaces
  history-less players when sorting by played-time ascending, so the guard is required to
  avoid an undefined-key warning).

### 4. Sortable header component

`resources/views/components/sortable-th.blade.php` — `@props(['column', 'label', 'defaultDir' => 'asc'])`:

- Reads current `request('sort')` / `request('dir')`.
- `active = (current sort === column)`. Link target dir: when active, the opposite of the
  current dir; when inactive, `defaultDir` (passed `desc` for Played time and AFK %, `asc` for
  Tee and Clan — so the first click on a numeric column shows the biggest first).
- Link via `request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDir, 'page' => 1])`
  (preserves any other query params, resets to page 1).
- Shows a Font Awesome 4.7 caret (`fa-caret-up` / `fa-caret-down`) on the active column.

A small `.sortable-th` rule (pointer cursor, hover, active emphasis, caret spacing) is added to
`resources/assets/sass/app.scss`. Because this changes the content-hashed bundle, the build
step must be followed by `php artisan responsecache:clear` (the response-cached `/serverbrowser`
pins the old bundle name otherwise — `/tees` itself is not cached).

### 5. Tests (Feature, `tests/Feature`)

Seed a few players with differing played time / AFK / clan, then:
- `GET /tees?sort=afk&dir=desc` lists a high-AFK player before a low-AFK one
  (`assertSeeInOrder`); `dir=asc` reverses; a player with no idle-tracked data appears last.
- `GET /tees?sort=played&dir=desc` orders by played minutes; `GET /tees` (no params) orders by
  name; `GET /tees?sort=clan` orders by clan name.
- `GET /tees?sort=garbage&dir=sideways` returns 200 and falls back to the name sort.
- The AFK cell renders `—` for a player with `afk_tracked = 0` and `N%` for one with data.
- The active header renders its caret / the sort link carries `sort=` + `dir=`.

## Risks / edge cases

- **Aggregation cost:** the `GROUP BY player_id` over `player_histories` / `player_sessions`
  runs per page load. Acceptable for this low-traffic admin list and no worse than today's
  per-row N+1; denormalization is the noted escape hatch if needed.
- **Pagination count with joins:** each derived table is one-row-per-player, so `paginate()`'s
  count query returns the true player count (verified by a feature test hitting the page).
- **No-data ordering:** the `IS NULL ASC` prefix keeps history-less / clanless / AFK-less
  players at the bottom regardless of direction, so they never crowd out the top of a sort.
- **Build:** `npm run build` + `php artisan responsecache:clear` after the SCSS change; no TS
  changes, so type-check/lint are unaffected.
