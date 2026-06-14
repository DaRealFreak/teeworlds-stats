# Map Viewer Entity Legend & Jump Navigator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the map detail page's static highlight legend into a navigator that lists only the entities present on the map (with counts), keeps the per-category outline toggle, and adds a "locate" (⌖) control per landmark that smart-focuses the camera and cycles through instances.

**Architecture:** `highlights.ts` holds the (expanded, split) category definitions as the single source of truth. A new pure module `mapIndex.ts` scans the game/front/tele physics layers once and returns the present categories + per-key jump positions. `viewer.ts` builds that index in its constructor and exposes `mapIndex()`, `jumpToNext(key)`, and `setEntitiesChangeListener()`. The Blade view renders the navigator from `mapIndex()` and drives jumps; SCSS makes the legend a scrollable vertical list.

**Tech Stack:** TypeScript (strict, `tsc` + ESLint + Prettier), Vitest, Bootstrap 5.3 SCSS compiled by Vite, Font Awesome 4.7, Blade.

Spec: `docs/superpowers/specs/2026-06-14-map-viewer-entity-legend-design.md`.

---

## File Structure

- `resources/assets/js/map/highlights.ts` — **modify**: split categories, add `jumpable` field, export `CHECKPOINT_FIRST`/`CHECKPOINT_LAST`.
- `resources/assets/js/map/mapIndex.ts` — **create**: `buildMapIndex(scene, gameGroupIndex)` → `{ entries, positions }`.
- `resources/assets/js/map/viewer.ts` — **modify**: build index in ctor; add `mapIndex()`, `jumpToNext()`, `setEntitiesChangeListener()`; notify entities listener in `setEntitiesOpacity`; remove `static highlightCategories()`.
- `resources/views/detail/map.blade.php` — **modify**: render navigator from `mapIndex()`, wire jumps + entities-slider sync.
- `resources/assets/sass/dark_admin_bootstrapius.scss` — **modify**: `.map-viewer` legend → scrollable vertical list; add `&__legend-child`, `&__legend-label`, `&__jump`, `&__jump-count`.
- `tests/js/map/mapIndex.test.ts` — **create**: unit tests for `buildMapIndex`.
- `tests/js/map/highlights.test.ts` — **modify**: update for the split category keys.

Notes for the implementer (toolset):
- Run all `npm`/`vendor/bin` commands from `/var/www/html` (inside the DDEV web container).
- TS is strict with `noUncheckedIndexedAccess`; index access needs a `!` or a guard (existing code uses `arr[0]!`).
- Single test file run: `npx vitest run tests/js/map/<file>.test.ts`.
- Tiles store their meaning in `tiles[i].id`. The game group's physics layers have `kind` `game`/`front`/`tele`. `id === 0` is air.

---

### Task 1: Expand highlight categories (split + jumpable)

**Files:**
- Modify: `resources/assets/js/map/highlights.ts:8-41`
- Test: `tests/js/map/highlights.test.ts:31-37`

- [ ] **Step 1: Update the existing test to the new keys (will fail to compile/run)**

In `tests/js/map/highlights.test.ts`, replace the first test (lines 31-37):

```ts
    it('outlines start tiles and nothing else', () => {
        const scene = sceneWith([33, 0], 2, 1); // [TILE_START, air]
        const v = buildHighlightVertices(scene, 0, cat('start'));
        // one tile × 4 border rects × 2 tris × 3 verts × 8 floats
        expect(v.length).toBe(4 * 2 * 3 * 8);
        expect(buildHighlightVertices(scene, 0, cat('freeze')).length).toBe(0);
    });
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `npx vitest run tests/js/map/highlights.test.ts`
Expected: FAIL — `cat('start')` returns `undefined` (no such category yet), so `.find(...)!` throws or the vertex count is wrong.

- [ ] **Step 3: Rewrite the category definitions**

In `resources/assets/js/map/highlights.ts`, replace lines 8-41 (the `ENTITY_OFFSET` const through the end of the `HIGHLIGHT_CATEGORIES` array) with:

```ts
const ENTITY_OFFSET = 255 - 16 * 4; // 191 (DDNet src/game/mapitems.h)

/** DDNet time-checkpoint tile ids; checkpoint number n is tile id CHECKPOINT_FIRST + (n - 1). */
export const CHECKPOINT_FIRST = 35;
export const CHECKPOINT_LAST = 59;

export interface HighlightCategory {
    key: string;
    label: string;
    /** Outline color, 0..1 RGB. */
    color: [number, number, number];
    ids: Set<number>;
    /** Point landmark you can jump to; false for area fills (Freeze) and the checkpoint parent. */
    jumpable: boolean;
}

const E = ENTITY_OFFSET;
const checkpointIds = new Set<number>(
    Array.from({ length: CHECKPOINT_LAST - CHECKPOINT_FIRST + 1 }, (_, i) => CHECKPOINT_FIRST + i),
);

export const HIGHLIGHT_CATEGORIES: readonly HighlightCategory[] = [
    { key: 'start', label: 'Start', color: [0.3, 1.0, 0.4], ids: new Set([33]), jumpable: true },
    { key: 'finish', label: 'Finish / Goal', color: [1.0, 0.85, 0.2], ids: new Set([34]), jumpable: true },
    // ENTITY_SPAWN, ENTITY_SPAWN_RED, ENTITY_SPAWN_BLUE
    { key: 'spawn', label: 'Spawn', color: [0.4, 1.0, 0.7], ids: new Set([E + 1, E + 2, E + 3]), jumpable: true },
    { key: 'shotgun', label: 'Shotgun', color: [1.0, 0.65, 0.15], ids: new Set([E + 8]), jumpable: true },
    { key: 'grenade', label: 'Grenade', color: [1.0, 0.45, 0.2], ids: new Set([E + 9]), jumpable: true },
    { key: 'laser', label: 'Laser', color: [0.7, 0.5, 1.0], ids: new Set([E + 11]), jumpable: true },
    { key: 'ninja', label: 'Ninja', color: [0.85, 0.95, 1.0], ids: new Set([E + 10]), jumpable: true },
    { key: 'health', label: 'Health', color: [1.0, 0.35, 0.4], ids: new Set([E + 7]), jumpable: true },
    { key: 'armor', label: 'Armor', color: [0.4, 0.6, 1.0], ids: new Set([E + 6]), jumpable: true },
    {
        key: 'teleport',
        label: 'Teleports',
        color: [0.4, 0.75, 1.0],
        // TELEINEVIL,TELEINWEAPON,TELEINHOOK,TELEIN,TELEOUT,TELECHECKOUT,TELECHECKIN,TELECHECKINEVIL
        ids: new Set([10, 14, 15, 26, 27, 30, 31, 63]),
        jumpable: true,
    },
    // Parent toggles all checkpoint outlines; per-number jumps live on its children (see mapIndex.ts).
    { key: 'checkpoints', label: 'Checkpoints', color: [0.8, 0.4, 1.0], ids: checkpointIds, jumpable: false },
    { key: 'freeze', label: 'Freeze', color: [0.55, 0.9, 1.0], ids: new Set([9, 11, 12, 13]), jumpable: false },
] as const;
```

Leave the `BORDER` constant, `pushRect`, and `buildHighlightVertices` (lines 43 onward) unchanged.

- [ ] **Step 4: Run the highlights test to confirm it passes**

Run: `npx vitest run tests/js/map/highlights.test.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Type-check**

Run: `npm run type-check`
Expected: no errors. (viewer.ts still compiles: `setHighlight` and the soon-to-be-removed `highlightCategories()` only read `key/label/color`, which still exist.)

- [ ] **Step 6: Commit**

```bash
git add resources/assets/js/map/highlights.ts tests/js/map/highlights.test.ts
git commit -m "feat(map-viewer): split highlight categories and tag jumpable landmarks"
```

---

### Task 2: Create the map index (pure, tested)

**Files:**
- Create: `resources/assets/js/map/mapIndex.ts`
- Test: `tests/js/map/mapIndex.test.ts`

- [ ] **Step 1: Write the failing tests**

Create `tests/js/map/mapIndex.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { buildMapIndex } from '../../../resources/assets/js/map/mapIndex';
import type { MapScene, TileLayer, Group, LayerKind } from '../../../resources/assets/js/map/types';

function layer(ids: number[], w: number, h: number, kind: LayerKind = 'game'): TileLayer {
    return {
        type: 'tilemap',
        kind,
        width: w,
        height: h,
        color: [255, 255, 255, 255],
        colorEnv: -1,
        colorEnvOffset: 0,
        image: -1,
        name: '',
        tiles: ids.map((id) => ({ id, flags: 0, skip: 0 })),
    };
}

function sceneOf(...layers: TileLayer[]): MapScene {
    const group: Group = {
        xOffset: 0, yOffset: 0, xParallax: 100, yParallax: 100,
        clipping: false, clipX: 0, clipY: 0, clipW: 0, clipH: 0, name: 'Game', layers,
    };
    return { info: { author: '', version: '', credits: '', license: '', settings: [] }, images: [], envelopes: [], groups: [group] };
}

describe('buildMapIndex', () => {
    it('lists only present categories, in HIGHLIGHT_CATEGORIES order, with counts', () => {
        // ids: spawn(192) appears twice, start(33) once, air(0). start is ordered before spawn.
        const { entries } = buildMapIndex(sceneOf(layer([33, 192, 0, 192], 2, 2)), 0);
        expect(entries.map((e) => e.key)).toEqual(['start', 'spawn']);
        expect(entries.find((e) => e.key === 'start')!.count).toBe(1);
        expect(entries.find((e) => e.key === 'spawn')!.count).toBe(2);
    });

    it('splits checkpoints into ascending numbered children with a summed parent count', () => {
        const { entries, positions } = buildMapIndex(sceneOf(layer([35, 37, 59], 3, 1)), 0);
        const cp = entries.find((e) => e.key === 'checkpoints')!;
        expect(cp.jumpable).toBe(false);
        expect(cp.toggleable).toBe(true);
        expect(cp.count).toBe(3);
        expect(cp.children!.map((c) => c.label)).toEqual(['Checkpoint 1', 'Checkpoint 3', 'Checkpoint 25']);
        expect(cp.children!.every((c) => c.jumpable && !c.toggleable && c.color === null)).toBe(true);
        expect(positions.get('cp:25')).toEqual([[2, 0]]);
    });

    it('marks freeze toggle-only and stores no jump positions for it', () => {
        const { entries, positions } = buildMapIndex(sceneOf(layer([9, 11], 2, 1)), 0);
        const freeze = entries.find((e) => e.key === 'freeze')!;
        expect(freeze.count).toBe(2);
        expect(freeze.jumpable).toBe(false);
        expect(positions.has('freeze')).toBe(false);
    });

    it('records jump positions in cell coordinates for jumpable categories', () => {
        // 3-wide row: shotgun(199) at x=0 and x=2.
        const { positions } = buildMapIndex(sceneOf(layer([199, 0, 199], 3, 1)), 0);
        expect(positions.get('shotgun')).toEqual([[0, 0], [2, 0]]);
    });

    it('reads teleports from the tele layer', () => {
        const { entries } = buildMapIndex(sceneOf(layer([26], 1, 1, 'tele')), 0);
        expect(entries.map((e) => e.key)).toContain('teleport');
    });

    it('returns an empty index when the game group is missing', () => {
        const empty = buildMapIndex({ info: { author: '', version: '', credits: '', license: '', settings: [] }, images: [], envelopes: [], groups: [] }, 0);
        expect(empty.entries).toEqual([]);
        expect(empty.positions.size).toBe(0);
    });
});
```

- [ ] **Step 2: Run to confirm it fails**

Run: `npx vitest run tests/js/map/mapIndex.test.ts`
Expected: FAIL — cannot resolve `./mapIndex` (module does not exist).

- [ ] **Step 3: Implement `mapIndex.ts`**

Create `resources/assets/js/map/mapIndex.ts`:

```ts
import type { MapScene, TileLayer } from './types';
import { HIGHLIGHT_CATEGORIES, CHECKPOINT_FIRST, CHECKPOINT_LAST } from './highlights';

/**
 * One legend row. Top-level rows mirror a HIGHLIGHT_CATEGORIES entry; checkpoint
 * children (key `cp:<n>`) are jump-only and carry no swatch/checkbox.
 */
export interface LegendEntry {
    key: string;
    label: string;
    color: [number, number, number] | null; // null = no swatch (checkpoint child)
    count: number;
    toggleable: boolean; // has an outline checkbox
    jumpable: boolean;
    children?: LegendEntry[]; // checkpoints only
}

export interface MapIndex {
    /** Lean UI descriptor (present categories only), ordered like HIGHLIGHT_CATEGORIES. */
    entries: LegendEntry[];
    /** Jump key (incl. `cp:<n>`) -> instance cells, in scan order. */
    positions: Map<string, [number, number][]>;
}

// Physics layers whose tiles[i].id carries a meaningful physics/entity id.
const SCANNED_KINDS = new Set(['game', 'front', 'tele']);

/**
 * Scan the game group's physics layers once and report which highlight categories are
 * present (with counts) plus the cells to jump to. Checkpoints split per number; Freeze
 * is counted but not enumerated (it can cover thousands of cells and isn't a landmark).
 */
export function buildMapIndex(scene: MapScene, gameGroupIndex: number): MapIndex {
    const entries: LegendEntry[] = [];
    const positions = new Map<string, [number, number][]>();
    const group = scene.groups[gameGroupIndex];
    if (!group) return { entries, positions };

    const idToKey = new Map<number, string>();
    const jumpable = new Set<string>();
    for (const cat of HIGHLIGHT_CATEGORIES) {
        if (cat.jumpable) jumpable.add(cat.key);
        if (cat.key === 'checkpoints') continue; // checkpoints are matched by range below
        for (const id of cat.ids) idToKey.set(id, cat.key);
    }

    const counts = new Map<string, number>();
    const cpPositions = new Map<number, [number, number][]>();
    let cpCount = 0;
    const pushPos = (key: string, x: number, y: number): void => {
        let arr = positions.get(key);
        if (!arr) {
            arr = [];
            positions.set(key, arr);
        }
        arr.push([x, y]);
    };

    for (const layer of group.layers) {
        if (layer.type !== 'tilemap' || !SCANNED_KINDS.has(layer.kind)) continue;
        const tl: TileLayer = layer;
        for (let y = 0; y < tl.height; y++) {
            for (let x = 0; x < tl.width; x++) {
                const id = tl.tiles[y * tl.width + x]?.id ?? 0;
                if (id === 0) continue;
                if (id >= CHECKPOINT_FIRST && id <= CHECKPOINT_LAST) {
                    const n = id - CHECKPOINT_FIRST + 1;
                    cpCount++;
                    let arr = cpPositions.get(n);
                    if (!arr) {
                        arr = [];
                        cpPositions.set(n, arr);
                    }
                    arr.push([x, y]);
                    continue;
                }
                const key = idToKey.get(id);
                if (!key) continue;
                counts.set(key, (counts.get(key) ?? 0) + 1);
                if (jumpable.has(key)) pushPos(key, x, y);
            }
        }
    }

    for (const cat of HIGHLIGHT_CATEGORIES) {
        if (cat.key === 'checkpoints') {
            if (cpCount === 0) continue;
            const numbers = Array.from(cpPositions.keys()).sort((a, b) => a - b);
            const children: LegendEntry[] = numbers.map((n) => {
                const cells = cpPositions.get(n)!;
                positions.set(`cp:${n}`, cells);
                return {
                    key: `cp:${n}`,
                    label: `Checkpoint ${n}`,
                    color: null,
                    count: cells.length,
                    toggleable: false,
                    jumpable: true,
                };
            });
            entries.push({
                key: 'checkpoints',
                label: cat.label,
                color: cat.color,
                count: cpCount,
                toggleable: true,
                jumpable: false,
                children,
            });
            continue;
        }
        const count = counts.get(cat.key) ?? 0;
        if (count === 0) continue;
        entries.push({
            key: cat.key,
            label: cat.label,
            color: cat.color,
            count,
            toggleable: true,
            jumpable: cat.jumpable,
        });
    }

    return { entries, positions };
}
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `npx vitest run tests/js/map/mapIndex.test.ts`
Expected: PASS (6 tests).

- [ ] **Step 5: Type-check + lint the new module**

Run: `npm run type-check && npx eslint resources/assets/js/map/mapIndex.ts tests/js/map/mapIndex.test.ts`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add resources/assets/js/map/mapIndex.ts tests/js/map/mapIndex.test.ts
git commit -m "feat(map-viewer): scan present entities into a jump-navigable map index"
```

---

### Task 3: Wire the index + jump API into the viewer

**Files:**
- Modify: `resources/assets/js/map/viewer.ts` (imports ~L15-17; constants ~L31; fields ~L229-235; ctor ~L275-276; methods ~L376-397)

- [ ] **Step 1: Add the imports**

In `resources/assets/js/map/viewer.ts`, just after line 17 (`import { HIGHLIGHT_CATEGORIES, buildHighlightVertices } from './highlights';`), add:

```ts
import { buildMapIndex } from './mapIndex';
import type { LegendEntry } from './mapIndex';
```

- [ ] **Step 2: Add the smart-focus constants**

Immediately after `const NATIVE_ZOOM = 64;` (line 31), add:

```ts
// Smart-focus floors used by jumpToNext: zoom in to at least FOCUS_ZOOM px/tile when more
// zoomed out, and raise the entities overlay to ENTITIES_MIN so the jumped-to sprite shows.
const FOCUS_ZOOM = 24;
const ENTITIES_MIN = 0.6;
```

- [ ] **Step 3: Add the fields**

Replace the field declaration line `private zoomListener: ((fraction: number) => void) | null = null;` (line 235) with:

```ts
    private zoomListener: ((fraction: number) => void) | null = null;
    private entitiesListener: ((opacity: number) => void) | null = null;

    // Legend / jump navigator (built once in the ctor from buildMapIndex).
    private readonly legendEntries: LegendEntry[];
    private readonly jumpPositions: Map<string, [number, number][]>;
    private readonly jumpCursors = new Map<string, number>();
```

- [ ] **Step 4: Build the index in the constructor**

Replace the line `this.buildTeleIndex();` (line 276) with:

```ts
        this.buildTeleIndex();

        const mapIndex = buildMapIndex(p.scene, p.gameGroupIndex);
        this.legendEntries = mapIndex.entries;
        this.jumpPositions = mapIndex.positions;
```

- [ ] **Step 5: Make `setEntitiesOpacity` notify the listener**

Replace the body of `setEntitiesOpacity` (lines 377-380) with:

```ts
    setEntitiesOpacity(opacity: number): void {
        this.entitiesOpacity = Math.max(0, Math.min(1, opacity));
        this.entitiesListener?.(this.entitiesOpacity);
        this.requestRender();
    }
```

- [ ] **Step 6: Fix the stale JSDoc on `setHighlight`**

Replace line 382 (`    /** Toggle a highlight category (see {@link MapViewer.highlightCategories}). */`) with:

```ts
    /** Toggle a highlight category's outline (see {@link MapViewer.mapIndex}). */
```

- [ ] **Step 7: Replace `highlightCategories()` with the navigator API**

Replace the `static highlightCategories()` method (lines 394-397) with:

```ts
    /** Present-on-this-map legend rows (no positions) for building the navigator UI. */
    mapIndex(): LegendEntry[] {
        return this.legendEntries;
    }

    /**
     * Smart-focus the next instance of `key`, cycling on repeat calls: enable the category
     * outline, raise the entities overlay and zoom to readable floors, then recenter + pulse.
     * `key` is a legend key (incl. checkpoint children `cp:<n>`). Returns null when empty.
     */
    jumpToNext(key: string): { index: number; total: number; highlightKey: string } | null {
        const positions = this.jumpPositions.get(key);
        if (!positions || positions.length === 0) return null;
        const cursor = ((this.jumpCursors.get(key) ?? -1) + 1) % positions.length;
        this.jumpCursors.set(key, cursor);
        const [x, y] = positions[cursor]!;
        const highlightKey = key.startsWith('cp:') ? 'checkpoints' : key;
        this.setHighlight(highlightKey, true);
        if (this.entitiesOpacity < ENTITIES_MIN) this.setEntitiesOpacity(ENTITIES_MIN);
        if (this.camera.zoom < FOCUS_ZOOM) {
            this.camera.zoom = FOCUS_ZOOM;
            this.notifyZoom();
        }
        this.camera.center = [x + 0.5, y + 0.5];
        this.startPulse(x, y);
        return { index: cursor, total: positions.length, highlightKey };
    }

    /** Register a listener invoked immediately, then on every entities-opacity change (0..1). */
    setEntitiesChangeListener(cb: (opacity: number) => void): void {
        this.entitiesListener = cb;
        cb(this.entitiesOpacity);
    }
```

- [ ] **Step 8: Type-check + lint + run the JS suite**

Run: `npm run type-check && npx eslint resources/assets/js/map/viewer.ts && npm test`
Expected: no type/lint errors; all Vitest tests pass. (No test references `highlightCategories()` — confirmed by grep; the only remaining caller is the Blade view, fixed in Task 4.)

- [ ] **Step 9: Commit**

```bash
git add resources/assets/js/map/viewer.ts
git commit -m "feat(map-viewer): expose mapIndex() and smart-focus jumpToNext() on the viewer"
```

---

### Task 4: Render the navigator in the Blade view

**Files:**
- Modify: `resources/views/detail/map.blade.php:76-100`

- [ ] **Step 1: Replace the entities handler + legend builder**

In `resources/views/detail/map.blade.php`, replace lines 76-100 (from `if (ent) ...` through the end of the `if (legend && ...)` block, i.e. up to and including the line `}` that closes that block at line 100) with:

```php
                if (ent) {
                    ent.addEventListener('input', function () {
                        viewer.setEntitiesOpacity(Number(ent.value) / 100);
                    });
                    // Keep the slider in sync when a jump auto-raises the entities overlay.
                    viewer.setEntitiesChangeListener(function (op) {
                        ent.value = String(Math.round(op * 100));
                    });
                }

                // Build the legend/navigator from the entities actually present on this map.
                var legend = document.getElementById('mapLegend');
                if (legend && viewer.mapIndex) {
                    var entries = viewer.mapIndex();
                    if (!entries.length) {
                        legend.style.display = 'none';
                    } else {
                        var checkboxes = {}; // highlight key -> checkbox, for jump-driven sync
                        var buildRow = function (entry, depth) {
                            var row = document.createElement('div');
                            row.className = 'map-viewer__legend-item' + (depth ? ' map-viewer__legend-child' : '');
                            if (entry.toggleable) {
                                var cb = document.createElement('input');
                                cb.type = 'checkbox';
                                cb.addEventListener('change', function () { viewer.setHighlight(entry.key, cb.checked); });
                                checkboxes[entry.key] = cb;
                                row.appendChild(cb);
                            }
                            if (entry.color) {
                                var swatch = document.createElement('span');
                                swatch.className = 'map-viewer__swatch';
                                swatch.style.background = 'rgb(' +
                                    Math.round(entry.color[0] * 255) + ',' +
                                    Math.round(entry.color[1] * 255) + ',' +
                                    Math.round(entry.color[2] * 255) + ')';
                                row.appendChild(swatch);
                            }
                            var label = document.createElement('span');
                            label.className = 'map-viewer__legend-label';
                            label.textContent = entry.label + ' (' + entry.count + ')';
                            row.appendChild(label);
                            if (entry.jumpable) {
                                var jump = document.createElement('button');
                                jump.type = 'button';
                                jump.className = 'map-viewer__jump';
                                jump.title = 'Jump to ' + entry.label + ' (click again for the next one)';
                                jump.innerHTML = '<i class="fa fa-crosshairs" aria-hidden="true"></i>';
                                var counter = document.createElement('span');
                                counter.className = 'map-viewer__jump-count';
                                jump.addEventListener('click', function () {
                                    var res = viewer.jumpToNext(entry.key);
                                    if (!res) return;
                                    counter.textContent = (res.index + 1) + '/' + res.total;
                                    if (checkboxes[res.highlightKey]) checkboxes[res.highlightKey].checked = true;
                                });
                                row.appendChild(jump);
                                row.appendChild(counter);
                            }
                            return row;
                        };
                        entries.forEach(function (entry) {
                            legend.appendChild(buildRow(entry, 0));
                            if (entry.children) {
                                entry.children.forEach(function (child) { legend.appendChild(buildRow(child, 1)); });
                            }
                        });
                    }
                }
```

- [ ] **Step 2: Verify no stale references remain**

Run: `grep -n "highlightCategories" resources/views/detail/map.blade.php`
Expected: no output (the old caller is gone).

- [ ] **Step 3: Build assets**

Run: `npm run build`
Expected: build succeeds (Vite bundles `app.ts`, which exports `window.MapViewer`).

- [ ] **Step 4: Commit**

```bash
git add resources/views/detail/map.blade.php
git commit -m "feat(map-viewer): render present-entity navigator with cycle-to-jump controls"
```

---

### Task 5: Style the navigator

**Files:**
- Modify: `resources/assets/sass/dark_admin_bootstrapius.scss:3325-3346`

- [ ] **Step 1: Make the legend a scrollable vertical list**

Replace the `&__legend` and `&__legend-item` blocks (lines 3325-3339):

```scss
    &__legend {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    &__legend-item {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        margin: 0;
        user-select: none;
        cursor: pointer;
        font-size: 0.85rem;
    }
```

with:

```scss
    &__legend {
        // Vertical rows that wrap into columns and scroll once the list gets tall
        // (entity-rich maps can list 25 checkpoints) instead of pushing the page down.
        display: flex;
        flex-flow: column wrap;
        align-content: flex-start;
        gap: 0.15rem 1.25rem;
        max-height: 16rem;
        overflow-y: auto;
        flex-basis: 100%;
    }
    &__legend-item {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        margin: 0;
        user-select: none;
        font-size: 0.85rem;
    }
    &__legend-child {
        padding-left: 1.35rem; // indent checkpoint children under the Checkpoints row
    }
    &__legend-label {
        white-space: nowrap;
    }
    &__jump {
        appearance: none;
        border: 0;
        padding: 0 0.2rem;
        background: transparent;
        color: inherit;
        line-height: 1;
        cursor: pointer;
        opacity: 0.75;

        &:hover {
            opacity: 1;
        }
    }
    &__jump-count {
        min-width: 1.8rem;
        font-size: 0.72rem;
        color: rgba(255, 255, 255, 0.6);
    }
```

(The `&__swatch` and `&__hint` blocks that follow stay unchanged.)

- [ ] **Step 2: Rebuild assets and clear the response cache**

Run: `npm run build && php artisan responsecache:clear`
Expected: build succeeds; cache cleared (the map detail route isn't response-cached, but clearing is harmless and keeps the bundle hash fresh for any cached page).

- [ ] **Step 3: Commit**

```bash
git add resources/assets/sass/dark_admin_bootstrapius.scss
git commit -m "style(map-viewer): scrollable vertical legend with jump buttons and counts"
```

---

### Task 6: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the whole front-end gate**

Run: `npm run type-check && npm run lint && npm test && npm run build`
Expected: all green (CI runs type-check + lint before build, so all three must pass).

- [ ] **Step 2: Clear the response cache**

Run: `php artisan responsecache:clear`
Expected: success.

- [ ] **Step 3: Manual check with Playwright**

Open a map detail page that has a downloadable file (e.g. find one via `/maps`, then navigate to `/map/<name>`). Confirm:
- the legend lists only entities present, each with a `(count)`;
- toggling a checkbox draws/removes that category's outline;
- clicking ⌖ recenters + pulses the instance, raises the Entities slider if it was at 0, zooms in if it was fully zoomed out, and ticks that row's checkbox; clicking again advances the `n/total` counter and moves to the next instance;
- checkpoints appear as an indented, numbered sub-group under a "Checkpoints" toggle row;
- the legend scrolls (doesn't push the page) on an entity-rich map.

Take a screenshot for the record.

- [ ] **Step 4: Final confirmation**

Confirm the branch `feat/map-viewer-entity-legend` contains the spec, plan, and the six feature commits, and that `git status` is clean.

---

## Self-Review

**1. Spec coverage**
- Goal (present-only legend + counts + toggle + jump-cycle) → Tasks 2, 4.
- Category set / split / jumpable → Task 1. Checkpoints split + Freeze toggle-only → Tasks 1, 2.
- Smart focus (outline on, entities floor, zoom floor, recenter, pulse, cycle) → Task 3 `jumpToNext`.
- Entities-slider sync → Task 3 (`setEntitiesOpacity` notifies) + Task 4 (`setEntitiesChangeListener`).
- Layout A (scrollable vertical list, indented checkpoint children) → Task 5.
- Empty index hides the legend; missing game group guarded → Task 2 (guard) + Task 4 (hide).
- Tests for `buildMapIndex` + updated highlights test → Tasks 1, 2.
- Verification (type-check/lint/test/build, responsecache clear, manual) → Task 6.
- Removal of `highlightCategories()` and stale JSDoc → Task 3.

**2. Placeholder scan:** no TBD/TODO; every code step shows full code; commands have expected output.

**3. Type consistency:** `LegendEntry` and `MapIndex` defined in Task 2 are imported and used unchanged in Task 3. `jumpToNext` returns `{ index, total, highlightKey }`, consumed exactly in Task 4. `buildMapIndex(scene, gameGroupIndex)` signature matches its call site in the ctor. `CHECKPOINT_FIRST`/`CHECKPOINT_LAST` exported in Task 1, imported in Task 2. Category keys (`start`, `finish`, `spawn`, `shotgun`, `grenade`, `laser`, `ninja`, `health`, `armor`, `teleport`, `checkpoints`, `freeze`) are consistent across highlights, mapIndex, and the test assertions.
