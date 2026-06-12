# Map Viewer — Phase C (WebGL renderer + page integration) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render a parsed `MapScene` (Phase B) interactively on a `<canvas>` via WebGL — tile
layers + quad layers with animated color/position envelopes, group parallax/clipping, pan/zoom,
fit-to-screen, and an entities overlay toggle — wired into the `/map/{name}` page, fed by the
`/map/{name}/file` route from Phase A.

**Architecture:** Pure, unit-tested logic (`envelope.ts` curve eval, `camera.ts` view transform,
`geometry.ts` tile/quad vertex builders) is separated from thin imperative glue
(`glRenderer.ts` WebGL context/shaders/draw, `viewer.ts` orchestration + input + texture
loading). The pure modules are vitest-tested; the GL glue is verified end-to-end in Phase D
(Playwright). `viewer.ts` is exposed as `window.MapViewer`; the map Blade view's inline
`<script>` calls it — matching the existing inline-script seam.

**Tech Stack:** WebGL1, strict TS (ES2022, `noUncheckedIndexedAccess`), vitest, Vite. Standard
mapres + entities PNGs vendored under `public/` (served as-is, like `public/skins`).

## Domain reference

- **Fixed-point:** map ints are `value * 1024`; `fx2f(v) = v / 1024`. Envelope values and
  (for quads) the corner colors are 0..255 ints — colors normalise by `/255`; envelope values
  by `/1024`.
- **Tileset cell:** a tile `id` (0..255) indexes a 16×16 grid in its layer's image:
  `col = id % 16`, `row = (id / 16) | 0`; UV cell = `[col/16, row/16] .. [+1/16]`. Tile `flags`
  bits: `1`=vflip, `2`=hflip, `4`=opaque, `8`=rot90 — applied to the cell's UV corners in order
  vflip → hflip → rot90.
- **World units:** one tile = 32×32 world units (Teeworlds renders tiles at 32px at zoom 1).
  Quad positions are already world coords (Phase B divided by 512 → tile units; multiply by 32
  for world px, OR keep tile units consistently — this plan uses **tile units**: 1 tile = 1
  unit, quad positions ×(1/32)… see Task 4). Keep one consistent unit across camera + geometry.
- **Envelope eval (from `ddnet/src/game/map/render_map.cpp` RenderEvalEnvelope):** 0 points →
  default; 1 point → its values; else wrap `t` by the last point's time, find segment
  `[i,i+1]` with `points[i].time <= t < points[i+1].time`, `a=(t-ti)/(t(i+1)-ti)`, transform
  `a` by curve (STEP→0, SLOW→a³, FAST→1−(1−a)³, SMOOTH→3a²−2a³, LINEAR→a; BEZIER→linear
  fallback in Phase C), then `lerp(values_i, values_{i+1}, a)`. Times are in ms.

## File structure

| File | Responsibility | Tested by |
| --- | --- | --- |
| `public/mapres/*.png` (vendored) | standard external tilesets | — |
| `public/entities/DDNet.png` (vendored) | entities overlay tileset | — |
| `resources/assets/js/map/envelope.ts` | curve eval | vitest |
| `resources/assets/js/map/camera.ts` | pan/zoom/parallax transform | vitest |
| `resources/assets/js/map/geometry.ts` | tile/quad vertex builders | vitest |
| `resources/assets/js/map/glRenderer.ts` | WebGL context, shaders, draw | Phase D |
| `resources/assets/js/map/viewer.ts` | orchestrate fetch→parse→textures→loop→input | Phase D |
| `resources/assets/js/app.ts` (modify) | `window.MapViewer = ...` | — |
| `resources/assets/js/global.d.ts` (modify) | declare `window.MapViewer` | — |
| `resources/views/detail/map.blade.php` (modify) | canvas + controls + init script | Phase D |
| `resources/assets/sass/dark_admin_bootstrapius.scss` (modify) | viewer panel styles | — |
| `tests/js/map/{envelope,camera,geometry}.test.ts` | unit tests | vitest |

---

## Task 1: Vendor mapres + entities assets

**Files:** create `public/mapres/*.png`, `public/entities/DDNet.png`.

- [ ] **Step 1: Copy the standard tilesets** from the untracked `ddnet` checkout into committed
public assets (precedent: `public/skins`):

```bash
mkdir -p public/mapres public/entities
cp ddnet/data/mapres/*.png public/mapres/
cp ddnet/data/editor/entities/DDNet.png public/entities/DDNet.png
```

- [ ] **Step 2: Verify** `ls public/mapres | wc -l` ≥ 50 and `public/entities/DDNet.png` exists.
Confirm `.gitignore` does NOT ignore `public/mapres` / `public/entities` (it ignores
`public/build`, `public/css`, `public/js`, `public/fonts`, `public/images` — none match, so these
are committable).

- [ ] **Step 3: Commit.**

```bash
git add public/mapres public/entities
git commit -m "feat(map): vendor standard mapres + entities tilesets for the viewer"
```

---

## Task 2: envelope evaluation

**Files:** create `resources/assets/js/map/envelope.ts`, `tests/js/map/envelope.test.ts`.

- [ ] **Step 1: Failing test.** Create `tests/js/map/envelope.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { evalEnvelope } from '../../../resources/assets/js/map/envelope';
import type { EnvPoint } from '../../../resources/assets/js/map/types';

const pt = (time: number, curve: number, v: number): EnvPoint => ({ time, curve, values: [v, 0, 0, 0] });

describe('evalEnvelope', () => {
    it('returns defaults for zero points', () => {
        expect(evalEnvelope(1, [], 0)).toEqual([0]);
    });
    it('returns the single point value (fx2f /1024)', () => {
        expect(evalEnvelope(1, [pt(0, 1, 1024)], 999)).toEqual([1]);
    });
    it('linearly interpolates between two points', () => {
        const pts = [pt(0, 1, 0), pt(1000, 1, 1024)]; // 0..1 over 1000ms
        expect(evalEnvelope(1, pts, 500)[0]).toBeCloseTo(0.5, 5);
    });
    it('STEP curve holds the start value until the next point', () => {
        const pts = [pt(0, 0, 0), pt(1000, 0, 1024)];
        expect(evalEnvelope(1, pts, 999)[0]).toBe(0);
    });
    it('SMOOTH curve eases (3a^2-2a^3) — midpoint stays 0.5 but quarter < 0.25', () => {
        const pts = [pt(0, 4, 0), pt(1000, 4, 1024)];
        expect(evalEnvelope(1, pts, 500)[0]).toBeCloseTo(0.5, 5);
        expect(evalEnvelope(1, pts, 250)[0]).toBeLessThan(0.25);
    });
    it('wraps time past the last point', () => {
        const pts = [pt(0, 1, 0), pt(1000, 1, 1024)];
        expect(evalEnvelope(1, pts, 1500)[0]).toBeCloseTo(0.5, 5); // 1500 % 1000 = 500
    });
});
```

- [ ] **Step 2: Run → fail.** `npx vitest run tests/js/map/envelope.test.ts`.

- [ ] **Step 3: Implement.** Create `resources/assets/js/map/envelope.ts`:

```ts
import type { EnvPoint } from './types';

/** Curve types (libtw2 / DDNet): 0 step, 1 linear, 2 slow, 3 fast, 4 smooth, 5 bezier. */
function applyCurve(curve: number, a: number): number {
    switch (curve) {
        case 0: return 0; // STEP
        case 2: return a * a * a; // SLOW
        case 3: { const i = 1 - a; return 1 - i * i * i; } // FAST
        case 4: return -2 * a * a * a + 3 * a * a; // SMOOTH
        default: return a; // LINEAR (1) and BEZIER (5)→linear fallback in Phase C
    }
}

/**
 * Evaluate an envelope at time `timeMs`, returning `channels` floats (values are fx2f: /1024).
 * Mirrors ddnet RenderEvalEnvelope: wrap by the last point's time, find the active segment,
 * transform the interpolation factor by the segment's start-point curve, then lerp.
 */
export function evalEnvelope(channels: number, points: EnvPoint[], timeMs: number): number[] {
    const out = new Array<number>(channels).fill(0);
    if (points.length === 0) return out;
    const fx = (v: number) => v / 1024;
    if (points.length === 1) {
        for (let c = 0; c < channels; c++) out[c] = fx(points[0]!.values[c] ?? 0);
        return out;
    }
    const last = points[points.length - 1]!;
    const maxTime = last.time;
    let t = maxTime > 0 ? ((timeMs % maxTime) + maxTime) % maxTime : 0;
    // find segment i where points[i].time <= t < points[i+1].time
    let i = 0;
    while (i < points.length - 1 && !(points[i]!.time <= t && t < points[i + 1]!.time)) i++;
    if (i >= points.length - 1) { for (let c = 0; c < channels; c++) out[c] = fx(last.values[c] ?? 0); return out; }
    const cur = points[i]!;
    const next = points[i + 1]!;
    const delta = next.time - cur.time;
    if (delta <= 0) { for (let c = 0; c < channels; c++) out[c] = fx(cur.values[c] ?? 0); return out; }
    const a = applyCurve(cur.curve, (t - cur.time) / delta);
    for (let c = 0; c < channels; c++) {
        const v0 = fx(cur.values[c] ?? 0);
        const v1 = fx(next.values[c] ?? 0);
        out[c] = v0 + (v1 - v0) * a;
    }
    return out;
}
```

- [ ] **Step 4: Run → pass.** `npx vitest run tests/js/map/envelope.test.ts` → 6 passing.
- [ ] **Step 5: type-check + lint** → green.
- [ ] **Step 6: Commit.** `git add resources/assets/js/map/envelope.ts tests/js/map/envelope.test.ts && git commit -m "feat(map): envelope curve evaluation"`

---

## Task 3: camera (pan / zoom / parallax)

**Files:** create `resources/assets/js/map/camera.ts`, `tests/js/map/camera.test.ts`.

Units: **tile units** (1 tile = 1 unit). The camera holds a center `(cx, cy)` in tile units and a
`zoom` (pixels per tile). `worldToScreen` maps a world point to canvas pixels; a group's parallax
scales the camera center by `parallax/100` before transforming that group's geometry.

- [ ] **Step 1: Failing test.** Create `tests/js/map/camera.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { Camera } from '../../../resources/assets/js/map/camera';

describe('Camera', () => {
    it('maps the center to the canvas middle', () => {
        const cam = new Camera(800, 600);
        cam.center = [10, 20];
        cam.zoom = 32;
        expect(cam.worldToScreen(10, 20, 100, 100)).toEqual([400, 300]);
    });
    it('scales by zoom (pixels per tile)', () => {
        const cam = new Camera(800, 600);
        cam.center = [0, 0];
        cam.zoom = 32;
        expect(cam.worldToScreen(1, 0, 100, 100)).toEqual([400 + 32, 300]);
    });
    it('applies parallax: a 0-parallax (bg) layer ignores camera panning', () => {
        const cam = new Camera(800, 600);
        cam.center = [50, 50];
        cam.zoom = 10;
        // parallax 0 → camera center treated as (0,0) for this layer
        expect(cam.worldToScreen(0, 0, 0, 0)).toEqual([400, 300]);
    });
    it('round-trips screen→world→screen', () => {
        const cam = new Camera(640, 480);
        cam.center = [3, 4];
        cam.zoom = 25;
        const [wx, wy] = cam.screenToWorld(120, 200, 100, 100);
        expect(cam.worldToScreen(wx, wy, 100, 100)).toEqual([120, 200]);
    });
    it('fitBounds centers and zooms to contain a box', () => {
        const cam = new Camera(800, 600);
        cam.fitBounds(0, 0, 50, 30);
        expect(cam.center).toEqual([25, 15]);
        expect(cam.zoom).toBeGreaterThan(0);
        // the wider dimension (50 tiles over 800px) bounds the zoom
        expect(cam.zoom).toBeLessThanOrEqual(800 / 50);
    });
});
```

- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.** Create `resources/assets/js/map/camera.ts`:

```ts
export class Camera {
    center: [number, number] = [0, 0];
    zoom = 32; // pixels per tile
    constructor(public viewW: number, public viewH: number) {}

    private effectiveCenter(parallaxX: number, parallaxY: number): [number, number] {
        return [this.center[0] * (parallaxX / 100), this.center[1] * (parallaxY / 100)];
    }

    worldToScreen(wx: number, wy: number, parallaxX = 100, parallaxY = 100): [number, number] {
        const [ecx, ecy] = this.effectiveCenter(parallaxX, parallaxY);
        return [this.viewW / 2 + (wx - ecx) * this.zoom, this.viewH / 2 + (wy - ecy) * this.zoom];
    }

    screenToWorld(sx: number, sy: number, parallaxX = 100, parallaxY = 100): [number, number] {
        const [ecx, ecy] = this.effectiveCenter(parallaxX, parallaxY);
        return [(sx - this.viewW / 2) / this.zoom + ecx, (sy - this.viewH / 2) / this.zoom + ecy];
    }

    /** Zoom by `factor` keeping the world point under (sx,sy) fixed on screen. */
    zoomAt(sx: number, sy: number, factor: number): void {
        const before = this.screenToWorld(sx, sy);
        this.zoom = Math.max(1, Math.min(256, this.zoom * factor));
        const after = this.screenToWorld(sx, sy);
        this.center = [this.center[0] + (before[0] - after[0]), this.center[1] + (before[1] - after[1])];
    }

    /** Pan by a screen-pixel delta. */
    panPixels(dx: number, dy: number): void {
        this.center = [this.center[0] - dx / this.zoom, this.center[1] - dy / this.zoom];
    }

    /** Center on a world box and zoom to contain it (with a small margin). */
    fitBounds(x0: number, y0: number, x1: number, y1: number): void {
        const w = Math.max(1e-6, x1 - x0);
        const h = Math.max(1e-6, y1 - y0);
        this.center = [(x0 + x1) / 2, (y0 + y1) / 2];
        this.zoom = Math.max(1, Math.min(this.viewW / w, this.viewH / h) * 0.95);
    }
}
```

- [ ] **Step 4: Run → pass.** (The `fitBounds` test allows the 0.95 margin via `<=`.)
- [ ] **Step 5: type-check + lint** → green.
- [ ] **Step 6: Commit.** `git add resources/assets/js/map/camera.ts tests/js/map/camera.test.ts && git commit -m "feat(map): viewer camera (pan/zoom/parallax/fit)"`

---

## Task 4: geometry builders (tile + quad vertices)

**Files:** create `resources/assets/js/map/geometry.ts`, `tests/js/map/geometry.test.ts`.

Output: interleaved vertex data for `gl.TRIANGLES`. Per vertex: `x, y` (world tile units), `u, v`
(texture 0..1), `r, g, b, a` (0..1). Each tile/quad = 2 triangles = 6 vertices = 48 floats.

- [ ] **Step 1: Failing test.** Create `tests/js/map/geometry.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { tileUV, buildTileLayerVertices } from '../../../resources/assets/js/map/geometry';
import type { TileLayer } from '../../../resources/assets/js/map/types';

describe('geometry', () => {
    it('maps tile id 0 to the top-left cell, id 17 to col1/row1', () => {
        expect(tileUV(0, 0)).toMatchObject({ u0: 0, v0: 0, u1: 1 / 16, v1: 1 / 16 });
        const uv = tileUV(17, 0); // col=1,row=1
        expect(uv.u0).toBeCloseTo(1 / 16);
        expect(uv.v0).toBeCloseTo(1 / 16);
    });

    it('emits 48 floats per non-air tile and skips air (id 0)', () => {
        const layer: TileLayer = {
            type: 'tilemap', kind: 'tiles', width: 2, height: 1,
            color: [255, 255, 255, 255], colorEnv: -1, colorEnvOffset: 0, image: 0, name: '',
            tiles: [{ id: 0, flags: 0, skip: 0 }, { id: 5, flags: 0, skip: 0 }],
        };
        const verts = buildTileLayerVertices(layer, [1, 1, 1, 1]);
        expect(verts.length).toBe(48); // one non-air tile
    });

    it('places the non-air tile at its grid cell (world units = tile index)', () => {
        const layer: TileLayer = {
            type: 'tilemap', kind: 'tiles', width: 2, height: 1,
            color: [255, 255, 255, 255], colorEnv: -1, colorEnvOffset: 0, image: 0, name: '',
            tiles: [{ id: 0, flags: 0, skip: 0 }, { id: 5, flags: 0, skip: 0 }],
        };
        const v = buildTileLayerVertices(layer, [1, 1, 1, 1]);
        // first vertex x,y of the tile at grid col=1,row=0 → world (1,0)
        expect(v[0]).toBe(1);
        expect(v[1]).toBe(0);
    });
});
```

- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.** Create `resources/assets/js/map/geometry.ts`:

```ts
import type { TileLayer, Quad } from './types';

export interface UVRect { u0: number; v0: number; u1: number; v1: number }

/** UV cell for a tile id within a 16x16 tileset. */
export function tileUV(id: number, flags: number): UVRect {
    const col = id % 16;
    const row = (id / 16) | 0;
    const s = 1 / 16;
    let r: UVRect = { u0: col * s, v0: row * s, u1: (col + 1) * s, v1: (row + 1) * s };
    // flags: 1 vflip, 2 hflip, 8 rot90 (4 = opaque, ignored for UVs)
    if (flags & 1) r = { ...r, v0: r.v1, v1: r.v0 };
    if (flags & 2) r = { ...r, u0: r.u1, u1: r.u0 };
    return r; // rot90 handled by corner ordering in the caller (Phase C: optional)
}

/** Push one textured, colored quad (2 triangles) into `out`. */
function pushQuad(
    out: number[],
    p: [number, number][], // 4 corners TL,TR,BL,BR (world)
    uv: [number, number][], // 4 uv corners matching p
    color: [number, number, number, number], // 0..1
): void {
    const order = [0, 1, 2, 1, 3, 2]; // TL,TR,BL, TR,BR,BL
    for (const idx of order) {
        out.push(p[idx]![0], p[idx]![1], uv[idx]![0], uv[idx]![1], color[0], color[1], color[2], color[3]);
    }
}

/** Build interleaved vertices for every non-air tile. `colorMul` multiplies the layer color (0..1). */
export function buildTileLayerVertices(layer: TileLayer, colorMul: [number, number, number, number]): number[] {
    const out: number[] = [];
    const [lr, lg, lb, la] = layer.color;
    const color: [number, number, number, number] = [
        (lr / 255) * colorMul[0], (lg / 255) * colorMul[1], (lb / 255) * colorMul[2], (la / 255) * colorMul[3],
    ];
    for (let y = 0; y < layer.height; y++) {
        for (let x = 0; x < layer.width; x++) {
            const tile = layer.tiles[y * layer.width + x];
            if (!tile || tile.id === 0) continue; // air
            const uvr = tileUV(tile.id, tile.flags);
            const p: [number, number][] = [[x, y], [x + 1, y], [x, y + 1], [x + 1, y + 1]];
            const uv: [number, number][] = [[uvr.u0, uvr.v0], [uvr.u1, uvr.v0], [uvr.u0, uvr.v1], [uvr.u1, uvr.v1]];
            pushQuad(out, p, uv, color);
        }
    }
    return out;
}

/** Build interleaved vertices for one quad (positions already in tile units after ÷512→×... see note). */
export function buildQuadVertices(
    quad: Quad,
    posOffset: [number, number],
    colorMul: [number, number, number, number],
): number[] {
    const out: number[] = [];
    // quad.points: [TL,TR,BL,BR,pivot]; positions are in tile units (Phase B ÷512 gives tile coords)
    const p: [number, number][] = [0, 1, 2, 3].map((i) => {
        const pt = quad.points[i]!;
        return [pt[0] + posOffset[0], pt[1] + posOffset[1]];
    });
    const uv: [number, number][] = [0, 1, 2, 3].map((i) => quad.texCoords[i]!);
    // per-corner color × colorMul, normalised
    for (const tri of [[0, 1, 2], [1, 3, 2]]) {
        for (const idx of tri) {
            const c = quad.colors[idx]!;
            out.push(p[idx]![0], p[idx]![1], uv[idx]![0], uv[idx]![1],
                (c[0] / 255) * colorMul[0], (c[1] / 255) * colorMul[1], (c[2] / 255) * colorMul[2], (c[3] / 255) * colorMul[3]);
        }
    }
    return out;
}
```

- [ ] **Step 4: Run → pass.** `npx vitest run tests/js/map/geometry.test.ts` → 3 passing.
- [ ] **Step 5: type-check + lint** → green.
- [ ] **Step 6: Commit.** `git add resources/assets/js/map/geometry.ts tests/js/map/geometry.test.ts && git commit -m "feat(map): tile/quad vertex builders"`

---

## Task 5: WebGL renderer glue

**Files:** create `resources/assets/js/map/glRenderer.ts`. (No vitest — verified in Phase D.)

- [ ] **Step 1: Implement** a minimal WebGL1 renderer. Responsibilities:
  - Construct from a `WebGLRenderingContext`. Compile one shader program: vertex shader takes
    `vec2 aPos, vec2 aUV, vec4 aColor` + a `uniform mat3 uView` (or just `uniform vec4 uTransform`
    = `[scaleX, scaleY, offX, offY]` mapping world→clip); fragment shader samples `uTex` ×
    `vColor`, discards near-zero alpha.
  - `createTexture(source: TexImageSource): WebGLTexture` (NEAREST filtering, CLAMP).
  - `draw(batches: { texture: WebGLTexture; vertices: Float32Array }[], transform)`: clear, for
    each batch bind texture, upload its vertex buffer, set the uniform, `drawArrays(TRIANGLES)`.
    Alpha blending on (`SRC_ALPHA, ONE_MINUS_SRC_ALPHA`).
  - A `whiteTexture` fallback for untextured/placeholder layers.

  Keep it ~150 lines; no domain logic (that's in geometry/envelope). Vertex stride = 8 floats.
  Provide a `gl.viewport`/resize helper. Export `class GlRenderer`.

- [ ] **Step 2: Build sanity.** `npm run type-check`, `npm run lint`, `npm run build` → green
  (the module isn't imported by `app.ts` yet, so build is unchanged). There is no unit test;
  correctness is established in Phase D.

- [ ] **Step 3: Commit.** `git add resources/assets/js/map/glRenderer.ts && git commit -m "feat(map): minimal WebGL renderer (shaders, textures, batched draw)"`

---

## Task 6: viewer orchestrator + window global

**Files:** create `resources/assets/js/map/viewer.ts`; modify `resources/assets/js/app.ts`,
`resources/assets/js/global.d.ts`.

- [ ] **Step 1: Implement `viewer.ts`** exporting `class MapViewer` with a static
  `async init(canvas: HTMLCanvasElement, opts: { mapUrl: string; mapresBase: string; entitiesUrl: string })`.
  Flow:
  1. `fetch(opts.mapUrl)` → `ArrayBuffer` → `parseMap(new Uint8Array(buf))` (Phase B barrel).
  2. Build a `GlRenderer` from `canvas.getContext('webgl')`; if null, show a graceful message and return.
  3. Load textures: for each `MapImage`, if embedded (`!external` and `data >= 0`) decode its pixels
     (the parser exposes the data index; fetch+inflate via a small helper or extend Phase B to
     expose embedded `ImageData` — see note) → `createTexture`; if external, `new Image()` from
     `${opts.mapresBase}/${name}.png` (onload → texture; onerror → white fallback).
  4. Compute world bounds from the Game group's first tile layer (width×height) and `camera.fitBounds`.
  5. Per frame (`requestAnimationFrame`): for each group, for each layer, evaluate color/position
     envelopes at `performance.now()` (via `evalEnvelope`), build vertices (`geometry.ts`) — caching
     static tile-layer vertices, rebuilding only envelope-animated quads — group them into batches by
     texture, and `renderer.draw(...)` with the camera's world→clip transform (per-group parallax).
  6. Input: pointer drag → `camera.panPixels`; wheel → `camera.zoomAt`; a `fit()` method; an
     `entities` boolean that, when on, also draws physics layers using the entities texture.
  Expose instance methods `fit()`, `toggleEntities()`, `destroy()` (cancel rAF, free GL).

  **Embedded-image note:** Phase B keeps `MapImage.data` as the data-block index but `parseMap`
  discards the `DataFile`. Add a small Phase-B-compatible export so the viewer can obtain embedded
  pixels: either (a) have `parseMap` also return embedded images as `ImageData` (extend `MapImage`
  with an optional `pixels?: ImageData`), or (b) export a `parseMapWithData` that returns
  `{ scene, getImagePixels(i): Promise<ImageData|null> }`. Choose (a): in Phase C, extend the Phase
  B `parseImage` to, for embedded RGBA images, `getData` the pixel block and attach
  `pixels: new ImageData(new Uint8ClampedArray(bytes), width, height)`. Update the Phase B image
  test if needed (it only checks width/height/external, so it stays green). Keep this change small
  and covered by a quick assertion that `scene.images[0].pixels` is set for gravity.map.

- [ ] **Step 2: Expose the global.** In `app.ts` add `import { MapViewer } from './map/viewer';`
  and `window.MapViewer = MapViewer;`. In `global.d.ts` add to the `Window` interface:
  `MapViewer: typeof import('./map/viewer').MapViewer;`.

- [ ] **Step 3: Build health.** `npm run type-check`, `npm run lint`, `npm run test` (all prior
  vitest green, plus the embedded-pixels assertion), `npm run build` (now the viewer IS imported by
  `app.ts`, so the bundle grows — confirm it builds without error).

- [ ] **Step 4: Commit.** `git add resources/assets/js/map/viewer.ts resources/assets/js/app.ts resources/assets/js/global.d.ts resources/assets/js/map/mapParser.ts tests/js/map/mapParser.test.ts && git commit -m "feat(map): MapViewer orchestrator + window global"`

---

## Task 7: page integration (canvas + controls + init script)

**Files:** modify `resources/views/detail/map.blade.php`,
`resources/assets/sass/dark_admin_bootstrapius.scss`.

- [ ] **Step 1: Add the viewer panel** to `detail/map.blade.php`, shown only when the map has a
  file (`$map->hasDownloadableFile()`), inside the existing `section-content > container-fluid`
  shell, above or beside the Download button:

```blade
@if($map->hasDownloadableFile())
    <div class="block map-viewer">
        <canvas id="mapCanvas" class="map-viewer__canvas" width="960" height="540"></canvas>
        <div class="map-viewer__controls">
            <button type="button" id="mapFit" class="btn btn-sm btn-secondary">Fit</button>
            <label class="map-viewer__toggle"><input type="checkbox" id="mapEntities"> Entities</label>
        </div>
    </div>
@endif
```

- [ ] **Step 2: Init script.** In the `@section('scripts')` block (after the play-stats-scripts
  include), add an inline `<script>` that drives the `window.MapViewer` global (the seam), guarded
  on the canvas existing:

```blade
    @if($map->hasDownloadableFile())
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var canvas = document.getElementById('mapCanvas');
            if (!canvas || !window.MapViewer) return;
            window.MapViewer.init(canvas, {
                mapUrl: {!! json_encode(route('mapFile', ['map_name' => $map->name])) !!},
                mapresBase: {!! json_encode(asset('mapres')) !!},
                entitiesUrl: {!! json_encode(asset('entities/DDNet.png')) !!}
            }).then(function (viewer) {
                if (!viewer) return;
                document.getElementById('mapFit').addEventListener('click', function () { viewer.fit(); });
                document.getElementById('mapEntities').addEventListener('change', function () { viewer.toggleEntities(); });
            });
        });
    </script>
    @endif
```

- [ ] **Step 3: Styles.** Add a small rule block to `dark_admin_bootstrapius.scss` (edited in place
  per the theme convention): `.map-viewer__canvas` full-width, dark background, `image-rendering:
  pixelated`, `cursor: grab`; `.map-viewer__controls` a flex row with a gap. Then `npm run build`.

- [ ] **Step 4: Rebuild + verify route.** `npm run build`. (`/map` is not response-cached, so no
  `responsecache:clear`.) Confirm `php artisan route:list | grep mapFile` shows the inline route.

- [ ] **Step 5: Feature test (PHP).** Add to `tests/Feature/MapFileAcquisitionTest.php`: a map with
  a sha256 renders the `/map/{name}` page containing `id="mapCanvas"` and the entities toggle; a map
  without a sha256 does NOT (`assertSee`/`assertDontSee`). Run `vendor/bin/phpunit --filter MapFileAcquisition`.

- [ ] **Step 6: Commit.** `git add resources/views/detail/map.blade.php resources/assets/sass/dark_admin_bootstrapius.scss tests/Feature/MapFileAcquisitionTest.php && git commit -m "feat(map): embed the WebGL viewer on the map page"`

---

## Task 8: phase exit gate

- [ ] **Step 1:** `npm run test` (all vitest green), `npm run type-check`, `npm run lint`,
  `npm run build`, and `vendor/bin/phpunit` (PHP suite unaffected) — all green.
- [ ] **Step 2:** `php artisan responsecache:clear` is NOT required (`/map` uncached); but DO run it
  if unsure, then confirm the page loads.
- [ ] **Step 3:** Real visual verification (the canvas actually renders the map) is **Phase D**
  (Playwright) — note it here; Phase C's exit bar is "toolchain green + the page serves the canvas +
  the pure modules are unit-tested."

## Self-review notes (spec coverage)

- Spec **Phase C** = `envelope.ts` (eval) + `renderer.ts` (WebGL tiles+quads+envelope animation,
  parallax/clip, culling) + `viewer.ts` (fetch+parse, textures embedded+external, pan/zoom/fit,
  entities toggle, `window.MapViewer`) + mapres vendoring + Blade integration. Mapped: Task 1
  (assets), 2 (envelope), 3 (camera), 4 (geometry/tiles+quads), 5 (GL glue), 6 (viewer+global), 7
  (page), 8 (gate).
- **Deferred/lighter than spec, logged:** scissor-based group **clipping** and aggressive viewport
  **culling** are noted in `glRenderer`/`viewer` but a basic version (draw all visible layers,
  cull obviously-offscreen tiles by camera bounds) is acceptable for Phase C; bezier envelopes use a
  linear fallback (no fixture exercises them). Real GL correctness is verified in Phase D.
- **Type consistency:** `evalEnvelope(channels, points, timeMs): number[]`,
  `Camera.worldToScreen/screenToWorld(...,parallaxX,parallaxY)`, `buildTileLayerVertices(layer, colorMul): number[]`,
  `buildQuadVertices(quad, posOffset, colorMul): number[]`, `GlRenderer.draw(batches, transform)`,
  `MapViewer.init(canvas, opts): Promise<MapViewer|null>` — consistent across tasks.
- **Phase B touch:** Task 6 extends `MapImage` with optional `pixels?: ImageData` for embedded
  images (needed to texture them) — a small, additive change kept green by the existing tests.
