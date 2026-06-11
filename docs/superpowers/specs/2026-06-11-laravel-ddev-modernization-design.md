# twstats Modernization — Design Spec

**Date:** 2026-06-11
**Project:** twstats (Teeworlds Stats) — `laravel/laravel` app
**Goal:** Bring a ~7-year-old Laravel 5.8 application up to current tooling: DDEV configuration, PHP, Node, the Laravel framework, and the frontend build chain.

---

## 1. Current state (baseline)

| Component | Current |
|---|---|
| PHP | `^7.1.3` (DDEV pinned to 7.3) |
| Laravel | 5.8.38 |
| DDEV config | `APIVersion: v1.12.0`, drud-era images, Node installed via a `setup_13.x` NodeSource `post-start` hook |
| Database | MariaDB 10.2 |
| Node | 13 |
| Build tool | laravel-mix 5 + webpack |
| Sass | `node-sass` (deprecated) |
| CSS framework | Bootstrap 4 + jQuery |
| Charts | Chart.js 2 |
| Tests | none |

**Application shape:** ~45 PHP files. A Teeworlds game-server stats site with:
- Custom UDP server-querying engine under `app/TwStats/*` (`socket_create`/`socket_sendto`/`socket_recvfrom`, requires `ext-sockets`).
- Standard MVC controllers (`MainController`, `SearchController`, `AjaxSearchController`, `InformationController`) + the `TwStats` controllers.
- 10 Eloquent models, 12 migrations, factories + seeders.
- Scheduled console commands (`UpdateData`, `UpdateDailySummary`) run via an in-container cron calling `artisan schedule:run`.
- Classic Laravel auth scaffolding (`Auth::routes()` + Login/Register/Reset controllers).
- Blade views (~30) using Bootstrap 4 markup, jQuery, jQuery-UI, jquery-validation, Chart.js.

**Notable / risky dependencies:**
- `laravelcollective/html` — abandoned after Laravel 8. Used in exactly **one** view (`search.blade.php`).
- `tom-lingham/searchy` — abandoned fuzzy-search package. Used in `SearchController` (6 calls) + registered in `config/app.php`. All usages are single-field (`name`) relevance lookups: `Searchy::search('<table>')->fields('name')->query($term)->getQuery()->having('relevance','>',20)->limit(10)->get()`.
- `fideloper/proxy` — folded into Laravel core (`TrustProxies`) as of L9.
- `fzaninotto/faker` — abandoned → `fakerphp/faker`.
- `doctrine/dbal ^2`, `spatie/laravel-responsecache ^5`, `barryvdh/laravel-ide-helper ^2`, `laravel/tinker ^1`, `nunomaduro/collision ^3`, `khill/php-duration ^1`.

---

## 2. Target stack

| Component | Target | Rationale |
|---|---|---|
| PHP | **8.5** | Newest stable (8.5.7, Jun 2026); fully supported by Laravel 13; project's only ext needs (`ext-sockets`, `ext-json`) are core |
| Laravel | **13.x** | Current major; min PHP 8.3 |
| DDEV | current schema, `type: laravel` | drop drud-era config |
| Database | **MariaDB 11.4 LTS** | LTS (maintained to 2029), supported by DDEV + L13 |
| Node | **22 LTS** | Native in DDEV; no NodeSource hook |
| Build tool | **Vite 6** | Laravel default since L9 |
| Sass | **dart-sass** | `node-sass` is EOL |
| CSS framework | **Bootstrap 5** | jQuery dropped where feasible |
| Charts | **Chart.js 4** | current major |

### Decisions made during brainstorming
- **Target Laravel 13** (newest), not 12.
- **PHP 8.5** (user preference; verified clean — nothing in the dependency set conflicts).
- **Migration strategy: fresh skeleton + port code** (app is small, no Vue; this yields the modern L11/12/13 skeleton rather than carrying the legacy structure forward).
- **Full frontend modernization** (Vite + Bootstrap 5 + Chart.js 4), not just a build-tool swap.
- **Tests-first**: add a regression baseline before migrating.

---

## 3. DDEV modernization

Regenerate `.ddev/config.yaml` to the current schema:
- `type: laravel`, `php_version: "8.5"`, `nodejs_version: "22"`, `docroot: public`.
- `database: { type: mariadb, version: "11.4" }`.
- Remove the obsolete `post-start` NodeSource hooks (Node is native now).
- Preserve the **scheduler**: keep an in-container cron running `php artisan schedule:run` every minute, via a modernized `post-start` hook or `web_extra_daemons`.
- Confirm `ext-sockets` is present in the current DDEV web image (it is in the standard image) — do not add a custom build for it unless verification shows otherwise.
- Prune stale drud-era bundled directories under `.ddev/` that are no longer part of the current DDEV layout.

**Acceptance:** `ddev start` boots cleanly on PHP 8.5 / Node 22 / MariaDB 11.4; `ddev exec php -v`, `ddev exec node -v`, `ddev exec php -m | grep sockets` all confirm the target versions/extension.

---

## 4. Laravel rebuild (fresh skeleton + port)

Scaffold a clean Laravel 13 skeleton, then port project code into it.

**Move in (logic largely unchanged):**
- `app/Models/*`
- `app/TwStats/*` — UDP query engine; socket logic is stable on PHP 8.5.
- `app/Console/Commands/*`
- `app/Http/Controllers/*` (excluding the framework `Controller` base, which the skeleton provides)
- `app/Utility/ChartUtility.php`
- `database/migrations/*`, `database/factories/*`, `database/seeds/*` → `database/seeders/*`
- `resources/views/*`, `resources/lang/*`
- public/static assets

**Adapt to the modern skeleton:**
- Middleware, exception handling, and routing config move into `bootstrap/app.php`. Delete `app/Http/Kernel.php` and `app/Console/Kernel.php`.
- Console scheduling → `routes/console.php` or `bootstrap/app.php` `->withSchedule(...)`. Reproduce the existing `UpdateData` / `UpdateDailySummary` cadence (honoring `CRONTASK_INTERVAL`).
- Drop the manual provider/alias arrays in `config/app.php`; rely on package auto-discovery + `bootstrap/providers.php`.
- Regenerate the standard `config/*` files and re-apply only the project's customizations (`config/responsecache.php`, any custom `services`/env wiring).
- Custom service providers (`AppServiceProvider`, `RouteServiceProvider`, etc.) carried over only where they hold real project logic.

**Route syntax:** convert all string-action routes (`'MainController@home'`) to FQCN array syntax (`[MainController::class, 'home']`) — the implicit controller namespace was removed in L8.

**Factories:** rewrite `database/factories` to class-based factories using `fakerphp/faker`.

**Auth:** install `laravel/ui` to restore `Auth::routes()` and the Login/Register/ForgotPassword/ResetPassword controllers, generating the **Bootstrap 5** auth scaffolding.

### Dependency replacement map

| Package | Action |
|---|---|
| `laravel/framework ^5.8` | → `^13` |
| `laravelcollective/html` | **Remove** — replace the single `search.blade.php` form with plain Blade + `@csrf` |
| `tom-lingham/searchy` | **Remove** — replace with an in-house relevance-scored query scope/service (exact/prefix/contains weighting → `relevance`, faithful to the existing `having('relevance','>',20)->limit(10)` usage) |
| `fideloper/proxy` | **Remove** — `TrustProxies` is core since L9 |
| `fzaninotto/faker` | → `fakerphp/faker` (dev) |
| `laravel/tinker ^1` | → `^2` |
| `nunomaduro/collision ^3` | → current L13-compatible |
| `spatie/laravel-responsecache ^5` | → latest L13 + PHP 8.5-compatible release (**verify**; fallback: disable response caching temporarily) |
| `barryvdh/laravel-ide-helper ^2` | → `^3` (dev) |
| `doctrine/dbal ^2` | → `^4` only if still referenced; otherwise drop (L13 rarely needs it) |
| `khill/php-duration ^1` | Keep — framework-agnostic; verify on PHP 8.5 |
| `laravel/ui` | **Add** — Bootstrap 5 auth scaffolding + `Auth::routes()` |

**Searchy replacement detail:** a small reusable helper (model scope or `FuzzySearch` service) that, given a search term and the `name` column, builds a query with a computed `relevance` score (e.g. exact match = highest, prefix `LIKE 'term%'` = high, contains `LIKE '%term%'` = medium) and supports `->having('relevance','>',N)->limit(M)`. Returns rows compatible with the existing `Model::hydrate(...)` calls so the controller change is minimal.

---

## 5. Frontend modernization

- Replace `webpack.mix.js` with `vite.config.js` (laravel-vite-plugin). Swap the `mix()` helper for `@vite([...])` in `resources/views/layouts/app.blade.php`.
- `node-sass` → **dart-sass**; fix deprecated Sass syntax (`@import` deprecations, `/` division → `math.div`).
- **Bootstrap 4 → 5** across SCSS and Blade: data-attribute renames (`data-toggle` → `data-bs-toggle`, `data-target` → `data-bs-target`, etc.), utility/class renames (`ml-*`/`mr-*` → `ms-*`/`me-*`, `.badge-*` → `.text-bg-*`, etc.), removed jQuery-dependent JS components.
- **Chart.js 2 → 4** API updates in `charthelper.js` and any chart config emitted by `ChartUtility.php` (scales config, dataset options).
- Keep jQuery only where genuinely still required (jQuery-UI widgets, jquery-validation); evaluate per use site and drop where Bootstrap 5 / vanilla JS suffices.

**Acceptance:** `npm install && npm run build` (Vite) succeeds on Node 22; pages render correctly with Bootstrap 5; charts render with Chart.js 4.

---

## 6. Test safety net (built first, as a regression baseline)

Because the app currently has **zero tests**, add a feature/unit suite against the **current 5.8 app first**, so behavior is pinned before migrating:
- HTTP smoke tests for every GET route (assert 200 + a key content marker) — `general`, `about`, `search`, the list pages, the detail/edit pages, the AJAX search endpoints.
- Search/redirect flow tests (`searchTee`/`searchClan`/... validation + redirect behavior, including the "not found → suggestions" path that exercises the Searchy replacement).
- A unit test around TwStats packet parsing using a **captured fixture** (no live UDP) so the query/parse logic is locked.
- Live UDP querying is mocked or skipped in CI.

The suite runs green on 5.8, then must stay green after the DDEV change and after the Laravel rebuild. Bootstrap 5 visual changes are not fully covered by these tests — phase 4 adds manual/Playwright visual verification.

---

## 7. Phasing

1. **Baseline tests** — add the regression suite against the current 5.8 app; confirm green.
2. **DDEV modernization** — PHP 8.5 / Node 22 / MariaDB 11.4; current app boots on the new container; tests green.
3. **Laravel rebuild** — fresh L13 skeleton, port code, dependency swaps, Searchy + form replacements, route-syntax conversion, factory rewrites, `laravel/ui` auth; tests green.
4. **Frontend** — Vite + Bootstrap 5 + Chart.js 4; build succeeds; manual/visual verification.
5. **Cleanup** — update `.github/workflows/*` (PHP 8.5 / Node 22), `README.md`, `.env.example`; final end-to-end verification.

---

## 8. Risks & open questions

- **`spatie/laravel-responsecache` on L13 + PHP 8.5** — verify a compatible release exists. Fallback: temporarily disable response caching; do not downgrade PHP.
- **Bootstrap 5 migration** is the largest visual-risk item (touches many Blade templates). Baseline tests cover routing/data, not pixels — phase 4 needs manual/Playwright visual checks.
- **CI workflows** (`php.yml`, `theme.yml`) reference old PHP/Node and will be rewritten in phase 5.
- **Scheduler parity** — confirm the modernized cron/daemon fires `schedule:run` on the new DDEV image exactly as before.
- **`khill/php-duration` on PHP 8.5** — small framework-agnostic lib; verify it has no deprecated-syntax breakage; replace with a trivial helper if it fails.

---

## 9. Out of scope

- No feature changes or redesign — this is a modernization, behavior is preserved.
- No database schema changes beyond what the framework upgrade requires.
- No move to a JS framework (Vue/React) — the app uses none today.
- Bumping to Laravel 14+ or PHP 8.6 (future) — not part of this effort.
