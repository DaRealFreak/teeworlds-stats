import { describe, it, expect } from 'vitest';
import { expandTiles, decodePhysicsTiles } from '../../../resources/assets/js/map/tiles';

describe('expandTiles', () => {
    it('reads one Tile per cell when not RLE-compressed (layer version < 4)', () => {
        // 2 cells: id=5 flags=8(rot), id=1 flags=0 — each 4 bytes (id, flags, skip, unused)
        const bytes = new Uint8Array([5, 8, 0, 0, 1, 0, 0, 0]);
        const tiles = expandTiles(bytes, 2, 1, false);
        expect(tiles).toHaveLength(2);
        expect(tiles[0]).toMatchObject({ id: 5, flags: 8 });
        expect(tiles[1]).toMatchObject({ id: 1, flags: 0 });
    });

    it('expands skip-RLE (layer version >= 4): skip=2 means 3 copies', () => {
        // one Tile id=7 with skip=2 then a Tile id=9 skip=0 → [7,7,7,9]
        const bytes = new Uint8Array([7, 0, 2, 0, 9, 0, 0, 0]);
        const tiles = expandTiles(bytes, 4, 1, true);
        expect(tiles.map((t) => t.id)).toEqual([7, 7, 7, 9]);
        expect(tiles.every((t) => t.skip === 0)).toBe(true);
    });
});

describe('decodePhysicsTiles', () => {
    it('tele: 2-byte {number, type} → id=type, physics.number', () => {
        // cell0 number=5 type=26(TELEIN), cell1 number=5 type=27(TELEOUT)
        const bytes = new Uint8Array([5, 26, 5, 27]);
        const { tiles, physics } = decodePhysicsTiles(bytes, 2, 1, 'tele');
        expect(tiles.map((t) => t.id)).toEqual([26, 27]);
        expect(Array.from(physics.number ?? [])).toEqual([5, 5]);
    });

    it('switch: 4-byte {number, type, flags, delay} → id, flags(rotation), number, delay', () => {
        // number=3 type=22 flags=8(rotate) delay=10
        const bytes = new Uint8Array([3, 22, 8, 10]);
        const { tiles, physics } = decodePhysicsTiles(bytes, 1, 1, 'switch');
        expect(tiles[0]).toMatchObject({ id: 22, flags: 8 });
        expect(physics.number?.[0]).toBe(3);
        expect(physics.delay?.[0]).toBe(10);
    });

    it('speedup: reads angle as the trailing i16 of the stride (6-byte struct w/ padding)', () => {
        // force=50 maxSpeed=200 type=29 pad=0 angle=270 (LE i16: 0x010E)
        const bytes = new Uint8Array([50, 200, 29, 0, 0x0e, 0x01]);
        const { tiles, physics } = decodePhysicsTiles(bytes, 1, 1, 'speedup');
        expect(tiles[0]!.id).toBe(29);
        expect(physics.force?.[0]).toBe(50);
        expect(physics.maxSpeed?.[0]).toBe(200);
        expect(physics.angle?.[0]).toBe(270);
    });

    it('is robust to a packed (5-byte) speedup struct: angle still the last 2 bytes', () => {
        // packed: force=10 maxSpeed=20 type=29 angle=90 (LE i16: 0x5A 0x00)
        const bytes = new Uint8Array([10, 20, 29, 0x5a, 0x00]);
        const { physics } = decodePhysicsTiles(bytes, 1, 1, 'speedup');
        expect(physics.angle?.[0]).toBe(90);
    });
});
