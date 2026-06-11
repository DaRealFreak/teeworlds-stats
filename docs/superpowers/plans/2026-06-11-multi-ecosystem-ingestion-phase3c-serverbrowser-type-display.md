# Multi-ecosystem Ingestion — Phase 3c: Serverbrowser Type Display — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show each server's type on the serverbrowser — a flavor badge (DDNet / Vanilla) plus protocol pills (0.6 / 0.7, both for dual-stack) — and add a client-side "type" filter, matching the existing dark theme and filter pattern.

**Architecture:** Add a "Type" column to `resources/views/list/live.blade.php` rendering the server's `flavor` as a badge and `protocols()` as small pills, plus a `data-flavor` attribute on each row and a `#filter_type` select in the existing filter bar. Extend `resources/assets/js/serverbrowser.js` to filter rows by `data-flavor`. Eager-load `addresses` in `MainController::liveServers` so `protocols()` does not N+1. No new model methods — the flavor→label mapping is a small inline `match` like the view's existing `@php` blocks.

**Tech Stack:** Laravel 13 Blade, Bootstrap 5.3 (badges, grid), vanilla JS compiled by Vite (`npm run build`), PHPUnit feature test via `$this->get('/serverbrowser')`. Run inside the DDEV web container.

**Spec:** `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` (§9 classification / display). Depends on Phase 1 (`Server::flavor`, `Server::protocols()`, `server_addresses`).

**Scope note:** Final sub-phase of Phase 3. After this, Phase 4 (Teeworlds 0.7 source) and the pre-Phase-4 schema-default cleanup remain. Built assets live in the git-ignored `public/build/`, so `npm run build` must run after the JS change (and on deploy).

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Http/Controllers/MainController.php` (modify) | `liveServers()` eager-loads `addresses` so `protocols()` is N+1-free. |
| `resources/views/list/live.blade.php` (modify) | "Type" column (flavor badge + protocol pills), `data-flavor` row attribute, `#filter_type` select in the filter bar. |
| `resources/assets/js/serverbrowser.js` (modify) | Read `#filter_type`; filter rows by `data-flavor`. |
| `tests/Feature/LiveServerBrowserTest.php` (modify) | Assert the type badges, `data-flavor` attribute, protocol pills, and the type filter render. |

---

## Task 1: Type column, badge, filter, and eager-load (+ tests)

**Files:**
- Modify: `tests/Feature/LiveServerBrowserTest.php`
- Modify: `app/Http/Controllers/MainController.php`
- Modify: `resources/views/list/live.blade.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/LiveServerBrowserTest.php`, add the `ServerAddress` import (alongside the existing model imports at the top):

```php
use App\Models\ServerAddress;
```

Then add these three members to the class (a seeder + two tests):

```php
    /**
     * Seed an online dual-stack DDNet server and an online vanilla 0.7 server, each with
     * a current map/mod and protocol-tagged addresses.
     */
    private function seedTypedServers(): void
    {
        $map = Map::create(['name' => 'Multeasymap']);
        $mod = Mod::create(['name' => 'DDraceNetwork']);

        $ddnet = Server::create([
            'name' => 'GER10 Novice', 'version' => '0.6.4, 19.1', 'flavor' => 'ddnet',
            'ip' => '10.1.0.1', 'port' => 8303, 'last_seen' => Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $ddnet->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        ServerAddress::create(['server_id' => $ddnet->id, 'ip' => '10.1.0.1', 'port' => 8303, 'protocol' => 6, 'is_canonical' => true]);
        ServerAddress::create(['server_id' => $ddnet->id, 'ip' => '10.1.0.1', 'port' => 8303, 'protocol' => 7, 'is_canonical' => false]);

        $vanilla = Server::create([
            'name' => 'CTF Pro', 'version' => '0.7.5', 'flavor' => 'vanilla_07',
            'ip' => '10.1.0.2', 'port' => 8303, 'last_seen' => Carbon::now(),
        ]);
        ServerHistory::create([
            'server_id' => $vanilla->id, 'map_id' => $map->id, 'mod_id' => $mod->id,
            'weekday' => 1, 'hour' => 12, 'continuous' => 1, 'minutes' => 60,
        ]);
        ServerAddress::create(['server_id' => $vanilla->id, 'ip' => '10.1.0.2', 'port' => 8303, 'protocol' => 7, 'is_canonical' => true]);
    }

    public function test_browser_shows_server_type_badges_and_protocol_pills(): void
    {
        $this->seedTypedServers();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('>DDNet</span>', false);            // flavor badge (not the server name)
        $response->assertSee('>Vanilla</span>', false);
        $response->assertSee('data-flavor="ddnet"', false);      // row attribute powers the filter
        $response->assertSee('data-flavor="vanilla_07"', false);
        $response->assertSee('>0.6</span>', false);              // dual-stack protocol pills
        $response->assertSee('>0.7</span>', false);
    }

    public function test_browser_renders_the_type_filter_control(): void
    {
        $this->seedTypedServers();

        $response = $this->get('/serverbrowser');

        $response->assertStatus(200);
        $response->assertSee('id="filter_type"', false);
        $response->assertSee('All types');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter LiveServerBrowserTest`
Expected: the two new tests FAIL (no `>DDNet</span>` badge, no `data-flavor`, no `#filter_type` yet); the existing tests still pass.

- [ ] **Step 3: Eager-load addresses in the controller**

In `app/Http/Controllers/MainController.php`, in `liveServers()`, add `'addresses'` to the eager-load so `protocols()` doesn't issue a query per row. Change:

```php
            ->with(['currentServerHistory.map', 'currentServerHistory.mod'])
```

to:

```php
            ->with(['currentServerHistory.map', 'currentServerHistory.mod', 'addresses'])
```

- [ ] **Step 4: Add the Type column, badge, filter, and row attribute to the view**

Replace the entire contents of `resources/views/list/live.blade.php` with:

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
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="filter_name"
                                       placeholder="Filter by server or player…" autocomplete="off">
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filter_type">
                                    <option value="">All types</option>
                                    <option value="ddnet">DDNet</option>
                                    <option value="vanilla_06">Vanilla 0.6</option>
                                    <option value="vanilla_07">Vanilla 0.7</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="filter_mod">
                                    <option value="">All gametypes</option>
                                    @foreach ($servers->map(fn ($s) => $s->currentServerHistory?->mod?->name)->filter()->unique()->sort() as $modName)
                                        <option value="{{ $modName }}">{{ $modName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filter_map">
                                    <option value="">All maps</option>
                                    @foreach ($servers->map(fn ($s) => $s->currentServerHistory?->map?->name)->filter()->unique()->sort() as $mapName)
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
                                    <th>Type</th>
                                    <th>Map</th>
                                    <th>Gametype</th>
                                    <th>Players</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($servers as $serverEntry)
                                    @php
                                        $history = $serverEntry->currentServerHistory;
                                        $mapName = $history?->map?->name;
                                        $modName = $history?->mod?->name;
                                        $players = $serverEntry->currentPlayers;
                                        $playerCount = $players->count();
                                        $playerNames = mb_strtolower($players->pluck('name')->implode(' '));
                                        // flavor → human label; protocol pills come from the address set
                                        $flavorLabel = match ($serverEntry->flavor) {
                                            'ddnet' => 'DDNet',
                                            'vanilla_06', 'vanilla_07' => 'Vanilla',
                                            default => null,
                                        };
                                    @endphp
                                    <tr data-name="{{ mb_strtolower($serverEntry->name) }}"
                                        data-map="{{ $mapName }}"
                                        data-mod="{{ $modName }}"
                                        data-flavor="{{ $serverEntry->flavor }}"
                                        data-players="{{ $playerCount }}"
                                        data-player-names="{{ $playerNames }}">
                                        <td>
                                            <a href="{{ url('server', [urlencode($serverEntry->id), urlencode($serverEntry->name)]) }}">{{ $serverEntry->name }}</a>
                                        </td>
                                        <td>
                                            @if ($flavorLabel)
                                                <span class="badge {{ $serverEntry->flavor === 'ddnet' ? 'bg-info' : 'bg-secondary' }}">{{ $flavorLabel }}</span>
                                            @endif
                                            @foreach ($serverEntry->protocols() as $protocol)
                                                <span class="badge bg-dark">0.{{ $protocol }}</span>
                                            @endforeach
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
                                        <td class="players-cell">
                                            @if ($playerCount)
                                                <span class="badge bg-primary server-player-count"
                                                      tabindex="0" role="button"
                                                      aria-label="Players on this server">{{ $playerCount }}</span>
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

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter LiveServerBrowserTest`
Expected: PASS (all tests, including the two new ones).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MainController.php resources/views/list/live.blade.php tests/Feature/LiveServerBrowserTest.php
git commit -m "feat(serverbrowser): show server type badge and protocol pills

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Client-side type filter + asset build

**Files:**
- Modify: `resources/assets/js/serverbrowser.js`

- [ ] **Step 1: Add the type filter to the JS**

In `resources/assets/js/serverbrowser.js`, make the following changes inside the `DOMContentLoaded` handler's filtering section:

1. After `const mapSelect = document.getElementById('filter_map');` add:

```javascript
    const typeSelect = document.getElementById('filter_type');
```

2. Change the guard `if (!nameInput || !modSelect || !mapSelect || !hideEmpty) {` to include the type select:

```javascript
    if (!nameInput || !modSelect || !mapSelect || !hideEmpty || !typeSelect) {
```

3. Inside `applyFilters`, after `const map = mapSelect.value;` add:

```javascript
        const type = typeSelect.value;
```

4. Inside the `rows.forEach` callback, after `const matchesMap = !map || row.dataset.map === map;` add:

```javascript
            const matchesType = !type || row.dataset.flavor === type;
```

5. Change the `row.hidden` assignment to include `matchesType`:

```javascript
            row.hidden = !((serverMatches || playerMatches) && matchesMod && matchesMap && matchesEmpty && matchesType);
```

6. Change the listener wiring line `[nameInput, modSelect, mapSelect].forEach((el) => el.addEventListener('input', applyFilters));` to:

```javascript
    [nameInput, modSelect, mapSelect, typeSelect].forEach((el) => el.addEventListener('input', applyFilters));
```

- [ ] **Step 2: Build the assets**

Run: `npm run build`
Expected: Vite build succeeds with no errors; `public/build/` is regenerated.

- [ ] **Step 3: Commit**

```bash
git add resources/assets/js/serverbrowser.js
git commit -m "feat(serverbrowser): filter the browser by server type

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Full-suite + build verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: all green (Phases 1–3b + the new serverbrowser type tests; the existing `LiveServerBrowserTest` cases still pass).

- [ ] **Step 2: Confirm the build is current**

Run: `npm run build`
Expected: succeeds; no uncommitted source changes remain (`public/build/` is git-ignored).

- [ ] **Step 3: Commit (only if a fixup was needed)**

No code changes expected; commit any needed fixup, otherwise skip.

---

## Self-Review

**Spec coverage (Phase 3c slice of §9):**
- Per-server type display: flavor badge (DDNet / Vanilla) + protocol pills (0.6 / 0.7, both for dual-stack) → Task 1 view. ✓
- Filterable by type → `#filter_type` select (Task 1) + `data-flavor` filtering (Task 2 JS). ✓
- N+1 avoidance for `protocols()` → eager-load `addresses` (Task 1 controller). ✓
- Out of scope: anything beyond the browser view (the flavor is already persisted by Phase 3b). Vanilla 0.6 vs 0.7 is distinguished by the protocol pill, not a separate badge — intentional, keeps the badge set small.

**Placeholder scan:** none — the full view, the exact JS edits, the controller edit, and the full test additions are provided.

**Type consistency:** the view reads `$serverEntry->flavor` (string `ddnet`/`vanilla_06`/`vanilla_07`/null from Phase 1) and `$serverEntry->protocols()` (Phase 1 `int[]`, eager-loaded via `addresses`). The JS reads `row.dataset.flavor` (set by `data-flavor="{{ $serverEntry->flavor }}"`) and compares to `#filter_type` option values (`ddnet`/`vanilla_06`/`vanilla_07`) — same vocabulary on both sides. The tests assert the rendered badge markup (`>DDNet</span>`, `>0.6</span>`) and the `data-flavor` attribute, so they verify the exact strings the JS depends on.
