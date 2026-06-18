# Map-version physics diff — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user pick two versions of a map and see what changed in the playable (physics) geometry — a colour-coded cell overlay on the existing viewer plus a structured breakdown.

**Architecture:** Entirely client-side diff reusing the existing `.map` parser and WebGL viewer. A new pure `diffPhysics(base, target)` module compares the six physics tile layers; the `Overlay` paints changed cells on its existing 2D canvas; a `MapCompare` orchestrator fetches/parses both versions and drives it. Compare is launched by **navigating** to `?version=<B>&compare=<A>` (the viewer can't swap maps in place), so the page reloads showing B and then overlays the diff against A. The only server-side change is reading `?compare=` in the existing `searchMapByName` action.

**Tech Stack:** PHP 8.3 / Laravel 13 (Blade, Eloquent), TypeScript (strict, no jQuery), Vite, vitest (node env), PHPUnit (SQLite `:memory:`).

## Global Constraints

- PHP 8.3+, Laravel 13. Runs inside the DDEV web container — use bare `php artisan`, `composer`, `npm`.
- Front-end bundle is **strict TypeScript, no jQuery**. CI (`.github/workflows/theme.yml`) runs `npm run type-check` + `npm run lint` before the build — both MUST stay green.
- Rebuild assets after any SCSS/JS change: `npm run build`. After a rebuild, run `php artisan responsecache:clear` before manually testing response-cached pages.
- The map detail view reaches the bundle only through `window.*` globals (the inline-script seam) declared in `resources/assets/js/global.d.ts` — keep those in sync.
- Tests: Feature tests use `RefreshDatabase` + factories. `Map` is created with `Map::create([...])` (it guards only `id`/timestamps, so `name`/`sha256`/`crc` are mass-assignable). `MapVersion::factory()` exists; `acquisition_status` defaults to `'pending'`.
- vitest runs in the **node** environment (no DOM/canvas) — only pure logic is unit-tested; DOM/canvas code is verified by type-check + a manual run.
- Comments explain **why** the current code exists (present tense), not what changed. Working docs in English.

---

### Task 1: `diffPhysics` + `formatVerdict` pure module

The heart of the feature: a pure function comparing the physics layers of two parsed maps, plus a one-line verdict formatter. No DOM, no WebGL — fully unit-testable.

**Files:**
- Create: `resources/assets/js/map/diff.ts`
- Create: `tests/js/map/diff.test.ts`

**Interfaces:**
- Consumes: `MapScene`, `TileLayer`, `LayerKind`, `PhysicsMeta` from `./types`.
- Produces (relied on by Tasks 2 & 3):
  - `type PhysicsKind = Exclude<LayerKind, 'tiles'>`
  - `const PHYSICS_KINDS: readonly PhysicsKind[]`
  - `type DiffCategory = 'added' | 'removed' | 'modified'`
  - `const DIFF_COLORS: Record<DiffCategory, string>`
  - `interface DiffCell { x: number; y: number; kind: PhysicsKind; category: DiffCategory }`
  - `interface LayerDiff { kind: PhysicsKind; inA: boolean; inB: boolean; added: number; removed: number; modified: number }`
  - `interface PhysicsDiff { aligned: boolean; gameDims: { a: [number, number] | null; b: [number, number] | null }; layers: LayerDiff[]; totals: { added: number; removed: number; modified: number }; cells: DiffCell[] }`
  - `function diffPhysics(base: MapScene, target: MapScene): PhysicsDiff`
  - `function formatVerdict(diff: PhysicsDiff): string`

- [ ] **Step 1: Write the failing tests**

Create `tests/js/map/diff.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { diffPhysics, formatVerdict } from '../../../resources/assets/js/map/diff';
import type { MapScene, TileLayer, Group, LayerKind } from '../../../resources/assets/js/map/types';

// Build a tile layer; flags/number arrays are parallel to ids when supplied.
function layer(
    ids: number[],
    w: number,
    h: number,
    kind: LayerKind = 'game',
    opts: { flags?: number[]; number?: number[] } = {},
): TileLayer {
    const tl: TileLayer = {
        type: 'tilemap',
        kind,
        width: w,
        height: h,
        color: [255, 255, 255, 255],
        colorEnv: -1,
        colorEnvOffset: 0,
        image: -1,
        name: '',
        tiles: ids.map((id, i) => ({ id, flags: opts.flags?.[i] ?? 0, skip: 0 })),
    };
    if (opts.number) tl.physics = { number: Uint8Array.from(opts.number) };
    return tl;
}

function sceneOf(...layers: TileLayer[]): MapScene {
    const group: Group = {
        xOffset: 0, yOffset: 0, xParallax: 100, yParallax: 100,
        clipping: false, clipX: 0, clipY: 0, clipW: 0, clipH: 0, name: 'Game', layers,
    };
    return { info: { author: '', version: '', credits: '', license: '', settings: [] }, images: [], envelopes: [], groups: [group] };
}

describe('diffPhysics', () => {
    it('reports zero changes for identical scenes', () => {
        const d = diffPhysics(sceneOf(layer([1, 0, 1, 0], 2, 2)), sceneOf(layer([1, 0, 1, 0], 2, 2)));
        expect(d.aligned).toBe(true);
        expect(d.totals).toEqual({ added: 0, removed: 0, modified: 0 });
        expect(d.cells).toEqual([]);
    });

    it('classifies added and modified game cells', () => {
        // base [1,1,0,0] → target [1,2,1,0]: i1 1→2 modified, i2 0→1 added.
        const d = diffPhysics(sceneOf(layer([1, 1, 0, 0], 2, 2)), sceneOf(layer([1, 2, 1, 0], 2, 2)));
        expect(d.totals).toEqual({ added: 1, removed: 0, modified: 1 });
        expect(d.cells).toContainEqual({ x: 0, y: 1, kind: 'game', category: 'added' });
        expect(d.cells).toContainEqual({ x: 1, y: 0, kind: 'game', category: 'modified' });
    });

    it('treats a solid→air game cell as removed', () => {
        const d = diffPhysics(sceneOf(layer([1], 1, 1)), sceneOf(layer([0], 1, 1)));
        expect(d.totals).toEqual({ added: 0, removed: 1, modified: 0 });
        expect(d.cells).toEqual([{ x: 0, y: 0, kind: 'game', category: 'removed' }]);
    });

    it('treats a flipped game tile (same id, different flags) as modified', () => {
        const a = sceneOf(layer([1], 1, 1, 'game', { flags: [0] }));
        const b = sceneOf(layer([1], 1, 1, 'game', { flags: [1] }));
        expect(diffPhysics(a, b).totals.modified).toBe(1);
    });

    it('treats a re-numbered tele cell (same id, different number) as modified', () => {
        const game = layer([1], 1, 1, 'game'); // shared so dims align
        const a = sceneOf(game, layer([26], 1, 1, 'tele', { number: [5] }));
        const b = sceneOf(game, layer([26], 1, 1, 'tele', { number: [7] }));
        const d = diffPhysics(a, b);
        expect(d.aligned).toBe(true);
        expect(d.layers.find((l) => l.kind === 'tele')!.modified).toBe(1);
        expect(d.cells).toContainEqual({ x: 0, y: 0, kind: 'tele', category: 'modified' });
    });

    it('reports a physics kind present on only one side structurally, with no cells', () => {
        const a = sceneOf(layer([1], 1, 1, 'game'));
        const b = sceneOf(layer([1], 1, 1, 'game'), layer([0], 1, 1, 'front'));
        const d = diffPhysics(a, b);
        const front = d.layers.find((l) => l.kind === 'front')!;
        expect(front.inA).toBe(false);
        expect(front.inB).toBe(true);
        expect(d.cells.every((c) => c.kind !== 'front')).toBe(true);
    });

    it('is not aligned and emits no cells when game dims differ', () => {
        const d = diffPhysics(sceneOf(layer([1, 1], 2, 1)), sceneOf(layer([1, 1, 1, 1], 2, 2)));
        expect(d.aligned).toBe(false);
        expect(d.cells).toEqual([]);
        expect(d.gameDims).toEqual({ a: [2, 1], b: [2, 2] });
    });

    it('ignores design (tiles) layers entirely', () => {
        const a = sceneOf(layer([1], 1, 1, 'game'), layer([5], 1, 1, 'tiles'));
        const b = sceneOf(layer([1], 1, 1, 'game'), layer([9], 1, 1, 'tiles'));
        expect(diffPhysics(a, b).totals).toEqual({ added: 0, removed: 0, modified: 0 });
    });
});

describe('formatVerdict', () => {
    it('summarises an aligned diff with counts and changed kinds', () => {
        const d = diffPhysics(sceneOf(layer([1, 1, 0, 0], 2, 2)), sceneOf(layer([1, 2, 1, 0], 2, 2)));
        expect(formatVerdict(d)).toBe('Same dimensions — 2 physics cells changed: +1 / -0 / ~1 across game.');
    });
    it('reports identical layers', () => {
        const d = diffPhysics(sceneOf(layer([1], 1, 1)), sceneOf(layer([1], 1, 1)));
        expect(formatVerdict(d)).toBe('Identical physics layers — no cell changed.');
    });
    it('reports a dimension mismatch as a likely different map', () => {
        const d = diffPhysics(sceneOf(layer([1, 1], 2, 1)), sceneOf(layer([1, 1, 1, 1], 2, 2)));
        expect(formatVerdict(d)).toBe('Different dimensions 2×1 → 2×2 — likely a different map.');
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm test -- diff`
Expected: FAIL — `Failed to resolve import ".../map/diff"` (module does not exist yet).

- [ ] **Step 3: Write the implementation**

Create `resources/assets/js/map/diff.ts`:

```ts
import type { MapScene, TileLayer, LayerKind, PhysicsMeta } from './types';

/** The gameplay-bearing tile layers — everything except plain design ('tiles') layers. */
export type PhysicsKind = Exclude<LayerKind, 'tiles'>;
export const PHYSICS_KINDS: readonly PhysicsKind[] = ['game', 'front', 'tele', 'speedup', 'switch', 'tune'];

export type DiffCategory = 'added' | 'removed' | 'modified';

/** One colour per change category, shared by the canvas overlay and the panel legend (single source). */
export const DIFF_COLORS: Record<DiffCategory, string> = {
    added: '#3fb950',
    removed: '#f85149',
    modified: '#d29922',
};

export interface DiffCell {
    x: number;
    y: number;
    kind: PhysicsKind;
    category: DiffCategory;
}

export interface LayerDiff {
    kind: PhysicsKind;
    inA: boolean;
    inB: boolean;
    added: number;
    removed: number;
    modified: number;
}

export interface PhysicsDiff {
    aligned: boolean; // false ⇒ game-layer dims differ or are missing ⇒ no per-cell diff
    gameDims: { a: [number, number] | null; b: [number, number] | null };
    layers: LayerDiff[];
    totals: { added: number; removed: number; modified: number };
    cells: DiffCell[];
}

/** First tile layer of a physics kind in any group — physics layers are singletons by .map convention. */
function findPhysics(scene: MapScene, kind: PhysicsKind): TileLayer | null {
    for (const group of scene.groups) {
        for (const layer of group.layers) {
            if (layer.type === 'tilemap' && layer.kind === kind) return layer;
        }
    }
    return null;
}

function dims(layer: TileLayer | null): [number, number] | null {
    return layer ? [layer.width, layer.height] : null;
}

function physVal(layer: TileLayer, field: keyof PhysicsMeta, i: number): number {
    return layer.physics?.[field]?.[i] ?? 0;
}

/**
 * Whether two cells at the same index differ in a way that matters for this kind. The base graphic is
 * tiles[i].id for every kind; the behaviour-bearing metadata varies (tele/tune number, switch
 * number+delay, speedup force+maxSpeed+angle), so a re-numbered teleport counts even when its id holds.
 */
function cellsDiffer(kind: PhysicsKind, a: TileLayer, b: TileLayer, i: number): boolean {
    if ((a.tiles[i]?.id ?? 0) !== (b.tiles[i]?.id ?? 0)) return true;
    if (kind === 'game' || kind === 'front') return (a.tiles[i]?.flags ?? 0) !== (b.tiles[i]?.flags ?? 0);
    if (kind === 'switch') {
        return (a.tiles[i]?.flags ?? 0) !== (b.tiles[i]?.flags ?? 0)
            || physVal(a, 'number', i) !== physVal(b, 'number', i)
            || physVal(a, 'delay', i) !== physVal(b, 'delay', i);
    }
    if (kind === 'speedup') {
        return physVal(a, 'force', i) !== physVal(b, 'force', i)
            || physVal(a, 'maxSpeed', i) !== physVal(b, 'maxSpeed', i)
            || physVal(a, 'angle', i) !== physVal(b, 'angle', i);
    }
    // tele, tune: keyed on the tele/tune number.
    return physVal(a, 'number', i) !== physVal(b, 'number', i);
}

/**
 * Diff the playable geometry of two parsed maps. Per-cell changes are produced only when both game
 * layers share the same dimensions ("aligned"); otherwise the result is structural only (per-kind
 * presence + dims), reflecting that differently-sized maps under one name are a different map, not an edit.
 */
export function diffPhysics(base: MapScene, target: MapScene): PhysicsDiff {
    const gameA = findPhysics(base, 'game');
    const gameB = findPhysics(target, 'game');
    const aligned = gameA !== null && gameB !== null && gameA.width === gameB.width && gameA.height === gameB.height;

    const layers: LayerDiff[] = [];
    const cells: DiffCell[] = [];
    const totals = { added: 0, removed: 0, modified: 0 };

    for (const kind of PHYSICS_KINDS) {
        const la = findPhysics(base, kind);
        const lb = findPhysics(target, kind);
        const entry: LayerDiff = { kind, inA: la !== null, inB: lb !== null, added: 0, removed: 0, modified: 0 };

        if (aligned && la && lb && la.width === lb.width && la.height === lb.height) {
            const n = la.width * la.height;
            for (let i = 0; i < n; i++) {
                const emptyA = (la.tiles[i]?.id ?? 0) === 0;
                const emptyB = (lb.tiles[i]?.id ?? 0) === 0;
                let category: DiffCategory | null = null;
                if (emptyA && !emptyB) category = 'added';
                else if (!emptyA && emptyB) category = 'removed';
                else if (!emptyA && !emptyB && cellsDiffer(kind, la, lb, i)) category = 'modified';
                if (category) {
                    entry[category]++;
                    totals[category]++;
                    cells.push({ x: i % la.width, y: Math.floor(i / la.width), kind, category });
                }
            }
        }
        layers.push(entry);
    }

    return { aligned, gameDims: { a: dims(gameA), b: dims(gameB) }, layers, totals, cells };
}

/** One-line human verdict for the panel header. */
export function formatVerdict(diff: PhysicsDiff): string {
    if (!diff.aligned) {
        const fmt = (d: [number, number] | null): string => (d ? `${d[0]}×${d[1]}` : '—');
        return `Different dimensions ${fmt(diff.gameDims.a)} → ${fmt(diff.gameDims.b)} — likely a different map.`;
    }
    const { added, removed, modified } = diff.totals;
    const total = added + removed + modified;
    if (total === 0) return 'Identical physics layers — no cell changed.';
    const kinds = diff.layers.filter((l) => l.added + l.removed + l.modified > 0).map((l) => l.kind);
    return `Same dimensions — ${total} physics cell${total === 1 ? '' : 's'} changed: ` +
        `+${added} / -${removed} / ~${modified} across ${kinds.join(', ')}.`;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm test -- diff`
Expected: PASS — all `diffPhysics` and `formatVerdict` cases green.

- [ ] **Step 5: Type-check and lint**

Run: `npm run type-check && npm run lint`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add resources/assets/js/map/diff.ts tests/js/map/diff.test.ts
git commit -m "feat(map-diff): pure physics-layer diff + verdict formatter"
```

---

### Task 2: Paint diff cells on the viewer's overlay

Wire the diff result into rendering: the `Overlay` gains a diff-cell pass on its existing 2D canvas, and `MapViewer` gains a `setDiffOverlay` setter that stores the cells and triggers a render. Canvas painting isn't unit-tested (node env has no canvas, consistent with the rest of `overlay.ts`); correctness is covered by type-check, the unchanged existing suite, and the manual run in Task 5.

**Files:**
- Modify: `resources/assets/js/map/overlay.ts`
- Modify: `resources/assets/js/map/viewer.ts`

**Interfaces:**
- Consumes: `DiffCell`, `DIFF_COLORS` from `./diff` (Task 1).
- Produces (relied on by Task 3): `MapViewer.setDiffOverlay(cells: DiffCell[]): void`.

- [ ] **Step 1: Extend `Overlay` to draw diff cells**

In `resources/assets/js/map/overlay.ts`, add the import near the top (after the existing imports):

```ts
import { DIFF_COLORS, type DiffCell } from './diff';
```

Add `diffCells` as the final parameter of `Overlay.draw(...)` and draw them right after the canvas is cleared (under the annotations/pulse). Replace the existing `draw(...)` signature line and its body opening:

```ts
    draw(
        camera: Camera,
        annotations: Annotation[],
        parallaxX: number,
        parallaxY: number,
        entitiesOpacity: number,
        pulse: PulseTarget | null,
        nowMs: number,
        diffCells: DiffCell[],
    ): void {
        const ctx = this.ctx;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0); // draw in CSS px, crisp on hi-DPI

        if (diffCells.length > 0) this.drawDiffCells(camera, diffCells, parallaxX, parallaxY);
        if (entitiesOpacity > 0 && camera.zoom >= ARROW_MIN_ZOOM && annotations.length > 0) {
            this.drawAnnotations(camera, annotations, parallaxX, parallaxY);
        }
        if (pulse) this.drawPulse(camera, parallaxX, parallaxY, pulse, nowMs);
    }

    /** Colour-coded fills + outlines for changed physics cells (version comparison overlay). */
    private drawDiffCells(camera: Camera, cells: DiffCell[], px: number, py: number): void {
        const ctx = this.ctx;
        ctx.lineWidth = Math.max(1, camera.zoom * 0.08);
        for (const c of cells) {
            const [x0, y0] = camera.worldToScreen(c.x, c.y, px, py);
            const [x1, y1] = camera.worldToScreen(c.x + 1, c.y + 1, px, py);
            if (x1 < 0 || y1 < 0 || x0 > this.cssW || y0 > this.cssH) continue; // cull offscreen
            const color = DIFF_COLORS[c.category];
            ctx.globalAlpha = 0.45;
            ctx.fillStyle = color;
            ctx.fillRect(x0, y0, x1 - x0, y1 - y0);
            ctx.globalAlpha = 1;
            ctx.strokeStyle = color;
            ctx.strokeRect(x0, y0, x1 - x0, y1 - y0);
        }
    }
```

- [ ] **Step 2: Add the diff-cell state + setter to `MapViewer`**

In `resources/assets/js/map/viewer.ts`, add to the import that already pulls map types (or as a new line near the top imports):

```ts
import type { DiffCell } from './diff';
```

Add a field alongside the other inspection state (near `private pulse: PulseTarget | null = null;`):

```ts
    private diffCells: DiffCell[] = [];
```

Add the public setter next to `setEntitiesOpacity` / `setHighlight`:

```ts
    /** Paint a set of changed physics cells over the map (version comparison); pass [] to clear. */
    setDiffOverlay(cells: DiffCell[]): void {
        this.diffCells = cells;
        this.requestRender();
    }
```

- [ ] **Step 3: Pass the cells through on every render**

In `resources/assets/js/map/viewer.ts`, update the single `this.overlay.draw(...)` call in `render(...)` to pass the cells as the new final argument:

```ts
        this.overlay.draw(
            this.camera,
            this.annotations,
            gameGroup?.xParallax ?? 100,
            gameGroup?.yParallax ?? 100,
            this.entitiesOpacity,
            this.pulse,
            timeMs,
            this.diffCells,
        );
```

- [ ] **Step 4: Type-check, lint, and run the full JS suite**

Run: `npm run type-check && npm run lint && npm test`
Expected: no type/lint errors; all vitest tests pass (the existing viewer/overlay tests still import cleanly — `Overlay.draw` is only called from `viewer.ts`).

- [ ] **Step 5: Commit**

```bash
git add resources/assets/js/map/overlay.ts resources/assets/js/map/viewer.ts
git commit -m "feat(map-diff): render diff cells on the viewer overlay"
```

---

### Task 3: `MapCompare` orchestrator + window seam

The browser-side coordinator: fetch both versions, diff, drive the overlay, and build the breakdown panel. Exposed as `window.MapCompare` like `window.MapViewer`. DOM assembly isn't unit-tested (node env); the verdict text it shows comes from `formatVerdict` (already tested in Task 1).

**Files:**
- Create: `resources/assets/js/map/compare.ts`
- Modify: `resources/assets/js/app.ts`
- Modify: `resources/assets/js/global.d.ts`

**Interfaces:**
- Consumes: `loadMapScene` from `./viewer`; `diffPhysics`, `formatVerdict`, `DIFF_COLORS`, `PhysicsDiff`, `DiffCategory` from `./diff`; `MapViewer` type; `MapViewer.setDiffOverlay` (Task 2).
- Produces: `window.MapCompare.run(opts)` for the Blade inline script (Task 5).

- [ ] **Step 1: Write the orchestrator**

Create `resources/assets/js/map/compare.ts`:

```ts
import { loadMapScene } from './viewer';
import { diffPhysics, formatVerdict, DIFF_COLORS, type PhysicsDiff, type DiffCategory } from './diff';
import type { MapViewer } from './viewer';

interface RunOptions {
    baseUrl: string; // version A (base) .map file URL
    targetUrl: string; // version B (target) .map file URL — the version the viewer renders
    viewer: MapViewer;
    panelEl: HTMLElement;
    labels: { base: string; target: string }; // short content-keys for the panel header
}

/** Small typed element builder to keep the DOM assembly readable. */
function el(tag: string, className: string, text?: string): HTMLElement {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function legend(diff: PhysicsDiff): HTMLElement {
    const wrap = el('div', 'mb-2');
    const items: [DiffCategory, number, string][] = [
        ['added', diff.totals.added, 'Added'],
        ['removed', diff.totals.removed, 'Removed'],
        ['modified', diff.totals.modified, 'Modified'],
    ];
    for (const [cat, n, label] of items) {
        const item = el('span', 'me-3', `${label} (${n})`);
        const sw = el('span', 'map-viewer__swatch', ''); // reuse the legend swatch styling
        sw.style.background = DIFF_COLORS[cat];
        item.prepend(sw);
        wrap.append(item);
    }
    return wrap;
}

function layerTable(diff: PhysicsDiff): HTMLElement {
    const table = el('table', 'table table-sm mb-0') as HTMLTableElement;
    const thead = document.createElement('thead');
    thead.innerHTML = '<tr><th>Layer</th><th>In A</th><th>In B</th><th>Added</th><th>Removed</th><th>Modified</th></tr>';
    const tbody = document.createElement('tbody');
    for (const l of diff.layers) {
        if (!l.inA && !l.inB) continue;
        const tr = document.createElement('tr');
        const fields = [l.kind, l.inA ? '✓' : '—', l.inB ? '✓' : '—', String(l.added), String(l.removed), String(l.modified)];
        for (const f of fields) tr.append(el('td', '', f));
        tbody.append(tr);
    }
    table.append(thead, tbody);
    return table;
}

function renderPanel(panelEl: HTMLElement, diff: PhysicsDiff, labels: RunOptions['labels']): void {
    const root = el('div', '');
    root.append(el('h2', 'h5', `Comparing ${labels.base} → ${labels.target}`));
    root.append(el('p', 'mb-2', formatVerdict(diff)));
    if (diff.aligned && diff.totals.added + diff.totals.removed + diff.totals.modified > 0) {
        root.append(legend(diff));
    }
    root.append(layerTable(diff));
    panelEl.replaceChildren(root);
}

export class MapCompare {
    /** Fetch both versions, diff their physics layers, paint the overlay (when aligned), fill the panel. */
    static async run(opts: RunOptions): Promise<void> {
        const { panelEl } = opts;
        panelEl.hidden = false;
        panelEl.replaceChildren(el('p', 'mb-0', 'Comparing versions…'));
        try {
            const [base, target] = await Promise.all([loadMapScene(opts.baseUrl), loadMapScene(opts.targetUrl)]);
            const diff = diffPhysics(base, target);
            if (diff.aligned) opts.viewer.setDiffOverlay(diff.cells);
            renderPanel(panelEl, diff, opts.labels);
        } catch {
            panelEl.replaceChildren(el('p', 'mb-0 text-danger', 'Could not load one of the versions to compare.'));
        }
    }
}
```

- [ ] **Step 2: Expose it on `window`**

In `resources/assets/js/app.ts`, after the existing `import { MapViewer } ... window.MapViewer = MapViewer;` lines (currently lines 51–52), append:

```ts
import { MapCompare } from './map/compare';
window.MapCompare = MapCompare;
```

- [ ] **Step 3: Declare the global type**

In `resources/assets/js/global.d.ts`, inside the `interface Window { ... }` block, after the `MapViewer` line, add:

```ts
        // Version comparison orchestrator — invoked by the map detail page when a ?compare= base is present.
        MapCompare: typeof import('./map/compare').MapCompare;
```

- [ ] **Step 4: Type-check, lint, build**

Run: `npm run type-check && npm run lint && npm run build`
Expected: no errors; the build emits a fresh `public/build/assets/app-*.js`.

- [ ] **Step 5: Commit**

```bash
git add resources/assets/js/map/compare.ts resources/assets/js/app.ts resources/assets/js/global.d.ts
git commit -m "feat(map-diff): MapCompare orchestrator + window seam"
```

---

### Task 4: Controller reads `?compare=` and exposes a base version

Server side: resolve `?compare=` to a pickable version, compute whether the compare UI is worthwhile, and hand both to the view. Asserted via view data (no HTML), so it's independent of the Blade work in Task 5.

**Files:**
- Modify: `app/Http/Controllers/SearchController.php` (`searchMapByName`, around lines 261–288)
- Create: `tests/Feature/MapVersionCompareTest.php`

**Interfaces:**
- Consumes: existing `$map`, `$selected`, `$versionAvailability`, `MapAvailability` (already imported), `MapFileStore $store`.
- Produces (relied on by Task 5's Blade): view variables `compare` (`MapVersion|null`) and `canCompare` (`bool`).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/MapVersionCompareTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapVersionCompareTest extends TestCase
{
    use RefreshDatabase;

    private function mapWithVersions(): array
    {
        // The Map row carries a key so hasDownloadableFile() is true (the viewer/compare gate).
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        $base = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        $target = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);

        return [$map, $base, $target];
    }

    public function test_compare_query_param_resolves_to_a_pickable_base_version(): void
    {
        [, $base, $target] = $this->mapWithVersions();

        $this->get('/map/Linear?version=' . $target->sha256 . '&compare=' . $base->sha256)
            ->assertOk()
            ->assertViewHas('compare', fn ($v) => $v !== null && $v->is($base))
            ->assertViewHas('selected', fn ($v) => $v->is($target))
            ->assertViewHas('canCompare', true);
    }

    public function test_compare_ignores_an_unavailable_base_version(): void
    {
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        $bad = MapVersion::factory()->for($map)->create([
            'sha256' => str_repeat('d', 64),
            'crc' => null,
            'acquisition_status' => MapVersion::STATUS_UNAVAILABLE,
        ]);

        $this->get('/map/Linear?compare=' . $bad->sha256)
            ->assertOk()
            ->assertViewHas('compare', null);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MapVersionCompareTest`
Expected: FAIL — `assertViewHas` reports the view is missing the `compare` (and `canCompare`) key.

- [ ] **Step 3: Implement the controller change**

In `app/Http/Controllers/SearchController.php`, inside `searchMapByName`, after the `$versionAvailability = ...` block and before the `return view(...)`, add:

```php
        // optional comparison base for the version diff overlay: ?compare=<sha256|crc>, must be pickable
        $compare = null;
        if ($request->filled('compare')) {
            $key = $request->query('compare');
            $compare = $map->versions->first(
                fn ($v) => ($v->getAttribute('sha256') === $key || $v->getAttribute('crc') === $key)
                    && MapAvailability::forVersion($v, $store) !== MapAvailability::Unavailable
            );
        }

        // the compare UI is worthwhile only when the map has a file to render under the overlay and at
        // least two versions are fetchable (anything but Unavailable can be produced by the file route)
        $canCompare = $map->hasDownloadableFile()
            && $versionAvailability->filter(fn ($a) => $a !== MapAvailability::Unavailable)->count() >= 2;
```

Then add `'compare'` and `'canCompare'` to the `compact(...)` in the `return view(...)`:

```php
        return view('detail.map', compact('map', 'selected', 'serverCounts', 'availability', 'versionAvailability', 'compare', 'canCompare'));
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter MapVersionCompareTest`
Expected: PASS — both methods green.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SearchController.php tests/Feature/MapVersionCompareTest.php
git commit -m "feat(map-diff): resolve ?compare base version in searchMapByName"
```

---

### Task 5: Versions-panel compare UI + breakdown container + wiring

The Blade surface: A/B radio columns (disabled for unavailable rows), a Compare button that navigates to `?version=B&compare=A`, an empty breakdown container, and the `MapCompare.run(...)` call inside the existing viewer init. Closes the loop and gets the end-to-end manual verification.

**Files:**
- Modify: `resources/views/detail/map.blade.php`
- Modify: `tests/Feature/MapVersionCompareTest.php` (add HTML-assertion methods)

**Interfaces:**
- Consumes: view vars `compare`, `canCompare` (Task 4); `window.MapCompare.run` (Task 3); `window.MapViewer` (existing); routes `searchMapByName`, `mapFile`.

- [ ] **Step 1: Write the failing HTML feature tests**

Append these methods to `tests/Feature/MapVersionCompareTest.php` (inside the class):

```php
    public function test_versions_panel_renders_compare_radios_and_disables_unavailable(): void
    {
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);
        $bad = MapVersion::factory()->for($map)->create([
            'sha256' => str_repeat('d', 64),
            'crc' => null,
            'acquisition_status' => MapVersion::STATUS_UNAVAILABLE,
        ]);

        $html = $this->get('/map/Linear')->assertOk()->getContent();

        $this->assertStringContainsString('name="cmpA"', $html);
        $this->assertStringContainsString('name="cmpB"', $html);
        $this->assertStringContainsString('id="mapCompareBtn"', $html);
        $this->assertStringContainsString('id="mapCompareResult"', $html);
        // the unavailable version's radio is disabled
        $this->assertMatchesRegularExpression('/value="' . $bad->sha256 . '"[^>]*disabled/', $html);
    }

    public function test_compare_param_renders_base_file_url_for_the_overlay(): void
    {
        [, $base, $target] = $this->mapWithVersions();

        $html = $this->get('/map/Linear?version=' . $target->sha256 . '&compare=' . $base->sha256)
            ->assertOk()->getContent();

        $this->assertStringContainsString('window.MapCompare.run', $html);
        $this->assertStringContainsString('/map/Linear/file/' . $base->sha256, $html);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter MapVersionCompareTest`
Expected: FAIL — the two new methods fail (`name="cmpA"` / `window.MapCompare.run` not found); the Task 4 methods still pass.

- [ ] **Step 3: Add the A/B columns to the versions table**

In `resources/views/detail/map.blade.php`, in the table header `<tr>` (currently lines 57–64), insert the two columns after the `Servers` header and before the empty actions `<th>`:

```blade
                                    <th scope="col">Servers</th>
                                    @if($canCompare)
                                        <th scope="col" class="text-center">A</th>
                                        <th scope="col" class="text-center">B</th>
                                    @endif
                                    <th scope="col"></th>
```

In the row body, after the `Servers` `<td>` (currently line 81) and before the actions `<td>`, insert:

```blade
                                        <td>{{ $serverCounts[$version->id] ?? 0 }}</td>
                                        @if($canCompare)
                                            @php($pickable = $avail !== \App\Service\MapAvailability::Unavailable)
                                            <td class="text-center"><input type="radio" name="cmpA" value="{{ $key }}" @disabled(!$pickable)></td>
                                            <td class="text-center"><input type="radio" name="cmpB" value="{{ $key }}" @checked($isSelected) @disabled(!$pickable)></td>
                                        @endif
```

- [ ] **Step 4: Add the Compare button and the breakdown container**

In the same view, after the closing `</div>` of the `table-responsive` block but still inside the `#mapVersions` block (after current line 92, before line 93's `</div>`), add the button:

```blade
                    </div>
                    @if($canCompare)
                        <div class="mt-2">
                            <button type="button" id="mapCompareBtn" class="btn btn-sm btn-primary" disabled>Compare A → B</button>
                            <span class="text-muted small">Pick a base (A) and a target (B), then compare what changed.</span>
                        </div>
                    @endif
```

After the `#mapVersions` block closes (after current line 94's `@endif`), add the breakdown container inside `container-fluid`:

```blade
            @endif
            @if($canCompare)
                <div class="block" id="mapCompareResult" hidden></div>
            @endif
```

- [ ] **Step 5: Add the navigation handler and the overlay wiring in scripts**

In `resources/views/detail/map.blade.php`, in `@section('scripts')`, add the navigation handler (independent of the viewer) right after `@include('partials.play-stats-scripts')`:

```blade
    @include('partials.play-stats-scripts')
    @if($canCompare)
    <script>
        (function () {
            var btn = document.getElementById('mapCompareBtn');
            if (!btn) return;
            var base = {!! json_encode(route('searchMapByName', ['map_name' => $map->name]), JSON_UNESCAPED_SLASHES) !!};
            var selected = function (name) {
                var found = null;
                document.querySelectorAll('input[name="' + name + '"]').forEach(function (r) { if (r.checked) found = r.value; });
                return found;
            };
            var sync = function () {
                var a = selected('cmpA'), b = selected('cmpB');
                btn.disabled = !(a && b && a !== b);
            };
            document.addEventListener('change', function (e) {
                if (e.target && (e.target.name === 'cmpA' || e.target.name === 'cmpB')) sync();
            });
            btn.addEventListener('click', function () {
                var a = selected('cmpA'), b = selected('cmpB');
                if (!a || !b || a === b) return;
                window.location.href = base + '?version=' + encodeURIComponent(b) + '&compare=' + encodeURIComponent(a);
            });
            sync();
        })();
    </script>
    @endif
```

Then, inside the existing viewer init callback (the `.then(function (viewer) { ... })` block, currently starting line 113), after the `if (!viewer) return;` guard and before the closing `});`, add the overlay wiring. Place it right before the final `});` that closes the `.then` callback (current line 276):

```blade
                // When the page was opened with a ?compare base, overlay the physics diff onto B.
                var compareUrl = {!! $compare ? json_encode(route('mapFile', ['map_name' => $map->name, 'version' => $compare->contentKey()]), JSON_UNESCAPED_SLASHES) : 'null' !!};
                var comparePanel = document.getElementById('mapCompareResult');
                if (compareUrl && comparePanel && window.MapCompare) {
                    window.MapCompare.run({
                        baseUrl: compareUrl,
                        targetUrl: {!! json_encode($selected ? route('mapFile', ['map_name' => $map->name, 'version' => $selected->contentKey()]) : route('mapFile', ['map_name' => $map->name]), JSON_UNESCAPED_SLASHES) !!},
                        viewer: viewer,
                        panelEl: comparePanel,
                        labels: {
                            base: {!! json_encode($compare ? \Illuminate\Support\Str::substr($compare->contentKey(), 0, 12) : '', JSON_UNESCAPED_SLASHES) !!},
                            target: {!! json_encode($selected ? \Illuminate\Support\Str::substr($selected->contentKey(), 0, 12) : '', JSON_UNESCAPED_SLASHES) !!}
                        }
                    });
                }
```

- [ ] **Step 6: Run the feature tests to verify they pass**

Run: `vendor/bin/phpunit --filter MapVersionCompareTest`
Expected: PASS — all four methods green.

- [ ] **Step 7: Build, clear caches, and run the whole suite**

Run: `npm run type-check && npm run lint && npm test && npm run build && php artisan responsecache:clear && vendor/bin/phpunit`
Expected: type-check/lint clean, all vitest + PHPUnit tests pass, assets rebuilt, responsecache cleared.

- [ ] **Step 8: Manual verification on the real data**

With the dev server reachable, open the map detail page and confirm the two real cases from the spec:
- `https://twstats.ddev.site/map/Linear` — the Versions table shows A/B radio columns; the unavailable row's radios are disabled; **Compare A → B** enables once a distinct A and B are picked.
- Pick the two ~187 KB versions (`6763690…` and `e4016838…`) → page reloads showing B; the breakdown panel reads "Same dimensions — N physics cells changed…" and changed cells are highlighted (green/red/amber) on the map.
- Pick a ~187 KB version vs the ~1 MB version (`64a3ed67…`) → panel reads "Different dimensions … — likely a different map." and no cell overlay is drawn.

Confirm the browser console is free of errors and `public/build/assets/app-*.js` loads (no 404 — that's what `responsecache:clear` guards against).

- [ ] **Step 9: Commit**

```bash
git add resources/views/detail/map.blade.php tests/Feature/MapVersionCompareTest.php
git commit -m "feat(map-diff): versions-panel compare UI + diff overlay wiring"
```

---

## Self-Review

**Spec coverage:**
- Physics-only diff over the six kinds, kind-aware equality → Task 1 (`diffPhysics`, `cellsDiffer`). ✓
- Dimension-mismatch → structural only (`aligned: false`, no cells) → Task 1 + `formatVerdict`. ✓
- Per-cell visual overlay (added/removed/modified, colour-coded) on the existing viewer → Task 2. ✓
- Launch via navigation to `?version=B&compare=A`, B rendered underneath → Tasks 4 (controller) + 5 (button handler + selection). ✓
- Compare selectors in the Versions panel, disabled for `unavailable` → Task 5. ✓
- Structured breakdown panel (verdict, legend, per-kind table) → Task 3 (`renderPanel`). ✓
- No new DB columns/migrations/routes; only `?compare=` read → Task 4. ✓
- vitest unit tests for the pure function; feature tests for the server/HTML surface → Tasks 1, 4, 5. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases"; every code step shows complete code and exact commands. ✓

**Type consistency:** `PhysicsDiff`/`DiffCell`/`DIFF_COLORS` defined in Task 1 are imported unchanged in Tasks 2 (`overlay.ts`, `viewer.ts`) and 3 (`compare.ts`). `setDiffOverlay(cells: DiffCell[])` defined in Task 2 is called in Task 3. `Overlay.draw(...)`'s new final `diffCells` param (Task 2 Step 1) matches the call site updated in Task 2 Step 3. View vars `compare`/`canCompare` produced in Task 4 are consumed in Task 5. ✓
