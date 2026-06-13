# Map archiving during the scrape — design

- **Date:** 2026-06-13
- **Status:** Approved (pending implementation)
- **Topic:** Proactively archive map files during `data:update`, plus a per-map archive-status
  badge on the map list.

## Problem

Today a map's `.map` file is only fetched lazily, on the first `/map/{name}/file` or
`/map/{name}/download` web request (`MapFileController` → `MapFileStore::ensure`). Maps that
nobody opens are never archived. We want to archive **as many maps as possible**, downloading
only maps we do not already have, and we want to see each map's archive state directly on the
website.

`MapFileStore::ensure(Map)` is already idempotent: it keys files by content identity
(`maps/<sha256|crc>.map` on the `local` disk), checks existence first, and only downloads on a
miss. So "only download what we don't have" is already the store's behaviour — the work is
driving it over the right set of maps within a safe time budget, and surfacing the state.

## Goals

- During each scrape, download `.map` files for maps we have an identity for but no cached file.
- Never re-download a file we already have.
- Keep the scrape window safe: the existing `data:update` is synchronous, runs every 10 min, and
  has **no `withoutOverlapping` guard**, so the archiving pass must be strictly bounded.
- Don't let maps that keep failing (crc-only with no reachable server, HTTP 404s, dead servers)
  monopolise each run's budget and starve maps never yet attempted.
- Show a small per-map archive-status badge on the map list (`/maps`) so progress is trackable
  from the UI.

## Non-goals

- No queue/worker infrastructure (none runs today).
- No separate scheduled command — the pass runs inline in `data:update`.
- No backfill UI / manual "archive now" button. (Could come later.)
- No filtering/sorting the map list by status (the table is plain server-paginated; display only).

## Decisions

These were settled during brainstorming:

1. **Execution model:** inline at the tail of `UpdateData::handle()` (after `closeStale()`).
2. **Sources:** HTTP **and** UDP, via the default `MapFileStore` source chain
   (`DdnetHttpMapSource` then `GameServerMapSource`) — bounded, because the UDP path can take up
   to ~20 s per map.
3. **Bounding:** **both** a wall-clock time budget **and** a per-run count cap; stop on whichever
   hits first.
4. **Retry policy:** track `last_archive_attempt_at` per map; pick never-attempted maps first and
   skip any attempted within a cooldown window. Stamp the time on every real attempt.
5. **Status badge:** four states — Downloaded / Scheduled / Retrying / Unavailable.

## Architecture

Two parts that share one definition of "eligible / cooling down" so the badge and the archiver
never disagree:

- **Part A — `App\Service\MapArchiver`** drives `MapFileStore` over the maps that need a file,
  within budget + cap, honouring the cooldown. Called inline by `UpdateData`.
- **Part B — the map-list badge** derives each map's state from the *same* `MapArchiver` logic
  (`statusFor`), so "Scheduled" on the list means exactly "the archiver would attempt this next".

The filesystem stays the single source of truth for "is it archived" (`MapFileStore::isCached`);
`last_archive_attempt_at` exists only to drive retry ordering/backoff, never to record success.

## Part A — the archiver

### Migration

Add one nullable column to `maps`:

```php
$table->timestamp('last_archive_attempt_at')->nullable()->after('crc');
```

No success/`archived_at` column — successful archival is proven by the file existing on disk.

### `MapFileStore::isCached(Map): bool`

Expose the store's existing key→path existence check so the archiver and the badge ask in one
place (and don't duplicate the path-traversal/hex-key guard):

```php
public function isCached(Map $map): bool
{
    $key = $map->getAttribute('sha256') ?? $map->getAttribute('crc');
    if ($key === null || !ctype_xdigit($key)) {
        return false;
    }
    return Storage::disk($this->disk)->exists('maps/' . $key . '.map');
}
```

`ensure()` should be refactored to reuse the same key/guard logic (extract a private
`cachedRelativePath(Map): ?string`) so there is exactly one definition of the path and its guard.

### `Map::scopeNeedsArchiving`

Candidate selection lives on the model (per the project convention of putting query helpers on
models). The cooldown threshold is passed in so the config stays in the service:

```php
public function scopeNeedsArchiving(Builder $query, Carbon $cooldownThreshold): Builder
{
    return $query
        ->where(fn ($q) => $q->whereNotNull('sha256')->orWhereNotNull('crc'))
        ->where(fn ($q) => $q->whereNull('last_archive_attempt_at')
                             ->orWhere('last_archive_attempt_at', '<', $cooldownThreshold))
        // never-attempted first (the IS NOT NULL prefix makes NULLs sort first on both
        // MySQL and SQLite), then oldest attempt first
        ->orderByRaw('last_archive_attempt_at IS NOT NULL, last_archive_attempt_at');
}
```

The query is **not** `LIMIT`ed — it is iterated lazily and the loop breaks on budget/cap, so maps
that are skipped for free (already on disk from a web hit) don't consume the candidate window.

### `MapArchiveStatus` enum

```php
enum MapArchiveStatus: string
{
    case Downloaded  = 'downloaded';   // file on disk
    case Scheduled   = 'scheduled';    // has identity, no file, eligible now / next run
    case Retrying    = 'retrying';     // has identity, no file, attempted within cooldown
    case Unavailable = 'unavailable';  // no sha256 and no crc — no source can ever serve it

    public function label(): string;        // "Downloaded" | "Scheduled" | "Retrying" | "Unavailable"
    public function badgeClass(): string;   // "bg-success" | "bg-info" | "bg-warning" | "bg-secondary"
}
```

### `MapArchiver`

```php
final class MapArchiver
{
    public function __construct(
        private readonly MapFileStore $store = new MapFileStore(),
    ) {}

    public function statusFor(Map $map): MapArchiveStatus
    {
        if ($this->store->isCached($map))   return MapArchiveStatus::Downloaded;
        if (!$map->hasDownloadableFile())   return MapArchiveStatus::Unavailable;
        return $this->isCoolingDown($map) ? MapArchiveStatus::Retrying : MapArchiveStatus::Scheduled;
    }

    public function archive(): MapArchiveReport
    {
        if (!env('MAP_ARCHIVE_ENABLED', true)) {
            return MapArchiveReport::disabled();
        }

        $maxPerRun  = (int) env('MAP_ARCHIVE_MAX_PER_RUN', 25);
        $deadline   = now()->addSeconds((int) env('MAP_ARCHIVE_BUDGET_SECONDS', 120));
        $cooldown   = now()->subHours($this->retryHours());

        $attempted = $stored = $failed = $skipped = 0;

        foreach (Map::needsArchiving($cooldown)->lazy() as $map) {
            if (now() >= $deadline || $attempted >= $maxPerRun) {
                break;
            }
            // already on disk (e.g. archived earlier via a /file web hit) — skip for free,
            // do not spend a cap/budget slot and do not re-stamp the attempt time
            if ($this->store->isCached($map)) {
                $skipped++;
                continue;
            }
            // stamp BEFORE the attempt, without bumping updated_at (toBase() = query builder,
            // which does not auto-touch timestamps), so a failure still parks the map for the
            // cooldown window
            Map::whereKey($map->getKey())->toBase()->update(['last_archive_attempt_at' => now()]);

            try {
                $this->store->ensure($map);
                $stored++;
            } catch (MapFileUnavailable) {
                $failed++;
            }
            $attempted++;
        }

        return new MapArchiveReport($attempted, $stored, $failed, $skipped);
    }

    private function retryHours(): int
    {
        return (int) env('MAP_ARCHIVE_RETRY_HOURS', 24);
    }

    private function isCoolingDown(Map $map): bool
    {
        $at = $map->getAttribute('last_archive_attempt_at');
        return $at !== null && $at >= now()->subHours($this->retryHours());
    }
}
```

`statusFor` and `isCoolingDown` share `retryHours()`, so the badge's "Scheduled vs Retrying"
boundary is exactly the archiver's "would I attempt this now" boundary.

### `MapArchiveReport`

A small readonly DTO of run counters for logging:

```php
final readonly class MapArchiveReport
{
    public function __construct(
        public int $attempted = 0,
        public int $stored = 0,
        public int $failed = 0,
        public int $skipped = 0,   // already-cached maps skipped for free
    ) {}

    public static function disabled(): self;        // all-zero, used when the kill switch is off
    public function summary(): string;              // e.g. "3 stored, 1 failed, 2 already cached (6 attempted)"
}
```

### `UpdateData` wiring

Add the archiver as a constructor dependency (matching the existing `new DdnetHttpSource()`
default-instantiation style) and call it once after `closeStale()`:

```php
$this->sessionRecorder->closeStale();

$report = $this->mapArchiver->archive();
$this->info('map archive: ' . $report->summary());
```

The command already streams output to `storage/logs/update_data/*.log`, so the summary is
captured per run.

## Part B — the map-list badge

### Controller

`MainController::maps` method-injects `MapArchiver` (its constructor deps all default, so the
container resolves it) and precomputes one status per paginated row:

```php
public function maps(MapArchiver $archiver)
{
    $maps = Map::orderBy('name')->paginate(50);
    $statuses = $maps->getCollection()->mapWithKeys(
        fn (Map $m) => [$m->id => $archiver->statusFor($m)]
    );
    return view('list.maps', compact('maps', 'statuses'));
}
```

At most 50 `Storage::exists` calls per render (one per row); `/maps` is **not** response-cached
(only `/serverbrowser` carries `CacheResponse`), so the badges are always live.

### View

`resources/views/list/maps.blade.php` — add a header cell and a badge cell, matching the existing
badge style in `resources/views/list/live.blade.php`:

```blade
<th>Archive</th>
...
<td>
    @php($status = $statuses[$mapEntry->id])
    <span class="badge {{ $status->badgeClass() }}">{{ $status->label() }}</span>
</td>
```

No SCSS/JS change (uses stock Bootstrap badge classes) → no `npm run build` and no responsecache
clear needed for this page.

## Configuration

Read via `env()` with defaults, matching the existing `env('CRONTASK_INTERVAL')` usage in
`UpdateData`:

| Variable | Default | Meaning |
| --- | --- | --- |
| `MAP_ARCHIVE_ENABLED` | `true` | Kill switch for the inline pass. |
| `MAP_ARCHIVE_BUDGET_SECONDS` | `120` | Wall-clock budget per run. |
| `MAP_ARCHIVE_MAX_PER_RUN` | `25` | Max download attempts per run. |
| `MAP_ARCHIVE_RETRY_HOURS` | `24` | Cooldown before re-attempting a map that has no file yet. |

## Data flow

1. `data:update` scrapes servers and upserts `maps` (identity columns `sha256`/`size`/`crc` via
   `updateMapIdentity`, unchanged).
2. After `closeStale()`, `MapArchiver::archive()` iterates `Map::needsArchiving(cooldown)` lazily,
   never-attempted first.
3. Per candidate: break on budget/cap; skip if already on disk; else stamp
   `last_archive_attempt_at` (no `updated_at` bump) and call `MapFileStore::ensure`, which tries
   HTTP then UDP and writes `maps/<key>.map` on success.
4. `GET /maps` renders each row's badge from `MapArchiver::statusFor`, reading the same on-disk
   existence + `last_archive_attempt_at` + cooldown.

## Error handling & edge cases

- **Budget granularity:** the deadline is checked *between* maps, so a single in-flight download
  (a UDP timeout, ≤ ~20 s) can overrun the budget by at most one map. Acceptable.
- **Failed download:** `MapFileStore::ensure` throws `MapFileUnavailable`; the map was already
  stamped, so it is parked for `MAP_ARCHIVE_RETRY_HOURS`. Counted as `failed`.
- **crc-only map, no live host:** `GameServerMapSource` returns null fast (no `server_histories`
  row) → `MapFileUnavailable` → cooldown. No 20 s cost.
- **Unavailable map (no identity):** excluded by `needsArchiving`; shown as `Unavailable` on the
  list.
- **File deleted off disk after success:** `last_archive_attempt_at` may still be set, but the
  existence check drives both the archiver (re-attempts after cooldown) and the badge (shows
  Scheduled/Retrying again) — the filesystem remains the source of truth, so it self-heals.
- **Kill switch off:** `archive()` returns `MapArchiveReport::disabled()` immediately; the badge
  still renders (Downloaded reflects existing files; uncached maps show Scheduled/Retrying).

## Testing

- **Unit — `MapArchiver::statusFor` (`Storage::fake('local')`):** returns Downloaded (file
  present), Unavailable (no identity), Retrying (no file, `last_archive_attempt_at` within
  cooldown), Scheduled (no file, null or beyond cooldown).
- **Feature — `MapArchiver::archive` (`Storage::fake` + scripted fake `MapSource`):**
  - stores uncached maps and stamps `last_archive_attempt_at`;
  - leaves `updated_at` unchanged when stamping;
  - skips a map still within cooldown;
  - skips an already-cached map without consuming a cap slot;
  - stops at `MAP_ARCHIVE_MAX_PER_RUN`;
  - budget guard: `MAP_ARCHIVE_BUDGET_SECONDS=0` makes zero attempts (deterministic, no sleeping);
  - a failing source leaves no file and counts as `failed`, leaving the map in cooldown.
- **Feature — `GET /maps`:** renders the correct badge for one map of each of the four states.

No test performs real HTTP or UDP — sources are faked and the disk is `Storage::fake('local')`.

## Files touched

**New**
- `app/Service/MapArchiver.php`
- `app/Service/MapArchiveStatus.php`
- `app/Service/MapArchiveReport.php`
- `database/migrations/XXXX_add_last_archive_attempt_at_to_maps_table.php`
- `tests/Unit/Service/MapArchiveStatusTest.php` (or covered within the archiver test)
- `tests/Feature/MapArchiverTest.php`
- `tests/Feature/MapListBadgeTest.php`

**Edited**
- `app/Service/MapFileStore.php` — add `isCached`, extract shared key/path helper
- `app/Console/Commands/UpdateData.php` — constructor dep + tail call + log line
- `app/Models/Map.php` — `scopeNeedsArchiving`
- `app/Http/Controllers/MainController.php` — `maps()` builds `$statuses`
- `resources/views/list/maps.blade.php` — Archive column + badge

## Behavioural note / trade-off

The first sweep drains gradually: ≤ `MAP_ARCHIVE_MAX_PER_RUN` maps and ≤
`MAP_ARCHIVE_BUDGET_SECONDS` per 10-minute run. A large backlog therefore takes many scrape
cycles to fully archive — the deliberate cost of keeping the scrape window safe. Operators can
raise the cap/budget envs to drain faster on a quiet instance.
