# Map archiving via a queue — design

- **Date:** 2026-06-13
- **Status:** Approved (pending implementation)
- **Topic:** Archive map files asynchronously on a queue, decoupled from the `data:update`
  scrape, plus a per-map archive-status badge on the map list.

## Problem

Today a map's `.map` file is only fetched lazily, on the first `/map/{name}/file` or
`/map/{name}/download` web request (`MapFileController` → `MapFileStore::ensure`). Maps that
nobody opens are never archived. We want to archive **as many maps as possible**, downloading
only maps we do not already have, **without** coupling that work to the stats scrape, and we want
to see each map's archive state directly on the website.

`MapFileStore::ensure(Map)` is already idempotent: it keys files by content identity
(`maps/<sha256|crc>.map` on the `local` disk), checks existence first, and only downloads on a
miss. So "only download what we don't have" is already the store's behaviour — the work is
driving it over the right set of maps, off the scrape thread, and surfacing the state.

## Goals

- Archive `.map` files for maps we have an identity for (`sha256` or `crc`) but no cached file.
- Never re-download a file we already have.
- Keep archiving **fully separate** from `data:update`: the scrape must not download maps or carry
  any archiving concern.
- Survive a current environment with **no Redis and no persistent worker/supervisor** — everything
  runs off Laravel's scheduler.
- Don't let maps that keep failing (crc-only with no reachable server, HTTP 404s, dead servers)
  churn endlessly or starve maps never yet attempted.
- Show a small per-map archive-status badge on the map list (`/maps`) so progress is trackable
  from the UI.

## Non-goals

- No Redis / Horizon / persistent daemon (none today; can be adopted later without redesign).
- No manual "archive now" button or backfill UI.
- No filtering/sorting the map list by status (plain server-paginated table; display only).
- No changes to `data:update` / the scrape pipeline.

## Decisions

Settled during brainstorming:

1. **Decoupling mechanism:** a **queue** (`ArchiveMap` jobs), not an inline pass.
2. **Queue driver:** `database` (jobs in MySQL). Adds `jobs` + `failed_jobs` tables; flips
   `QUEUE_CONNECTION` to `database`. No Redis/predis.
3. **Worker runtime:** **scheduled drain bursts** — the scheduler runs
   `queue:work --queue=maps --stop-when-empty` periodically; no persistent daemon. Upgradeable to
   a supervised daemon later with no code change.
4. **Enqueue trigger:** a **dedicated scheduled command** `maps:archive-enqueue`, fully separate
   from `data:update`.
5. **Retry policy:** two layers — the job's own `$tries`/`backoff` for transient blips, and a
   per-map `last_archive_attempt_at` **cooldown** that gates re-enqueue so persistent failures
   don't churn.
6. **Status badge:** four states — Downloaded / Queued / Retrying / Unavailable.

## Architecture

```
schedule (cron)                          queue (MySQL `jobs` table)        worker burst
─────────────────                        ──────────────────────────       ─────────────
maps:archive-enqueue  ──selects──▶  dispatch ArchiveMap(map) ──┐
  every 10 min          uncached    (ShouldBeUnique: 1/ map)   │
  (cooldown-filtered)   maps                                   ▼
                                              jobs table  ◀── queue:work --queue=maps
                                                               --stop-when-empty
                                                               every 5 min, withoutOverlapping
                                                                   │ handle()
                                                                   ▼
                                              MapArchiver::download(map)
                                                = stamp last_archive_attempt_at
                                                + MapFileStore::ensure(map)  (HTTP then UDP)
```

`App\Service\MapArchiver` is the single brain shared by the job, the enqueue command, and the
badge, so all three agree on "eligible / cooling down / archived". The filesystem
(`MapFileStore::isCached`) stays the source of truth for "is it archived";
`last_archive_attempt_at` exists only to drive retry/backoff and the badge — never to record
success.

## Infrastructure changes

- **`.env` / `.env.example`:** `QUEUE_CONNECTION=database` (currently `sync`).
- **Migrations:** add the framework's `jobs` and `failed_jobs` tables (generated via the standard
  queue table generators). `config/queue.php` already defines the `database` connection and a
  `failed` store on `failed_jobs` (line 123) — no config edit needed.
- **Migration:** `last_archive_attempt_at` timestamp on `maps` (see below).
- **No batches table** (no job batching used).

## Schema

```php
// maps
$table->timestamp('last_archive_attempt_at')->nullable()->after('crc');
```

No success/`archived_at` column — successful archival is proven by the file existing on disk.

## Components

### `MapFileStore::isCached(Map): bool`

Expose the store's existing key→path existence check so the archiver, the job and the badge ask in
one place (and don't duplicate the path-traversal/hex-key guard):

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

`ensure()` is refactored to reuse the same key/guard logic (extract a private
`cachedRelativePath(Map): ?string`) so there is exactly one definition of the path and its guard.

### `Map::scopeNeedsArchiving`

Candidate selection lives on the model (project convention: query helpers on models). The cooldown
threshold is passed in so config stays in the service:

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

This is a SQL-only filter (it cannot check the filesystem); the enqueue command additionally skips
maps already on disk via `isCached` so no no-op jobs are dispatched.

### `MapArchiveStatus` enum

```php
enum MapArchiveStatus: string
{
    case Downloaded  = 'downloaded';   // bg-success   — file on disk
    case Queued      = 'queued';       // bg-info      — has identity, no file, eligible (queued / will be)
    case Retrying    = 'retrying';     // bg-warning   — has identity, no file, attempted within cooldown
    case Unavailable = 'unavailable';  // bg-secondary — no sha256 and no crc

    public function label(): string;        // "Downloaded" | "Queued" | "Retrying" | "Unavailable"
    public function badgeClass(): string;   // "bg-success" | "bg-info" | "bg-warning" | "bg-secondary"
}
```

The badge derives from `isCached` + identity + cooldown — **not** from the `jobs` table — so
rendering `/maps` stays cheap (no per-row queue lookups).

### `MapArchiver` (shared brain)

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
        return $this->isCoolingDown($map) ? MapArchiveStatus::Retrying : MapArchiveStatus::Queued;
    }

    public function cooldownThreshold(): Carbon
    {
        return now()->subHours($this->retryHours());
    }

    /** Stamp the attempt then download; throws MapFileUnavailable on failure (the job retries). */
    public function download(Map $map): void
    {
        $this->markAttempted($map);
        $this->store->ensure($map);
    }

    private function markAttempted(Map $map): void
    {
        // stamp without bumping updated_at: toBase() returns the query builder, which does not
        // auto-touch timestamps. A failure still parks the map for the cooldown window.
        Map::whereKey($map->getKey())->toBase()->update(['last_archive_attempt_at' => now()]);
    }

    private function isCoolingDown(Map $map): bool
    {
        $at = $map->getAttribute('last_archive_attempt_at');
        return $at !== null && $at >= $this->cooldownThreshold();
    }

    private function retryHours(): int
    {
        return (int) env('MAP_ARCHIVE_RETRY_HOURS', 24);
    }
}
```

`statusFor`'s Queued/Retrying boundary and the enqueue command's `needsArchiving(cooldownThreshold)`
filter both derive from `cooldownThreshold()`, so they partition the same set: a map is **Queued**
iff the enqueuer would select it, **Retrying** iff it's filtered out for cooldown.

### `App\Jobs\ArchiveMap`

```php
final class ArchiveMap implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $uniqueFor = 3600;   // lock TTL safety: auto-release if a worker dies mid-job

    public function __construct(public readonly Map $map)
    {
        $this->onQueue('maps');
    }

    public function uniqueId(): string
    {
        return (string) $this->map->getKey();
    }

    public function backoff(): array
    {
        return [60, 300, 900];   // transient HTTP/UDP blips
    }

    public function handle(MapArchiver $archiver): void
    {
        $archiver->download($this->map);   // throws MapFileUnavailable -> retry, then failed_jobs
    }
}
```

- **Dedup:** `ShouldBeUnique` keyed by map id (cache lock; the `file` cache store supports atomic
  locks) prevents a second pending job for the same map while one is queued/running. After it
  finishes, the `last_archive_attempt_at` cooldown prevents re-enqueue for `MAP_ARCHIVE_RETRY_HOURS`.
- **Two-layer retry:** `$tries`/`backoff` retry transient failures within the lock lifetime; a map
  with no usable source exhausts `$tries` quickly and lands in `failed_jobs`, then the cooldown
  gates the next attempt a day later.
- **Model serialization:** `Queueable`/`SerializesModels` stores only the map id and re-fetches a
  fresh `Map` on `handle`.

### `App\Console\Commands\EnqueueMapArchiveJobs` (`maps:archive-enqueue`)

```php
public function handle(MapArchiver $archiver): int
{
    if (!env('MAP_ARCHIVE_ENABLED', true)) {
        return self::SUCCESS;
    }

    $limit     = (int) env('MAP_ARCHIVE_ENQUEUE_LIMIT', 200);
    $threshold = $archiver->cooldownThreshold();
    $dispatched = $skipped = 0;

    foreach (Map::needsArchiving($threshold)->lazy() as $map) {
        if ($dispatched >= $limit) {
            break;
        }
        if ($archiver->statusFor($map) === MapArchiveStatus::Downloaded) {
            $skipped++;             // already on disk (e.g. via a web /file hit) — no job
            continue;
        }
        ArchiveMap::dispatch($map); // ShouldBeUnique drops it if one is already pending
        $dispatched++;
    }

    $this->info("map archive enqueue: {$dispatched} dispatched, {$skipped} already cached");
    return self::SUCCESS;
}
```

`MAP_ARCHIVE_ENQUEUE_LIMIT` bounds how many jobs the first sweep floods into the `jobs` table per
run, keeping the table and each worker burst manageable.

### Schedule (`bootstrap/app.php`)

`data:update` is left untouched. Add:

```php
$schedule->command('maps:archive-enqueue')->everyTenMinutes()->withoutOverlapping();

$schedule->command('queue:work --queue=maps --stop-when-empty --max-time=240')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->before(fn () => File::ensureDirectoryExists(storage_path('logs/maps_worker')))
    ->sendOutputTo(storage_path('logs/maps_worker/'.now()->timestamp.'.log'));

$schedule->command('queue:prune-failed --hours=168')->weekly();
```

- `--stop-when-empty` exits when the queue drains; `--max-time=240` caps a burst below the 5-min
  cadence; `withoutOverlapping()` stops a slow burst colliding with the next. The job's `$tries`
  governs retries (no `--tries` needed on the worker).
- `queue:prune-failed` keeps `failed_jobs` from growing unbounded as permanently-unreachable maps
  fail once per cooldown cycle.

## Part B — the map-list badge

### Controller

`MainController::maps` method-injects `MapArchiver` and precomputes one status per paginated row:

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

At most 50 `Storage::exists` calls per render; `/maps` is **not** response-cached (only
`/serverbrowser` carries `CacheResponse`), so badges are always live.

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

No SCSS/JS change (stock Bootstrap badge classes) → no `npm run build`, no responsecache concern.

## Configuration

Read via `env()` with defaults, matching the existing `env('CRONTASK_INTERVAL')` usage:

| Variable | Default | Meaning |
| --- | --- | --- |
| `QUEUE_CONNECTION` | `database` | Async driver backing the jobs (was `sync`). |
| `MAP_ARCHIVE_ENABLED` | `true` | Kill switch for the enqueue command. |
| `MAP_ARCHIVE_RETRY_HOURS` | `24` | Cooldown before re-enqueuing a map with no file yet. |
| `MAP_ARCHIVE_ENQUEUE_LIMIT` | `200` | Max jobs dispatched per enqueue run. |

Worker burst params (`--max-time`, cadence) live in the schedule definition.

## Data flow

1. `data:update` runs unchanged (scrape + identity upsert; no archiving).
2. `maps:archive-enqueue` (every 10 min) selects `Map::needsArchiving(cooldownThreshold)` lazily,
   skips already-cached maps, and dispatches `ArchiveMap` (unique per map id) onto the `maps` queue
   up to the enqueue limit.
3. The scheduled `queue:work` burst (every 5 min) drains the `maps` queue: each job calls
   `MapArchiver::download` = stamp `last_archive_attempt_at` (no `updated_at` bump) + `ensure`
   (HTTP then UDP), writing `maps/<key>.map` on success; on failure it retries per `backoff`, then
   lands in `failed_jobs`.
4. `GET /maps` renders each row's badge from `MapArchiver::statusFor`, reading on-disk existence +
   `last_archive_attempt_at` + cooldown.

## Error handling & edge cases

- **Failed download:** `ensure` throws `MapFileUnavailable`; the map was already stamped, so it is
  parked for `MAP_ARCHIVE_RETRY_HOURS`. After `$tries`, the job lands in `failed_jobs`.
- **crc-only map, no live host:** `GameServerMapSource` returns null fast → `MapFileUnavailable` →
  cooldown. No 20 s cost.
- **Duplicate enqueue:** `ShouldBeUnique` drops a second dispatch while one is pending; the cooldown
  prevents re-enqueue after completion.
- **Worker dies mid-job:** `uniqueFor = 3600` auto-releases the unique lock after an hour so the
  map isn't permanently blocked from re-enqueue.
- **Unavailable map (no identity):** excluded by `needsArchiving`; shown as `Unavailable`.
- **File deleted off disk after success:** the existence check drives both the badge and (after
  cooldown) re-enqueue — the filesystem stays the source of truth, so it self-heals.
- **Burst overrun:** `--max-time=240` + `withoutOverlapping()` keep a burst inside its 5-min window;
  unfinished work resumes next burst.
- **Kill switch off:** `maps:archive-enqueue` returns early (no dispatch); the badge still renders
  (Downloaded reflects existing files; uncached maps show Queued/Retrying).

## Testing

- **Unit — `MapArchiver::statusFor` (`Storage::fake('local')`):** Downloaded (file present),
  Unavailable (no identity), Retrying (no file, attempt within cooldown), Queued (no file, null or
  beyond cooldown).
- **Feature — `ArchiveMap` job (`Storage::fake` + scripted fake `MapSource` via a `MapArchiver`
  with a faked store):** handling stores the file and stamps `last_archive_attempt_at` without
  bumping `updated_at`; a failing source throws `MapFileUnavailable` and leaves the map in cooldown;
  `uniqueId()` equals the map id.
- **Feature — `maps:archive-enqueue` (`Queue::fake()`):** dispatches `ArchiveMap` for eligible maps,
  not for cooldown / already-cached / unavailable maps, and honours `MAP_ARCHIVE_ENQUEUE_LIMIT`.
  (Job uniqueness itself is framework behaviour; selection logic is what we assert.)
- **Feature — `GET /maps`:** renders the correct badge for one map of each of the four states.

No test performs real HTTP or UDP — sources are faked and the disk is `Storage::fake('local')`.
`phpunit.xml` already keeps `QUEUE_CONNECTION` at its test default; `Queue::fake()` / direct
`handle()` calls keep job tests off the real database queue.

## Files touched

**New**
- `app/Jobs/ArchiveMap.php`
- `app/Console/Commands/EnqueueMapArchiveJobs.php`
- `app/Service/MapArchiver.php`
- `app/Service/MapArchiveStatus.php`
- `database/migrations/XXXX_create_jobs_table.php`
- `database/migrations/XXXX_create_failed_jobs_table.php`
- `database/migrations/XXXX_add_last_archive_attempt_at_to_maps_table.php`
- `tests/Unit/Service/MapArchiveStatusTest.php`
- `tests/Feature/ArchiveMapJobTest.php`
- `tests/Feature/EnqueueMapArchiveJobsTest.php`
- `tests/Feature/MapListBadgeTest.php`

**Edited**
- `app/Service/MapFileStore.php` — add `isCached`, extract shared key/path helper
- `app/Models/Map.php` — `scopeNeedsArchiving`
- `app/Http/Controllers/MainController.php` — `maps()` builds `$statuses`
- `resources/views/list/maps.blade.php` — Archive column + badge
- `bootstrap/app.php` — schedule the enqueue command, the worker burst, and `queue:prune-failed`
- `.env` / `.env.example` — `QUEUE_CONNECTION=database`

**Unchanged**
- `app/Console/Commands/UpdateData.php` — the scrape no longer has (and never gains) an archiving
  concern.

## Behavioural note / trade-off

Archiving is now best-effort and latency-tolerant: the enqueue command and the worker each run on
the scheduler, so a freshly-seen map is archived within roughly one enqueue + one worker cycle (up
to ~15 min), and a large initial backlog drains over many cycles bounded by
`MAP_ARCHIVE_ENQUEUE_LIMIT` and the worker's `--max-time`. This latency is the deliberate cost of
keeping archiving fully off the scrape thread with no persistent worker. Operators can raise the
enqueue limit / worker `--max-time`, or later promote the scheduled burst to a supervised
`queue:work` daemon, to drain faster — with no code change.
