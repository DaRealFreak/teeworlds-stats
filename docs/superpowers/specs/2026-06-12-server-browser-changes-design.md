# Server browser changes — design

Four UI/data refinements to the live server browser
(`resources/views/list/live.blade.php`), driven by the route
`MainController::liveServers()`.

## Goals

1. Show how many servers are online right now.
2. Stop the table columns from resizing when a filter changes the visible rows.
3. Show current/max players (e.g. `81/128`) — max players is not stored today.
4. Show a copyable `ip:port` for joining the server in-game.

Out of scope: the server *detail* page (no server-info panel exists there today),
and changing the player-count numerator (it stays the count of distinct recently
seen players, as today).

## 1. Online-server count

Append a muted count to the block title:

> **Servers online right now** (1,234)

using `number_format($servers->count())`. The collection is already passed to the
view; no controller change.

## 2. Fixed-size columns

The table is auto-layout (`<table class="table ...">`), so each column's width
follows the widest *visible* cell. Filtering to a long map name (or away from one)
makes the columns jump.

Fix: scope `table-layout: fixed; width: 100%` to `#server_browser_table` and add a
`<colgroup>` with explicit widths:

| Column   | Width |
|----------|-------|
| Server   | 30%   |
| Type     | 15%   |
| Map      | 20%   |
| Gametype | 20%   |
| Players  | 15%   |

Long values in the Server (name + address), Map, and Gametype cells truncate with
`white-space: nowrap; overflow: hidden; text-overflow: ellipsis` and carry a `title`
attribute so the full text is available on hover, rather than reflowing the layout.
Type badges (`DDNet`, `0.6`, `0.7`) may wrap within their fixed cell. The players
popover is unaffected — it renders on `body` (the JS copies the hidden
`.server-players` innerHTML into a Bootstrap popover with `container: 'body'`).

Styles go in `resources/assets/sass/app.scss` next to the existing SERVER BROWSER
block. Rebuild with `npm run build`.

## 3. Max players — schema + persistence + display

Max players is parsed by the protocol layer into `DiscoveredServer`
(`maxClients`, `maxPlayers`) but `ServerPersister` drops it; nothing stores it.

- **Migration** adds two nullable `unsignedSmallInteger` columns to `servers`:
  `max_clients` and `max_players`. They are current-snapshot fields, like
  `name` / `version` / `last_seen`.
- **`ServerPersister::persist()`** sets `max_clients` and `max_players` from the
  `DiscoveredServer` each scrape, alongside the existing `setAttribute` calls.
- **`Server` model** gets `int` casts for both columns.
- **View**: the Players badge becomes `{{ $playerCount }}/{{ $maxClients }}`,
  displaying **max_clients** (total slots; always ≥ the shown player count). When
  `max_clients` is missing or `0` (legacy rows, until the first re-scrape after
  deploy), it falls back to showing just the count. Empty servers show `0/<max>`.

`max_players` is persisted but not displayed yet — kept available for later use.

## 4. Copyable `ip:port`

Under the server-name link in the Server column, render the canonical
`{{ $serverEntry->ip }}:{{ $serverEntry->port }}` as muted monospace text with a
clipboard icon (Font Awesome 4.7 `fa fa-clipboard`), `role="button"`,
`tabindex="0"`, and a `data-connect` attribute holding `ip:port`.

`serverbrowser.js` gets a click/Enter handler that copies **only `ip:port`** (the
string you paste into the in-game connect field) via `navigator.clipboard.writeText`
and briefly flashes "Copied!" (swap the icon/label, restore after ~1.2s). The
address truncates within the fixed Server column.

## Testing

Per the project's regression-test convention:

- **Feature/Unit**: `ServerPersister` stores `max_clients` / `max_players` from a
  `DiscoveredServer`.
- **Feature**: the live route renders the server count, the `count/max` ratio, and
  the `ip:port` address.

## Files touched

- `database/migrations/2026_06_12_*_add_max_players_to_servers_table.php` (new)
- `app/TwStats/Persistence/ServerPersister.php`
- `app/Models/Server.php` (casts)
- `resources/views/list/live.blade.php`
- `resources/assets/sass/app.scss`
- `resources/assets/js/serverbrowser.js`
- tests under `tests/Feature` (and/or `tests/Unit`)
