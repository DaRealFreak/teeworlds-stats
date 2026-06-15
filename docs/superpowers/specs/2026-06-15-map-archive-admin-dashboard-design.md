# Map Archive Admin Dashboard — Design

**Date:** 2026-06-15
**Status:** Approved (ready for implementation planning)

## Goal

Give the (admin-only) operator a login-gated dashboard with visual stats about the map
archiving pipeline: which map versions were downloaded, which failed, and which game servers
fail to serve maps. This requires recording acquisition outcomes the system currently throws
away (failures, failure reasons, and the server a UDP attempt targeted).

## Context

- The app is **admin-only**: public registration is closed (`Auth::routes(['register' => false])`),
  accounts are provisioned with `php artisan admin:create`, and `app/Models/User.php` has no role
  column. Therefore *any authenticated user is an admin* — the `auth` middleware alone gates the panel;
  no role system is introduced.
- Map acquisition runs through `App\Service\MapFileStore::ensureVersion()`, which tries
  `DdnetHttpMapSource` (HTTP mirror, `maps.ddnet.org`) then `GameServerMapSource` (in-game UDP, with a
  server-advertised HTTP fast-download fallback). Both sources return `null` on failure and **discard
  the reason and the server involved**.
- The only outcome the system currently persists is `map_versions.last_acquire_attempt_at` (a "last
  tried at" stamp shared by `InlineMapAcquirer` and `MapArchiveBackfiller`). Whether a version is
  cached is a disk check (`MapFileStore::isCachedVersion`), not a column. There is no per-server or
  per-failure record — so "which servers failed" is unanswerable from existing data.
- The sidebar layout (`resources/views/layouts/app.blade.php`) already has section seams
  (Main / Overview / Extras) and an `@auth`/`@guest` block in the top bar. Charts render through the
  global `window.Chart` / `window.ChartHelper` (declared in `resources/assets/js/global.d.ts`) from
  inline `@section('scripts')` blocks, as the detail views (`general`, `detail/*`) already do.

## Architecture

Four units, each independently testable:

1. **Tracking (write path):** an event fired by the acquisition sources and a listener that persists
   one `map_acquisition_attempts` row per attempt.
2. **Stats (read path):** `App\Service\MapArchiveStats` aggregates the attempts table plus derived
   counts from `map_versions` and on-disk storage.
3. **Presentation:** `Admin\DashboardController` + a Blade view rendering Bootstrap cards/tables and
   Chart.js graphs.
4. **Retention:** `Prunable` on the model + a scheduled `model:prune`.

### 1. Tracking: `map_acquisition_attempts`

**Migration** creates the table:

| column           | type                                   | notes |
|------------------|----------------------------------------|-------|
| `id`             | big increments                         | |
| `map_version_id` | unsigned int, FK → `map_versions`, idx | the version we tried to acquire |
| `server_id`      | unsigned int **nullable**, FK → `servers`, idx | the host dialled; **null** for HTTP-mirror fetches |
| `source`         | string(16), idx                        | `http_mirror` \| `udp` \| `http_fastdl` |
| `outcome`        | string(8)                              | `success` \| `failure` |
| `reason`         | string(32) nullable                    | short failure code (null on success) |
| `bytes`          | unsigned int nullable                  | size on success |
| `created_at`     | timestamp, idx                         | append-only; **no `updated_at`** |

FKs use `nullOnDelete` (server) / `cascadeOnDelete` (version) so the log never blocks a parent delete.
`reason` vocabulary (closed set, documented in the model): `not_mirrored_404`, `timeout`,
`connect_failed`, `name_mismatch`, `not_datafile`, `sha_mismatch`, `no_hosts`, `http_error`,
`download_failed` (generic fallback).

**Model** `App\Models\MapAcquisitionAttempt`: `$guarded = ['id']`, casts `bytes` int, `belongsTo`
`MapVersion` and `Server`, `public const UPDATED_AT = null` (append-only, created_at only). Uses
`Illuminate\Database\Eloquent\Prunable` with `prunable()` = `where('created_at', '<', now()->subDays(30))`.

**Event** `App\Events\MapAcquisitionAttempted` — a plain data carrier:
`(?int $mapVersionId, ?int $serverId, string $source, string $outcome, ?string $reason, ?int $bytes)`.

**Listener** `App\Listeners\RecordMapAcquisitionAttempt` — writes one row. The whole body is wrapped in
`try { … } catch (\Throwable) {}` so a logging failure can never propagate into (and break) a download.
Registered via Laravel 11+ auto-discovery (the listener's `handle()` type-hints the event).

**Fire points** (the sources gain an injected/dispatched event; outcomes recorded where source + reason
are known):

- `DdnetHttpMapSource::download()` — `source=http_mirror`, `server_id=null`:
  - missing sha256 → not fired (not eligible, not an attempt).
  - non-2xx → `failure` / `not_mirrored_404` (or `http_error` for non-404).
  - size or sha mismatch → `failure` / `sha_mismatch`.
  - verified → `success` with `bytes`.
- `GameServerMapSource::downloadFromHost()` — gains additive **optional** context args
  `(?int $serverId = null, ?int $mapVersionId = null)` (callers in `downloadFromCandidates` pass them;
  the public `?string` return is unchanged so existing direct-call tests stand). Fires:
  - verified UDP bytes → `success` / `source=udp`.
  - `MapAvailableViaHttp` → `fetchAdvertisedUrl` success → `success` / `source=http_fastdl`; its failure →
    `failure` / `source=http_fastdl` / reason (`name_mismatch`, `sha_mismatch`, `http_error`, `not_datafile`).
  - name mismatch / non-datafile after a UDP transfer → `failure` / `udp` / (`name_mismatch` | `not_datafile`).
  - `\Throwable` (connect/timeout/transfer) → `failure` / `udp` / `download_failed`.
  - `downloadFromCandidates` with no candidate hosts → one `failure` / `udp` / `no_hosts`
    (`server_id=null`) so "couldn't even find a host" is visible.

`InlineMapAcquirer` and `MapArchiveBackfiller` need **no** changes — they call `ensureVersion`, and the
sources beneath them now emit the events.

### 2. Stats: `App\Service\MapArchiveStats`

A read-only service the controller depends on; every method returns plain arrays/collections for the
view. All time-windowed methods take a `$days = 30` argument.

- `overview(): array` — derived, no attempts table. To stay cheap (no O(versions) disk scan), the
  headline reports counts that are each a single directory listing or `COUNT` query: `cachedFiles` and
  `cachedBytes` (one `Storage::disk('local')` listing of `maps/` — one file per content key, so this is
  the honest "maps cached" number), `totalVersions` (`COUNT` of `map_versions`), `neverAttempted`
  (`last_acquire_attempt_at IS NULL`), and `coolingDown` (`last_acquire_attempt_at >= now()-retryHours`).
  No exact per-version cached/uncached split — `cachedFiles` vs `totalVersions` conveys coverage well
  enough for a headline without statting every version.
- `downloadsPerDay(int $days): array` — `[ 'YYYY-MM-DD' => count ]` of `outcome=success` grouped by
  `date(created_at)`, zero-filled across the window (for the chart).
- `recentDownloads(int $limit = 25)` — latest successes joined to map name + version key + source + bytes.
- `recentFailures(int $limit = 25)` — latest `outcome=failure` with map name, source, reason, server.
- `failingServers(int $days, int $limit = 20)` — group failures by `server_id` (UDP sources only),
  with `fails`, `lastReason`, `lastTriedAt`, and a success-rate (`success/(success+failure)` for that
  server in the window). Ordered by `fails` desc.
- `sourceBreakdown(int $days): array` — per `source`, `{success, failure}` counts.

### 3. Presentation

- **Routes** (`routes/web.php`): a group
  `Route::middleware('auth')->prefix('admin')->group(function () { Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard'); });`
  placed before the `Auth::routes(...)` call.
- **Controller** `App\Http\Controllers\Admin\DashboardController@index` — resolves `MapArchiveStats`,
  passes the arrays to `view('admin.dashboard', …)`. Thin; no aggregation logic of its own.
- **View** `resources/views/admin/dashboard.blade.php` extends `layouts.app`:
  - headline `card`s row (counts + storage),
  - a Chart.js `<canvas>` for downloads/day (data passed via a `data-*` attribute or a `blade` global,
    consistent with the inline-script seam; chart built in an `@section('scripts')` block using
    `window.Chart` / `window.ChartHelper`),
  - tables for recent downloads, recent failures, failing servers,
  - a small source-breakdown card.
  Empty states ("no attempts recorded yet") are rendered when a section is empty.
- **Nav** (`layouts/app.blade.php`): a new `@auth` sidebar section
  `<span class="heading">Admin</span>` with a link to `route('admin.dashboard')`.

### 4. Retention

`MapAcquisitionAttempt` is `Prunable` (30 days). `bootstrap/app.php` schedules
`$schedule->command('model:prune', ['--model' => [MapAcquisitionAttempt::class]])->daily();`.

## Error handling

- The listener swallows all throwables — telemetry must never break acquisition.
- Event args are nullable where a context value may be unknown (e.g. `server_id` for HTTP, or
  `map_version_id` on the legacy `fetch(Map)` path); rows with null context are still useful aggregates.
- The dashboard degrades gracefully on an empty table (empty-state copy, zero-filled chart).

## Testing

- **Auth gate** (`tests/Feature/AdminDashboardTest.php`): guest → redirect to `login`; authenticated
  (factory user) → `200` and sees the section headings.
- **Recording** (`tests/Feature/MapAcquisitionRecordingTest.php`): drive each source with a fake
  transport/HTTP fake; assert a `map_acquisition_attempts` row with the expected
  `source`/`outcome`/`reason`/`server_id`/`bytes`. Assert the listener swallows a write failure.
- **Stats** (`tests/Feature/MapArchiveStatsTest.php`): seed attempts + versions; assert
  `downloadsPerDay` zero-fill + counts, `failingServers` ranking + success-rate, `recentFailures`,
  `sourceBreakdown`, and the derived `overview` counts.
- **Pruning** (`tests/Feature/MapAcquisitionPruneTest.php`): a 31-day-old row is pruned, a 29-day-old row
  is kept.

## Out of scope (this iteration)

- Player/clan/scrape-health admin pages (the panel is map-archive-only, but lives under `/admin` so they
  can be added later).
- Per-user roles/permissions (the app is admin-only).
- Retrying or triggering downloads from the dashboard (read-only stats only).

## Defaults

- Window for tables/charts and pruning: **30 days**.
- Chart library: the already-bundled Chart.js via the existing `window.Chart`/`ChartHelper` globals.
- Cache disk: `local` (`storage/app/private/maps`), matching `MapFileStore`.
