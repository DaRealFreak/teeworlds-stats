import { describe, it, expect } from 'vitest';
import { tileUV, buildTileLayerVertices, buildQuadVertices } from '../../../resources/assets/js/map/geometry';
import type { TileLayer } from '../../../resources/assets/js/map/types';

describe('geometry', () => {
    it('maps tile id 0 to the top-left cell, id 17 to col1/row1', () => {
        expect(tileUV(0, 0)).toMatchObject({ u0: 0, v0: 0, u1: 1 / 16, v1: 1 / 16 });
        const uv = tileUV(17, 0);
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
});
