# Map version tracking + acquisition rework — design

Date: 2026-06-15
Status: approved design, pending implementation plan

## Goal

A single map **name** in Teeworlds is not unique: different servers host byte-different
files under the same name (e.g. `FlipLipp30` exists as several distinct `crc` versions). Today
twstats keeps **one `maps` row per name** with a **single** `sha256/crc/size`, populated
no-clobber from the first sighting — so every other version of that name is silently discarded.

This feature:

1. **Tracks** every distinct content version of a map name.
2. **Lists** them on the map detail page, each individually **viewable** (WebGL viewer) and
   **downloadable**.
3. **Reworks map-file acquisition** so the versions we list can actually be obtained: vanilla
   (UDP-only) maps are grabbed **inline during the scrape, from the host we just saw serving
   them** — the only reliable window — and the racy deferred archive queue is retired.

Play-time statistics stay **aggregated by name** (the `maps` row remains the stats/URL anchor).
Versions are a child collection, not a re-modeling of histories.

## Current state (relevant)

- `maps`: `id, name, sha256?, crc?, size?, last_archive_attempt_at?, timestamps`. `name` is the
  de-facto key (`Map::firstOrCreate(['name' => ...])`, `Map::where('name', ...)->firstOrFail()`).
- `UpdateData::updateMapIdentity` fills `sha256/crc/size` **no-clobber** (first sighting wins).
- `player_histories` / `server_histories` reference `map_id` (name-level).
- Acquisition: `MapFileStore::ensure(Map)` reads `map.sha256/crc`, tries `DdnetHttpMapSource`
  (HTTP, `maps.ddnet.org/<name>_<sha256>.map`) then `GameServerMapSource` (UDP, the single
  most-recently-seen host). Cache path: `storage/app/private/maps/<name>_<sha|crc>.map`.
- Proactive archiving (to be **retired**): `maps:archive-enqueue` (`EnqueueMapArchiveJobs`) →
  `ArchiveMap` job on the `maps` queue → `MapArchiver` → `MapFileStore::ensure`; scheduled in
  `bootstrap/app.php` alongside `queue:work --queue=maps`. `/maps` shows a per-map status badge
  via `MapArchiver::statusFor` (`MapArchiveStatus`).
- The connection handshake fix (security-token suffix on the initial 0.6 CONNECT) is **already
  merged** in this branch and makes UDP downloads work against strict vanilla/Gores hosts —
  which is what makes reliable inline acquisition viable.

## Acquisition asymmetry (the key insight driving the rework)

| Map type | Reliable acquisition window | Strategy |
|---|---|---|
| **HTTP** (DDNet, has `sha256`, mirrored on maps.ddnet.org) | anytime | **on-demand / lazy** |
| **UDP-only** (vanilla 0.6/0.7, `crc`, no HTTP mirror) | **only while a host is live on it** | **grab inline during the scrape** from the host we just saw |

The deferred queue is racy precisely for UDP-only maps (the host has switched maps by the time
the job runs) and pointless for HTTP maps (HTTP works anytime). So acquisition moves to where the
information is: the scrape.

### Update (2026-06-15): archive *every* version inline, not just UDP-only

The "leave HTTP maps for on-demand" half of the table above proved too coarse. A server-reported
`sha256` does **not** mean the map is mirrored on maps.ddnet.org: custom maps on DDNet-flavoured
servers (e.g. `fng` maps like `AliveFNG`) advertise a content hash yet **404** on the HTTP mirror.
Such versions were skipped by inline acquisition (gated on `sha256 === null`) *and* unservable over
HTTP, so they were never cached — the detail-page viewer fell back to a confusing "Map file not
available". There is no cheap way to pre-classify "has a hash but isn't mirrored", so
`InlineMapAcquirer::consider()` now drops the `sha256` gate and archives **every uncached, not-cooling-down
version** (HTTP first, UDP fallback), building a complete local mirror. The bounded run
(`MAP_ACQUIRE_MAX_PER_RUN` / `MAP_ACQUIRE_TIME_BUDGET_S`) still keeps the scrape from waiting on
downloads, and the per-version cooldown still parks stubborn hosts (e.g. datacenter IPs that filter
CONNECT). On-demand acquisition on the file routes is unchanged. The availability badge
(`MapAvailability`) is now shown on the **map detail page** as well as the `/maps` list.

## Design

### 1. Data model

New table **`map_versions`** (source of truth for distinct files):

| column | notes |
|---|---|
| `id` | surrogate PK |
| `map_id` | FK → `maps`, indexed |
| `sha256` | char(64), nullable (DDNet identity) |
| `crc` | string(8), nullable (0.6/0.7 identity) |
| `size` | unsigned int, nullable |
| `first_seen_at`, `last_seen_at` | datetimes; drive the list ordering + "current" |
| `last_acquire_attempt_at` | nullable; light cooldown for failed inline grabs |
| timestamps | |

Indexes: `(map_id, sha256)`, `(map_id, crc)` for match-by-either upserts and OR-match lookups.

`server_histories` gains **`map_version_id`** (nullable FK → `map_versions`): which version a host
was serving at that sighting. Enables version-targeted multi-host fallback and the per-version
"N servers" count. Stats still group by `map_id`; this column does not split stats.

`maps` **keeps its existing `sha256/crc/size` with the existing no-clobber rule** — a best-effort
"is there any downloadable identity" cache for the legacy readers (`hasDownloadableFile`, the
`/maps` badge, `MapFileStore::ensure(Map)`). It is intentionally *not* repointed to the current
version: the project's no-clobber identity rule (mirroring the afk/skin rule) is deliberate and
encoded in existing tests, and nulling a known `sha256` because the newest sighting was crc-only
would regress the HTTP-download path. The **current version is the `currentVersion()` relation**
(newest `map_versions` row by `last_seen_at`); Phase 3's default (no-version) route/viewer resolves
to it. `last_archive_attempt_at` is **removed** from `maps` (moves to
`map_versions.last_acquire_attempt_at`). (Alternative considered: a `current_version_id` FK — more
churn; rejected for this lightweight change.)

### 2. Scraper: version upsert + identity normalization

In `UpdateData`, alongside the existing per-sighting logic, delegate to a small service that
upserts a `map_versions` row whenever the sighting carries an identity (`sha256` or `crc`):

- **Match by either key** within the map: by `sha256` if the sighting has one, else by `crc`.
- **Backfill the missing key** on a matched row (e.g. a `crc`-only row gains `sha256` when a
  later both-carrying sighting or a download reveals it). `ServerMerger` already unifies a server
  listed on both masters, so such sightings exist.
- Matched → bump `last_seen_at`; unmatched → create (`first_seen_at = last_seen_at = now`).
- Stamp `server_histories.map_version_id` with the resolved version.
- `maps.sha256/crc/size` keep their **existing no-clobber** behavior (a per-field best-effort
  identity cache for legacy readers). Each distinct identity also persists as a `map_versions` row;
  "current version" is read via the `currentVersion()` relation, not from `maps.*`.
- Keyless sightings (no `sha256`/`crc`) create no version row, exactly as today.

**Merge pass** (normalization): when a version gains a second identity it didn't have (always the
case after a download, which computes **both** `sha256` and `crc`), collapse any other
`map_versions` row of the same map sharing either key into it: `first_seen_at = min`,
`last_seen_at = max`, re-point `server_histories.map_version_id`, delete the dup.

**Residual we accept (documented, not engineered around):** a byte-identical file seen *only* via
DDNet (`sha256`) on one host and *only* via 0.6 (`crc`) on another, never together and never
downloaded, shows as two rows until the first download merges them. Rare (cross-ecosystem
identical map) and self-healing.

### 3. Inline acquisition during the scrape (replaces the deferred queue)

`UpdateData` runs acquisition **after** stats are committed, so stats never wait on a download:

1. During the server loop, collect candidates: `(host, version)` where the version is **UDP-only**
   (no `sha256`), **not cached** (`MapFileStore::isCached`), and not attempted within the cooldown.
2. After histories are written, run a **bounded, best-effort** acquisition pass over the
   candidates: connect to the **exact host** seen serving it (guaranteed identity match — no
   multi-host needed here), download, verify, cache, backfill both keys onto the version (→ merge).
   Each download time-boxed and non-fatal; stamp `last_acquire_attempt_at`.
3. **Bound per run**: a count cap and/or wall-clock budget (config: `MAP_ACQUIRE_MAX_PER_RUN`,
   `MAP_ACQUIRE_TIME_BUDGET_S`). Skipped candidates are retried next scrape (the host is usually
   still up). Keeps `data:update` inside its 10-minute window.

**HTTP maps are not downloaded during the scrape** — fetched **on demand** (and then cached) when
first viewed/downloaded, since maps.ddnet.org is always available.

### 4. Per-version download + viewer (version-aware routes)

- `GET /map/{name}/file/{version?}` and `GET /map/{name}/download/{version?}`. `{version}` = the
  content key (`sha256` or `crc`, hex); omitted ⇒ current version (back-compatible URLs).
- `MapFileController` resolves the `MapVersion` by **`sha256 = key OR crc = key`** scoped to the
  map (so links survive backfill/merge — a merged row keeps both keys), and hands its identity to
  a generalized **`MapFileStore::ensure(MapVersion)`** (cache already keyed by name + sha/crc, so
  naturally per-version). `backfillIdentity` writes onto the version and triggers the merge pass.
- **On-demand acquisition fallback**: ensuring a non-cached version tries HTTP (if `sha256`), else
  **version-targeted multi-host UDP** — walk the hosts from `server_histories` for *that
  `map_version_id`*, newest-first, across protocols, until one verifies. If none currently serves
  it, the viewer shows the graceful "Map file not available" already built. (This is the only place
  multi-host retry is needed; the common case — current version, grabbed inline — is already
  cached.)

### 5. Map detail UI

A **"Versions" panel**, shown only when ≥2 versions exist (single-version maps look unchanged):

```
Versions (3)
 ● 3421b577   335 KB   seen Jun 14   4 servers   [View] [Download]   ← current
   d6909b17   281 KB   seen Jun 13   6 servers   [View] [Download]
   8a901f2c   402 KB   seen May 02   1 server    [View] [Download]
```

Ordered by `last_seen_at` desc; current marked. "View" re-inits the existing WebGL viewer against
`/map/{name}/file/{key}`; "Download" → `/map/{name}/download/{key}`. Server count from
`server_histories` grouped by `map_version_id`.

### 6. Removed / repurposed components

- **Remove**: `app/Jobs/ArchiveMap.php`, `app/Service/MapArchiver.php`,
  `app/Console/Commands/EnqueueMapArchiveJobs.php`, the `maps:archive-enqueue` and
  `queue:work --queue=maps` scheduler entries in `bootstrap/app.php`.
- **Repurpose**: `MapArchiveStatus` → a "map availability" view-model (cached? downloadable?
  version count) for the `/maps` badge; `MainController::maps` updated accordingly.
- **Generalize**: `MapFileStore::ensure` to take a `MapVersion`; backfill writes to the version;
  add the merge pass. `GameServerMapSource` rewritten to version-targeted multi-host for the
  on-demand fallback, and to download from a **given host** for inline acquisition.

### 7. Migration & backfill

- Create `map_versions`; add `server_histories.map_version_id`.
- Backfill one `map_versions` row per existing `maps` row that has an identity (`sha256` or `crc`),
  from its current `sha256/crc/size`, `first_seen_at = last_seen_at = maps.updated_at`, carry over
  `last_archive_attempt_at` → `last_acquire_attempt_at`.
- Backfill `server_histories.map_version_id`: for maps that backfill to exactly one version, set it
  to that version; otherwise leave null (old rows don't record which version they served).
- Drop `maps.last_archive_attempt_at`.
- Existing cached `.map` files already match the version key (`<name>_<sha|crc>.map`) — still valid.

## Testing

- **Scraper version upsert**: new version; `last_seen_at` bump on re-sight; match-by-`sha256` and
  match-by-`crc`; partial-identity backfill; `server_histories.map_version_id` stamped.
- **Merge pass**: a `crc`-only and a `sha256`-only row of one map collapse when a download supplies
  both; `first/last_seen` combined, `server_histories` re-pointed.
- **Inline acquisition**: a candidate UDP-only uncached version is grabbed from the seen host
  (scripted `FakeUdpTransport`/connection); the per-run bound caps work; failures are non-fatal and
  set `last_acquire_attempt_at`; HTTP maps are *not* grabbed inline.
- **Per-version routes**: `MapFileController` serves a specific cached version; OR-match resolves a
  version by either key; graceful 404 for an unacquirable version; `{version?}` omitted ⇒ current.
- **On-demand multi-host fallback**: tries hosts for the version's `map_version_id` until one
  verifies; gives up gracefully when none serves it.
- **Map model**: `versions()` relation, `currentVersion`, server-count-per-version.
- **Migration backfill**: one version per identitied map; `server_histories` linkage; column moves.
- **UI**: versions panel renders for ≥2 versions, hidden for one; view/download links correct.

## Phasing (for the implementation plan)

1. **Data model + scraper**: `map_versions`, `server_histories.map_version_id`, migration/backfill,
   version upsert + normalization/merge in `UpdateData`. (`maps.*` becomes current-version pointer.)
2. **Acquisition rework**: generalize `MapFileStore::ensure(MapVersion)`; inline bounded UDP grab in
   `UpdateData`; HTTP on-demand; rewrite `GameServerMapSource` (given-host + version-targeted
   multi-host); **retire** the queue/jobs/command/scheduler entries; repurpose `MapArchiveStatus`.
3. **Routes + per-version download/view**: version-aware `MapFileController` + routes + on-demand
   fallback.
4. **UI**: versions panel on the map detail page; viewer re-init per version.

## Known limitations / non-goals

- Stats are **not** split per version (deliberate — "aggregate stats, list versions").
- Cross-ecosystem byte-identical maps may briefly show two rows until first download (see §2).
- Non-current UDP-only versions are **best-effort** on demand: if no host currently serves a given
  old version and it was never grabbed inline, it stays unavailable (graceful message). This is
  inherent to the protocol, not a regression.
- The version-targeted multi-host fallback only has host data from **post-migration** sightings
  (old `server_histories` rows have a null `map_version_id`), so for versions that predate this
  feature the fallback may find no candidate hosts. New sightings stamp the link going forward.
- Proactively archiving *every* version (vs. grab-when-seen) is out of scope; inline acquisition
  already captures each new version as it appears.
