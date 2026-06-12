# Server browser changes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an online-server count, fixed-width columns, a `players/max` ratio, and a copyable `ip:port` to the live server browser.

**Architecture:** Max players is already parsed into `DiscoveredServer` but dropped at persistence, so two nullable snapshot columns are added to `servers` and written by `ServerPersister`. The Blade view (`list/live.blade.php`) renders the new data; fixed layout and the click-to-copy affordance are added via `app.scss` and `serverbrowser.js`. Column widths get an eyes-on tuning pass in the browser.

**Tech Stack:** Laravel 13, Blade, MySQL (SQLite `:memory:` for tests), Bootstrap 5.3 + SCSS via Vite, vanilla ESM, PHPUnit, Playwright.

---

## File Structure

- **Create** `database/migrations/2026_06_12_000200_add_max_players_to_servers_table.php` — adds `max_clients` / `max_players` to `servers`.
- **Modify** `app/Models/Server.php` — integer casts + property annotations for the two columns.
- **Modify** `app/TwStats/Persistence/ServerPersister.php` — write the two columns each scrape.
- **Modify** `resources/views/list/live.blade.php` — count, ratio, ip:port, `<colgroup>`, truncation hooks.
- **Modify** `resources/assets/sass/app.scss` — fixed table layout, cell truncation, `.server-connect` styling.
- **Modify** `resources/assets/js/serverbrowser.js` — copy-to-clipboard handler.
- **Modify** `tests/Feature/Persistence/ServerPersisterTest.php` — max-players persistence test.
- **Modify** `tests/Feature/LiveServerBrowserTest.php` — count / ratio / fallback / ip:port render tests.

---

## Task 1: Persist max_clients and max_players

**Files:**
- Create: `database/migrations/2026_06_12_000200_add_max_players_to_servers_table.php`
- Modify: `app/Models/Server.php:46`
- Modify: `app/TwStats/Persistence/ServerPersister.php:47-48`
- Test: `tests/Feature/Persistence/ServerPersisterTest.php`

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/Persistence/ServerPersisterTest.php` (it constructs the `DiscoveredServer` inline with distinct max values rather than via the shared `server()` helper, so the two columns can't be transposed undetected):

```php
    public function test_persists_max_clients_and_max_players(): void
    {
        $discovered = new DiscoveredServer(
            [new DiscoveredAddress('192.0.2.20', 8303, 6)],
            'CapServer', 'Multeasymap', 'DDraceNetwork', '0.6.4, 19.1', 128, 64, [], 'eu', 'ddnet'
        );

        $server = (new ServerPersister())->persist($discovered);

        $this->assertSame(128, $server->fresh()->max_clients);
        $this->assertSame(64, $server->fresh()->max_players);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_persists_max_clients_and_max_players`
Expected: FAIL — `null` does not match `128` (column does not exist / not written).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_12_000200_add_max_players_to_servers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Capacity the server reports each scrape (parsed into DiscoveredServer but
            // previously dropped). Nullable: legacy rows carry no value until the next
            // scrape backfills them, and a source may report an unknown (0) capacity.
            $table->unsignedSmallInteger('max_clients')->nullable()->after('flavor');
            $table->unsignedSmallInteger('max_players')->nullable()->after('max_clients');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['max_clients', 'max_players']);
        });
    }
};
```

- [ ] **Step 4: Add casts to the Server model**

In `app/Models/Server.php`, immediately after the `protected $guarded` line (line 46), add:

```php
    protected $casts = [
        'max_clients' => 'integer',
        'max_players' => 'integer',
    ];
```

Also add these two lines to the model's `@property` docblock (after `@property int $port`, line 32) so the IDE/static analysis knows the columns:

```php
 * @property int|null $max_clients
 * @property int|null $max_players
```

- [ ] **Step 5: Write the columns in ServerPersister**

In `app/TwStats/Persistence/ServerPersister.php`, after the `flavor` line (line 47), add:

```php
            $model->setAttribute('max_clients', $server->maxClients);
            $model->setAttribute('max_players', $server->maxPlayers);
```

The surrounding block now reads:

```php
            $model->setAttribute('name', $server->name);
            $model->setAttribute('version', $server->version);
            $model->setAttribute('flavor', $server->flavor);
            $model->setAttribute('max_clients', $server->maxClients);
            $model->setAttribute('max_players', $server->maxPlayers);
            $model->setAttribute('ip', $canonical->ip);
            $model->setAttribute('port', $canonical->port);
            $model->setAttribute('last_seen', Carbon::now());
            $model->save();
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter test_persists_max_clients_and_max_players`
Expected: PASS.

- [ ] **Step 7: Apply the migration to the dev DB**

Run: `php artisan migrate`
Expected: the `2026_06_12_000200_add_max_players_to_servers_table` migration runs.

- [ ] **Step 8: Run the full persistence suite (no regressions)**

Run: `vendor/bin/phpunit tests/Feature/Persistence/ServerPersisterTest.php`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_06_12_000200_add_max_players_to_servers_table.php \
        app/Models/Server.php app/TwStats/Persistence/ServerPersister.php \
        tests/Feature/Persistence/ServerPersisterTest.php
git commit -m "feat(serverbrowser): persist reported max_clients/max_players per server"
```

---

## Task 2: Render count, players/max ratio, and ip:port in the view

**Files:**
- Modify: `resources/views/list/live.blade.php`
- Test: `tests/Feature/LiveServerBrowserTest.php`

- [ ] **Step 1: Write the failing tests**

Add these four methods to `tests/Feature/LiveServerBrowserTest.php` (`seedBrowserFixture()` already creates two online servers — `10.0.0.1:8303` with one player `RosterTee`, and `10.0.0.2:8303` empty — plus one stale server):

```php
    public function test_browser_shows_the_online_server_count(): void
    {
        $this->seedBrowserFixture(); // two online, one stale

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('id="online_server_count">(2)</span>', false);
    }

    public function test_browser_shows_player_count_over_max_clients(): void
    {
        $map = Map::create(['name' => 'ctf5']);
        $mod = Mod::create(['name' => 'ctf']);
        $server = Server::create([
            'name' => 'Capacity Server', 'version' => '0.7.5', 'ip' => '10.0.5.1', 'port' => 8303,
            'last_seen' => Carbon::now(), 'max_clients' => 64, 'max_players' => 64,
        ]);
        ServerHistory::create([
            'server_id' => $server->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        $player = Player::create(['name' => 'CapTee', 'country' => 'DE']);
        PlayerHistory::create([
            'server_id' => $server->id, 'player_id' => $player->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('Players on this server">1/64</span>', false); // current/max in the badge
    }

    public function test_browser_falls_back_to_a_bare_count_when_max_is_unknown(): void
    {
        $this->seedBrowserFixture(); // online servers have no max_clients (legacy rows)

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('Players on this server">1</span>', false); // no "/" when max is unknown
    }

    public function test_browser_shows_a_copyable_ip_port(): void
    {
        $this->seedBrowserFixture();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('class="server-connect', false);
        $response->assertSee('data-connect="10.0.0.1:8303"', false); // the online server's join address
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'test_browser_shows_the_online_server_count|test_browser_shows_player_count_over_max_clients|test_browser_falls_back_to_a_bare_count_when_max_is_unknown|test_browser_shows_a_copyable_ip_port'`
Expected: FAIL — the count span, the `1/64`/`1` badge text, and the `server-connect` markup do not exist yet.

- [ ] **Step 3: Add the online-server count to the title**

In `resources/views/list/live.blade.php`, replace the title line (line 16):

```blade
                        <div class="title"><strong>Servers online right now</strong></div>
```

with:

```blade
                        <div class="title">
                            <strong>Servers online right now</strong>
                            <span class="text-muted" id="online_server_count">({{ number_format($servers->count()) }})</span>
                        </div>
```

- [ ] **Step 4: Compute the ratio in the per-row PHP block**

In the same file, inside the `@php` block (after the `$flavorLabel = match (...)` assignment, before the closing `@endphp` at line 81), add:

```blade
                                        $maxClients = $serverEntry->max_clients;
                                        // total slots is always ≥ the shown player count, so the ratio never reads
                                        // "over capacity"; fall back to a bare count when capacity is unknown (0/null).
                                        $ratio = $maxClients > 0 ? $playerCount . '/' . $maxClients : (string) $playerCount;
```

- [ ] **Step 5: Add the fixed `<colgroup>`**

Immediately after the `<table ... id="server_browser_table">` line (line 57), insert:

```blade
                                <colgroup>
                                    <col style="width: 30%;">
                                    <col style="width: 15%;">
                                    <col style="width: 20%;">
                                    <col style="width: 20%;">
                                    <col style="width: 15%;">
                                </colgroup>
```

- [ ] **Step 6: Add the server name truncation hook and copyable address**

Replace the Server `<td>` (lines 89-91):

```blade
                                        <td>
                                            <a href="{{ url('server', [urlencode($serverEntry->id), urlencode($serverEntry->name)]) }}">{{ $serverEntry->name }}</a>
                                        </td>
```

with:

```blade
                                        <td>
                                            <a href="{{ url('server', [urlencode($serverEntry->id), urlencode($serverEntry->name)]) }}"
                                               class="server-name d-block" title="{{ $serverEntry->name }}">{{ $serverEntry->name }}</a>
                                            <span class="server-connect small text-muted" role="button" tabindex="0"
                                                  data-connect="{{ $serverEntry->ip }}:{{ $serverEntry->port }}"
                                                  title="Copy {{ $serverEntry->ip }}:{{ $serverEntry->port }} to clipboard">{{ $serverEntry->ip }}:{{ $serverEntry->port }} <i class="fa fa-clipboard" aria-hidden="true"></i></span>
                                        </td>
```

- [ ] **Step 7: Truncate the Map and Gametype cells**

Replace the Map `<td>` (lines 100-104):

```blade
                                        <td>
                                            @if ($mapName)
                                                <a href="{{ url('map', urlencode($mapName)) }}">{{ $mapName }}</a>
                                            @endif
                                        </td>
```

with:

```blade
                                        <td class="cell-truncate">
                                            @if ($mapName)
                                                <a href="{{ url('map', urlencode($mapName)) }}" title="{{ $mapName }}">{{ $mapName }}</a>
                                            @endif
                                        </td>
```

Replace the Gametype `<td>` (lines 105-109):

```blade
                                        <td>
                                            @if ($modName)
                                                <a href="{{ url('mod', urlencode($modName)) }}">{{ $modName }}</a>
                                            @endif
                                        </td>
```

with:

```blade
                                        <td class="cell-truncate">
                                            @if ($modName)
                                                <a href="{{ url('mod', urlencode($modName)) }}" title="{{ $modName }}">{{ $modName }}</a>
                                            @endif
                                        </td>
```

- [ ] **Step 8: Show the ratio in the Players cell**

Replace the populated badge (line 112-114):

```blade
                                                <span class="badge bg-primary server-player-count"
                                                      tabindex="0" role="button"
                                                      aria-label="Players on this server">{{ $playerCount }}</span>
```

with:

```blade
                                                <span class="badge bg-primary server-player-count"
                                                      tabindex="0" role="button"
                                                      aria-label="Players on this server">{{ $ratio }}</span>
```

Replace the empty badge (line 124):

```blade
                                                <span class="badge bg-secondary">0</span>
```

with:

```blade
                                                <span class="badge bg-secondary" aria-label="Players on this server">{{ $ratio }}</span>
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'test_browser_shows_the_online_server_count|test_browser_shows_player_count_over_max_clients|test_browser_falls_back_to_a_bare_count_when_max_is_unknown|test_browser_shows_a_copyable_ip_port'`
Expected: PASS.

- [ ] **Step 10: Run the full browser suite (no regressions)**

Run: `vendor/bin/phpunit tests/Feature/LiveServerBrowserTest.php`
Expected: all green (the existing roster/badge/filter tests still pass).

- [ ] **Step 11: Commit**

```bash
git add resources/views/list/live.blade.php tests/Feature/LiveServerBrowserTest.php
git commit -m "feat(serverbrowser): show online count, players/max ratio, and copyable ip:port"
```

---

## Task 3: Fixed layout, truncation styling, and click-to-copy

**Files:**
- Modify: `resources/assets/sass/app.scss:568` (end of the SERVER BROWSER block)
- Modify: `resources/assets/js/serverbrowser.js:24`

- [ ] **Step 1: Add the fixed-layout and connect styling**

Append to `resources/assets/sass/app.scss`, after the `.players-cell--match { ... }` rule (line 568):

```scss
/* Fixed layout so columns keep their width when filtering hides/shows rows (the
   colgroup sets the proportions); long map/gametype/name values ellipsize instead. */
#server_browser_table {
  table-layout: fixed;
  width: 100%;

  td.cell-truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .server-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

/* the joinable address under each server name; click/Enter copies ip:port */
.server-connect {
  display: inline-block;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
  cursor: pointer;
  font-family: var(--bs-font-monospace, monospace);

  &:hover {
    text-decoration: underline;
  }

  .fa {
    margin-left: 0.25rem;
    opacity: 0.7;
  }
}
```

- [ ] **Step 2: Add the copy handler**

In `resources/assets/js/serverbrowser.js`, after the popover `forEach` block closes (line 24, before the `// ---- client-side filtering ----` comment), insert:

```javascript
    // ---- click/Enter on an address copies ip:port for the in-game connect field ----
    table.querySelectorAll('.server-connect').forEach((el) => {
        const label = el.innerHTML;
        const copy = () => {
            navigator.clipboard.writeText(el.dataset.connect || '').then(() => {
                el.textContent = 'Copied!';
                window.setTimeout(() => { el.innerHTML = label; }, 1200);
            });
        };
        el.addEventListener('click', copy);
        el.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                copy();
            }
        });
    });
```

This sits before the filter-control early-return (`if (!nameInput ...) return;`), so copying works even though the filter elements always exist on this page.

- [ ] **Step 3: Build the assets**

Run: `npm run build`
Expected: Vite writes `public/build/` with no errors.

- [ ] **Step 4: Clear the response cache**

The live page is response-cached (`spatie/laravel-responsecache`), so the old HTML would otherwise mask the view changes.

Run: `php artisan responsecache:clear`
Expected: `Response cache cleared!`

- [ ] **Step 5: Commit**

```bash
git add resources/assets/sass/app.scss resources/assets/js/serverbrowser.js
git commit -m "feat(serverbrowser): fixed column widths and click-to-copy address"
```

---

## Task 4: Verify in the browser and tune column widths

This task is visual — the `<colgroup>` percentages (30/15/20/20/15) are a first guess and need eyes-on confirmation, which is exactly what the user asked for.

**Files:**
- Possibly modify: `resources/views/list/live.blade.php` (colgroup widths)
- Possibly modify: `resources/assets/sass/app.scss`

- [ ] **Step 1: Ensure the page has data**

The browser is empty if no servers were scraped recently. Check:

Run: `php artisan tinker --execute="echo App\Models\Server::where('last_seen','>=',\Carbon\Carbon::now()->subMinutes(env('CRONTASK_INTERVAL')*1.5))->count();"`
Expected: a non-zero count. If it is `0`, run `php artisan data:update` once to populate (and re-run `php artisan responsecache:clear`).

- [ ] **Step 2: Open the page in Playwright**

Use `mcp__playwright__browser_navigate` to `https://twstats.ddev.site/serverbrowser`, then `mcp__playwright__browser_take_screenshot` (full page). Confirm: the count appears in the title, each row shows `players/max` and an `ip:port` line, and the columns look balanced.

- [ ] **Step 3: Stress the fixed widths**

Type a long map name into `#filter_map` (via `mcp__playwright__browser_select_option`) and confirm with a screenshot that the column widths do **not** shift between the all-rows view and the filtered view. Hover a truncated map/gametype cell and confirm the `title` tooltip shows the full value.

- [ ] **Step 4: Verify copy-to-clipboard**

Click a `.server-connect` element (`mcp__playwright__browser_click`); confirm the label briefly flips to "Copied!". Read it back with `mcp__playwright__browser_evaluate` running `navigator.clipboard.readText()` and confirm it equals the row's `ip:port` (note: clipboard read may require the page to be focused).

- [ ] **Step 5: Tune widths if needed (frontend-design skill)**

If any column looks cramped or over-wide, invoke the **frontend-design** skill to choose better `<colgroup>` proportions, edit the percentages in `list/live.blade.php` (and any spacing in `app.scss`), then `npm run build && php artisan responsecache:clear` and re-screenshot. Repeat until the layout reads well at desktop width. Also check a narrow viewport with `mcp__playwright__browser_resize` (e.g. 768px) since the table is inside `.table-responsive`.

- [ ] **Step 6: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 7: Commit any tuning changes**

```bash
git add resources/views/list/live.blade.php resources/assets/sass/app.scss
git commit -m "style(serverbrowser): tune fixed column widths from browser review"
```

(Skip this commit if Step 5 made no changes.)

---

## Done when

- The browser shows the online-server count, each row shows `players/max` and a copyable `ip:port`, and the columns no longer jump when filtering.
- `vendor/bin/phpunit` is green.
- All changes are committed (not pushed).
