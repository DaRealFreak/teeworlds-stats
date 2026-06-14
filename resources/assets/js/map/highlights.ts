import type { MapScene, TileLayer } from './types';

/**
 * Tile-highlight categories. Physics layers store a tile's meaning directly in `tiles[i].id`:
 * the game/front layers use DDNet's TILE_ and ENTITY_ ids, and the tele layer uses the tele
 * m_Type (we set id = m_Type at decode). Entity pickups sit at ENTITY_OFFSET + entity id.
 */
const ENTITY_OFFSET = 255 - 16 * 4; // 191 (DDNet src/game/mapitems.h)

/** DDNet time-checkpoint tile ids; checkpoint number n is tile id CHECKPOINT_FIRST + (n - 1). */
export const CHECKPOINT_FIRST = 35;
export const CHECKPOINT_LAST = 59;

export interface HighlightCategory {
    key: string;
    label: string;
    /** Outline color, 0..1 RGB. */
    color: [number, number, number];
    ids: Set<number>;
    /** Point landmark you can jump to; false for the teleport/checkpoint group parents (their numbered children carry the jumps). */
    jumpable: boolean;
}

const E = ENTITY_OFFSET;
const checkpointIds = new Set<number>(
    Array.from({ length: CHECKPOINT_LAST - CHECKPOINT_FIRST + 1 }, (_, i) => CHECKPOINT_FIRST + i),
);

export const HIGHLIGHT_CATEGORIES: readonly HighlightCategory[] = [
    { key: 'start', label: 'Start', color: [0.3, 1.0, 0.4], ids: new Set([33]), jumpable: true },
    { key: 'finish', label: 'Finish / Goal', color: [1.0, 0.85, 0.2], ids: new Set([34]), jumpable: true },
    // ENTITY_SPAWN, ENTITY_SPAWN_RED, ENTITY_SPAWN_BLUE
    { key: 'spawn', label: 'Spawn', color: [0.4, 1.0, 0.7], ids: new Set([E + 1, E + 2, E + 3]), jumpable: true },
    { key: 'shotgun', label: 'Shotgun', color: [1.0, 0.65, 0.15], ids: new Set([E + 8]), jumpable: true },
    { key: 'grenade', label: 'Grenade', color: [1.0, 0.45, 0.2], ids: new Set([E + 9]), jumpable: true },
    { key: 'laser', label: 'Laser', color: [0.7, 0.5, 1.0], ids: new Set([E + 11]), jumpable: true },
    { key: 'ninja', label: 'Ninja', color: [0.85, 0.95, 1.0], ids: new Set([E + 10]), jumpable: true },
    { key: 'health', label: 'Health', color: [1.0, 0.35, 0.4], ids: new Set([E + 7]), jumpable: true },
    { key: 'armor', label: 'Armor', color: [0.4, 0.6, 1.0], ids: new Set([E + 6]), jumpable: true },
    {
        key: 'teleport',
        label: 'Teleports',
        color: [0.4, 0.75, 1.0],
        // TELEINEVIL,TELEINWEAPON,TELEINHOOK,TELEIN,TELEOUT,TELECHECKOUT,TELECHECKIN,TELECHECKINEVIL
        ids: new Set([10, 14, 15, 26, 27, 30, 31, 63]),
        // Parent toggles all teleport outlines; per-number jumps live on its children (see mapIndex.ts).
        jumpable: false,
    },
    // Parent toggles all checkpoint outlines; per-number jumps live on its children (see mapIndex.ts).
    { key: 'checkpoints', label: 'Checkpoints', color: [0.8, 0.4, 1.0], ids: checkpointIds, jumpable: false },
] as const;

/** Outline thickness as a fraction of a tile. */
const BORDER = 0.12;

function pushRect(
    out: number[],
    x0: number,
    y0: number,
    x1: number,
    y1: number,
    c: [number, number, number, number],
): void {
    const v = (x: number, y: number): void => {
        out.push(x, y, 0, 0, c[0], c[1], c[2], c[3]);
    };
    // two triangles: (TL,TR,BL)(TR,BR,BL); u,v=0 so the white fallback texture is sampled
    v(x0, y0);
    v(x1, y0);
    v(x0, y1);
    v(x1, y0);
    v(x1, y1);
    v(x0, y1);
}

/**
 * Build interleaved outline vertices (x,y,u,v,r,g,b,a) for one category, in the game group's
 * world coordinates. Scans the game/front/tele layers (where physics ids live); design layers
 * are ignored so e.g. a design tile that happens to be index 9 is never mistaken for freeze.
 */
export function buildHighlightVertices(
    scene: MapScene,
    gameGroupIndex: number,
    category: HighlightCategory,
): Float32Array {
    const out: number[] = [];
    const c: [number, number, number, number] = [category.color[0], category.color[1], category.color[2], 0.95];
    const group = scene.groups[gameGroupIndex];
    if (!group) return new Float32Array(0);

    for (const layer of group.layers) {
        if (layer.type !== 'tilemap') continue;
        if (layer.kind !== 'game' && layer.kind !== 'front' && layer.kind !== 'tele') continue;
        const tl: TileLayer = layer;
        for (let y = 0; y < tl.height; y++) {
            for (let x = 0; x < tl.width; x++) {
                const t = tl.tiles[y * tl.width + x];
                if (!t || !category.ids.has(t.id)) continue;
                pushRect(out, x, y, x + 1, y + BORDER, c); // top
                pushRect(out, x, y + 1 - BORDER, x + 1, y + 1, c); // bottom
                pushRect(out, x, y + BORDER, x + BORDER, y + 1 - BORDER, c); // left
                pushRect(out, x + 1 - BORDER, y + BORDER, x + 1, y + 1 - BORDER, c); // right
            }
        }
    }
    return new Float32Array(out);
}
