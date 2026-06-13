import type { Tile, PhysicsMeta, LayerKind } from './types';

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

/**
 * Decode a special physics-layer block (tele/speedup/switch/tune) into renderable tiles plus
 * per-cell metadata. Unlike design tiles these are full w*h arrays (no skip-RLE) of a
 * kind-specific struct; the stride is derived from the block length so CSpeedupTile's struct
 * padding (5 vs 6 bytes) can't desync the decode. The base graphic id is the struct's m_Type.
 *
 * Struct layouts (DDNet src/game/mapitems.h):
 *   CTeleTile   {u8 number; u8 type}                          → id=type, number
 *   CTuneTile   {u8 number; u8 type}                          → id=type, number
 *   CSwitchTile {u8 number; u8 type; u8 flags; u8 delay}      → id=type, flags, number, delay
 *   CSpeedupTile{u8 force; u8 maxSpeed; u8 type; i16 angle}   → id=type, force, maxSpeed, angle
 */
export function decodePhysicsTiles(
    bytes: Uint8Array,
    width: number,
    height: number,
    kind: LayerKind,
): { tiles: Tile[]; physics: PhysicsMeta } {
    const total = width * height;
    const stride = total > 0 ? Math.floor(bytes.length / total) : 0;
    const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const tiles: Tile[] = [];
    const physics: PhysicsMeta = {};

    const at = (i: number, off: number): number => bytes[i * stride + off] ?? 0;

    if (kind === 'switch') {
        const number = new Uint8Array(total);
        const delay = new Uint8Array(total);
        for (let i = 0; i < total; i++) {
            number[i] = at(i, 0);
            delay[i] = at(i, 3);
            tiles.push({ id: at(i, 1), flags: at(i, 2), skip: 0 }); // m_Flags carries rotation
        }
        physics.number = number;
        physics.delay = delay;
    } else if (kind === 'speedup') {
        const force = new Uint8Array(total);
        const maxSpeed = new Uint8Array(total);
        const angle = new Int16Array(total);
        for (let i = 0; i < total; i++) {
            force[i] = at(i, 0);
            maxSpeed[i] = at(i, 1);
            // m_Angle is the trailing i16 of the stride (padding-robust).
            const ap = i * stride + (stride - 2);
            angle[i] = ap >= 0 && ap + 1 < bytes.length ? dv.getInt16(ap, true) : 0;
            tiles.push({ id: at(i, 2), flags: 0, skip: 0 });
        }
        physics.force = force;
        physics.maxSpeed = maxSpeed;
        physics.angle = angle;
    } else {
        // tele / tune: {number, type}
        const number = new Uint8Array(total);
        for (let i = 0; i < total; i++) {
            number[i] = at(i, 0);
            tiles.push({ id: at(i, 1), flags: 0, skip: 0 });
        }
        physics.number = number;
    }

    return { tiles, physics };
}
