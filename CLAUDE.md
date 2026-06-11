# twstats — Teeworlds Stats

A Laravel 13 application that scrapes Teeworlds master/game servers and tracks players,
clans, servers, maps, mods and play time. Dark "Bootstrapious" admin theme.

## Project identity & shared workspace

> This repo is **twstats**. The DDEV web container mounts every project at
> `/var/www/html`, and the Claude file-memory store is **shared across all of them** —
> always confirm a remembered fact belongs to twstats before acting on it.

**Committing is allowed in this project.** (This overrides the global
"don't auto-commit" preference, which still applies to other repos.) Commit your own
work here; do not push unless asked.

## Stack

- PHP 8.3+, Laravel 13 (`laravel/ui` auth, `spatie/laravel-responsecache`).
- Frontend: Bootstrap 5.3 + SCSS compiled by **Vite** (`npm run build`), Chart.js 4,
  jQuery + jQuery UI, `flag-icons`, Font Awesome 4.7.
- DB: MySQL in DDEV; SQLite `:memory:` for tests.
- Runs **inside** the DDEV web container — use bare `php artisan`, `composer`, `npm`,
  and `curl` with a `Host:` header.

## Common commands

- `npm run build` — compile assets. There is no dev server running by default, so
  **rebuild after any SCSS/JS change** (built assets live in the git-ignored
  `public/build/`, so they are rebuilt on deploy too).
- `vendor/bin/phpunit` — run the test suite (`tests/Unit`, `tests/Feature`).
- `php artisan migrate` — run migrations against the dev DB.
- `php artisan data:update` — the scraper (scheduled every 10 min in `bootstrap/app.php`).

## Architecture

- **Data collection**: `app/Console/Commands/UpdateData.php` queries the master/game
  servers and upserts `players`, `servers`, `clans`, aggregated `player_histories` /
  `server_histories`, and discrete `player_sessions` (via `App\Service\SessionRecorder`).
- **Teeworlds protocol layer**: `app/TwStats/` (master/game server querying, the
  `Countries` id↔code↔name map).
- **Models** (`app/Models`): chart/aggregate helpers live on the models themselves
  (`chartPlayed*`, `chartOnline*`, `chartOnlineHeatmap`, session helpers on `Player`).
  Histories store *aggregated minutes* per weekday/hour/map/mod; `player_sessions`
  is the only source of discrete login→logout windows.
- **Controllers**: `MainController` (lists + general stats), `SearchController`
  (detail pages + fuzzy search via `App\Service\FuzzySearch`).
- **Views** (`resources/views`, Blade): theme in
  `resources/assets/sass/dark_admin_bootstrapius.scss` is vendored but **edited in
  place**. Shared country breakdown markup lives in `resources/views/partials/countries.blade.php`.
- **Country flags**: `flag-icons` SVGs are copied to `/build/flags/4x3` by
  `vite.config.js` (`stripBase`); `$flag-icons-path` in `app.scss` points there
  (Sass does not rebase the package's relative `url()`s).

## Testing notes

- Feature tests use `RefreshDatabase` + factories (`Player`, `Server`, `Clan`).
  `Map`/`Mod` have no factory — create them with `::create(['name' => ...])`.
- `phpunit.xml` sets `CRONTASK_INTERVAL` (the scrape window used by `Player::online()`
  and `SessionRecorder`); keep it set or online/session logic degenerates.

## Conventions

- Comments explain *why* the current code exists (present tense), not what changed.
- Write specs/working docs in English.
