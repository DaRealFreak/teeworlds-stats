# Live Server Browser — Design

**Date:** 2026-06-11
**Status:** Approved (Design A)

## Goal

Add a new page that shows the **last logged server list** — i.e. the servers seen in
the most recent scrape (currently online) — as a filterable, in-game-style server
browser. Hovering a server's player count reveals the players currently on that server.

This is a **new, separate page**. The existing `/servers` page (all-time aggregate:
total time played, most-played map/mod) is left untouched.

## Non-goals

- No live querying of the master/game servers from page views. The page reads only the
  local DB that the scheduled `data:update` scraper (every 10 min) already populates.
- No `current / max` player count (max slots are not stored; out of scope — current
  count only).
- No country flags in the hover roster (avoids the name→flag-code mapping); name + clan
  only, mirroring the server detail page.
- No pagination (it is a live snapshot you filter, like the in-game browser).
- No schema/migration changes.

## Data model

The current map/gametype of an online server is derivable from its most recently
updated `ServerHistory` row (the scraper touches that row every scrape for the server's
current map/mod). The live roster is already provided by `Server::currentPlayers()`.

Add one helper to `App\Models\Server`:

```php
/**
 * the server history row for the map/mod the server is running right now; the
 * scraper bumps this row's updated_at every scrape, so the latest one reflects
 * the current map and gametype
 */
public function currentServerHistory()
{
    return $this->hasOne(ServerHistory::class)->latestOfMany('updated_at');
}
```

`currentPlayers()` already exists and is reused as-is.

## Controller

`App\Http\Controllers\MainController::liveServers()`:

```php
public function liveServers()
{
    return view('list.live')->with(
        'servers',
        Server::where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5))
            ->with(['currentPlayers', 'currentServerHistory.map', 'currentServerHistory.mod'])
            ->withCount('currentPlayers')
            ->orderByDesc('current_players_count')
            ->get()
    );
}
```

- The online window (`CRONTASK_INTERVAL * 1.5`) matches `Server::online()`, so "last
  logged" == the set the rest of the app treats as online.
- Eager loading (`with` + `withCount`) avoids N+1 across the roster and map/mod.
- Default order: most populated first.

## Route + caching

- New route in `routes/web.php`:
  `Route::get('/serverbrowser', [MainController::class, 'liveServers'])->name('serverbrowser');`
- Apply Spatie's `CacheResponse` middleware to **this route only** (scoped — not the
  global `web` group, so auth/edit pages are unaffected):
  `->middleware(\Spatie\ResponseCache\Middlewares\CacheResponse::class)`.
- **This is the first actual use of `spatie/laravel-responsecache`** — the middleware
  was configured (`enabled => true`) and cleared on schedule, but never applied to any
  route, so nothing was being cached. No config change is needed.
- **Invalidation is already wired:** `bootstrap/app.php` runs `responsecache:clear` in
  the scrape's `->after()` hook (every 10 min). So the cached page is flushed the moment
  fresh data lands; the next visitor re-renders once and everyone after gets the cached
  copy. Net: the page is at most one scrape interval (~10 min) stale, and the query +
  render runs at most once per scrape cycle regardless of traffic.

## View — `resources/views/list/live.blade.php`

Extends `layouts.app`, same block/table scaffolding as the other list views.

**Filter bar** (above the table):
- Text input — filters by server name.
- `<select>` — gametype/mod; options are the distinct mods present in the result set,
  built in Blade from the collection.
- `<select>` — map; options are the distinct maps present in the result set.
- Checkbox — "hide empty servers".

**Table** — columns: **Server** (link to `server` detail route) · **Map** (link to `map`
route) · **Gametype** (link to `mod` route) · **Players** (count).
- Each `<tr>` carries `data-name`, `data-mod`, `data-map`, `data-players` for the JS
  filter.
- The **Players** cell is the popover trigger. Immediately after it sits a hidden
  `<div class="server-players">` containing the linked player names (+ clan when present)
  for that server, rendered inline from `$server->currentPlayers`.
- Servers with 0 current players render the count but **no popover** / no hidden div.

Current map/gametype come from `$server->currentServerHistory?->map` /
`->mod`; guard for the (rare) case where a freshly-seen server has no history row yet.

## Frontend — `resources/assets/js/serverbrowser.js`

Added to the import list in `resources/assets/js/app.js`. Vanilla JS (no jQuery).

On `DOMContentLoaded`, scoped to the live page (guard on a page-specific element so it is
a no-op elsewhere):
- **Filtering:** read the four controls, show/hide rows by matching against each row's
  `data-*` attributes. Instant, no reload. Text match is case-insensitive substring;
  selects match exactly (empty = all); the checkbox hides rows with `data-players="0"`.
- **Popovers:** initialize a Bootstrap `Popover` on each player-count trigger with
  `{ html: true, trigger: 'hover focus', container: 'body', content: <the row's hidden
  .server-players element> }`.

Rebuild assets with `npm run build` after the JS change.

## Navigation

Add a sidebar entry under the **Main** heading in `resources/views/layouts/app.blade.php`:
`<li><a href="{{ url('serverbrowser') }}"><i class="fa fa-server"></i>Server Browser</a></li>`
(`fa-server` exists in the bundled Font Awesome 4.7).

## Testing

Feature test (`RefreshDatabase` + factories; `CRONTASK_INTERVAL` is set by `phpunit.xml`):

- An online server (recent `last_seen`) with N current players appears, shows player
  count N, and embeds those N players' names for the hover.
- The server's current map and gametype render (seeded via a `ServerHistory` row;
  `Map`/`Mod` created with `::create(['name' => ...])`).
- A stale server (old `last_seen`) is **excluded** from the page.
- The route returns HTTP 200.

`currentPlayers` is wired by creating `PlayerHistory` rows with a recent `updated_at`
linking player↔server (the relation filters on the `CRONTASK_INTERVAL` window).

## Files touched

- `app/Models/Server.php` — add `currentServerHistory()`.
- `app/Http/Controllers/MainController.php` — add `liveServers()`.
- `routes/web.php` — add cached `/serverbrowser` route.
- `resources/views/list/live.blade.php` — new view.
- `resources/assets/js/serverbrowser.js` — new JS module.
- `resources/assets/js/app.js` — import the new module.
- `resources/views/layouts/app.blade.php` — sidebar nav entry.
- `tests/Feature/LiveServerBrowserTest.php` — new feature test.
