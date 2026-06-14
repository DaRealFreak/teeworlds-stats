import { describe, it, expect } from 'vitest';
import { tileUV, buildTileLayerVertices, buildQuadVertices } from '../../../resources/assets/js/map/geometry';
import type { TileLayer } from '../../../resources/assets/js/map/types';

describe('geometry', () => {
    it('maps tile id 0 to the top-left cell, id 17 to col1/row1', () => {
        // tileUV returns four corners [TL, TR, BL, BR]; TL/BR span the cell (UVs are inset by
        // half a texel to prevent edge bleed, hence toBeCloseTo rather than exact equality).
        const a = tileUV(0, 0);
        expect(a[0][0]).toBeCloseTo(0); // TL.u
        expect(a[0][1]).toBeCloseTo(0); // TL.v
        expect(a[3][0]).toBeCloseTo(1 / 16); // BR.u
        expect(a[3][1]).toBeCloseTo(1 / 16); // BR.v
        const uv = tileUV(17, 0);
        expect(uv[0][0]).toBeCloseTo(1 / 16); // TL.u
        expect(uv[0][1]).toBeCloseTo(1 / 16); // TL.v
    });

    it('emits 48 floats per non-air tile and skips air (id 0)', () => {
        const layer: TileLayer = {
            type: 'tilemap', kind: 'tiles', width: 2, height: 1,
            color: [255, 255, 255, 255], colorEnv: -1, colorEnvOffset: 0, image: 0, name: '',
            tiles: [{ id: 0, flags: 0, skip: 0 }, { id: 5, flags: 0, skip: 0 }],
        };
        const verts = buildTileLayerVertices(layer, [1, 1, 1, 1]);
        expect(verts.length).toBe(48);
    });

    it('places the non-air tile at its grid cell (world units = tile index)', () => {
        const layer: TileLayer = {
            type: 'tilemap', kind: 'tiles', width: 2, height: 1,
            color: [255, 255, 255, 255], colorEnv: -1, colorEnvOffset: 0, image: 0, name: '',
            tiles: [{ id: 0, flags: 0, skip: 0 }, { id: 5, flags: 0, skip: 0 }],
        };
        const v = buildTileLayerVertices(layer, [1, 1, 1, 1]);
        expect(v[0]).toBe(1);
        expect(v[1]).toBe(0);
    });

    // Regression: tile flip/rotate must match CRenderMap::RenderTilemap exactly.
    // DDNet bit 1 = TILEFLAG_XFLIP (mirror U/horizontal), bit 2 = TILEFLAG_YFLIP
    // (mirror V/vertical), bit 8 = TILEFLAG_ROTATE (90° CW). The legacy names
    // "VFLIP"/"HFLIP" describe the opposite axis, which is the trap this guards.
    //
    // Asserted through buildTileLayerVertices (stable API): a 1x1 layer of tile id 1
    // (id 0 is air and would be skipped). Cell id 1 spans uL=1/16,uR=2/16,vT=0,vB=1/16.
    // Vertex 0 is corner TL (u@idx2,v@idx3); vertex 1 is corner TR (u@idx10,v@idx11).
    const s = 1 / 16;
    const oneTile = (flags: number): number[] => {
        const layer: TileLayer = {
            type: 'tilemap', kind: 'tiles', width: 1, height: 1,
            color: [255, 255, 255, 255], colorEnv: -1, colorEnvOffset: 0, image: 0, name: '',
            tiles: [{ id: 1, flags, skip: 0 }],
        };
        return buildTileLayerVertices(layer, [1, 1, 1, 1]);
    };

    it('no flags: TL corner samples the cell top-left', () => {
        const v = oneTile(0);
        expect(v[2]).toBeCloseTo(s);   // u = left edge of cell 1
        expect(v[3]).toBeCloseTo(0);   // v = top
    });
    it('XFLIP (bit 1) mirrors horizontally: TL samples the right edge', () => {
        const v = oneTile(1);
        expect(v[2]).toBeCloseTo(2 * s); // u -> right
        expect(v[3]).toBeCloseTo(0);     // v unchanged
    });
    it('YFLIP (bit 2) mirrors vertically: TL samples the bottom edge', () => {
        const v = oneTile(2);
        expect(v[2]).toBeCloseTo(s);   // u unchanged
        expect(v[3]).toBeCloseTo(s);   // v -> bottom
    });
    it('ROTATE (bit 8) rotates the cell 90° CW', () => {
        const v = oneTile(8);
        expect(v[2]).toBeCloseTo(s);   // TL.u
        expect(v[3]).toBeCloseTo(s);   // TL.v
        expect(v[10]).toBeCloseTo(s);  // TR.u
        expect(v[11]).toBeCloseTo(0);  // TR.v
    });

    it('buildQuadVertices emits 48 floats, applies posOffset, and normalises per-corner colors', () => {
        // Triangle order [0,1,2],[1,3,2] — first vertex is corner 0 (TL).
        // Interleave per vertex: x, y, u, v, r, g, b, a.
        const quad = {
            points: [[0, 0], [2, 0], [0, 2], [2, 2], [1, 1]] as [number, number][],
            colors: [[255, 0, 0, 255], [0, 255, 0, 255], [0, 0, 255, 255], [255, 255, 255, 255]] as [number, number, number, number][],
            texCoords: [[0, 0], [1, 0], [0, 1], [1, 1]] as [number, number][],
            posEnv: -1, posEnvOffset: 0, colorEnv: -1, colorEnvOffset: 0,
        };
        const v = buildQuadVertices(quad, [10, 20], [1, 1, 1, 1]);
        expect(v.length).toBe(48);
        // First vertex = corner 0 (TL): pos (0,0) + offset (10,20); color red normalised.
        expect(v[0]).toBe(10);           // x
        expect(v[1]).toBe(20);           // y
        expect(v[4]).toBeCloseTo(1, 5);  // r = 255/255 * 1
        expect(v[5]).toBeCloseTo(0, 5);  // g = 0/255 * 1
    });

    // Quad pivot rotation — CRenderMap::ForceRenderQuads + Rotate (render_map.cpp):
    // each corner is rotated around the pivot points[4] by the position-envelope angle
    //   x' = dx·cos − dy·sin + cx,  y' = dx·sin + dy·cos + cy  (dx = px−cx, dy = py−cy)
    // and the offset is added AFTER the rotation.
    const rotQuad = () => ({
        // All four corners at (2,1); pivot points[4] at (1,1).
        points: [[2, 1], [2, 1], [2, 1], [2, 1], [1, 1]] as [number, number][],
        colors: [[255, 255, 255, 255], [255, 255, 255, 255], [255, 255, 255, 255], [255, 255, 255, 255]] as [number, number, number, number][],
        texCoords: [[0, 0], [1, 0], [0, 1], [1, 1]] as [number, number][],
        posEnv: -1, posEnvOffset: 0, colorEnv: -1, colorEnvOffset: 0,
    });

    it('leaves corners unrotated when the angle is 0', () => {
        const v = buildQuadVertices(rotQuad(), [0, 0], [1, 1, 1, 1], 0);
        expect(v[0]).toBe(2);
        expect(v[1]).toBe(1);
    });

    it('rotates each corner around the pivot points[4]', () => {
        // (2,1) about pivot (1,1): dx=1, dy=0; 90° → (0,1) → +pivot = (1,2).
        const v = buildQuadVertices(rotQuad(), [0, 0], [1, 1, 1, 1], Math.PI / 2);
        expect(v[0]).toBeCloseTo(1, 6); // x
        expect(v[1]).toBeCloseTo(2, 6); // y
    });

    it('adds the position offset after rotating', () => {
        // rotated corner (1,2) then + offset (10,20) = (11,22) — offset is not itself rotated.
        const v = buildQuadVertices(rotQuad(), [10, 20], [1, 1, 1, 1], Math.PI / 2);
        expect(v[0]).toBeCloseTo(11, 6);
        expect(v[1]).toBeCloseTo(22, 6);
    });
});
