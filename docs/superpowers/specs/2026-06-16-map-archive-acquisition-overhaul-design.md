# Map Archive Acquisition Overhaul — Design

**Date:** 2026-06-16
**Status:** Approved design (pending implementation plan)
**Builds on:** `2026-06-15-map-version-tracking-design.md`, `2026-06-15-map-archive-admin-dashboard-design.md`, the live-window candidate gate (commit 09aecc4)

## Goal

Replace the current "probe-everything-every-time, bounded-per-run" acquisition strategy with a
**background filler that drains the whole archive unbounded, parallel to scrapes**, backed by a
**persisted per-version acquisition status** so every map's situation (queued / cached / server-only /
unavailable) is known and visible. Retire the hourly HTTP backfill.

## Motivation / problems with the current strategy

Acquisition today funnels through `MapFileStore` (source chain `[DdnetHttpMapSource, GameServerMapSource]`,
first non-null wins) via three triggers:

1. **Inline** (`InlineMapAcquirer`, synchronous in `data:update`) — bounded to `MAP_ACQUIRE_MAX_PER_RUN`
   (25) and `MAP_ACQUIRE_TIME_BUDGET_S` (60s).
2. **Hourly backfill** (`maps:archive-backfill --http-only`) — drains the historical backlog over HTTP
   only, never UDP.
3. **On-demand** (`/file`, `/download`) — full chain synchronously in the web request.

A single `last_acquire_attempt_at` + `MAP_ACQUIRE_RETRY_HOURS` (6h) throttles all retries uniformly.

Pain points:

- **No persisted classification.** A version is re-classified on every attempt. A custom DDNet-flavoured
  map (has a sha256 but 404s on maps.ddnet.org — fng/test maps) is re-probed over HTTP every 6h forever,
  and its UDP attempts are indistinguishable from "just hasn't downloaded yet."
- **Bounded inline pass throttles the initial fill.** Only 25 maps / 60s per scrape, so the existing
  tracked-but-uncached backlog drains slowly.
- **The 6h cooldown is too blunt.** A no-live-host attempt parks a version for 6h, during which the
  scrape *skips* it even when a server starts hosting it.
- **The hourly backfill is awkward.** Its real purpose was the one-time historical backlog; as a
  perpetual hourly HTTP-only job it is neither needed (once new maps are grabbed promptly) nor complete
  (it never does UDP).

## Decisions (locked)

- **Mirror "not there" verdict:** re-check after ~30 days (maps get published to the mirror when ranked).
- **UDP trigger / cooldown:** event-driven — only attempt UDP when a live host is serving the version; a
  "no live host" outcome does **not** start the 6h cooldown. Only real download failures cool down.
- **On-demand UDP:** keep the synchronous full chain in `/file` and `/download` (explicit user action).
- **Status granularity:** lean 4-state enum — `pending` / `cached` / `udp_only` / `unavailable`.
- **Fill bounds:** no per-run count cap; a generous configurable wall-clock budget (default 60 min) as a
  safety valve. `withoutOverlapping` resumes the drain on the next tick.

## Architecture

### A. Unified background filler

A single command **`maps:archive-fill`**, scheduled
`->everyTenMinutes()->withoutOverlapping($lockMinutes)->runInBackground()` — its own process, running
parallel to `data:update` (the project already uses this pattern for the current backfill; there is **no
queue worker** — `QUEUE_CONNECTION=database` with nothing draining it — so a scheduled background command,
not a queued job, is the correct mechanism).

The filler drains **all** versions needing acquisition, ordered **newest-first** (`last_seen_at` desc), so
freshly-seen maps are processed at the top of every pass. No count cap; stops at the wall-clock budget
(`MAP_FILL_TIME_BUDGET_S`, default 3600). `withoutOverlapping` lets one long run span multiple scrape
cycles without stacking; its lock expiry is set to match the budget so a crashed run self-heals.

This **replaces** both:

- `InlineMapAcquirer` (and its `MAP_ACQUIRE_MAX_PER_RUN` / `MAP_ACQUIRE_TIME_BUDGET_S` caps) — removed from
  `data:update`. The scrape's only archive responsibility becomes recording new versions (status
  `pending`), which `MapVersionRecorder` already does.
- `maps:archive-backfill --http-only` (hourly) — removed from the schedule.

`MapArchiveBackfiller` is evolved into `MapArchiveFiller` (it already has newest-first candidate listing,
the live-window check, and cooldown handling); the `--http-only` restriction and bounded caps are dropped,
status writes are added, and the time budget becomes the only run bound.

**"Done after a day":** the first run or two *classify* the entire backlog — HTTP-mirrored versions cache
quickly, the rest flip to `udp_only`/`unavailable`. Steady-state work then shrinks to new maps plus any
`udp_only` version that now has a live host. The dashboard's `pending` count is the explicit progress
signal.

### B. Persisted acquisition status

New columns on `map_versions`:

- `acquisition_status` — string/enum, default `pending`.
- `status_checked_at` — nullable timestamp; when the status was last resolved (drives the 30-day mirror
  recheck).

States and meaning:

| status | meaning | how reached |
|---|---|---|
| `pending` | recorded, not yet resolved ("in queue") | set on version creation |
| `cached` | `.map` file on disk | HTTP 200+verified, or UDP success, or found already on disk |
| `udp_only` | not on the mirror (404 or crc-only); a live host has served it within the live/recheck window | HTTP 404, or crc-only first touch, while a live host exists |
| `unavailable` | `udp_only` **and** no live host seen recently — currently unobtainable | resolved with no live host |

Notes:

- `mirrored` is intentionally **not** a resting state: a mirrored version caches almost immediately, so it
  collapses into `cached`. "DDNet-available" can be shown transiently for a `pending` version that carries
  a sha256 and is not known-missing (derived, not stored).
- `udp_only` ↔ `unavailable` flip based on live-host presence at resolution time; both mean "not on the
  mirror," differing only in whether a live host is currently reachable.
- The 30-day recheck: a `udp_only`/`unavailable` version whose `status_checked_at` is older than
  `MAP_MIRROR_RECHECK_DAYS` (default 30) is HTTP-probed again in case it has since been published; crc-only
  versions (no sha256) never HTTP-probe.

`MapFileStore` becomes the **single owner** of status resolution: it already orchestrates the source chain
and observes each outcome, so both the filler and the on-demand routes keep status correct. The store
exposes enough of the outcome (cached / mirror-404 / udp-success / udp-real-failure / no-live-host) to set
the right status and to decide whether to stamp the cooldown.

### C. Event-driven cooldown

`last_acquire_attempt_at` (6h, `MAP_ACQUIRE_RETRY_HOURS`) is stamped **only on a failed download attempt
from a reachable source** — an HTTP fetch that errored or failed verification, or a UDP transfer to a live
host that failed mid-stream. Its sole job is to avoid immediately re-hammering a flaky source.

It is explicitly **not** stamped on the two *classification* outcomes, which are governed by status instead:

- **HTTP 404** — a classification result, not a download failure. It sets `status=udp_only` +
  `status_checked_at=now`; its re-probe cadence is the 30-day recheck (`status_checked_at`), **not** the 6h
  cooldown. Stamping the cooldown here would hide the version from the filler for 6h and block the UDP
  retry when a host appears.
- **No live host** — nothing was attempted; sets `status=unavailable` and touches neither the cooldown nor
  the network.

So a `udp_only`/`unavailable` version stays a filler candidate every pass and is UDP-grabbed the instant a
live host appears, while a version whose *download* just failed waits 6h.

### D. On-demand routes

`/file` and `/download` keep the synchronous full chain via `MapFileStore`, now status-aware (skip HTTP
when `udp_only` and within the recheck window; update status on the outcome). No cooldown interaction (the
controller already does not touch `last_acquire_attempt_at`).

### E. Visibility

- `MapAvailability` gains a mapping from `acquisition_status` so the `/maps` list and map/version detail
  badges read **Cached / Queued / Server-only / Unavailable** instead of today's flat "Downloadable."
- The `/admin` dashboard gains a status breakdown (counts per `acquisition_status`) so the backlog drain
  is observable.

## Data flow (resolution per version)

```
resolve(version):
  on disk?                                   -> status=cached; return file
  needsHttpProbe = sha256 present AND
      (status == pending
       OR (status in {udp_only, unavailable} AND status_checked_at older than RECHECK_DAYS)):
    HTTP probe maps.ddnet.org
      200 + verified -> cache; status=cached; return
      404            -> status=udp_only; status_checked_at=now   (NO cooldown; fall through to UDP)
      transient/verify-fail -> stamp cooldown; return null        (retried after 6h)
  live host serving it? (resolved in BULK for the run, see below):
    yes -> UDP download
             success -> cache; status=cached; return
             failure -> status=udp_only; stamp cooldown; return null
    no  -> status=unavailable; status_checked_at=now; NO cooldown stamp; return null
```

**Bulk live-host resolution.** Because `unavailable` versions deliberately never stamp the cooldown, they
remain filler candidates every pass. To avoid a per-version "is a host serving this?" query across a large
`unavailable` backlog each run, the filler resolves live-host presence **once per run** — a single indexed
query over `server_histories` (within the live window) intersected with the uncached candidate set — and
only does UDP work for versions in that set. Everything else needing only classification (`pending` with a
sha256, or a 30-day-due recheck) is handled by the HTTP path; the rest is skipped cheaply.

## Components touched

- **Migration:** add `acquisition_status` (default `pending`) + `status_checked_at` to `map_versions`.
  Existing rows default to `pending`; the filler reconciles them (cached-on-disk → `cached`) on first
  touch — no heavy disk scan in the migration.
- **`app/Models/MapVersion.php`:** cast + helpers for the status; scope for "needs acquisition."
- **`app/Service/MapFileStore.php`:** owns status resolution + cooldown-stamp decision; returns/sets status
  per outcome.
- **`app/Service/DdnetHttpMapSource.php`:** honours the status/recheck gate (skip probe when known-missing
  and fresh); the 404 outcome feeds the store's `udp_only` transition.
- **`app/Service/GameServerMapSource.php`:** already live-gated (commit 09aecc4); surfaces "no live host"
  vs "real failure" so the store can pick `unavailable` vs `udp_only`.
- **`app/Service/MapArchiveFiller.php`:** evolved from `MapArchiveBackfiller` — unbounded count, time
  budget only, status writes, no `--http-only`.
- **`app/Console/Commands/`:** `maps:archive-fill` (new/renamed); remove the old backfill command or alias
  it.
- **`app/Console/Commands/UpdateData.php`:** drop the inline `InlineMapAcquirer` run.
- **`app/Service/InlineMapAcquirer.php`:** removed.
- **`bootstrap/app.php`:** remove the hourly backfill schedule; add the `maps:archive-fill` schedule.
- **`app/Service/MapAvailability.php`** + `/admin` dashboard + `/maps` badges: read `acquisition_status`.
- **Tests:** filler drain (unbounded + budget), status transitions (pending→cached / →udp_only /
  →unavailable), 30-day recheck, event-driven no-cooldown-on-no-host, on-demand status-awareness, badge
  mapping.

## Out of scope

- The reliable-but-slow per-chunk UDP transfer is unchanged (the reverted pipelining stays out).
- `fetch(Map)` legacy map-keyed path keeps probing (rare; acquisition is version-centric).
- No queue worker is introduced; the background work stays scheduler-driven.

## Risks

- **Unbounded HTTP probing** of a large pending backlog in one run could burst maps.ddnet.org. Mitigated by
  the wall-clock budget + `withoutOverlapping`; a small inter-probe politeness delay can be added if needed.
- **Two processes writing `map_versions`** (scrape + filler). They touch mostly disjoint rows/columns;
  status + `last_acquire_attempt_at` are written via query-builder updates (no full-model clobber), as the
  existing `markAttempted` already does.
- **Long-held `withoutOverlapping` lock** if a run crashes: lock expiry is set to the budget so it
  self-heals on the next tick.
