import type { MapScene, TileLayer, LayerKind } from './types';
import { HIGHLIGHT_CATEGORIES, CHECKPOINT_FIRST, CHECKPOINT_LAST } from './highlights';

/**
 * One legend row. Group parents carry `children`; leaf rows don't. Typed-group children
 * (weapons/pickups) are real categories with their own color + outline toggle; numbered-group
 * children (`tele:<n>`/`cp:<n>`) are jump-only and share the parent's single outline color.
 */
export interface LegendEntry {
    key: string;
    label: string;
    color: [number, number, number] | null; // null = no swatch (group master / numbered child)
    count: number;
    toggleable: boolean; // has an outline checkbox
    jumpable: boolean;
    children?: LegendEntry[];
}

export interface MapIndex {
    /** Lean UI descriptor (present categories only). */
    entries: LegendEntry[];
    /** Jump key (leaf categories incl. `tele:<n>`/`cp:<n>`) -> instance cells, in scan order. */
    positions: Map<string, [number, number][]>;
}

// Physics layers whose tiles[i].id carries a meaningful physics/entity id.
const SCANNED_KINDS = new Set<LayerKind>(['game', 'front', 'tele']);

// Ungrouped leaf rows, in display order (each is a HIGHLIGHT_CATEGORIES key).
const FLAT_KEYS = ['start', 'finish', 'spawn'];

// Typed groups: a master toggle over member categories that each keep their own color + toggle.
const TYPED_GROUPS: { key: string; label: string; members: string[] }[] = [
    { key: 'weapons', label: 'Weapons', members: ['shotgun', 'grenade', 'laser', 'ninja'] },
    { key: 'pickups', label: 'Pickups', members: ['health', 'armor'] },
];

const CATEGORY = new Map(HIGHLIGHT_CATEGORIES.map((c) => [c.key, c]));

/**
 * Scan the game group's physics layers once and report which categories are present (with
 * counts) plus the cells to jump to, organized into the navigator's grouped structure:
 * flat Start/Finish/Spawn, the Weapons/Pickups typed groups, and the Teleports/Checkpoints
 * numbered groups (children split by tele number / checkpoint number).
 */
export function buildMapIndex(scene: MapScene, gameGroupIndex: number): MapIndex {
    const entries: LegendEntry[] = [];
    const positions = new Map<string, [number, number][]>();
    const group = scene.groups[gameGroupIndex];
    if (!group) return { entries, positions };

    const idToKey = new Map<number, string>();
    for (const cat of HIGHLIGHT_CATEGORIES) {
        if (cat.key === 'checkpoints') continue; // checkpoints are matched by range below
        for (const id of cat.ids) idToKey.set(id, cat.key);
    }

    const counts = new Map<string, number>();
    const cpByNumber = new Map<number, [number, number][]>();
    const teleByNumber = new Map<number, [number, number][]>();
    const pushNum = (map: Map<number, [number, number][]>, n: number, x: number, y: number): void => {
        let arr = map.get(n);
        if (!arr) {
            arr = [];
            map.set(n, arr);
        }
        arr.push([x, y]);
    };
    const pushPos = (key: string, x: number, y: number): void => {
        let arr = positions.get(key);
        if (!arr) {
            arr = [];
            positions.set(key, arr);
        }
        arr.push([x, y]);
    };

    for (const layer of group.layers) {
        if (layer.type !== 'tilemap' || !SCANNED_KINDS.has(layer.kind)) continue;
        const tl: TileLayer = layer;
        const numbers = tl.physics?.number;
        for (let y = 0; y < tl.height; y++) {
            for (let x = 0; x < tl.width; x++) {
                const i = y * tl.width + x;
                const id = tl.tiles[i]?.id ?? 0;
                if (id === 0) continue;
                if (id >= CHECKPOINT_FIRST && id <= CHECKPOINT_LAST) {
                    counts.set('checkpoints', (counts.get('checkpoints') ?? 0) + 1);
                    pushNum(cpByNumber, id - CHECKPOINT_FIRST + 1, x, y);
                    continue;
                }
                const key = idToKey.get(id);
                if (!key) continue;
                counts.set(key, (counts.get(key) ?? 0) + 1);
                if (key === 'teleport') {
                    // Teleports number their cells in the tele layer's physics meta, not the id.
                    const n = numbers?.[i] ?? 0;
                    if (n > 0) pushNum(teleByNumber, n, x, y);
                } else if (CATEGORY.get(key)?.jumpable) {
                    pushPos(key, x, y);
                }
            }
        }
    }

    // A present leaf category (own color, own toggle, jumpable). Returns null when absent.
    const leaf = (key: string): LegendEntry | null => {
        const cat = CATEGORY.get(key);
        const count = counts.get(key) ?? 0;
        if (!cat || count === 0) return null;
        return { key, label: cat.label, color: cat.color, count, toggleable: true, jumpable: true };
    };

    // A numbered group (Teleports/Checkpoints): one outline color + master toggle on the parent,
    // jump-only numbered children. Returns null when the category is absent.
    const numberedGroup = (
        catKey: string,
        byNumber: Map<number, [number, number][]>,
        childKeyPrefix: string,
        childLabelPrefix: string,
    ): LegendEntry | null => {
        const cat = CATEGORY.get(catKey);
        const count = counts.get(catKey) ?? 0;
        if (!cat || count === 0) return null;
        const numbers = Array.from(byNumber.keys()).sort((a, b) => a - b);
        const children: LegendEntry[] = numbers.map((n) => {
            const cells = byNumber.get(n)!;
            positions.set(`${childKeyPrefix}${n}`, cells);
            return {
                key: `${childKeyPrefix}${n}`,
                label: `${childLabelPrefix} ${n}`,
                color: null,
                count: cells.length,
                toggleable: false,
                jumpable: true,
            };
        });
        return { key: catKey, label: cat.label, color: cat.color, count, toggleable: true, jumpable: false, children };
    };

    for (const key of FLAT_KEYS) {
        const e = leaf(key);
        if (e) entries.push(e);
    }

    for (const g of TYPED_GROUPS) {
        const children = g.members.map(leaf).filter((e): e is LegendEntry => e !== null);
        if (children.length === 0) continue;
        const total = children.reduce((sum, c) => sum + c.count, 0);
        // color: null -> the group header shows a master checkbox only; children carry the colors.
        entries.push({ key: g.key, label: g.label, color: null, count: total, toggleable: true, jumpable: false, children });
    }

    const tele = numberedGroup('teleport', teleByNumber, 'tele:', 'Teleport');
    if (tele) entries.push(tele);
    const checkpoints = numberedGroup('checkpoints', cpByNumber, 'cp:', 'Checkpoint');
    if (checkpoints) entries.push(checkpoints);

    return { entries, positions };
}
