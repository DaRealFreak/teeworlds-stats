import type { TileLayer, Quad } from './types';

export interface UVRect {
    u0: number;
    v0: number;
    u1: number;
    v1: number;
}

/** UV cell for a tile id within a 16x16 tileset. */
export function tileUV(id: number, flags: number): UVRect {
    const col = id % 16;
    const row = (id / 16) | 0;
    const s = 1 / 16;
    let r: UVRect = { u0: col * s, v0: row * s, u1: (col + 1) * s, v1: (row + 1) * s };
    if (flags & 1) r = { ...r, v0: r.v1, v1: r.v0 }; // vflip
    if (flags & 2) r = { ...r, u0: r.u1, u1: r.u0 }; // hflip
    return r;
}

function pushQuad(
    out: number[],
    p: [number, number][],
    uv: [number, number][],
    color: [number, number, number, number],
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
        (lr / 255) * colorMul[0],
        (lg / 255) * colorMul[1],
        (lb / 255) * colorMul[2],
        (la / 255) * colorMul[3],
    ];
    for (let y = 0; y < layer.height; y++) {
        for (let x = 0; x < layer.width; x++) {
            const tile = layer.tiles[y * layer.width + x];
            if (!tile || tile.id === 0) continue; // air
            const uvr = tileUV(tile.id, tile.flags);
            const p: [number, number][] = [
                [x, y],
                [x + 1, y],
                [x, y + 1],
                [x + 1, y + 1],
            ];
            const uv: [number, number][] = [
                [uvr.u0, uvr.v0],
                [uvr.u1, uvr.v0],
                [uvr.u0, uvr.v1],
                [uvr.u1, uvr.v1],
            ];
            pushQuad(out, p, uv, color);
        }
    }
    return out;
}

/** Build interleaved vertices for one quad. `posOffset` shifts all corners; `colorMul` multiplies corner colors (0..1). */
export function buildQuadVertices(
    quad: Quad,
    posOffset: [number, number],
    colorMul: [number, number, number, number],
): number[] {
    const out: number[] = [];
    const p: [number, number][] = [0, 1, 2, 3].map((i) => {
        const pt = quad.points[i]!;
        return [pt[0] + posOffset[0], pt[1] + posOffset[1]];
    });
    const uv: [number, number][] = [0, 1, 2, 3].map((i) => quad.texCoords[i]!);
    for (const tri of [
        [0, 1, 2],
        [1, 3, 2],
    ]) {
        for (const idx of tri) {
            const c = quad.colors[idx]!;
            out.push(
                p[idx]![0],
                p[idx]![1],
                uv[idx]![0],
                uv[idx]![1],
                (c[0] / 255) * colorMul[0],
                (c[1] / 255) * colorMul[1],
                (c[2] / 255) * colorMul[2],
                (c[3] / 255) * colorMul[3],
            );
        }
    }
    return out;
}
