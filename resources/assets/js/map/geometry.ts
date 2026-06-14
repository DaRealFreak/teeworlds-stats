import type { TileLayer, Quad } from './types';

/** Per-corner tile UVs in render-corner order [TL, TR, BL, BR] (matches the positions built below). */
export type TileUV = [[number, number], [number, number], [number, number], [number, number]];

// Tile flags. Despite the legacy "VFLIP"/"HFLIP" names, bit 1 mirrors the U (horizontal)
// axis and bit 2 mirrors the V (vertical) axis — see DDNet src/game/mapitems.h.
const TILEFLAG_XFLIP = 1;
const TILEFLAG_YFLIP = 2;
const TILEFLAG_ROTATE = 8;

/**
 * UVs for tile `id` within a 16x16 tileset, with flip/rotate applied.
 *
 * The flip/rotate sequence transcribes CRenderMap::RenderTilemap (DDNet
 * src/game/map/render_map.cpp) line-for-line: a rect cannot express a 90°
 * rotation, so we carry four explicit corners and mutate them exactly as the
 * client does. This is what makes flipped/rotated tiles match in-game.
 */
export function tileUV(id: number, flags: number): TileUV {
    const col = id % 16;
    const row = (id / 16) | 0;
    const s = 1 / 16;
    // Inset the cell by half a texel (tilesets are 1024px → 64px cells) so neighbouring tiles
    // never bleed in at fractional zoom — the same sub-texel correction the client applies via
    // its Nudge/Frac offset. Applied symmetrically before flip/rotate so the corners stay valid.
    const HALF_TEXEL = 0.5 / 1024;
    const uL = col * s + HALF_TEXEL;
    const uR = (col + 1) * s - HALF_TEXEL;
    const vT = row * s + HALF_TEXEL;
    const vB = (row + 1) * s - HALF_TEXEL;

    // DDNet corner order: (x0,y0)=TL (x1,y1)=TR (x2,y2)=BR (x3,y3)=BL.
    let x0 = uL,
        y0 = vT;
    let x1 = uR,
        y1 = vT;
    let x2 = uR,
        y2 = vB;
    let x3 = uL,
        y3 = vB;

    if (flags & TILEFLAG_XFLIP) {
        x0 = x2;
        x1 = x3;
        x2 = x3;
        x3 = x0;
    }
    if (flags & TILEFLAG_YFLIP) {
        y0 = y3;
        y2 = y1;
        y3 = y1;
        y1 = y0;
    }
    if (flags & TILEFLAG_ROTATE) {
        let tmp = x0;
        x0 = x3;
        x3 = x2;
        x2 = x1;
        x1 = tmp;
        tmp = y0;
        y0 = y3;
        y3 = y2;
        y2 = y1;
        y1 = tmp;
    }

    // Re-order DDNet's [TL, TR, BR, BL] to the [TL, TR, BL, BR] order used below.
    return [
        [x0, y0], // TL
        [x1, y1], // TR
        [x3, y3], // BL
        [x2, y2], // BR
    ];
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
            const uv = tileUV(tile.id, tile.flags); // [TL, TR, BL, BR] — corner order matches p
            const p: [number, number][] = [
                [x, y],
                [x + 1, y],
                [x, y + 1],
                [x + 1, y + 1],
            ];
            pushQuad(out, p, uv, color);
        }
    }
    return out;
}

/**
 * Build interleaved vertices for one quad. `posOffset` shifts all corners; `colorMul` multiplies
 * corner colors (0..1); `rotation` (radians) spins each corner about the quad's pivot (`points[4]`)
 * before the offset is applied — matching CRenderMap::ForceRenderQuads + Rotate (render_map.cpp):
 *   x' = dx·cos − dy·sin + cx,  y' = dx·sin + dy·cos + cy   (dx = px−cx, dy = py−cy)
 */
export function buildQuadVertices(
    quad: Quad,
    posOffset: [number, number],
    colorMul: [number, number, number, number],
    rotation = 0,
): number[] {
    const out: number[] = [];
    const pivot = quad.points[4] ?? [0, 0];
    const cos = Math.cos(rotation);
    const sin = Math.sin(rotation);
    const p: [number, number][] = [0, 1, 2, 3].map((i) => {
        const pt = quad.points[i]!;
        let px = pt[0];
        let py = pt[1];
        if (rotation !== 0) {
            const dx = px - pivot[0];
            const dy = py - pivot[1];
            px = dx * cos - dy * sin + pivot[0];
            py = dx * sin + dy * cos + pivot[1];
        }
        return [px + posOffset[0], py + posOffset[1]];
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
