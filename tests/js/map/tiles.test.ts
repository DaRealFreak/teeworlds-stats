import { describe, it, expect } from 'vitest';
import { expandTiles } from '../../../resources/assets/js/map/tiles';

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
