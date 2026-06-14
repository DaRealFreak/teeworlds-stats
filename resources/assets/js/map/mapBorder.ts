/**
 * Map-edge geometry that mirrors how DDNet renders the area beyond a tile layer's bounds
 * (src/game/map/render_layer.cpp):
 *   - the outer row/column of tiles is repeated outward (TILERENDERFLAG_EXTEND), so the map never
 *     cuts to background at its edge — see {@link buildEdgeExtendVertices};
 *   - the game layer additionally paints a field of TILE_DEATH ("kill") tiles starting
 *     BorderRenderDistance tiles past every edge — see {@link buildKillBorderVertices}.
 *
 * Both produce interleaved x,y,u,v,r,g,b,a vertices (8 floats/vertex) in the layer's tile space, the
 * same format the GlRenderer consumes. They are view-dependent (clipped to the visible rect) so the
 * vertex count stays bounded by what is actually on screen.
 */

import type { TileLayer } from './types';
import { tileUV } from './geometry';

/** DDNet src/game/map/render_layer.h: kill tiles begin this many tiles past each map edge. */
export const BORDER_RENDER_DISTANCE = 201;

/** A visible world rect in tile units: [x0, y0, x1, y1] with x0<x1, y0<y1 (y not flipped). */
export type TileRect = [number, number, number, number];

/**
 * Push one axis-aligned rect (two triangles), white, with UVs set to the *world* tile coordinates.
 * fract() sampling then wraps the death cell on integer tile boundaries, so the field tiles in lock-
 * step with the map grid: each cell is a whole tile and the map edge (always an integer offset from
 * the map's origin) meets the field cleanly instead of slicing a cell at a zoom-dependent position.
 */
function pushRect(out: number[], x0: number, y0: number, x1: number, y1: number): void {
    // corners TL, TR, BL, BR (uv = world xy); triangles [TL,TR,BL],[TR,BR,BL]
    const c = [
        [x0, y0],
        [x1, y0],
        [x0, y1],
        [x1, y1],
    ];
    for (const i of [0, 1, 2, 1, 3, 2]) {
        const p = c[i]!;
        out.push(p[0]!, p[1]!, p[0]!, p[1]!, 1, 1, 1, 1);
    }
}

/** RGBA multiplier applied to a layer's tiles (0..1). */
type Rgba = [number, number, number, number];

/** Push one source tile at grid cell (gx,gy), reusing its flip/rotate UV — mirrors geometry.pushQuad. */
function pushTile(out: number[], gx: number, gy: number, id: number, flags: number, color: Rgba): void {
    const uv = tileUV(id, flags); // [TL, TR, BL, BR]
    const p: [number, number][] = [
        [gx, gy],
        [gx + 1, gy],
        [gx, gy + 1],
        [gx + 1, gy + 1],
    ];
    for (const i of [0, 1, 2, 1, 3, 2]) {
        out.push(p[i]![0], p[i]![1], uv[i]![0], uv[i]![1], color[0], color[1], color[2], color[3]);
    }
}

/**
 * Repeat a tile layer's outer row/column (and corner) tiles outward past its bounds to fill the
 * visible area, matching DDNet's TILERENDERFLAG_EXTEND (render_layer.cpp RenderTileBorder). The extend
 * is clipped to `vis` and to `maxDistance` tiles past each edge so the geometry stays bounded; air
 * tiles are skipped. `colorMul` lets an animated layer tint its border like its body.
 */
export function buildEdgeExtendVertices(
    layer: TileLayer,
    vis: TileRect,
    maxDistance: number = BORDER_RENDER_DISTANCE,
    colorMul: Rgba = [1, 1, 1, 1],
): number[] {
    const W = layer.width;
    const H = layer.height;
    if (W <= 0 || H <= 0) return [];
    const [bx0, by0, bx1, by1] = vis;

    const color: Rgba = [
        (layer.color[0] / 255) * colorMul[0],
        (layer.color[1] / 255) * colorMul[1],
        (layer.color[2] / 255) * colorMul[2],
        (layer.color[3] / 255) * colorMul[3],
    ];

    // Integer cell ranges, clamped to ±maxDistance around the map edges.
    const ix0 = Math.floor(Math.max(bx0, -maxDistance));
    const iy0 = Math.floor(Math.max(by0, -maxDistance));
    const ix1 = Math.ceil(Math.min(bx1, W + maxDistance));
    const iy1 = Math.ceil(Math.min(by1, H + maxDistance));

    const out: number[] = [];
    const place = (gx: number, gy: number, sx: number, sy: number): void => {
        const t = layer.tiles[sy * W + sx];
        if (!t || t.id === 0) return; // air
        pushTile(out, gx, gy, t.id, t.flags, color);
    };

    // Left / right borders: each visible map row's edge tile repeated horizontally.
    const rowTop = Math.max(0, iy0);
    const rowBot = Math.min(H, iy1);
    for (let y = rowTop; y < rowBot; y++) {
        for (let x = ix0; x < 0; x++) place(x, y, 0, y); // left edge column
        for (let x = W; x < ix1; x++) place(x, y, W - 1, y); // right edge column
    }
    // Top / bottom borders: each visible map column's edge tile repeated vertically.
    const colLeft = Math.max(0, ix0);
    const colRight = Math.min(W, ix1);
    for (let x = colLeft; x < colRight; x++) {
        for (let y = iy0; y < 0; y++) place(x, y, x, 0); // top edge row
        for (let y = H; y < iy1; y++) place(x, y, x, H - 1); // bottom edge row
    }
    // Corners: the corner tile fills the diagonal region past it.
    for (let y = iy0; y < 0; y++) for (let x = ix0; x < 0; x++) place(x, y, 0, 0); // top-left
    for (let y = iy0; y < 0; y++) for (let x = W; x < ix1; x++) place(x, y, W - 1, 0); // top-right
    for (let y = H; y < iy1; y++) for (let x = ix0; x < 0; x++) place(x, y, 0, H - 1); // bottom-left
    for (let y = H; y < iy1; y++) for (let x = W; x < ix1; x++) place(x, y, W - 1, H - 1); // bottom-right
    return out;
}

/**
 * The kill-tile field outside the map's ±`distance` rectangle, clipped to `vis`. Decomposed exactly
 * like DDNet RenderKillTileBorder: left/right strips span the full visible height, top/bottom strips
 * fill only the inner x-range so corners are covered once (by the left/right strips).
 */
export function buildKillBorderVertices(
    mapW: number,
    mapH: number,
    vis: TileRect,
    distance: number = BORDER_RENDER_DISTANCE,
): number[] {
    const [bx0, by0, bx1, by1] = vis;
    const left = -distance;
    const top = -distance;
    const right = mapW + distance;
    const bottom = mapH + distance;

    const out: number[] = [];
    if (bx0 < left) pushRect(out, bx0, by0, left, by1); // left strip (full height)
    if (bx1 > right) pushRect(out, right, by0, bx1, by1); // right strip (full height)

    const innerX0 = Math.max(bx0, left);
    const innerX1 = Math.min(bx1, right);
    if (innerX1 > innerX0) {
        if (by0 < top) pushRect(out, innerX0, by0, innerX1, top); // top strip (inner width)
        if (by1 > bottom) pushRect(out, innerX0, bottom, innerX1, by1); // bottom strip (inner width)
    }
    return out;
}
