# Map viewer — entity legend & jump navigator

- **Date:** 2026-06-14
- **Status:** Approved (design)
- **Area:** front-end map viewer (`resources/assets/js/map/`, `resources/views/detail/map.blade.php`)

## Goal

Turn the map detail page's static highlight legend into a navigator that:

1. lists only the entities **actually present** on the map, with per-category counts;
2. keeps today's per-category outline toggle (colored highlight); and
3. adds a "locate" (⌖) control per landmark that **smart-focuses** the camera on an
   instance and **cycles** through the rest on repeat clicks.

## Background (current state)

- `resources/views/detail/map.blade.php` builds `#mapLegend` from
  `MapViewer.highlightCategories()` — a fixed set of 5 categories (Start/Finish,
  Teleports, Weapons, Health/Armor, Freeze) rendered as `[checkbox][swatch] Label`
  items that toggle colored outlines via `viewer.setHighlight(key, on)`.
- `highlights.ts` owns `HIGHLIGHT_CATEGORIES` (key, label, color, `ids: Set<number>`)
  and `buildHighlightVertices(scene, gameGroupIndex, category)`.
- The viewer already has the jump primitives: the teleport click-to-partner feature
  sets `camera.center = [x+0.5, y+0.5]` and calls `startPulse(x, y)`. Highlight
  outlines render whenever `highlightBatches.size > 0`, **independent of**
  `entitiesOpacity` (viewer.ts ~L690-696).
- Slider sync is done with a listener (`setZoomChangeListener`); there is no
  equivalent for the Entities slider yet.
- Confirmed DDNet ids (`ddnet/src/game/mapitems.h`): `TILE_START=33`, `TILE_FINISH=34`,
  time checkpoints `35..59` (checkpoint n = id − 34), `ENTITY_OFFSET=191`, so
  spawn=192/193/194, armor=197, health=198, shotgun=199, grenade=200, ninja=201,
  laser=202; freeze tiles 9/11/12/13.

## Decisions

- **Granularity:** extend the existing legend rows (not a separate panel). Only present
  categories are shown, with counts. Checkpoints break out into numbered children.
- **Category set (finest split):** Start, Finish/Goal, Spawn, Shotgun, Grenade, Laser,
  Ninja, Health, Armor, Teleports (one grouped cycle, not per-number), Checkpoints
  (split per number), Freeze (toggle only — area fill, not a landmark).
- **Jump behavior — smart focus:** on ⌖, enable that category's outline, raise the
  Entities overlay to a visibility floor if it's lower, zoom in to a readable floor if
  more zoomed out, recenter, and pulse. Repeat clicks cycle to the next instance.
- **Layout:** approach A — convert `.map-viewer__legend` into a vertical, responsive,
  capped/scrollable list in the existing controls area (no floating panel, no side
  column). Avoids canvas pointer-event juggling and occlusion.
- **Smart-focus constants (tunable, documented in code):** `FOCUS_ZOOM ≈ 24` px/tile
  (zoom-in floor); `ENTITIES_MIN ≈ 0.6` (overlay-visibility floor).

## Detailed design

### 1. `highlights.ts` — expand the category set (data)

Split the 5 categories into the finer set below and add a `jumpable` flag.
`buildHighlightVertices` is unchanged (it already works off each category's `ids`).

```ts
export interface HighlightCategory {
    key: string;
    label: string;
    color: [number, number, number]; // 0..1 RGB
    ids: Set<number>;
    jumpable: boolean; // false for area fills (Freeze)
}
```

| key | label | ids | jumpable | suggested color (0..1 RGB) |
|---|---|---|---|---|
| `start` | Start | 33 | yes | 0.30, 1.00, 0.40 |
| `finish` | Finish / Goal | 34 | yes | 1.00, 0.85, 0.20 |
| `spawn` | Spawn | 192, 193, 194 | yes | 0.40, 1.00, 0.70 |
| `shotgun` | Shotgun | 199 | yes | 1.00, 0.65, 0.15 |
| `grenade` | Grenade | 200 | yes | 1.00, 0.45, 0.20 |
| `laser` | Laser | 202 | yes | 0.70, 0.50, 1.00 |
| `ninja` | Ninja | 201 | yes | 0.85, 0.95, 1.00 |
| `health` | Health | 198 | yes | 1.00, 0.35, 0.40 |
| `armor` | Armor | 197 | yes | 0.40, 0.60, 1.00 |
| `teleport` | Teleports | 10,14,15,26,27,30,31,63 | yes | 0.40, 0.75, 1.00 |
| `checkpoints` | Checkpoints | 35..59 | no¹ | 0.80, 0.40, 1.00 |
| `freeze` | Freeze | 9, 11, 12, 13 | no | 0.55, 0.90, 1.00 |

¹ The `checkpoints` **parent** is toggle-only; its numbered **children** carry the
jumps (see `mapIndex.ts`). Colors are suggestions; final values tunable.

`static highlightCategories()` is removed (superseded by the instance `mapIndex()`).
The only callers are this viewer and the blade (verified by grep); the blade switches
to `mapIndex()`.

### 2. `mapIndex.ts` — new, pure, tested (logic)

Scans the game/front/tele physics layers of the game group **once** and returns only
present categories. Categories come from `highlights.ts`'s `HIGHLIGHT_CATEGORIES`
(single source of truth); `buildMapIndex` special-cases `key === 'checkpoints'` to
split instances into numbered children. Freeze gets a count but no stored positions
(no point enumerating thousands of tiles).

```ts
export interface LegendEntry {
    key: string;                       // 'spawn', 'checkpoints', 'cp:3', ...
    label: string;
    color: [number, number, number] | null; // null = no swatch (checkpoint child)
    count: number;
    toggleable: boolean;               // has an outline checkbox
    jumpable: boolean;
    children?: LegendEntry[];          // checkpoints only
}

export interface MapIndex {
    entries: LegendEntry[];                    // lean UI descriptor (no positions)
    positions: Map<string, [number, number][]>; // jump key -> instance cells
}

export function buildMapIndex(scene: MapScene, gameGroupIndex: number): MapIndex;
```

- Categories are emitted in the table order above; a category with `count === 0`
  (and, for checkpoints, no children) is omitted.
- `checkpoints` entry: `toggleable: true`, `jumpable: false`, `count` = total
  checkpoint tiles; `children` = one `{ key:'cp:<n>', label:'Checkpoint <n>',
  color:null, toggleable:false, jumpable:true, count }` per present number, ascending.
- `positions` includes every jumpable key, including each `cp:<n>`. `freeze` and the
  `checkpoints` parent have no positions entry.

### 3. `viewer.ts` — wire index + jump (orchestration)

- Constructor builds the index once: `const idx = buildMapIndex(scene, gameGroupIndex)`;
  store `this.legendEntries = idx.entries` and `this.jumpPositions = idx.positions`.
  Add `private jumpCursors = new Map<string, number>()`.
- New public API:

```ts
/** Lean legend descriptor for building the UI (no positions). */
mapIndex(): LegendEntry[];

/**
 * Smart-focus the next instance of `key` and cycle on repeat calls.
 * Enables the category outline, raises Entities to ENTITIES_MIN if lower,
 * zooms to FOCUS_ZOOM if more zoomed out, recenters and pulses.
 * Returns null when the key has no instances.
 */
jumpToNext(key: string): { index: number; total: number; highlightKey: string } | null;

/** Mirror of setZoomChangeListener for the Entities slider. */
setEntitiesChangeListener(cb: (opacity: number) => void): void;
```

`jumpToNext` logic:

```
positions = jumpPositions.get(key); if empty -> return null
cursor = ((jumpCursors.get(key) ?? -1) + 1) % positions.length; store it
[x, y] = positions[cursor]
highlightKey = key.startsWith('cp:') ? 'checkpoints' : key
setHighlight(highlightKey, true)                          // idempotent
if entitiesOpacity < ENTITIES_MIN -> set it, notify entities listener
if camera.zoom   < FOCUS_ZOOM    -> set it, notifyZoom()
camera.center = [x + 0.5, y + 0.5]; startPulse(x, y)
return { index: cursor, total: positions.length, highlightKey }
```

`FOCUS_ZOOM` / `ENTITIES_MIN` live as module constants near `NATIVE_ZOOM`.
`setEntitiesOpacity` should also fire the entities listener so wheel-less programmatic
raises stay in sync (the listener is invoked once on registration, like zoom).

### 4. `detail/map.blade.php` — render the navigator (view)

Replace the `highlightCategories()` loop with one driven by `viewer.mapIndex()`:

- Each top-level entry renders a row: `[checkbox?][swatch?] Label (count) [⌖?]`.
  - checkbox present iff `toggleable`; `change` → `viewer.setHighlight(key, checked)`.
  - ⌖ button present iff `jumpable`; `click` → `viewer.jumpToNext(key)`, then, on a
    non-null result: tick the `result.highlightKey` row's checkbox and update a small
    `n/total` counter (`${index+1}/${total}`) on the button.
- Checkpoints render the parent row (checkbox only, no ⌖) followed by an indented
  sub-group of child rows (label + count + ⌖, no checkbox/swatch).
- Register `viewer.setEntitiesChangeListener(frac => entSlider.value = round(frac*100))`
  so smart-focus auto-raises keep the Entities slider synced (alongside the existing
  zoom listener).
- If `mapIndex()` is empty, hide the legend container.
- The existing teleport click-to-partner behavior and the hint line stay.

### 5. SCSS — `.map-viewer` block in `dark_admin_bootstrapius.scss`

- `&__legend`: switch to a vertical, responsive multi-column list
  (`flex-direction: column` within columns, or `column-count`/grid), with a capped
  `max-height` and `overflow-y: auto` so entity-rich maps scroll instead of pushing the
  page down.
- Add `&__legend-group` (checkpoint sub-group) with a left indent, `&__jump` (the ⌖
  button, sized to match `.btn-sm`), and `&__jump-count` (muted `n/total`).
- Reuse existing `&__legend-item` / `&__swatch`.

### 6. Tests — `tests/js/map/mapIndex.test.ts`

Mirror `highlights.test.ts`. Build synthetic `TileLayer`/`MapScene` fixtures and assert:

- present vs absent categories (e.g. a scene with only spawn + start lists exactly
  those, in table order);
- counts match the number of matching tiles;
- checkpoint children: ids 35, 37, 59 → labels "Checkpoint 1", "Checkpoint 3",
  "Checkpoint 25", ascending; parent count = sum of children;
- `freeze` present but `jumpable === false` and absent from `positions`;
- `positions` holds the right cells for a jumpable key, including `cp:<n>`.

Plus a `gravity.map` fixture smoke test: parse → `buildMapIndex` returns a non-empty,
well-formed index.

Update `highlights.test.ts` for the new (split) category set and the removal of
`highlightCategories()`.

## Data flow

```
load → parse scene → MapViewer ctor → buildMapIndex() (entries + positions)
blade → viewer.mapIndex() → render legend rows

click checkbox → viewer.setHighlight(key, on)                 (unchanged)
click ⌖        → viewer.jumpToNext(key)
                  → enable outline + raise Entities (→ slider) + zoom floor (→ slider)
                  → recenter + pulse → { index, total, highlightKey }
                  → blade ticks highlightKey checkbox + updates n/total
```

## Edge cases / error handling

- 0-count categories are never listed; checkpoints with no present numbers are omitted.
- Non-DDNet / empty game layer → empty index → legend container hidden.
- Missing game group → `buildMapIndex` returns an empty index (guarded), no throw.
- `jumpToNext` on a key with no positions → returns `null`; the blade no-ops.
- Teleport "total" may be large (all in/out tele tiles) — acceptable for cycling.

## Out of scope (YAGNI)

- Floating/side legend panels (approaches B/C).
- Per-number teleport navigation (the existing click-to-partner covers it).
- Per-checkpoint outline toggles (the parent toggles all).
- Search/filter box, minimap, or persisting toggle state across reloads.

## Verification

`npm run type-check && npm run lint && npm test && npm run build`, then
`php artisan responsecache:clear`, then a Playwright check of a map detail page
(`/map/<name>`): legend lists present entities with counts, ⌖ recenters + pulses +
cycles, checkbox/sliders stay in sync.

## v2 — Grouped & collapsible navigator

A follow-up reshaped the flat list into grouped, collapsible categories:

- **Flat rows:** Start, Finish/Goal, Spawn (own color, toggle + jump).
- **Typed groups** (Weapons → Shotgun/Grenade/Laser/Ninja; Pickups → Health/Armor):
  each child keeps its own color + outline toggle + jump; the group header is a
  tri-state **master** checkbox toggling all children at once. Open by default.
- **Numbered groups** (Teleports, Checkpoints): the header is a single outline toggle
  (one group color); children are numbered, jump-only. Teleports number their cells by
  the tele layer's `physics.number` (not the tile id); Checkpoints by `id − 34`.
  Collapsed by default (they can hold 25+ rows).
- **Freeze removed.**
- Groups use native `<details>/<summary>` for accessible, keyboard-friendly collapse;
  the master checkbox `stopPropagation`s its click so toggling the outline doesn't also
  collapse the group.

Implementation: `mapIndex.ts` emits the grouped tree (typed groups via a `TYPED_GROUPS`
config, numbered groups via a shared helper; child jump keys `tele:<n>` / `cp:<n>`).
`viewer.jumpToNext` maps `tele:* → teleport` alongside `cp:* → checkpoints`. The Blade
builds it with `buildLeaf` + `buildGroup`; SCSS adds the group/header/caret/children
rules. `highlights.ts` drops `freeze` and flips `teleport.jumpable → false`.
