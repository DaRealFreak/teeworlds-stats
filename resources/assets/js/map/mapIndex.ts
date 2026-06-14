import type { MapScene, TileLayer, LayerKind } from './types';
import { HIGHLIGHT_CATEGORIES, CHECKPOINT_FIRST, CHECKPOINT_LAST } from './highlights';

/**
 * One legend row. Top-level rows mirror a HIGHLIGHT_CATEGORIES entry; checkpoint
 * children (key `cp:<n>`) are jump-only and carry no swatch/checkbox.
 */
export interface LegendEntry {
    key: string;
    label: string;
    color: [number, number, number] | null; // null = no swatch (checkpoint child)
    count: number;
    toggleable: boolean; // has an outline checkbox
    jumpable: boolean;
    children?: LegendEntry[]; // checkpoints only
}

export interface MapIndex {
    /** Lean UI descriptor (present categories only), ordered like HIGHLIGHT_CATEGORIES. */
    entries: LegendEntry[];
    /** Jump key (incl. `cp:<n>`) -> instance cells, in scan order. */
    positions: Map<string, [number, number][]>;
}

// Physics layers whose tiles[i].id carries a meaningful physics/entity id.
const SCANNED_KINDS = new Set<LayerKind>(['game', 'front', 'tele']);

/**
 * Scan the game group's physics layers once and report which highlight categories are
 * present (with counts) plus the cells to jump to. Checkpoints split per number; Freeze
 * is counted but not enumerated (it can cover thousands of cells and isn't a landmark).
 */
export function buildMapIndex(scene: MapScene, gameGroupIndex: number): MapIndex {
    const entries: LegendEntry[] = [];
    const positions = new Map<string, [number, number][]>();
    const group = scene.groups[gameGroupIndex];
    if (!group) return { entries, positions };

    const idToKey = new Map<number, string>();
    const jumpable = new Set<string>();
    for (const cat of HIGHLIGHT_CATEGORIES) {
        if (cat.jumpable) jumpable.add(cat.key);
        if (cat.key === 'checkpoints') continue; // checkpoints are matched by range below
        for (const id of cat.ids) idToKey.set(id, cat.key);
    }

    const counts = new Map<string, number>();
    const cpPositions = new Map<number, [number, number][]>();
    let cpCount = 0;
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
        for (let y = 0; y < tl.height; y++) {
            for (let x = 0; x < tl.width; x++) {
                const id = tl.tiles[y * tl.width + x]?.id ?? 0;
                if (id === 0) continue;
                if (id >= CHECKPOINT_FIRST && id <= CHECKPOINT_LAST) {
                    const n = id - CHECKPOINT_FIRST + 1;
                    cpCount++;
                    let arr = cpPositions.get(n);
                    if (!arr) {
                        arr = [];
                        cpPositions.set(n, arr);
                    }
                    arr.push([x, y]);
                    continue;
                }
                const key = idToKey.get(id);
                if (!key) continue;
                counts.set(key, (counts.get(key) ?? 0) + 1);
                if (jumpable.has(key)) pushPos(key, x, y);
            }
        }
    }

    for (const cat of HIGHLIGHT_CATEGORIES) {
        if (cat.key === 'checkpoints') {
            if (cpCount === 0) continue;
            const numbers = Array.from(cpPositions.keys()).sort((a, b) => a - b);
            const children: LegendEntry[] = numbers.map((n) => {
                const cells = cpPositions.get(n)!;
                positions.set(`cp:${n}`, cells);
                return {
                    key: `cp:${n}`,
                    label: `Checkpoint ${n}`,
                    color: null,
                    count: cells.length,
                    toggleable: false,
                    jumpable: true,
                };
            });
            entries.push({
                key: 'checkpoints',
                label: cat.label,
                color: cat.color,
                count: cpCount,
                toggleable: true,
                jumpable: false,
                children,
            });
            continue;
        }
        const count = counts.get(cat.key) ?? 0;
        if (count === 0) continue;
        entries.push({
            key: cat.key,
            label: cat.label,
            color: cat.color,
            count,
            toggleable: true,
            jumpable: cat.jumpable,
        });
    }

    return { entries, positions };
}
