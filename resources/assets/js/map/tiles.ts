import type { Tile } from './types';

/**
 * Decode a 'Tile' byte array (4 bytes/tile: id, flags, skip, unused) into w*h tiles.
 * When `useSkip` (tilemap layer version >= 4, the 0.7 RLE), each entry repeats `skip+1` times;
 * the expanded tiles carry skip=0 (Teeworlds rendering assumes skip is 0).
 */
export function expandTiles(bytes: Uint8Array, width: number, height: number, useSkip: boolean): Tile[] {
    const total = width * height;
    const tiles: Tile[] = [];
    let p = 0;
    while (tiles.length < total && p + 4 <= bytes.length) {
        const id = bytes[p] ?? 0;
        const flags = bytes[p + 1] ?? 0;
        const skip = bytes[p + 2] ?? 0;
        p += 4;
        const repeat = useSkip ? skip + 1 : 1;
        for (let r = 0; r < repeat && tiles.length < total; r++) tiles.push({ id, flags, skip: 0 });
    }
    while (tiles.length < total) tiles.push({ id: 0, flags: 0, skip: 0 }); // pad a truncated layer
    return tiles;
}
