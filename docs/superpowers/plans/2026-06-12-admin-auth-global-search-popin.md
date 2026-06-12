# Admin-only Auth, Global Search & Popin Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lock the existing email+password login down to admins only (and remove the dead Facebook copy), add a sticky navbar global-search box backed by a unified endpoint, and stop the server-browser player-roster popin from insta-closing when a tee is opened in a new tab.

**Architecture:** Three independent phases. (1) Auth: disable registration, provision admins via an Artisan command, scrub Facebook text — no new auth fields, no roles (a closed register list means every user is an admin). (2) Search: a new `AjaxSearchController@searchGlobal` reuses the existing `FuzzySearch` ranker to return type-grouped, URL-carrying results; a navbar `<input>` + new `globalsearch.js` render a live grouped dropdown. (3) Popin: switch the Bootstrap popover from `trigger: 'hover focus'` to `trigger: 'manual'` with explicit click-to-pin / outside-click / Esc control, removing Bootstrap's `focusout` close path entirely.

**Tech Stack:** PHP 8.3 / Laravel 13, `laravel/ui` auth scaffold, Bootstrap 5.3 + SCSS via Vite, vanilla ES modules, PHPUnit (SQLite `:memory:`), Playwright (MCP) for JS verification.

**Reference spec:** `docs/superpowers/specs/2026-06-12-admin-auth-global-search-popin-design.md`

**Conventions to honour (from CLAUDE.md):**
- Run `npm run build` after any SCSS/JS change (there is no dev server; `public/build/` is git-ignored). There is **no** `npm run lint` script in this repo — skip linting.
- Run `vendor/bin/phpunit` for PHP tests.
- Comments explain *why* code exists (present tense), never what changed.
- Committing is allowed in this project; **do not push** unless asked. Work on the current `master` branch.

---

## Phase 1 — Admin-only authentication

> Per the user's explicit instruction, **Phase 1 ships no automated tests.** Verify with the listed CLI smoke commands.

### Task 1.1: Close public registration

**Files:**
- Modify: `routes/web.php:53`
- Modify: `resources/views/layouts/app.blade.php:59-88` (remove the Register link)
- Delete: `app/Http/Controllers/Auth/RegisterController.php`
- Delete: `resources/views/auth/register.blade.php`

- [ ] **Step 1: Disable the register routes**

In `routes/web.php`, replace line 53:

```php
// Authentication routes (laravel/ui) — registration is closed; this is an admin-only app.
// Admins are provisioned with `php artisan admin:create`.
Auth::routes(['register' => false]);
```

- [ ] **Step 2: Remove the Register link from the navbar**

In `resources/views/layouts/app.blade.php`, the `@guest` branch currently renders both Login and Register (lines 60-66). Delete only the Register list item so the block reads:

```blade
                    @guest
                        <div class="list-inline-item">
                            <a class="nav-link" href="{{ route('login') }}">{{ __('Login') }}</a>
                        </div>
                    @else
```

(Leave the `@else` authenticated dropdown untouched.)

- [ ] **Step 3: Delete the now-unreachable register controller and view**

```bash
rm app/Http/Controllers/Auth/RegisterController.php resources/views/auth/register.blade.php
```

- [ ] **Step 4: Verify the register route is gone and no route references the deleted controller**

Run: `php artisan route:list | grep -i register`
Expected: no output (the `register` / `register POST` routes are gone).

Run: `php artisan route:list > /dev/null && echo OK`
Expected: `OK` (route file still compiles; nothing references `RegisterController`).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php resources/views/layouts/app.blade.php
git add -A app/Http/Controllers/Auth/RegisterController.php resources/views/auth/register.blade.php
git commit -m "feat(auth): close public registration (admin-only app)"
```

---

### Task 1.2: Add the `admin:create` Artisan command

**Files:**
- Create: `app/Console/Commands/CreateAdmin.php`

- [ ] **Step 1: Create the command**

Create `app/Console/Commands/CreateAdmin.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    /**
     * Registration is closed (admin-only app), so admin accounts are provisioned
     * from the CLI instead of a public sign-up form. Credentials are prompted for
     * interactively so they never land in git or shell history.
     */
    protected $signature = 'admin:create';

    protected $description = 'Create an admin user (interactive; public registration is closed)';

    public function handle(): int
    {
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password (min 8 chars)');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // The User model casts `password` to `hashed`, so the plain value is hashed on save.
        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Admin user {$email} created.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Verify the command is registered**

Run: `php artisan list | grep admin:create`
Expected: a line like `admin:create  Create an admin user (interactive; public registration is closed)`.

- [ ] **Step 3: Smoke-test against the dev DB (optional but recommended)**

Run (interactive): `php artisan admin:create` and enter a name, a real-looking email, and an 8+ char password.
Expected: `Admin user <email> created.` Re-running with the same email should print the `unique` validation error and exit non-zero, proving validation works. (Remove the throwaway row afterwards if desired via `php artisan tinker`.)

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/CreateAdmin.php
git commit -m "feat(auth): add admin:create command to provision admins"
```

---

### Task 1.3: Remove the dead Facebook copy

**Files:**
- Modify: `resources/views/about.blade.php:28-39`

- [ ] **Step 1: Delete both Facebook blocks**

In `resources/views/about.blade.php`, delete the two `.form-group-material` blocks that describe the never-built Facebook preference feature — the "Choose which statistics to display" block (lines 28-32) and the "Facebook login" block (lines 34-39). After the edit, the markup jumps straight from the "Teeworlds" block (ends line 26) to the "If a clan/server/tee is missing" block (starts line 41):

```blade
                            <div class="form-group-material">
                                <h3>Teeworlds</h3>
                                <div>
                                    <a href="{{ url('https://www.teeworlds.com/') }}">Teeworlds</a>
                                    is an opensource multiplayer game.
                                </div>
                            </div>

                            <div class="form-group-material">
                                <h3>If a clan/server/tee is missing</h3>
```

- [ ] **Step 2: Verify no Facebook references remain**

Run: `grep -ri facebook resources/views/`
Expected: no output.

- [ ] **Step 3: Commit**

```bash
git add resources/views/about.blade.php
git commit -m "docs(about): drop dead Facebook-login copy (never implemented)"
```

---

## Phase 2 — Global search (sticky navbar box)

### Task 2.1: Unified `/search/global` endpoint (TDD)

**Files:**
- Test: `tests/Feature/GlobalSearchTest.php` (create)
- Modify: `routes/web.php` (add route after line 50)
- Modify: `app/Http/Controllers/AjaxSearchController.php` (add `searchGlobal` + `use App\Service\FuzzySearch;`)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GlobalSearchTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The navbar global search returns matches grouped by entity type, each carrying a
     * ready-to-use detail URL built server-side (the server owns the route patterns).
     */
    public function test_global_search_groups_matches_by_type_with_urls(): void
    {
        Player::create(['name' => 'nameless tee', 'country' => 'DE']);
        Clan::factory()->create(['name' => 'nameless crew']);
        $server = Server::factory()->create(['name' => 'nameless server']);
        Map::create(['name' => 'namelessmap']);
        Mod::create(['name' => 'namelessmod']);

        $response = $this->getJson('/search/global?term=nameless');

        $response->assertOk();
        $response->assertJsonStructure([
            'players' => [['name', 'url']],
            'clans' => [['name', 'url']],
            'servers' => [['name', 'id', 'url']],
            'maps' => [['name', 'url']],
            'mods' => [['name', 'url']],
        ]);

        $response->assertJsonPath('players.0.name', 'nameless tee');
        $response->assertJsonPath('players.0.url', url('tee', urlencode('nameless tee')));
        $response->assertJsonPath(
            'servers.0.url',
            url('server', [urlencode($server->id), urlencode($server->name)])
        );
    }

    /**
     * Mirrors the navbar JS guard: terms shorter than 2 chars do no work and return
     * empty groups (not a 404 / not an error).
     */
    public function test_global_search_returns_empty_groups_for_short_terms(): void
    {
        Player::create(['name' => 'nameless tee', 'country' => 'DE']);

        $response = $this->getJson('/search/global?term=n');

        $response->assertOk();
        $response->assertExactJson([
            'players' => [],
            'clans' => [],
            'servers' => [],
            'maps' => [],
            'mods' => [],
        ]);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `vendor/bin/phpunit --filter GlobalSearchTest`
Expected: FAIL — the route does not exist yet, so `/search/global` 404s and `assertOk()` fails.

- [ ] **Step 3: Add the route**

In `routes/web.php`, after the existing Ajax search routes (after line 50), add:

```php
// Unified search for the navbar global-search box (grouped, URL-carrying results)
Route::get('/search/global', [AjaxSearchController::class, 'searchGlobal'])->name('searchGlobalAjax');
```

- [ ] **Step 4: Implement `searchGlobal`**

In `app/Http/Controllers/AjaxSearchController.php`, add the import below the existing `use` block:

```php
use App\Service\FuzzySearch;
```

Then add this method inside the class (e.g. after `searchMap`):

```php
    /**
     * Unified search across every entity type for the navbar global-search box.
     * Returns up to 5 relevance-ranked matches per type (via FuzzySearch), each with a
     * detail URL built here — the server owns the route patterns and the name encoding.
     */
    public function searchGlobal(Request $request)
    {
        $term = trim((string) $request->input('term'));

        $empty = [
            'players' => [], 'clans' => [], 'servers' => [], 'maps' => [], 'mods' => [],
        ];

        // Mirror the navbar JS guard: too-short terms do no work.
        if (mb_strlen($term) < 2) {
            return response()->json($empty);
        }

        $limit = 5;

        $players = FuzzySearch::on(Player::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Player $p) => [
                'name' => $p->name,
                'url' => url('tee', urlencode($p->name)),
            ])->values();

        $clans = FuzzySearch::on(Clan::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Clan $c) => [
                'name' => $c->name,
                'url' => url('clan', urlencode($c->name)),
            ])->values();

        $servers = FuzzySearch::on(Server::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Server $s) => [
                'name' => $s->name,
                'id' => $s->id,
                'url' => url('server', [urlencode($s->id), urlencode($s->name)]),
            ])->values();

        $maps = FuzzySearch::on(Map::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Map $m) => [
                'name' => $m->name,
                'url' => url('map', urlencode($m->name)),
            ])->values();

        $mods = FuzzySearch::on(Mod::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Mod $m) => [
                'name' => $m->name,
                'url' => url('mod', urlencode($m->name)),
            ])->values();

        return response()->json([
            'players' => $players,
            'clans' => $clans,
            'servers' => $servers,
            'maps' => $maps,
            'mods' => $mods,
        ]);
    }
```

- [ ] **Step 5: Run the test to confirm it passes**

Run: `vendor/bin/phpunit --filter GlobalSearchTest`
Expected: PASS (2 tests, both green).

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/GlobalSearchTest.php routes/web.php app/Http/Controllers/AjaxSearchController.php
git commit -m "feat(search): unified /search/global endpoint (grouped, URL-carrying)"
```

---

### Task 2.2: Navbar markup — add the search box, remove the dormant overlay

**Files:**
- Modify: `resources/views/layouts/app.blade.php:34-44` (delete dormant `.search-panel`)
- Modify: `resources/views/layouts/app.blade.php:45-58` (insert the search form)

- [ ] **Step 1: Delete the dormant `.search-panel` overlay**

In `resources/views/layouts/app.blade.php`, delete the entire `<div class="search-panel"> … </div>` block (lines 34-44) — it is the never-wired full-screen overlay. The `<nav class="navbar …">` should now contain `<div class="container-fluid …">` as its first child.

- [ ] **Step 2: Insert the global-search form into the navbar**

Inside `<div class="container-fluid d-flex align-items-center justify-content-between">`, add the search form as a middle flex child — between the closing `</div>` of `.navbar-header` and the opening `<div class="right-menu …">`:

```blade
                </div>
                <form class="global-search" role="search" autocomplete="off"
                      onsubmit="return false;">
                    <i class="fa fa-search global-search__icon" aria-hidden="true"></i>
                    <input type="search" id="global_search_input" class="global-search__input"
                           placeholder="Search players, clans, servers…" aria-label="Global search"
                           data-global-search="{{ url('search/global') }}">
                    <ul class="global-search__menu" id="global_search_menu" hidden></ul>
                </form>
                <div class="right-menu list-inline no-margin-bottom">
```

(The `onsubmit="return false;"` keeps Enter from reloading the page; `globalsearch.js` handles Enter to navigate to the active result.)

- [ ] **Step 3: Verify Blade still renders (compile check)**

Run: `php artisan view:clear && php artisan route:list > /dev/null && echo OK`
Expected: `OK` (no Blade syntax error surfaces on the next render; full visual check happens after the JS/SCSS tasks + build).

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(search): add navbar global-search box, drop dormant search overlay"
```

---

### Task 2.3: `globalsearch.js` + SCSS + bundle import

**Files:**
- Create: `resources/assets/js/globalsearch.js`
- Modify: `resources/assets/js/app.js:26-27` (add import)
- Modify: `resources/assets/sass/app.scss` (append the global-search styles)

- [ ] **Step 1: Create the JS module**

Create `resources/assets/js/globalsearch.js`:

```js
// Global search: a navbar-wide search box backed by /search/global. As the user types, a
// debounced fetch returns matches grouped by entity type (players/clans/servers/maps/mods);
// each renders as a direct link to its detail page. Keyboard: ↑/↓ across the flattened result
// list, Enter follows the active (or first) hit, Esc closes. '/' and Ctrl/Cmd+K focus the box
// from anywhere (ignored while typing in another field). Names are user-controlled (from
// Teeworlds), so every label is set via textContent — never innerHTML — to avoid XSS.
(function () {
    'use strict';

    const DEBOUNCE_MS = 200;
    const MIN_LENGTH = 2;

    // display order + label + Font Awesome 4.7 icon per result group
    const GROUPS = [
        { key: 'players', label: 'Players', icon: 'fa-user' },
        { key: 'clans', label: 'Clans', icon: 'fa-users' },
        { key: 'servers', label: 'Servers', icon: 'fa-server' },
        { key: 'maps', label: 'Maps', icon: 'fa-map-o' },
        { key: 'mods', label: 'Mods', icon: 'fa-gamepad' },
    ];

    function debounce(fn, wait) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), wait);
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        const input = document.getElementById('global_search_input');
        const menu = document.getElementById('global_search_menu');
        if (!input || !menu) {
            return;
        }
        const form = input.closest('.global-search');
        const url = input.getAttribute('data-global-search');

        // flattened list of the currently-rendered result anchors, for ↑/↓ navigation
        let items = [];
        let activeIndex = -1;

        function close() {
            menu.hidden = true;
            menu.innerHTML = '';
            items = [];
            activeIndex = -1;
        }

        function setActive(index) {
            if (activeIndex >= 0 && items[activeIndex]) {
                items[activeIndex].classList.remove('is-active');
            }
            activeIndex = index;
            if (activeIndex >= 0 && items[activeIndex]) {
                items[activeIndex].classList.add('is-active');
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function render(data) {
            menu.innerHTML = '';
            items = [];
            activeIndex = -1;

            GROUPS.forEach((group) => {
                const results = Array.isArray(data[group.key]) ? data[group.key] : [];
                if (!results.length) {
                    return;
                }

                const heading = document.createElement('li');
                heading.className = 'global-search__group';
                heading.textContent = group.label;
                menu.appendChild(heading);

                results.forEach((result) => {
                    const li = document.createElement('li');
                    const a = document.createElement('a');
                    a.className = 'global-search__item';
                    a.href = result.url;

                    const icon = document.createElement('i');
                    icon.className = 'fa ' + group.icon + ' global-search__item-icon';
                    icon.setAttribute('aria-hidden', 'true');
                    a.appendChild(icon);

                    const label = document.createElement('span');
                    label.className = 'global-search__item-name';
                    // user-controlled name → textContent, never innerHTML
                    label.textContent = result.name;
                    a.appendChild(label);

                    li.appendChild(a);
                    menu.appendChild(li);
                    items.push(a);
                });
            });

            if (!items.length) {
                const empty = document.createElement('li');
                empty.className = 'global-search__empty';
                empty.textContent = 'No matches';
                menu.appendChild(empty);
            }

            menu.hidden = false;
        }

        const fetchResults = debounce(function () {
            const term = input.value.trim();
            if (term.length < MIN_LENGTH) {
                close();
                return;
            }
            fetch(url + '?term=' + encodeURIComponent(term), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => (data ? render(data) : close()))
                .catch(() => close());
        }, DEBOUNCE_MS);

        input.addEventListener('input', fetchResults);

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (items.length) {
                    setActive((activeIndex + 1) % items.length);
                }
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (items.length) {
                    setActive((activeIndex - 1 + items.length) % items.length);
                }
            } else if (event.key === 'Enter') {
                // follow the highlighted hit, or the first one if none is highlighted
                const target = activeIndex >= 0 ? items[activeIndex] : items[0];
                if (target) {
                    event.preventDefault();
                    window.location.href = target.href;
                }
            } else if (event.key === 'Escape') {
                close();
                input.blur();
            }
        });

        // click-away closes the dropdown
        document.addEventListener('click', function (event) {
            if (form && !form.contains(event.target)) {
                close();
            }
        });

        // '/' and Ctrl/Cmd+K focus the box from anywhere, unless already typing in a field
        document.addEventListener('keydown', function (event) {
            const target = event.target;
            const typing = target instanceof HTMLElement
                && (target.matches('input, textarea, select') || target.isContentEditable);

            const slash = event.key === '/' && !typing;
            const cmdK = (event.metaKey || event.ctrlKey) && (event.key === 'k' || event.key === 'K');
            if (slash || cmdK) {
                event.preventDefault();
                if (form) {
                    form.classList.add('global-search--open');
                }
                input.focus();
                input.select();
            }
        });

        // mobile: the magnifier toggles the collapsed box open (harmless on desktop — just focuses)
        const icon = form ? form.querySelector('.global-search__icon') : null;
        if (icon) {
            icon.addEventListener('click', function () {
                form.classList.add('global-search--open');
                input.focus();
            });
        }
    });
})();
```

- [ ] **Step 2: Import the module in the bundle**

In `resources/assets/js/app.js`, add the import after `import './autocomplete';` (line 26):

```js
import './autocomplete';
import './globalsearch';
import './front';
```

- [ ] **Step 3: Append the SCSS**

Append to `resources/assets/sass/app.scss` (after the existing autocomplete block, or at end of file):

```scss
/*
* ==========================================================
*     GLOBAL SEARCH (navbar box + grouped results dropdown)
* ==========================================================
*/
.global-search {
  position: relative;
  flex: 1 1 auto;
  max-width: 420px;
  margin: 0 16px;
  display: flex;
  align-items: center;

  &__icon {
    position: absolute;
    left: 12px;
    color: #8a8d93;
    cursor: pointer;
  }

  &__input {
    width: 100%;
    padding: 7px 12px 7px 32px;
    background: #22252a;
    border: 1px solid #3a3e45;
    border-radius: 30px;
    color: #c4c6ca;
    font-size: 0.9rem;

    &::placeholder {
      color: #6a6c70;
    }

    &:focus {
      outline: none;
      border-color: #DB6574;
      background: #2d3035;
    }
  }

  &__menu {
    position: absolute;
    z-index: 1001;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    margin: 0;
    padding: 6px 0;
    list-style: none;
    max-height: 70vh;
    overflow-y: auto;
    background: #2d3035;
    border: 1px solid #444951;
    border-radius: 6px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
  }

  &__group {
    padding: 6px 14px 3px;
    color: #75787f;
    text-transform: uppercase;
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    font-weight: 700;
  }

  &__item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 14px;
    color: #c4c6ca;
    text-decoration: none;
    font-size: 0.9rem;

    &:hover,
    &.is-active {
      background: #DB6574;
      color: #fff;
    }
  }

  &__item-icon {
    flex: 0 0 1rem;
    text-align: center;
    color: #864DD9;
  }

  // keep the icon readable on the pink highlight
  &__item:hover &__item-icon,
  &__item.is-active &__item-icon {
    color: #fff;
  }

  &__item-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__empty {
    padding: 10px 14px;
    color: #75787f;
    font-size: 0.85rem;
  }
}

// mobile: collapse to just the magnifier; tapping it (adds .global-search--open) reveals a
// full-width box dropped under the navbar
@media (max-width: 767px) {
  .global-search {
    flex: 0 0 auto;
    max-width: none;
    margin: 0 8px;

    .global-search__input {
      display: none;
    }

    &--open .global-search__input {
      display: block;
      position: absolute;
      top: 100%;
      left: 8px;
      right: 8px;
      z-index: 1001;
    }

    &--open .global-search__menu {
      left: 8px;
      right: 8px;
    }
  }
}
```

- [ ] **Step 4: Build the assets**

Run: `npm run build`
Expected: Vite completes with no errors and emits a new `public/build/` bundle.

- [ ] **Step 5: Commit**

```bash
git add resources/assets/js/globalsearch.js resources/assets/js/app.js resources/assets/sass/app.scss
git commit -m "feat(search): live grouped navbar search dropdown (globalsearch.js + styles)"
```

---

### Task 2.4: Remove the dormant `.search-panel` SCSS

**Files:**
- Modify: `resources/assets/sass/dark_admin_bootstrapius.scss` (delete two dead rules)

- [ ] **Step 1: Delete the dead search-overlay rules**

In `resources/assets/sass/dark_admin_bootstrapius.scss`, delete:
1. The `.search-panel { display: none; }` rule and the entire following `.search-inner { … }` rule (lines ~376-427, ending at the `}` that closes `.search-inner`). These styled the overlay just removed from the Blade.
2. In the `@media (max-width: 1199px)` block, the `.search-inner form { … }` rule (lines ~719-730).

Leave every other rule in both blocks intact (e.g. `.menu-large`, `.dropdown-menu`, `.sidebar-toggle`).

- [ ] **Step 2: Rebuild to confirm the SCSS still compiles**

Run: `npm run build`
Expected: Vite/Sass completes with no "unmatched `}`" or undefined-variable errors.

- [ ] **Step 3: Commit**

```bash
git add resources/assets/sass/dark_admin_bootstrapius.scss
git commit -m "refactor(search): drop dead .search-panel overlay styles"
```

---

### Task 2.5: Verify the search end-to-end (Playwright)

> No JS test harness exists; verify in a real browser via the Playwright MCP tools. Requires the app reachable in this DDEV environment with some scraped data (players/servers exist after the scheduled scraper has run).

- [ ] **Step 1: Open the home page**

Use `mcp__playwright__browser_navigate` to the app's base URL (the DDEV site URL for this project). Then `mcp__playwright__browser_snapshot` to confirm the navbar shows the global-search input.

- [ ] **Step 2: Type a query and confirm the grouped dropdown**

`mcp__playwright__browser_type` into `#global_search_input` a 2+ char fragment of a known player/clan/server name. Wait (`mcp__playwright__browser_wait_for`) for `#global_search_menu` to become visible. Snapshot: confirm at least one group heading (e.g. "Players") and result links appear, and that result anchors point at `/tee/…`, `/clan/…`, `/server/…` URLs.

- [ ] **Step 3: Confirm keyboard + navigation**

Press `ArrowDown` then `Enter` (`mcp__playwright__browser_press_key`) and confirm the browser navigates to the highlighted result's detail page. Press `/` on a fresh page load and confirm the search input gains focus.

- [ ] **Step 4: Record the result**

If all three pass, note it in the task tracker. If data is too sparse to match anything, seed a player via `php artisan tinker` (`App\Models\Player::create(['name' => 'verifytee', 'country' => 'DE'])`) and retry, then remove it. No commit (verification only).

---

## Phase 3 — Server-browser popin: click-to-pin

### Task 3.1: Switch the roster popover to manual click-to-pin

**Files:**
- Modify: `resources/assets/js/serverbrowser.js:12-49` (replace the popover block)

- [ ] **Step 1: Replace the popover initialisation block**

In `resources/assets/js/serverbrowser.js`, replace the whole `// ---- player-count popovers …` block (the `table.querySelectorAll('.server-player-count').forEach(…)` call, lines ~12-49) with the manual-trigger version below. Leave the rest of the file (connect-copy, filtering) untouched.

```js
    // ---- player-count popovers: each row's hidden .server-players carries the roster + tee canvases ----
    // Manually controlled (not Bootstrap's 'hover focus'): the roster holds tee links the user opens in
    // new tabs, and the built-in 'focus' trigger fired focusout on ctrl/middle-click and slammed the
    // popover shut before the tab opened. A click toggles a roster open (pinning it); it closes only on an
    // outside click or Esc, so modifier-clicking a link inside it leaves it open.
    let openPopover = null;

    function closeOpenPopover() {
        if (openPopover) {
            openPopover.hide();
            openPopover = null;
        }
    }

    table.querySelectorAll('.server-player-count').forEach((trigger) => {
        const roster = trigger.parentElement.querySelector('.server-players');
        if (!roster) {
            return;
        }
        const popover = new Popover(trigger, {
            html: true,
            // we own this markup (Blade-escaped); disable the sanitizer so the tee <canvas> elements
            // and their data-tee attrs are not stripped
            sanitize: false,
            trigger: 'manual',
            container: 'body',
            customClass: 'server-roster-popover', // wider + multi-column (see app.scss)
            content: () => {
                // clone the live DOM nodes rather than innerHTML — a serialized <canvas> loses its
                // pixels. Drop the roster's d-none so the clone shows. Draw the tees onto the clone
                // right here: canvas bitmaps survive being moved into the tip, and because tee.js
                // caches the composed result this is instant on repeat opens and never leaves a
                // permanently-blank canvas. Only the opened server's sprites draw, never the page's rest.
                const clone = roster.cloneNode(true);
                clone.classList.remove('d-none');
                // flow big rosters into columns so they don't scroll forever. A multicol box is
                // shrink-to-fit, so it needs an explicit width to actually form columns.
                const count = clone.childElementCount;
                const cols = count > 24 ? 3 : (count > 8 ? 2 : 1);
                if (cols > 1) {
                    clone.style.columnCount = String(cols);
                    clone.style.columnGap = '16px';
                    clone.style.width = (cols * 176) + 'px';
                }
                renderAllTees(clone, { onlyVisible: false });
                return clone;
            },
        });

        // click toggles this roster; stopPropagation keeps the document handler below from
        // immediately treating the same click as an "outside" click
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (openPopover === popover) {
                closeOpenPopover();
                return;
            }
            closeOpenPopover();
            popover.show();
            openPopover = popover;
        });
    });

    // close the open roster on an outside click; a click inside it (including a ctrl/middle-click on a
    // tee link that opens a new tab) is left alone, so the roster stays pinned
    document.addEventListener('click', (event) => {
        if (!openPopover) {
            return;
        }
        if (event.target.closest('.server-roster-popover')) {
            return;
        }
        closeOpenPopover();
    });

    // Esc closes any open roster
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeOpenPopover();
        }
    });
```

- [ ] **Step 2: Build the assets**

Run: `npm run build`
Expected: Vite completes with no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/assets/js/serverbrowser.js
git commit -m "fix(serverbrowser): pin player roster on click so opening a tee in a new tab keeps it open"
```

- [ ] **Step 4: Verify the fix (Playwright)**

Navigate to the server-browser page (`/serverbrowser`). Then:
1. `mcp__playwright__browser_click` a `.server-player-count` badge on a populated row → snapshot shows the `.server-roster-popover` with player rows + tee canvases.
2. Ctrl/middle-click a `.server-player` tee link inside the roster (open in a new tab) — use `mcp__playwright__browser_click` with the modifier, or `mcp__playwright__browser_press_key`/`run_code_unsafe` to dispatch a ctrl-click — then snapshot the original tab and **assert the roster is still open** (the bug being fixed).
3. Click empty page area → roster closes. Press the badge again, then `Escape` → roster closes.

If `/serverbrowser` has no populated rows (empty roster), this can't be exercised — note that and rely on the next scrape, or temporarily seed a server+players via tinker.

---

## Final verification

- [ ] **Step 1: Full PHP suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass (including the new `GlobalSearchTest`); no regressions in `SearchFlowTest`, `LiveServerBrowserTest`, `RouteSmokeTest`.

- [ ] **Step 2: Production asset build**

Run: `npm run build`
Expected: clean build, no Sass/JS errors.

- [ ] **Step 3: Route sanity**

Run: `php artisan route:list | grep -E 'search/global|login|logout' && php artisan route:list | grep -i register || echo "register gone (expected)"`
Expected: `search/global`, `login`, `logout` present; no `register` route.

---

## Self-review notes (coverage check against the spec)

- Spec WS1 "close registration / delete RegisterController+view / remove Register link" → Task 1.1. ✓
- Spec WS1 "admin:create Artisan command" → Task 1.2. ✓
- Spec WS1 "remove Facebook references" → Task 1.3 (both Facebook blocks). ✓
- Spec WS1 "no role column / email login unchanged" → no schema/login-form change in any task. ✓
- Spec WS1 "no auth tests" → Phase 1 ships none. ✓
- Spec WS2 "unified grouped endpoint reusing FuzzySearch" → Task 2.1 (+ test). ✓
- Spec WS2 "navbar input, collapses on mobile" → Task 2.2 markup + Task 2.3 responsive SCSS. ✓
- Spec WS2 "globalsearch.js: debounced fetch, grouped dropdown, arrow/Enter/Esc, '/' & Ctrl/Cmd+K" → Task 2.3. ✓
- Spec WS2 "remove dormant .search-panel (markup + SCSS)" → Task 2.2 (markup) + Task 2.4 (SCSS). ✓
- Spec WS2 "XSS: textContent not innerHTML; min 2 chars" → Task 2.3 render() + MIN_LENGTH, Task 2.1 server guard. ✓
- Spec WS2 "v1 non-goal: no per-result tee canvases (FA icons)" → Task 2.3 GROUPS icons. ✓
- Spec WS2 "endpoint feature test" → Task 2.1 GlobalSearchTest. ✓
- Spec WS3 "trigger: manual, click-to-pin, outside-click + Esc, one open at a time, tee render unchanged" → Task 3.1. ✓
- Spec WS3 "Playwright regression verification" → Task 3.1 Step 4. ✓
- Cross-cutting "npm run build / phpunit / commit per workstream; no lint script" → per-task builds + Final verification. ✓
