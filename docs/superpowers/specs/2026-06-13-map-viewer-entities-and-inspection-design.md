# Map viewer: entities fidelity & inspection tools

## Problem

The interactive map viewer (`resources/assets/js/map/`) now renders design + an entities
overlay, but several inspection gaps remain:

1. **Teleport / speedup / switch / tune tiles are invisible.** The parser decodes every
   physics layer as a 4-byte `CTile`, but these layers use smaller structs
   (`CTeleTile` 2B, `CTuneTile` 2B, `CSwitchTile` 4B, `CSpeedupTile` 6B). The overlay
   currently *skips* them rather than draw garbage, so teleporters never show.
2. **No way to follow a teleport** to its destination while inspecting a map.
3. **No way to highlight key tiles** (start/finish, weapons, freeze, …) to find them fast.
4. **Tiles look pixelated.** The `<canvas>` backing store is fixed at 960×540 and CSS-
   stretched to 100% width with no `devicePixelRatio`, so the whole view is nearest-
   neighbour upscaled.
5. The annotations the in-game client draws (teleport numbers, speedup arrows + force,
   switch numbers + delay) have nowhere to render — the GL tile pipeline can't draw text.

All semantics are taken from the `ddnet/` reference checkout (`mapitems.h`,
`src/game/map/render_map.cpp`, `render_layer.cpp`, `components/mapimages.cpp`).

## Goals

- **Decode the special physics structs** so tele/speedup/switch/tune render in the overlay
  (base graphic = `m_Type` indexing the entities tileset), and capture the per-cell
  metadata (`number`, `delay`, `force`, `maxSpeed`, `angle`) for annotations + follow.
- **Follow teleport**: click a teleport-in tile → pan to the matching out tile(s) (paired by
  `m_Number`) and pulse-highlight; click an out tile → its sources. Active only while
  teleports are visible (entities overlay > 0).
- **Highlight legend**: a toggleable legend of categories (Start/Finish, Teleports, Weapons,
  Pickups, Freeze) that outlines all matching tiles in a distinct colour. Works with the
  entities overlay on or off.
- **2D annotation overlay**: a transparent `<canvas>` over the GL canvas, drawn through the
  same camera, showing teleport numbers, speedup arrows + `force/maxSpeed`, switch
  `number`/`delay`, and the follow pulse ring. Culled below a zoom threshold so it never
  clutters.
- **Resolution fix**: size both canvases' backing stores to displayed CSS size ×
  `devicePixelRatio` (capped at 2), kept in sync via `ResizeObserver`. Camera math stays in
  CSS pixels; GL viewport + 2D context scale to device pixels. Keep `NEAREST`.

## Non-goals

- Bezier envelope easing, group clipping (tracked separately).
- Rendering switch *logic* / tele check evaluation — we only display, not simulate.
- Localised label fonts; a plain system font on the 2D overlay is fine.

## Design

### Parsing (`tiles.ts`, `mapParser.ts`, `types.ts`)

- New pure `decodePhysicsTiles(bytes, w, h, kind)` returning `{ tiles: Tile[], physics }`.
  Stride is derived as `floor(bytes.length / (w*h))` (robust to `CSpeedupTile` padding); the
  speedup `angle` is read as the **last 2 bytes** of the stride. Field offsets:
  - tele/tune (stride 2): `[number, type]` → `tile.id = type`, `physics.number`.
  - switch (stride 4): `[number, type, flags, delay]` → `tile.id = type`,
    `tile.flags = flags` (rotation), `physics.number`, `physics.delay`.
  - speedup (stride 6): `[force, maxSpeed, type, …angle]` → `tile.id = type`,
    `physics.force/maxSpeed/angle`.
- `parseTilemapLayer` calls this for tele/speedup/switch/tune; game/front/design keep the
  `expandTiles` (`CTile`, skip-RLE) path unchanged.
- `TileLayer` gains optional `physics?: { number?, delay?, force?, maxSpeed?, angle? }`
  (typed arrays, parallel to `tiles`).

### Rendering (`viewer.ts`, `highlights.ts`, `overlay.ts`)

- Drop the tele/speedup/switch/tune exclusion — all physics layers now build overlay batches.
- `highlights.ts`: category → tile-id sets; scans game/front/tele layers and builds colored
  **outline** vertex batches (border strips, thickness a fixed fraction of a tile). Drawn in
  GL after the layers via the existing `draw()` (white texture + per-vertex colour),
  independent of entities mode.
- `overlay.ts`: owns the 2D context. Each render, clears and draws annotations for visible
  special tiles via `camera.worldToScreen`, plus the follow pulse. Zoom-threshold culled.

### Interaction & resolution (`viewer.ts`, blade, SCSS)

- Build `teleIndex: Map<number, {ins:[x,y][], outs:[x,y][]}>` from the tele layer.
- Pointer: a press→release with small movement + short hold = click (else it's a pan). On
  click while overlay > 0, map to a world tile, look up the tele number, pan to + pulse the
  partner.
- Resolution: `ResizeObserver` sizes both canvases to `clientW/H * dpr`; camera view size =
  CSS px. SCSS pins `aspect-ratio: 16/9`; overlay canvas is `position:absolute` over the GL
  canvas with `pointer-events:none`.
- Blade: add the overlay canvas and the highlight legend (colored checkboxes → `viewer`).

## Testing

- `tiles.test.ts`: `decodePhysicsTiles` for tele/switch/speedup/tune (ids, numbers, delay,
  force/maxSpeed, angle, padding-robust stride).
- `highlights.test.ts`: category matching finds the right tile positions per layer.
- `mapParser.test.ts`: tele/switch layers expose `physics` metadata.
- Manual: load a teleport map (`/map/Purple+Panic+4`) — teleports visible + numbered,
  click-to-follow, legend toggles, crisp at native resolution.
