# Keyless Map Archiving — Design

**Date:** 2026-06-14
**Status:** Approved (design); pending implementation plan.

## Goal

Archive the maps that currently show **Unavailable** on `/maps` — maps for which we have
recorded *no content key* (`sha256` and `crc` both null) — by downloading them straight from a
live server over UDP, learning their identity from the wire, and backfilling it. Covers both the
0.6 wire (vanilla 0.6 **and** DDNet-flavour servers, which speak classic 0.6) and, as a second
phase, native 0.7 (sixup) servers.

## Background

`/maps` lists 3857 maps. Their archivability splits three ways (measured 2026-06-14):

| Bucket | Count | How it can be fetched |
| --- | --- | --- |
| has `sha256` | 3293 | `DdnetHttpMapSource` → `maps.ddnet.org`, **anytime**, no live server needed |
| `crc` only | 3 | UDP from a live host |
| **no key at all** | **561** | nothing today → shown **Unavailable** |

The 561 keyless maps are not a timing problem and not fixable by the existing archive queue: a
`Map` only gets a key when a *sighting carries one*, and the discovery sources differ — DDNet's
HTTP feed carries `sha256`, the 0.6 UDP source carries `crc` only when the server's info header
includes it (almost never: 3 maps), and the 0.7 UDP source carries no key at all. **560 of the
561 keyless maps were seen on at least one server** (`server_histories`), so we *do* know where
they were last hosted.

The key realisation (validated live this session): **`MapDownloadSession` needs no pre-known key
to download.** It reads the map's crc/size (and sha256, when present) from the server's
`MAP_CHANGE` message and self-verifies the bytes against the server-announced crc/size. The map
*name* — always reported, and the link from `Map` → recent `server_histories` host already exists
— is enough to find a host, connect, and pull whatever it is serving. We then derive the content
hash ourselves and record it.

**Live proof (this session):** a keyless map (`run_away`, on a DDNet-flavour host) downloaded
purely by connecting — no pre-known hash — and self-verified against the server-announced crc.
That run also surfaced the one real risk (below): the bytes were only 266 bytes, i.e. the host had
almost certainly *swapped to a different/placeholder map* since we last saw it.

## Architecture overview

Three parts, delivered in two independently-mergeable phases:

- **Phase 1 (0.6, the ~560-map bulk):** relax `GameServerMapSource` to fetch keyless maps; have
  `MapDownloadSession` report the observed identity; add a name-match gate and a map-datafile
  sanity check; derive + backfill the content key from the downloaded bytes; teach the archive
  *selection/status* layer that "keyless but has a recent host" is archivable.
- **Phase 2 (0.7, the 14-map tail):** add a native 0.7 (sixup) download session and route by the
  host's flavour. Higher live-test risk (datacenter anti-bot CONNECT filtering we already hit on
  0.6), so it ships after Phase 1 is banked.

The existing proactive archive queue (`maps:archive-enqueue` → `ArchiveMap` job → `MapArchiver`)
is **unchanged in shape**; we only widen what it is willing to attempt and fix the one place the
store assumes a key already exists. The `data:update` scrape stays untouched.

---

## Part 1 — Acquire keyless maps over UDP and backfill their identity

### 1a. `MapDownloadSession` returns the observed identity

Today `MapDownloadSession::download(): string` returns only the map bytes; it already parses the
map name, crc, size and (optional) sha256 from `MAP_CHANGE` but discards all but the bytes.

Change it to return a small immutable result so callers can name-gate and backfill:

```php
final class MapDownloadResult
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $name,     // server-reported MAP_CHANGE name
        public readonly int $crc,         // signed int32, as announced/verified
        public readonly int $size,        // announced == strlen(bytes)
        public readonly ?string $sha256,  // hex, when the server announced one (DDNet/0.7); else null
    ) {}
}
```

`download()` returns `MapDownloadResult`. Integrity verification is unchanged (still throws
`MapDownloadFailed` on size/crc/sha mismatch). The 0.6 behaviour must remain byte-identical; this
is purely a richer return value.

### 1b. `GameServerMapSource` fetches keyless maps, with two gates

Current `doFetch()` bails immediately when the map has neither key:

```php
if ($crcHex === null && $sha256 === null) {
    return null;
}
```

Remove that guard. The host lookup (`ServerHistory` for this `map_id`, most-recently-updated,
preferred protocol-6 address) already works without a key. After a successful download, apply two
gates **before** returning bytes:

1. **Name-match gate.** `MapDownloadResult::$name` (normalised the same way both sides — trim, and
   compare case-sensitively against `Map::$name`) must equal the target map's name. A host that
   has swapped maps since we recorded it will report a different name → return `null` (do not
   archive, do not backfill). This is what guards against the 266-byte `run_away` mislabel.
2. **Map-datafile sanity check.** The bytes must look like a Teeworlds map datafile: a 4-byte
   signature of `"DATA"` or `"ATAD"` (byte-order tolerant) and a plausible minimum length. Reject
   placeholder/troll content. Implemented as a small private helper; failure → return `null`.

When both gates pass, return the bytes. When a known `expectedCrc`/`expectedSha256` exists (keyed
maps), keep passing it to `download()` so verification stays strict for those.

### 1c. `MapFileStore` — invert the key-first flow for keyless maps

This is the load-bearing change. `MapFileStore::ensure()` today computes the cache path *first*
and throws if the map has no key:

```php
$relative = $this->relativePathFor($map);
if ($relative === null) {
    throw new MapFileUnavailable();   // keyless maps never even reach the sources
}
```

For a keyless map the path cannot be known until we have the bytes (the path key is the content
hash). Restructure `ensure()` so that, on a cache miss, it asks the sources for bytes, and only
*then* — for a map that had no key — derives the content identity from the bytes and records it:

```
ensure(Map $map):
    storage = disk(this.disk)

    relative = relativePathFor(map)                 // null when keyless
    if relative !== null and storage.exists(relative):
        return relative                              // fast path: keyed + already cached

    foreach source in sources:
        bytes = source.fetch(map)                    // HTTP source returns null for keyless
        if bytes === null: continue

        if relativePathFor(map) === null:            // keyless: derive + backfill identity
            backfillIdentity(map, bytes)             // sets sha256 (hash), crc, size when null (no-clobber)

        relative = relativePathFor(map)
        if relative === null:                        // defensive: still no key → cannot name the file
            continue
        storage.put(relative, bytes)
        return relative

    throw new MapFileUnavailable()
```

`backfillIdentity(Map, string $bytes)` computes `sha256 = hash('sha256', $bytes)`,
`crc = crc32($bytes)` (stored as the existing 8-hex-char column form), `size = strlen($bytes)`,
and persists only the columns that are currently null (no-clobber, mirroring the scraper's
`UpdateData::updateMapIdentity`). After it runs, `relativePathFor($map)` resolves to
`maps/<name>_<derived-sha256>.map` and the file is stored there.

Notes:
- The content hash we derive *is* the map's true sha256 (hash of the verified bytes), so recording
  it in the `sha256` column is correct even for vanilla maps that DDNet never mirrors.
- The name-match gate lives in the UDP source (1b), so by the time bytes reach the store they are
  known to belong to this map. The store derives keys from bytes only; it never needs the
  server-reported name.
- `DdnetHttpMapSource` is unaffected: it still returns `null` for keyless maps (no `sha256` → no
  URL), so the store falls through to the UDP source.

### 1d. Selection + status: "keyless but has a host" is archivable

Three call sites currently treat "no key" as "Unavailable / not selectable". Widen them so a
keyless map that has at least one `server_histories` row (a host we can attempt) is treated as
archivable; a keyless map with *no* host stays genuinely Unavailable.

- **`Map::hasDownloadableFile()`** → `true` when `sha256` or `crc` is set **or** the map has any
  `serverRecords`. (Drives `MapArchiver::statusFor()`'s Unavailable branch.)
- **`MapArchiver::statusFor()`** → unchanged logic, but now keyless-with-host maps fall through to
  Queued / Retrying instead of Unavailable.
- **`Map::scopeNeedsArchiving()`** → the key predicate gains an `orWhereHas('serverRecords')` so
  the enqueuer selects keyless-with-host maps:

  ```php
  ->where(fn (Builder $q) => $q->whereNotNull('sha256')
                                ->orWhereNotNull('crc')
                                ->orWhereHas('serverRecords'))
  ```

  The cooldown clause and ordering are unchanged. A keyless host that keeps failing (dead/swapped/
  anti-bot) is parked by the existing `last_archive_attempt_at` cooldown and re-tried each window;
  no give-up cap for now (YAGNI — noted as a future refinement).

**Performance:** `/maps` builds a status per listed map. `whereHas`/`exists` per row would be N
queries on the paginated list. Eager-load it in `MainController::maps` (e.g.
`withCount('serverRecords')` on the page query, and have `statusFor` honour a preloaded count when
present) so the list stays a constant number of queries.

---

## Part 2 — Native 0.7 (sixup) in-game download

`MapDownloadSession` is 0.6-only. A native 0.7 server uses a different download shape. All facts
below were verified against the **server** source (the authority for what we receive),
`teeworlds/src/engine/server/server.cpp` and `src/game/version.h`.

### 0.7 protocol (verified)

- **NETMSG ids** (counted from the real enum in `src/engine/shared/protocol.h`, sequential from
  `NETMSG_INFO=1`): `INFO=1, MAP_CHANGE=2, MAP_DATA=3, CON_READY=5, READY=18, REQUEST_MAP_DATA=23`.
  Wire framing is the same `(msgId<<1)|system_flag` as 0.6, `system_flag=1`.
- **Trigger:** client → `NETMSG_INFO` (vital) = version string + password string + a trailing
  int (client version). The version string **must exactly equal** `GAME_NETVERSION`
  = **`"0.7 802f1be60a05665f"`** (`version.h:7-8`) or the server drops the client ("Wrong
  version"). The trailing int is read but its absence does not block `SendMap`; send it for
  correctness. On a valid INFO the server calls `SendMap` → `MAP_CHANGE` (`server.cpp:810-839`).
- **`MAP_CHANGE`** (`server.cpp:701-707`), in order: `string name, int crc, int size,
  int chunksPerRequest, int chunkSize, raw sha256[32]`.
- **Pull loop:** client → `NETMSG_REQUEST_MAP_DATA` (vital, **no chunk argument** — the server
  tracks its own per-client chunk counter, `server.cpp:841-868`). The server replies with a
  *window* of up to `sv_map_download_speed` (default **8**, range 1–16) `MAP_DATA` messages.
- **`MAP_DATA`** (`server.cpp:862-863`): **raw bytes only** — no `last`/`crc`/`chunk`/`size`
  fields (unlike 0.6). Append in receive order (the reliable layer guarantees ordering).
  **Completion = total bytes received ≥ announced size** (there is no `last` flag). Re-send
  `REQUEST_MAP_DATA` after each window until complete.
- We only want the file, so once all bytes are received we verify sha256 (and crc) and disconnect;
  we never send `READY`/`ENTERGAME`.

### 0.7 session implementation

- Add a 0.7 download session alongside the 0.6 one. Factor the shared, protocol-independent pieces
  (`verifyIntegrity`, `toInt32`, `MapDownloadResult`, msg-type unpacking) so they are not
  duplicated; the 0.6 receive/verify behaviour must remain byte-identical. The two `download()`
  bodies differ enough (0.6 per-chunk request with `expectedChunk` tracking; 0.7 windowed pull
  with raw append and size-based termination) that they stay as separate methods/classes — do not
  force one skeleton if it obscures either flow.
- A small factory selects the session by flavour:
  `for(ConnectionInterface $conn, string $flavor)` → 0.7 session for `vanilla_07`, 0.6 session
  otherwise.

### 0.7 routing in `GameServerMapSource`

Today the source prefers a protocol-6 address and always builds a 0.6 session. Change host
selection to honour flavour: for a `vanilla_07` host, pick a protocol-7 address and the 0.7
session; for `ddnet`/`vanilla_06`, keep protocol-6 + the 0.6 session. `GameConnection` already
completes the 0.7 sixup handshake when constructed with `vanilla_07`, and `receive()` already
returns decoded NETMSG payloads, so the new session reuses all framing/Huffman/token plumbing.

---

## Data flow (keyless map, end to end)

```
maps:archive-enqueue  ──selects──▶  Map (keyless, has server_histories)   [scopeNeedsArchiving]
        │ dispatch ArchiveMap (unique per map id, database queue)
        ▼
queue:work --queue=maps ──▶ ArchiveMap::handle ──▶ MapArchiver::download(map)
        │ stamp last_archive_attempt_at (toBase, no updated_at bump)
        ▼
MapFileStore::ensure(map)
        │ cache miss (no key → no path yet)
        ├─ DdnetHttpMapSource::fetch → null (no sha256)
        ├─ GameServerMapSource::fetch:
        │     find recent host → connect (0.6 or 0.7 per flavour) → MapDownloadSession::download
        │     → MapDownloadResult{bytes,name,crc,size,sha256?}
        │     name-match gate (server name == map name?)  + datafile sanity → bytes | null
        │ backfillIdentity(map, bytes): sha256=hash(bytes), crc, size  (no-clobber, persisted)
        ▼ store at maps/<name>_<sha256>.map
   badge flips Unavailable → Downloaded
```

## Error handling

- `GameServerMapSource::fetch` keeps its top-level `try/catch (\Throwable) → null`: any connect /
  protocol / timeout failure yields a clean fall-through, never a thrown archive job.
- Name-match or datafile-sanity failure → `null` (the map is *not* backfilled and stays keyless;
  it is re-tried after the cooldown, in case the host returns to the right map).
- `MapFileStore::ensure` throws `MapFileUnavailable` only when *no* source produced acceptable
  bytes — unchanged contract. `MapArchiver::download` still stamps the attempt before calling
  `ensure`, so a failure parks the map for the cooldown window.
- The 0.7 session throws `MapDownloadFailed` on timeout / malformed message / integrity mismatch,
  caught by `GameServerMapSource` like the 0.6 path.

## Testing strategy

Follows the existing suite's patterns (`RefreshDatabase`, `Storage::fake('local')`, scripted
`FakeConnection`, `Map::create([...])` — no `Map` factory).

- **`MapDownloadResult` / 0.6 session:** session returns the parsed identity; existing 0.6
  download tests updated for the new return type; 0.6 bytes/verify behaviour asserted unchanged.
- **`GameServerMapSource` keyless path (FakeConnection):** keyless map + matching server name →
  bytes returned; server name mismatch → `null`; non-datafile bytes → `null`; keyed map still
  passes `expectedCrc`/`expectedSha256` through.
- **`MapFileStore` keyless flow:** keyless map + a source returning datafile bytes → file stored at
  `maps/<name>_<sha256>.map`, and `sha256`/`crc`/`size` backfilled (no-clobber: a pre-set column is
  not overwritten); keyed-and-cached fast path unchanged; no source → `MapFileUnavailable`.
- **Selection/status:** `scopeNeedsArchiving` includes a keyless-with-host map and excludes a
  keyless-no-host map; `statusFor` returns Queued (not Unavailable) for keyless-with-host and
  Unavailable for keyless-no-host; `hasDownloadableFile` reflects the same.
- **0.7 session (FakeConnection):** scripted `MAP_CHANGE` (with chunksPerRequest/chunkSize/sha256)
  + windowed raw `MAP_DATA` → assembled bytes + sha256 verify; windowing (re-request after a
  window) exercised; INFO carries `"0.7 802f1be60a05665f"`.
- **Factory:** `vanilla_07` → 0.7 session; `ddnet`/`vanilla_06` → 0.6 session.

`vendor/bin/phpunit` green; `npm run lint` / type-check are unaffected (no front-end changes beyond
the existing badge, which already exists).

## Risks / live verification

- **0.7 NETMSG ids** are counted from a sequential enum, not explicitly numbered — verify live
  before trusting (`READY=18`, `REQUEST_MAP_DATA=23`). A live hexdump probe (the
  `LoggingTransport` decorator method that cracked the 0.6 fng bug) is the fallback if a 0.7
  download stalls.
- **0.7 burst bundling:** 0.6 servers bundle `MAP_CHANGE` + first chunks in one datagram; confirm
  whether 0.7 servers do too and handle uniformly (process every message of every receive batch).
- **Anti-bot CONNECT filtering:** the same datacenter-IP filtering that blocked some 0.6 servers
  applies to 0.7; some keyless maps will remain unreachable for environmental, not protocol,
  reasons. They simply stay Unavailable / get re-tried.
- **Placeholder maps:** the datafile sanity check is the guard; if real maps are ever rejected,
  loosen the minimum-size bound (the signature check is the hard part).

## Out of scope

- Discovery-side key capture (teaching the 0.6/0.7 *browse* sources to record crc/sha256). The
  download-by-connect path makes it redundant: we learn the key for free at download time.
- A give-up cap for perpetually-failing keyless hosts (the cooldown already bounds churn).
- Any change to `data:update`, the queue infrastructure, the schedule, or the cache filename
  scheme (`maps/<name>_<sha256|crc>.map`).
