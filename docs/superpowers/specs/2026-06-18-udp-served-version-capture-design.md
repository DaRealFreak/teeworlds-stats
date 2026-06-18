# UDP served-version capture & phantom throttle

**Date:** 2026-06-18
**Status:** Approved (design)
**Component:** `app/Service/GameServerMapSource.php` + map-acquisition pipeline

## 1. Problem & evidence

The map-archive acquisition pipeline discards a large class of perfectly good map
downloads. Two telemetry buckets dominate the failures:

- `name_mismatch` (~3894) — **already fixed** separately (the name gate was
  case-sensitive; `savannux` vs wire `Savannux`). See the `nameMatches()`
  case-insensitive helper. This spec does **not** revisit that.
- `crc_mismatch` (~2464) — the focus here. The session downloads the **full,
  self-consistent map** (bytes verified against the server-announced crc + size),
  then throws on the *caller-expectation* crc check, so the verified bytes of a
  real map are thrown away inside the exception.

Two live-confirmed shapes produce `crc_mismatch`:

1. **Same name, new content version.** `ICTFX` (`202.61.252.74:8305`) is recorded
   from the DDNet feed as `ctf4_old` / crc `64bbdea4`, but the in-game download
   serves `ctf4_old` / crc `7630078e` — a genuinely different content version of
   the same map.
2. **Different map entirely (city server).** `MMOTee` (`192.124.189.112:8310`)
   reports **three different names across three channels**:

   | Channel | Map name |
   |---|---|
   | DDNet HTTP master feed (what we store & try to acquire) | `Multiworld` |
   | Live connless 0.6 browser query (`gie3`) | `main` |
   | In-game `NETMSG_MAP_CHANGE` (what the download serves) | `Gridania` |

   The in-game served map is **stable** (`Gridania` across repeated connects).

Because discovery only ever sees the feed/connless identity, the actually-served
version is never recorded. `MapArchiveFiller` keeps re-targeting the phantom feed
version every pass (a fresh `server_histories` row keeps it in the live-host set),
re-downloading and re-rejecting it forever.

### Verified protocol facts

- **A mid-download map change cannot corrupt the archive.** `ddnet`
  `src/engine/server/server.cpp` (~3273–3286): on a map reload the server, for
  every connected client (incl. one mid-download), sends a fresh `MAP_CHANGE`,
  `Reset()`s the client, and drops it to `STATE_CONNECTING`. Our session ignores
  the second `MAP_CHANGE` and keeps requesting the old map's chunk indices, so the
  accumulated bytes fail the final **crc + size self-consistency check** →
  `MapDownloadFailed`. A clean failure, never silently-wrong bytes.
- **The "map active since …" timer is not cheaply usable.** It is absent from all
  connless info responses (version/name/map/gametype/players only). It lives
  in-game (MOTD / broadcast / round timer in snapshots), so reading it would mean
  implementing full snapshot/MOTD decoding — the one large slice of the protocol
  we do not have — and it is mod-specific. Rejected as a gate.

## 2. Goal & scope

When a UDP download yields a self-consistent map whose identity differs from the
version we requested:

1. **Capture the served map under its true (wire) name** — record `Map` +
   `MapVersion` keyed off the `MAP_CHANGE` name and store the verified bytes.
2. **Throttle the phantom** — stamp the requested version's 6h retry cooldown so
   the filler stops re-attempting it every pass.

**Explicitly out of scope (decided during design):**

- **No aliasing** (e.g. recording `Gridania` bytes under a `Multiworld` version,
  or a `Multiworld (Gridania)` label). We cannot know whether a city server ever
  swaps its served map, so the only honest record is the served map under its own
  name + a throttle on the phantom.
- No permanent observation-based suppression of the phantom (a later enhancement
  built on these observations).
- No snapshot/MOTD/timer parsing.
- The `MapAvailableViaHttp` advertised-different-name path is unchanged (no bytes
  in hand there; that case is dominated by genuinely cycling fast-download
  servers, which the live-window gate already handles).

## 3. Design (Approach A — capture inside `GameServerMapSource`)

### 3.1 Self-consistency only in the session

`runSession()` calls `downloadWithIdentity()` **without** passing
`expectedSha256` / `expectedCrc` (pass `null`). The session then performs only its
server-announced **self-consistency** checks (size, announced-crc vs bytes,
announced-sha if present) and returns a `MapDownloadResult { bytes, name, crc,
size, sha256 }`. The caller-expectation comparison moves up into the source, where
it can distinguish "exactly what we asked for" from "a different real map".

### 3.2 Comparison & routing in `runSession()`

After the download + datafile gate, compute the served identity
(`sha = sha256(bytes)`, `crc = crc32(bytes)` as 8-hex; equal to the announced crc
by self-consistency) and route:

| Case | Condition | Action |
|---|---|---|
| **Exact match** | wire name matches (case-insensitive) **and** (expected sha set → served sha == expected; else expected crc set → served crc == expected; else keyless → name match alone) | dispatch `success` for the requested version; **return bytes** (unchanged happy path) |
| **Served other** | not an exact match | `capturer->capture(...)` the served map (returns the served `MapVersion` W); dispatch `success` for W **and** a `served_other_version` failure for the **requested** version (drives the throttle); **return null** |

All telemetry dispatch stays in the source (one place); the capturer is pure
persistence.
| **Not a datafile** | magic check fails | `not_datafile` failure; return null (unchanged) |

This covers both live shapes: same-name/new-crc (ICTFX) and different-name
(MMOTee) both fall into **Served other**.

### 3.3 New: `CapturedMapVersionRecorder` (pure persistence)

`capture(string $wireName, string $bytes, ?string $servedCrcHex): ?MapVersion`

1. `$map = Map::firstOrCreate(['name' => $wireName])` (new `Map` for a
   genuinely-different map name; existing one for same-name/new-crc).
2. `$sha = sha256($bytes)`, `$crc = $servedCrcHex ?? crc32-hex($bytes)`,
   `$size = strlen($bytes)`.
3. `$version = (new MapVersionRecorder)->record($map, $sha, $crc, $size)` —
   no-clobber match/create/merge (idempotent: a re-capture returns the existing
   row).
4. If not already cached, write bytes via the shared cache-path helper and
   `Storage::disk(...)->put(...)`, then `$version->markStatus(CACHED)`.
5. Return `$version`.

The capturer dispatches no events — the source owns telemetry. The served
version's `success` event (dispatched by the source, §3.2) is what the existing
`ApplyMapAcquisitionState` uses to mark it cached + clear its `(server, servedMap)`
backoff.

To avoid a construction cycle (`MapFileStore` default-constructs
`GameServerMapSource`, so the capturer must **not** default-construct a
`MapFileStore`) and to keep cache-path naming single-sourced, extract the path
computation into a pure stateless helper:

### 3.4 New: `MapCachePath` (pure helper)

`MapCachePath::forIdentity(string $name, ?string $sha256, ?string $crc): ?string`
— the existing `MapFileStore::pathForIdentity` logic (sha-or-crc key, hex
path-traversal guard, name slug). `MapFileStore` is refactored to delegate to it
(its public `relativePathFor*` API is unchanged); `CapturedMapVersionRecorder`
uses it too. No `MapSource` dependency → no cycle.

### 3.5 Throttle: `ApplyMapAcquisitionState`

Add a new UDP reason that stamps the **requested** version's
`last_acquire_attempt_at` (the existing 6h `MAP_ACQUIRE_RETRY_HOURS` cooldown,
query-builder stamp so `updated_at` is not bumped):

- `served_other_version` (udp / udp_fallback, failure) → stamp the requested
  version's cooldown. This drops phantom re-attempts from every filler pass to
  once per 6h. It deliberately does **not** back off the `(server, map)` pair (the
  host is healthy) and does **not** flip status to `unavailable` (a host may yet
  serve the real version).

### 3.6 Telemetry vocabulary

Add to the documented closed set in `MapAcquisitionAttempt`:

- `served_other_version` — UDP host served a different self-consistent version
  than requested; the served version was captured and the requested version
  throttled.

The served version's capture is recorded as an ordinary `success` (reason `null`).
`crc_mismatch` stays in the vocabulary for the rare true self-consistency
corruption case. The admin dashboard consumes outcome/reason generically
(`SUM(CASE …)`, `groupBy`), so the new reason needs no dashboard code change; it
will simply appear in the per-server "last reason" and reduce apparent failure
counts as captures land as successes.

## 4. Data flow

```
MapArchiveFiller / MapFileStore::ensureVersion(requested V of map A)
  └─ GameServerMapSource::fetchVersion → downloadFromCandidates → downloadFromHost
       └─ runSession: session.downloadWithIdentity(expected*=null)   // self-consistency only
            ├─ exact match (name + crc/sha == V)
            │     → dispatch success(V); return bytes  ──► store caches V (happy path)
            └─ served other (name B, or same name new crc)
                  → CapturedMapVersionRecorder.capture(B-or-A', bytes, crc)
                  │     → record served version W; write file; markStatus(CACHED)
                  → dispatch success(W)                          ──► W cached, backoff cleared
                  → dispatch failure(V, 'served_other_version')  ──► throttle V 6h
                  → return null  ──► store reports MapFileUnavailable for V (caught by filler)
```

## 5. Edge cases

- **Keyless `fetch(Map)` path** (no expected sha/crc): exact match = name match
  alone → return bytes (unchanged). Name mismatch → capture the served map under
  its wire name, return null; no version-level throttle applies (there is no
  requested `MapVersion`).
- **Captured `Gridania` is not a new phantom.** It is created with one cached
  version and no `server_histories` rows point at it, so the filler never
  re-targets it.
- **Idempotency.** Re-capture returns the existing version (no-clobber `record`)
  and a cache hit (no re-write). Repeated phantom retries (post-cooldown) are
  cheap re-confirmations.
- **`udp_fallback` source** behaves identically; the capture/throttle events carry
  the active `$udpSource` label.
- **No corruption risk** from mid-download cycling (verified, §1) — a cycled
  transfer fails self-consistency before reaching the comparison.

## 6. Testing plan

Reuse `tests/Feature/Connection/GameServerMapSourceHttpTest`'s
`connectionYielding` + `mapChange`/`mapData` helpers (no real UDP).

New / changed tests:

1. **Same-name, new-crc capture (ICTFX shape).** Request version of `ctf4_old`
   crc X; host serves `ctf4_old` crc Y. Assert: a new `MapVersion` (crc Y) is
   recorded under the same map, its file is written, status `cached`, a `success`
   event for the served version, a `served_other_version` failure for the
   requested version, `fetchVersion` returns null, and the requested version's
   `last_acquire_attempt_at` is stamped.
2. **Different-name capture (MMOTee shape).** Request `Multiworld`; host serves
   `Gridania`. Assert a `Gridania` `Map` + version are created and cached, the
   requested `Multiworld` version is throttled, return null.
3. **Exact-match happy path unchanged** (incl. case-insensitive name) — returns
   bytes, single `success`, no capture, no throttle.
4. **Update** `test_classifies_a_caller_crc_mismatch_as_crc_mismatch`: its
   scenario (name matches, crc differs) now becomes a capture + throttle, not a
   `crc_mismatch`. Rename/retarget accordingly.
5. `MapCachePath` parity test: produces the same paths `MapFileStore` did.
6. `ApplyMapAcquisitionState`: `served_other_version` stamps the requested
   version's cooldown and does **not** create a `(server, map)` backoff.

Full suite (`vendor/bin/phpunit`) green; `npm run lint` clean (no front-end
change expected).

## 7. Files touched

- `app/Service/GameServerMapSource.php` — self-consistency-only download +
  served-vs-expected routing.
- `app/Service/CapturedMapVersionRecorder.php` — **new**.
- `app/Service/MapCachePath.php` — **new** (extracted pure path helper).
- `app/Service/MapFileStore.php` — delegate path computation to `MapCachePath`.
- `app/Listeners/ApplyMapAcquisitionState.php` — `served_other_version` throttle.
- `app/Models/MapAcquisitionAttempt.php` — vocabulary docblock.
- Tests as in §6.
