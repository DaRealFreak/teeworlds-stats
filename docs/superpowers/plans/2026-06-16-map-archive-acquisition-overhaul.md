# Map Archive Acquisition Overhaul — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the bounded inline acquire + hourly HTTP backfill with one unbounded background filler,
a persisted 4-state per-version `acquisition_status`, a 30-day mirror recheck, an event-driven cooldown,
and a per-(host, map) UDP backoff.

**Spec:** `docs/superpowers/specs/2026-06-16-map-archive-acquisition-overhaul-design.md` (read it for the
rationale behind each decision).

**Architecture (the seam that holds it together):** status, backoff and the per-map cooldown are written
**reactively off the existing `App\Events\MapAcquisitionAttempted` event** — the sources already fire it at
every outcome with `(mapVersionId, serverId, source, outcome, reason, bytes)`, i.e. exactly the data each
state transition needs — so no `MapSource` return-type refactor is required. `MapFileStore` sets
`acquisition_status=cached` when it writes (or finds) the file. `DdnetHttpMapSource` and
`GameServerMapSource` only *read* state to gate their work; the listener *writes* the failure/classification
transitions.

**Tech stack:** Laravel 13, PHP 8.3, PHPUnit (SQLite `:memory:`). `phpunit.xml` sets `CRONTASK_INTERVAL=10`.

**Conventions (every task):**
- TDD: failing test first, run it red, implement, run green, commit.
- Comments explain *why* (present tense), never "what changed".
- Commits stage **only** the files the task touches — NEVER `git add -A/-u/.` (untracked reference dirs
  `ddnet/`, `teeworlds/`, `.ddev/`, `ddnet-scripts/`, `ddnet-web/`, `map_peaks/` must stay untracked).
- End commit messages with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- `Map`/`Mod` have no factory — `Model::create(['name' => ...])`. `Player`/`Server`/`Clan` have factories.
- Run the named tests after each task; run the full suite (`vendor/bin/phpunit`) at the end of each task.

---

## Task 1: Schema + `ServerMapBackoff` model

**Files:**
- Create: `database/migrations/2026_06_16_000000_add_acquisition_status_to_map_versions.php`
- Create: `database/migrations/2026_06_16_000100_create_server_map_backoffs_table.php`
- Create: `app/Models/ServerMapBackoff.php`
- Test: `tests/Feature/ServerMapBackoffTest.php`

- [ ] **Step 1 — migration: map_versions status columns**

```php
// up(): Schema::table('map_versions', ...)
$table->string('acquisition_status', 16)->default('pending')->after('size');
$table->timestamp('status_checked_at')->nullable()->after('acquisition_status');
$table->index('acquisition_status');
// down(): drop the index then the two columns
```

- [ ] **Step 2 — migration: server_map_backoffs table**

```php
Schema::create('server_map_backoffs', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('server_id');
    $table->unsignedInteger('map_id');
    $table->unsignedSmallInteger('failure_streak')->default(0);
    $table->timestamp('cooldown_until')->nullable();
    $table->timestamps();
    $table->unique(['server_id', 'map_id']);
    $table->index('cooldown_until'); // candidate-filter + prune
    $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
    $table->foreign('map_id')->references('id')->on('maps')->cascadeOnDelete();
});
```

- [ ] **Step 3 — write failing test** `tests/Feature/ServerMapBackoffTest.php` (`RefreshDatabase`):
  - `test_recording_a_failure_escalates_the_cooldown_span`: call `ServerMapBackoff::recordFailure($serverId, $mapId)` 3× and assert `failure_streak` is 1→2→3 and `cooldown_until` is ~`now()+30m`, `+1h`, `+2h` (assert within a few seconds with `assertEqualsWithDelta` on the timestamp diff in minutes).
  - `test_span_is_capped`: many failures → `cooldown_until` diff never exceeds the 24h cap.
  - `test_clear_resets_the_pair`: after failures, `ServerMapBackoff::clear($serverId, $mapId)` removes the row (or zeroes it) → `inBackoff()` false.
  - `test_in_backoff_only_while_cooldown_in_future`: a row with `cooldown_until` in the past is not in backoff.

- [ ] **Step 4 — run red:** `vendor/bin/phpunit --filter ServerMapBackoffTest` → fails (no model).

- [ ] **Step 5 — implement `App\Models\ServerMapBackoff`:**
  - `$guarded = ['id']`; cast `cooldown_until` → `datetime`.
  - Relations `server()`, `map()`.
  - `protected static function span(int $streak): \Carbon\CarbonInterval` — base 30 min, `min(BASE << (streak-1), CAP)` with `CAP = 24h`; read `MAP_HOST_UDP_BACKOFF_BASE_MIN` (default 30) and `MAP_HOST_UDP_BACKOFF_CAP_MIN` (default 1440).
  - `public static function recordFailure(int $serverId, int $mapId): void` — `updateOrCreate(['server_id'=>…,'map_id'=>…], …)`; increment streak; set `cooldown_until = now()->add(self::span($newStreak))`.
  - `public static function clear(int $serverId, int $mapId): void` — delete the row.
  - `public function inBackoff(): bool` — `cooldown_until !== null && cooldown_until->isFuture()`.
  - Implement `Illuminate\Database\Eloquent\MassPrunable` + `prunable()` = `where('cooldown_until', '<', now()->subDay())` so long-expired rows are swept by the daily `model:prune`.

- [ ] **Step 6 — run green:** `vendor/bin/phpunit --filter ServerMapBackoffTest`. Then `php artisan migrate` against dev DB.

- [ ] **Step 7 — register prune + commit.** Add `ServerMapBackoff::class` to the daily `model:prune` array in `bootstrap/app.php` (next to `MapAcquisitionAttempt::class`). Commit migrations + model + test + bootstrap change.

---

## Task 2: `MapVersion` status vocabulary + helpers

**Files:**
- Modify: `app/Models/MapVersion.php`
- Test: `tests/Unit/Models/MapVersionStatusTest.php`

- [ ] **Step 1 — failing test:** assert the four status constants exist (`STATUS_PENDING/CACHED/UDP_ONLY/UNAVAILABLE` = `pending/cached/udp_only/unavailable`), that `acquisition_status` defaults to `pending` on a freshly created version, and that a `scopeNeedingAcquisition` query excludes `cached` versions and includes the others.
- [ ] **Step 2 — run red.**
- [ ] **Step 3 — implement:** add the constants; cast `status_checked_at` → datetime; `scopeNeedingAcquisition($q)` = `where('acquisition_status', '!=', self::STATUS_CACHED)`; a small `markStatus(string $status): void` query-builder helper that sets `acquisition_status` + `status_checked_at=now()` without bumping the model's own `updated_at` (mirror the existing `markAttempted` query-builder pattern). Keep `contentKey()` as-is.
- [ ] **Step 4 — run green; commit.**

---

## Task 3: Reactive status + backoff + cooldown via the event listener

This is the heart of the overhaul. Extend the existing telemetry listener so the same event that records a
`map_acquisition_attempts` row also advances `acquisition_status`, the per-(host,map) backoff, and the
per-map cooldown. Keep the telemetry write and the state write in separate `try/catch` blocks so neither
can break the other (and neither can break a download).

**Files:**
- Modify: `app/Listeners/RecordMapAcquisitionAttempt.php` (add a second guarded step), OR create
  `app/Listeners/ApplyMapAcquisitionState.php` as a second auto-discovered listener on the same event.
  Prefer a **separate listener** so telemetry and control-state stay independent.
- Create: `app/Listeners/ApplyMapAcquisitionState.php`
- Test: `tests/Feature/MapAcquisitionStateTest.php`

- [ ] **Step 1 — failing tests** (`RefreshDatabase`; dispatch `MapAcquisitionAttempted` and assert DB state).
  Build a `Map` + `MapVersion` (+ `Server` where a serverId is needed). One test per transition:
  - http_mirror / failure / `not_mirrored_404` → version `acquisition_status=udp_only`, `status_checked_at` set, `last_acquire_attempt_at` **unchanged**.
  - http_mirror / failure / `sha_mismatch` (and `http_error`) → `last_acquire_attempt_at` set (map cooldown), status **unchanged**.
  - udp / failure / `no_hosts` → version `acquisition_status=unavailable`, `status_checked_at` set.
  - udp / failure / `connect_failed` (and `timeout`, `download_failed`) with serverId → a `server_map_backoffs` row for (server, version.map) with `failure_streak=1`.
  - udp / failure / `name_mismatch` (and `crc_mismatch`) → **no** backoff row, status unchanged.
  - udp / success with serverId → backoff row cleared (seed one first), version `acquisition_status=cached`.
  - http_mirror / success → version `acquisition_status=cached`.
- [ ] **Step 2 — run red.**
- [ ] **Step 3 — implement `ApplyMapAcquisitionState::handle(MapAcquisitionAttempted $e)`** inside a
  whole-body `try/catch(\Throwable)`:
  - Resolve `$version = $e->mapVersionId ? MapVersion::with('map')->find($e->mapVersionId) : null;` and
    `$mapId = $version?->map?->id`.
  - `match` on `[$e->source, $e->outcome, $e->reason]` (use guard `if`s for the reason groups):
    - success (any source) + `$version` → `$version->markStatus(cached)`.
    - success + udp/udp_fallback + serverId + mapId → `ServerMapBackoff::clear($serverId, $mapId)`.
    - http_mirror failure `not_mirrored_404` + `$version` → `markStatus(udp_only)`.
    - http_mirror failure (`http_error`|`sha_mismatch`) + `$version` → stamp `last_acquire_attempt_at=now()` (query-builder, like `markAttempted`).
    - udp/udp_fallback failure (`connect_failed`|`timeout`|`download_failed`) + serverId + mapId → `ServerMapBackoff::recordFailure($serverId, $mapId)`.
    - udp failure `no_hosts` + `$version` → `markStatus(unavailable)`.
    - else (name_mismatch / crc_mismatch / not_datafile) → nothing.
  - Define the reason groups as private const arrays so they read clearly and match
    `GameServerMapSource::classifyDownloadFailure`'s tokens.
- [ ] **Step 4 — run green; commit.**

---

## Task 4: `MapFileStore` sets `cached`; `DdnetHttpMapSource` recheck gate

**Files:**
- Modify: `app/Service/MapFileStore.php`
- Modify: `app/Service/DdnetHttpMapSource.php`
- Test: `tests/Feature/MapFileStoreStatusTest.php`

- [ ] **Step 1 — failing tests:**
  - `ensureVersion` on a version whose file already exists on disk sets `acquisition_status=cached` (reconcile) and returns the path.
  - `DdnetHttpMapSource::fetchVersion` returns null **without making an HTTP call** when the version is `udp_only` and `status_checked_at` is within `MAP_MIRROR_RECHECK_DAYS` (use `Http::fake()` + `Http::assertNothingSent()`).
  - It **does** probe again when `status_checked_at` is older than the recheck window (`Http::assertSentCount(1)`).
- [ ] **Step 2 — run red.**
- [ ] **Step 3 — implement:**
  - `MapFileStore::ensureVersion`: in the cache-hit early return, also `$version->markStatus(cached)` if not already cached. (The cached event-listener path covers fresh downloads; this reconciles pre-existing files + the migration's `pending` default.) Leave `ensure(Map)` legacy path untouched.
  - `DdnetHttpMapSource::download` / `fetchVersion`: before probing, if a version is supplied and is `udp_only` (or `unavailable`) with `status_checked_at` newer than `MAP_MIRROR_RECHECK_DAYS` (default 30), return null without the HTTP call. (Pass the `MapVersion` down, or add a guard in `fetchVersion` before delegating to `download`.) Keep the 404 telemetry firing on a real probe so the listener can flip status.
- [ ] **Step 4 — run green; commit.**

---

## Task 5: `GameServerMapSource` (host, map) backoff filter

**Files:**
- Modify: `app/Service/GameServerMapSource.php`
- Test: `tests/Feature/Connection/GameServerMapSourceBackoffTest.php`

- [ ] **Step 1 — failing tests** (`RefreshDatabase`, factory connection like the existing
  `MapAcquisitionRecordingTest`):
  - A candidate host with a future `server_map_backoffs` row for this map is **skipped** (connection factory never invoked; records `no_hosts` when it is the only candidate).
  - The same host with a backoff row for a **different** map is **still dialed** for this map.
  - An expired backoff row does **not** skip the host.
- [ ] **Step 2 — run red.**
- [ ] **Step 3 — implement:** in `downloadFromCandidates`, after selecting live `server_id`s, drop those
  whose `(server_id, map_id)` has a `server_map_backoffs.cooldown_until` in the future. The `map_id` is the
  version's map (thread it in, or look it up once from the version). Prefer a single `whereNotExists`/left-join
  so it stays one query. The backoff stacks with the live-window gate already present.
- [ ] **Step 4 — run green; commit.**

---

## Task 6: `MapArchiveFiller` + `maps:archive-fill` command

Evolve `MapArchiveBackfiller` into the unbounded, status-aware filler with bulk live-host+backoff
resolution. Keep `MapArchiveBackfiller`'s candidate listing and cooldown handling.

**Files:**
- Create: `app/Service/MapArchiveFiller.php` (or rename `MapArchiveBackfiller.php` → and delete the old).
- Create: `app/Console/Commands/ArchiveMapFill.php` (`maps:archive-fill {--time-budget=} {--limit=0}`).
- Test: `tests/Feature/MapArchiveFillTest.php`

- [ ] **Step 1 — failing tests** (`RefreshDatabase`, inject a fake store / connection factory):
  - Drains **all** uncached versions in one run when under budget (no 25-cap) — seed e.g. 30 cacheable-over-HTTP versions, fake HTTP 200, assert all 30 cached.
  - Stops at the wall-clock budget (set `--time-budget=0`/tiny → attempts ≤ small number).
  - Orders newest-first (`last_seen_at` desc).
  - Does **no** UDP work for a `udp_only` version that has no live host (bulk resolution): assert the connection factory is never invoked for it.
  - A `cached` version is skipped.
- [ ] **Step 2 — run red.**
- [ ] **Step 3 — implement `MapArchiveFiller::run(int $limit, int $timeBudgetS, ?callable $log)`:**
  - Candidate ids: `MapVersion::needingAcquisition()` carrying a content key, newest-first, minus the per-map cooldown (`last_acquire_attempt_at` within `MAP_ACQUIRE_RETRY_HOURS`).
  - **Bulk resolution once per run:** one query → the set of version ids that have ≥1 live host (`server_histories` within the live window) whose `(server,map)` is not in backoff. Use it to decide HTTP-only vs full store per version, mirroring the old `isCurrentlyServed` but set-based.
  - For each candidate under the budget: `isCachedVersion?` skip; else `store->ensureVersion` (full store when it has a healthy live host, http-only store otherwise). Do **not** pre-stamp the cooldown — the listener stamps reactively. Swallow per-version throwables.
  - Reuse the env names: `MAP_FILL_TIME_BUDGET_S` (default 3600), `MAP_BACKFILL_LIVE_WINDOW_MIN`, `MAP_ACQUIRE_RETRY_HOURS`.
- [ ] **Step 4 — command** `maps:archive-fill`: thin wrapper calling the filler with the budget; print the summary.
- [ ] **Step 5 — run green; commit.**

---

## Task 7: Wire scheduling; retire `InlineMapAcquirer` + hourly backfill

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `app/Console/Commands/UpdateData.php` (drop the inline acquire run)
- Delete: `app/Service/InlineMapAcquirer.php`, `app/Console/Commands/ArchiveMapBackfill.php`,
  `app/Service/MapArchiveBackfiller.php` (folded into the filler) — and their tests, or repoint them.
- Test: `tests/Feature/ScheduleWiringTest.php` (or adjust existing scrape tests)

- [ ] **Step 1 — failing/again-green tests:** update `InlineMapAcquisitionTest` / `MapArchiveBackfillTest` —
  delete or rewrite against `MapArchiveFiller`. Add a small test that `data:update` no longer constructs
  `InlineMapAcquirer` (grep-level or behavioral: scraping a server records a `pending` version but does not
  download inline).
- [ ] **Step 2 — implement:**
  - `UpdateData`: remove the `InlineMapAcquirer` field, `consider()` calls, and the post-stats `run()`.
    Recording new versions as `pending` is already done by `MapVersionRecorder` (verify the default applies).
  - `bootstrap/app.php`: remove the `maps:archive-backfill --http-only` hourly schedule; add
    `->command('maps:archive-fill')->everyTenMinutes()->withoutOverlapping($lockMin)->runInBackground()->appendOutputTo(...)`,
    with `$lockMin` ≈ the budget in minutes so a crashed run self-heals.
  - Delete the retired classes + commands.
- [ ] **Step 3 — run green (full suite); commit.**

---

## Task 8: Visibility — `MapAvailability` + badges + dashboard breakdown

**Files:**
- Modify: `app/Service/MapAvailability.php`
- Modify: `app/Service/MapArchiveStats.php` (+ `resources/views/admin/dashboard.blade.php`)
- Modify: `resources/views` map list + detail badge partials as needed
- Test: `tests/Feature/MapAvailabilityStatusTest.php`, extend `tests/Feature/MapArchiveStatsTest.php`

- [ ] **Step 1 — failing tests:** `MapAvailability::forVersion` returns Cached / Queued / Server-only /
  Unavailable for the four statuses; `MapArchiveStats` exposes a `statusBreakdown()` count map.
- [ ] **Step 2 — run red.**
- [ ] **Step 3 — implement:** map `acquisition_status` → the badge enum/labels (keep the existing
  `Cached` path; `pending`→Queued, `udp_only`→Server-only, `unavailable`→Unavailable). Add
  `statusBreakdown()` (a `groupBy('acquisition_status')` count) and render a small card/table on `/admin`.
- [ ] **Step 4 — run green; commit.** No JS/TS changed, so no `npm` build; if any Blade-only change affects
  a response-cached page, note `php artisan responsecache:clear` for manual testing.

---

## Task 9: Final integration pass

- [ ] **Step 1:** `vendor/bin/phpunit` — full suite green.
- [ ] **Step 2:** `php artisan migrate` on the dev DB; smoke `php artisan maps:archive-fill --time-budget=20`
  and confirm it logs a summary and writes statuses (no fatal).
- [ ] **Step 3:** grep for stale references to `InlineMapAcquirer` / `maps:archive-backfill` /
  `MapArchiveBackfiller`; remove any leftovers.
- [ ] **Step 4:** update the memory file `project_twstats_admin_dashboard.md` with the new acquisition model.
- [ ] **Step 5:** final review against the spec; `superpowers:finishing-a-development-branch`.
