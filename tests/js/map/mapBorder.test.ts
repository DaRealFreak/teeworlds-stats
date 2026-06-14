import { describe, it, expect } from 'vitest';
import { BORDER_RENDER_DISTANCE, buildKillBorderVertices, buildEdgeExtendVertices } from '../../../resources/assets/js/map/mapBorder';
import { tileUV } from '../../../resources/assets/js/map/geometry';
import type { TileLayer } from '../../../resources/assets/js/map/types';

// Interleaved vertex layout is x,y,u,v,r,g,b,a (8 floats). Decode for assertions.
function verts(buf: number[]): { x: number; y: number; u: number; v: number }[] {
    const out = [];
    for (let i = 0; i < buf.length; i += 8) out.push({ x: buf[i]!, y: buf[i + 1]!, u: buf[i + 2]!, v: buf[i + 3]! });
    return out;
}
const xs = (buf: number[]) => verts(buf).map((p) => p.x);
const ys = (buf: number[]) => verts(buf).map((p) => p.y);

// DDNet draws TILE_DEATH outside a rectangle that extends BorderRenderDistance (201) tiles past every
// map edge (render_layer.cpp RenderKillTileBorder). buildKillBorderVertices emits that field clipped
// to the visible rect, decomposed left / right / top / bottom with no corner overlap.
const D = BORDER_RENDER_DISTANCE;
const W = 500;
const H = 400;

describe('buildKillBorderVertices', () => {
    it('uses DDNet BorderRenderDistance of 201', () => {
        expect(BORDER_RENDER_DISTANCE).toBe(201);
    });

    it('emits nothing when the visible rect stays inside the ±201 kill rectangle', () => {
        const buf = buildKillBorderVertices(W, H, [-50, -50, W + 50, H + 50], D);
        expect(buf).toHaveLength(0);
    });

    it('draws a left strip from the visible edge to -201 when the view pans far left', () => {
        const buf = buildKillBorderVertices(W, H, [-400, 100, 200, 300], D);
        expect(buf.length).toBeGreaterThan(0);
        expect(Math.min(...xs(buf))).toBeCloseTo(-400); // reaches the visible left edge
        expect(Math.max(...xs(buf))).toBeCloseTo(-D); // stops at -201 (kill rectangle edge)
        // full visible height
        expect(Math.min(...ys(buf))).toBeCloseTo(100);
        expect(Math.max(...ys(buf))).toBeCloseTo(300);
        // UVs equal world tile coords so the death cell aligns to the integer grid: fract() wraps on
        // tile boundaries, and the map edge (an integer) meets the field on a whole tile, never sliced.
        expect(verts(buf).every((p) => Math.abs(p.u - p.x) < 1e-9 && Math.abs(p.v - p.y) < 1e-9)).toBe(true);
    });

    it('covers all four sides when the view surrounds the map, with no corner double-cover', () => {
        const buf = buildKillBorderVertices(W, H, [-300, -300, W + 300, H + 300], D);
        // four quads × 6 verts × 8 floats
        expect(buf.length).toBe(4 * 6 * 8);
        // The top strip is the only quad lying entirely at/above the kill-rect top (y ≤ -201). It must
        // be clamped to the inner x-range [-201, W+201] — the corners are owned by the left/right strips.
        const quads: { x: number; y: number }[][] = [];
        for (let i = 0; i < buf.length; i += 48) quads.push(verts(buf.slice(i, i + 48)));
        const topQuad = quads.find((q) => q.every((p) => p.y <= -D + 1e-6))!;
        expect(topQuad).toBeDefined();
        expect(Math.min(...topQuad.map((p) => p.x))).toBeCloseTo(-D);
        expect(Math.max(...topQuad.map((p) => p.x))).toBeCloseTo(W + D);
    });

    it('only draws the bottom strip when the view pans below the map', () => {
        const buf = buildKillBorderVertices(W, H, [0, H + 100, W, H + 500], D);
        expect(buf.length).toBe(6 * 8); // single quad
        expect(Math.min(...ys(buf))).toBeCloseTo(H + D); // starts at H+201
        expect(Math.max(...ys(buf))).toBeCloseTo(H + 500);
    });
});

// Top-left vertex (x,y,u,v) of every quad — pushTile/pushQuad emit corner 0 = TL first.
function tlVerts(buf: number[]): { x: number; y: number; u: number; v: number }[] {
    const out = [];
    for (let i = 0; i < buf.length; i += 48) out.push({ x: buf[i]!, y: buf[i + 1]!, u: buf[i + 2]!, v: buf[i + 3]! });
    return out;
}

// 2×2 design layer: (0,0)=5 (1,0)=6 / (0,1)=7 (1,1)=8.
const layer2x2 = (over: Partial<TileLayer> = {}): TileLayer =>
    ({
        type: 'tilemap', kind: 'tiles', width: 2, height: 2,
        color: [255, 255, 255, 255], colorEnv: -1, colorEnvOffset: 0, image: 0, name: '',
        tiles: [
            { id: 5, flags: 0, skip: 0 }, { id: 6, flags: 0, skip: 0 },
            { id: 7, flags: 0, skip: 0 }, { id: 8, flags: 0, skip: 0 },
        ],
        ...over,
    }) as TileLayer;

// DDNet TILERENDERFLAG_EXTEND repeats the outer row/column (and corner) tiles outward past the map.
describe('buildEdgeExtendVertices', () => {
    it('emits nothing when the visible rect is inside the map', () => {
        expect(buildEdgeExtendVertices(layer2x2(), [0.2, 0.2, 1.8, 1.8], 10)).toHaveLength(0);
    });

    it('repeats the left edge column outward, reusing each row’s edge tile', () => {
        const buf = buildEdgeExtendVertices(layer2x2(), [-3, 0, 1, 2], 10);
        const tl = tlVerts(buf);
        expect(tl).toHaveLength(6); // x = -3,-2,-1 × rows 0,1
        expect(tl.every((p) => p.x < 0 && p.x >= -3)).toBe(true);
        // a row-0 border tile carries tile (0,0)=id 5's UV; a row-1 tile carries (0,1)=id 7's UV
        const row0 = tl.find((p) => p.y === 0)!;
        const row1 = tl.find((p) => p.y === 1)!;
        expect(row0.u).toBeCloseTo(tileUV(5, 0)[0][0]);
        expect(row1.u).toBeCloseTo(tileUV(7, 0)[0][0]);
    });

    it('clamps the extend to maxDistance tiles past the edge', () => {
        const buf = buildEdgeExtendVertices(layer2x2(), [-100, 0, 1, 2], 2);
        expect(Math.min(...tlVerts(buf).map((p) => p.x))).toBe(-2); // not -100
    });

    it('skips air (id 0) edge tiles', () => {
        const layer = layer2x2();
        layer.tiles[0] = { id: 0, flags: 0, skip: 0 }; // (0,0) now air
        const tl = tlVerts(buildEdgeExtendVertices(layer, [-3, 0, 1, 2], 10));
        expect(tl).toHaveLength(3); // only row 1 (id 7) repeats; row 0 is air
        expect(tl.every((p) => p.y === 1)).toBe(true);
    });

    it('repeats the corner tile into the diagonal region past the corner', () => {
        const buf = buildEdgeExtendVertices(layer2x2(), [-2, -2, 1, 1], 10);
        const tl = tlVerts(buf);
        // the top-left diagonal cell (-1,-1) repeats the corner tile (0,0)=id 5
        const corner = tl.find((p) => p.x === -1 && p.y === -1)!;
        expect(corner).toBeDefined();
        expect(corner.u).toBeCloseTo(tileUV(5, 0)[0][0]);
    });
});
