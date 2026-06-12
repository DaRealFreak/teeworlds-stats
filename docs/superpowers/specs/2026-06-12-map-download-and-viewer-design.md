# Map download & interactive map viewer

## Problem

A "map" in twstats is currently just a name: the `maps` table is `id` + `name` +
timestamps, and `/map/{name}` shows play-stats only. There is no way to download the
actual `.map` file or to see what a map looks like. We want both: a **Download .map**
button and an **interactive in-browser viewer** that renders the map (tile layers, quad
layers, animated envelopes) on a `<canvas>` with pan/zoom.

The reference checkouts (`ddnet/`, `teeworlds/`, `ddnet-web/`, `ddnet-scripts/`) do **not**
contain a drop-in renderer — ddnet.org's map images are hand-taken in-game screenshots, and
`ddnet-scripts` only extracts metadata via libtw2's `ddnet_properties`. What they *do*
provide is the authoritative material to build one:

- **Format spec** — libtw2 datafile + map docs (`ddnet-web/www/docs/libtw2/{datafile,map,
  connection,packet,packet7,huffman}/`).
- **Render semantics** — `ddnet/src/game/client/components/maplayers.cpp` (+ `mapimages.cpp`)
  and `ddnet/src/engine/shared/{datafile,map}.cpp`.
- **Net protocol** — `ddnet/src/engine/{client,shared}/network*.cpp`, `client.cpp` download
  path.
- **Tilesets** — `ddnet/data/mapres/*.png`, `game.png`, entities.

## Goals

- Capture each map's `sha256` + `size` (DDNet) and `crc` (0.6/0.7) during the scrape.
- Acquire the raw `.map` bytes on demand and cache them, behind a pluggable **`MapSource`**
  abstraction with two implementations:
  - **HTTP** from `maps.ddnet.org` (DDNet maps, needs sha256) — built first.
  - **UDP in-game download** from a currently-online server hosting the map (0.6/0.7 and
    base-protocol mods) — built last; the renderer doesn't care which source produced the
    bytes.
- A **Download .map** button on `/map/{name}`.
- A **client-side WebGL viewer** parsing the datafile in strict TS and rendering, at full
  fidelity: tile layers (with flip/rotate flags), quad layers (per-corner colors, UVs), and
  **animated position/color envelopes**, with pan / wheel-zoom / fit-to-screen and an
  optional entities overlay.

## Non-goals

- No mass pre-downloading of maps; acquisition is lazy (on first view) and cached by hash.
- No physics/gameplay simulation, no demo playback, no sounds.
- No editing. Read-only viewer.
- No HTTP source for non-DDNet maps (they have none); those rely on the UDP path.
- The UDP path does not attempt heavily-customized mod protocols that deviate from the base
  0.6/0.7 net layer — those degrade to "unavailable".

## Build order (phases)

Each phase is independently shippable. `MapSource` is the seam that lets the UDP work land
last without reworking the viewer.

- **A — Acquisition + download (HTTP).** Schema, scraper capture, `MapFileStore`,
  `DdnetHttpMapSource`, file route, Download button. PHP only.
- **B — Datafile parser (TS).** Container + scene parsing into typed structures.
- **C — WebGL viewer (TS).** Renderer (tiles → quads → envelopes), viewport, texture
  loading, asset vendoring, detail-page integration. Works on the full DDNet corpus.
- **D — Integration polish + verification.** Empty states, Playwright render check.
- **E — UDP map source.** `app/TwStats/Connection/` net stack + `GameServerMapSource`;
  extends coverage to 0.6/0.7/base mods. No viewer rework.

## Data model context

- Identity stays keyed by `maps.name` (the route + `SearchController` look maps up by name).
- The DDNet master JSON's `map` object carries `name` + `sha256` + `size`;
  `DdnetServerListParser` (`:81`) currently reads only `name`.
- 0.6/0.7 extended browser info carries `mapCrc`; `SixInfoCodec` currently `array_shift`s
  and discards it. (Seven's equivalent likewise.)
- A given name can in principle be seen with different hashes over time (map updates); we
  store the **latest-seen** sha256/size/crc on the row. The download/UDP layer verifies the
  bytes against the stored hash and treats a mismatch as a cache miss.

## Design

### Phase A — Acquisition + download (PHP)

**A1. Migration.** Add three nullable columns to `maps`:

| Column | Type | Source | Use |
| --- | --- | --- | --- |
| `sha256` | `char(64)` nullable | DDNet feed | DDNet HTTP identity + cache key |
| `size` | `unsignedInteger` nullable | DDNet feed / UDP `MAP_CHANGE` | download validation |
| `crc` | `char(8)` nullable | 0.6/0.7 info + UDP `MAP_CHANGE` | non-DDNet identity / verify |

**A2. Scraper capture.**
- `DdnetServerListParser`: also read `map.sha256` (lower-case hex) and `map.size`, plumb
  through `DiscoveredServer` → the map value object.
- `SixInfoCodec` / `SevenInfoCodec`: keep the `mapCrc` field instead of discarding it.
- `UpdateData`: when upserting the `maps` row, set `sha256`/`size`/`crc` **only when the
  current source provides them** — never null out an existing value (a 0.6 source must not
  clobber a DDNet sha256, mirroring the existing afk=null no-clobber rule). Coalesce:
  `update(['sha256' => $new ?? $existing, …])`.

**A3. `App\Service\MapFileStore`.** The single entry point the controller uses.
- `path(Map $map): string` → returns the local path to a cached `.map`, acquiring it on
  miss. Cache location: `storage/app/private/maps/<sha256-or-crc>.map` (the `local` disk root
  is `storage/app/private`).
- Acquisition delegates to a `MapSource` chain (Phase A ships only `DdnetHttpMapSource`;
  Phase E appends `GameServerMapSource`). The chain returns the first success.
- Throws a typed `MapFileUnavailable` when no source can produce verified bytes.

**A4. `DdnetHttpMapSource implements MapSource`.**
- Only eligible when `$map->sha256` is set.
- URL: `rtrim(cl_map_download_url,'/')` (`https://maps.ddnet.org`) + `/` +
  `rawurlencode($name) . '_' . $sha256 . '.map'` — matching `server.cpp:3006` /
  `client.cpp:1266` (`%s_%s.map`, then URL-escaped; `_`, hex, `.` are RFC-3986 unreserved,
  so only the name is escaped). Verify in implementation against `str_url_encode`/`EscapeUrl`.
- Uses Laravel `Http` (so tests can `Http::fake`). Validates the downloaded length against
  `size` and the sha256 against `$map->sha256`; mismatch → failure (not cached).

**A5. Route + controller.** `App\Http\Controllers\MapFileController`:
- `GET /map/{name}/file` — streams the cached `.map` (`application/octet-stream`, inline);
  this is what the viewer fetches. **Not** wrapped in `CacheResponse` (binary, large).
- `GET /map/{name}/download` — same bytes, `Content-Disposition: attachment;
  filename="<name>.map"`.
- Both resolve the map by name (404 if unknown), call `MapFileStore::path`, and return 404
  with a small text body on `MapFileUnavailable`.

**A6. Download button.** In `detail/map.blade.php`, a Download button shown only when the
map is acquirable (Phase A: `sha256` present). Non-acquirable maps render a muted
"Map file not available" note instead.

### Phase B — Datafile parser (strict TS)

New module dir `resources/assets/js/map/`. Pure, side-effect-free, fully typed.

- **`datafile.ts`** — the container (per the libtw2 datafile doc): parse header (version,
  sizes, counts), the item-type index, the item index, and the data section. Data items are
  zlib-compressed and inflated on demand via the browser-native **`DecompressionStream`
  ('deflate')** — no new npm dependency. Exposes `getItem(type, id)`, `getData(index)`,
  `numItems(type)`.
- **`mapParser.ts`** — interpret items into a typed `MapScene` (item layouts per the libtw2
  map doc + `map.cpp`):
  - `info` (author/version/credits/settings),
  - `images[]` — embedded (RGBA data item → `ImageData`) or external (name only),
  - `envelopes[]` + `envPoints[]` (channels, curve type per point),
  - `groups[]` — parallax x/y, offset, clip rect, layer index range,
  - `layers[]` — `tilemap` (width/height, color, flags incl. game/tele/speedup/front/switch/
    tune special types, image ref, tile data: index + flags + skip/reserved) and `quads`
    (per-quad 4 points, 4 colors, 4 texcoords, position/color envelope refs + offsets).
  - Tile array decoding handles the run-length `skip` field and the DDNet special layers'
    wider tile structs.

### Phase C — WebGL viewer (strict TS)

- **`envelope.ts`** — `evalEnvelope(env, points, timeMs, channels)` returning up to 4 floats.
  Implements step/linear/slow/fast/smooth and bezier curves, matching
  `CRenderTools::RenderEvalEnvelope`. Used for both quad position (channels x,y,rot) and
  color (r,g,b,a).
- **`renderer.ts`** — a small WebGL1 renderer. **WebGL, not 2D canvas**, because correct
  quads need per-vertex color + free UV + rotation/skew that canvas2d can't express. Tiles
  and quads share one textured-triangle pipeline:
  - Tiles: each visible, non-empty tile → two triangles sampling its 16×16 cell from the
    layer's tileset, with flip-h/flip-v/rotate applied to UVs and the layer color
    multiplied in. **Viewport culling** — only tiles within the current view rect are
    emitted, batched per layer/texture.
  - Quads: two triangles, per-corner color, per-corner UV; position & color modulated by the
    evaluated envelopes.
  - Groups: apply parallax (relative to camera) and clip rect (scissor) before drawing their
    layers.
  - Animation: `requestAnimationFrame` loop advancing envelope time; redraw only while
    animating or interacting.
- **`viewer.ts`** — the public entry, exposed as `window.MapViewer` and called from the
  detail page's inline `<script>` (the existing inline-script/`window.*` seam in
  `global.d.ts`). Responsibilities:
  - `fetch('/map/<name>/file')` → `ArrayBuffer` → `parseMap`.
  - Texture loading: embedded images → `texImage2D` from `ImageData`; external images →
    `/mapres/<name>.png` (missing → 1×1 placeholder texture, layer still drawn untextured).
  - Interaction: drag-pan, wheel-zoom about cursor, fit-to-screen, devicePixelRatio scaling.
  - Optional **entities overlay** toggle (renders game/front/tele/switch/speedup/tune layers
    using the editor entities texture instead of skipping them).
  - Error/empty states surfaced back to the Blade container (no file → message; parse error
    → message).

**Assets.** Only the *standard* mapres are referenced by name (mappers embed custom
tilesets directly in the datafile), so the set is bounded. Vendor the standard set —
`ddnet/data/mapres/*.png`, `game.png`, and the editor entities images — into committed
`public/mapres/` (and `public/entities/`), the same precedent as `public/skins` for tees, so
production needs no checkout. `vite.config.js` already copies `flag-icons` via a `stripBase`
step; no Vite change is required for already-committed `public/` assets (they ship as-is).

**Wiring & build.** `detail/map.blade.php` gains a viewer panel (canvas + zoom/fit/entities
controls + the Download button) above the existing play-stats include. New TS enters the
bundle through `resources/assets/js/app.ts`; `window.MapViewer` is declared in
`global.d.ts`. `/map` is **not** response-cached (only `/serverbrowser` is), so a plain
`npm run build` suffices — no `responsecache:clear`.

### Phase E — UDP map source (PHP)

New layer `app/TwStats/Connection/` (parallel to `Discovery/`), reusing `UdpTransport`,
`Unpacker`, `VariableInt`, and `FlavorClassifier`. References: libtw2
`connection`/`packet`/`packet7`/`huffman` docs + `ddnet/src/engine/{client,shared}/
network*.cpp` and `client.cpp`.

- **`PacketCodec`** — packet header (control/connless/compression/resend flags, ack, chunk
  count), per-chunk vital/sequence headers, ack tracking + resend, keepalive. 0.7 payloads
  pass through **`Huffman`** (the libtw2 table).
- **`GameConnection`** — handshake state machine: 0.6 token (`TKEN`, incl. DDNet-0.6) and
  0.7 control-token negotiation; `CONNECT → CONNECTACCEPT → ACCEPT`; clean `DISCONNECT`.
- **`MapDownloadSession`** — read `MAP_CHANGE`/`MAP_DETAILS` (name, crc, sha256, size, chunk
  count), request/receive `MAP_DATA` chunks, reassemble, verify crc/size (+ sha256 when
  given), then disconnect. Per-protocol message-number tables for 0.6 vs 0.7.
- **`GameServerMapSource implements MapSource`** — picks a currently-online server hosting
  the map (from the scraper's server rows), selects the protocol via `FlavorClassifier`,
  runs a `MapDownloadSession`, returns verified bytes. Appended to the `MapFileStore` chain
  after `DdnetHttpMapSource`. After this phase, the Download button shows whenever any
  source is viable (sha256 **or** a known online host), not only for DDNet.
  - **Security guardrail:** when this phase first writes `crc`, validate it is hex at the
    source (as `DdnetServerListParser` already does for sha256: `strlen === 64 && ctype_xdigit`),
    and/or have `MapFileStore::ensure` assert `ctype_xdigit($key)` before building the cache
    filename `maps/<key>.map` — otherwise an unvalidated crc becomes a path-traversal vector.
    (Not a Phase A concern: `crc` is never written until this phase, and `sha256` is already
    validated at its only write site.)

**Operational caveats** (documented, accepted): a UDP download briefly occupies a player
slot; some servers disable in-game map download; non-base mod protocols are out of scope; a
map hosted nowhere right now is unfetchable. Each degrades to "unavailable", never an error
page.

## Testing

Per the repo's regression-test convention (own-code features, existing test env). SQLite
`:memory:`; `Map` has no factory → `Map::create(['name' => …, 'sha256' => …])`.

**Phase A (PHP).**
- *Unit* — `DdnetHttpMapSource` URL builder: a name with spaces/special chars + a sha256 →
  the exact escaped `maps.ddnet.org/...` URL.
- *Unit* — `DdnetServerListParser` now surfaces `sha256`/`size`; `SixInfoCodec`/
  `SevenInfoCodec` surface `crc`.
- *Unit* — `UpdateData` map upsert sets hashes when present and **does not** null an existing
  sha256 when a later source omits it.
- *Feature* — `GET /map/{name}/file` with a pre-seeded cached file streams the bytes; with
  no local file, `Http::fake()` serves a body and it is cached + validated; a size/sha
  mismatch → 404/unavailable; an unknown map → 404. `GET …/download` sets the attachment
  header.

**Phases B–C (TS).**
- `npm run type-check` (strict `tsc`) + `npm run lint` (ESLint/Prettier) stay green — CI
  gate.
- If a JS test runner is present, a `datafile.ts` parse test against
  `ddnet-web/www/map/gravity.map` (asserts header/group/layer counts). Otherwise rely on the
  Phase D Playwright render check.

**Phase D.** Playwright: load `/map/<seeded-ddnet-map>`, confirm the canvas paints
non-empty pixels (and the entities toggle changes output).

**Phase E (PHP).** `PacketCodec`/`Huffman` round-trip unit tests against captured byte
fixtures; `MapDownloadSession` chunk-reassembly + crc-verify against a recorded
`MAP_DATA` sequence fixture (no live network in CI). `GameServerMapSource` selection logic
unit-tested with a fake transport.

## Risks / edge cases

- **Non-DDNet maps before Phase E** — no sha256, no HTTP source: the page shows
  "Map file not available", no canvas. Phase E removes this for hosted 0.6/0.7 maps.
- **Hash drift** — stored sha256 ≠ server's current map: download verification fails → cache
  miss → re-acquire; the scrape's latest-seen update converges it.
- **Large maps** — multi-MB `.map`; mitigated by lazy acquisition, on-disk cache, and
  viewport-culled rendering. The file route streams rather than buffering.
- **Custom embedded tilesets / missing mapres** — embedded images always render; an unknown
  external image falls back to a placeholder texture rather than failing the whole map.
- **WebGL unavailable** — viewer detects a missing context and shows a graceful message;
  the Download button still works.
- **UDP fragility (Phase E)** — packet loss/timeouts handled with bounded retries; servers
  that disable download or speak custom protocols degrade to "unavailable".
