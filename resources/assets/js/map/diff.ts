import type { MapScene, TileLayer, LayerKind, PhysicsMeta } from './types';

/** The gameplay-bearing tile layers — everything except plain design ('tiles') layers. */
export type PhysicsKind = Exclude<LayerKind, 'tiles'>;
export const PHYSICS_KINDS: readonly PhysicsKind[] = ['game', 'front', 'tele', 'speedup', 'switch', 'tune'];

export type DiffCategory = 'added' | 'removed' | 'modified';

/** One colour per change category, shared by the canvas overlay and the panel legend (single source). */
export const DIFF_COLORS: Record<DiffCategory, string> = {
    added: '#3fb950',
    removed: '#f85149',
    modified: '#d29922',
};

export interface DiffCell {
    x: number;
    y: number;
    kind: PhysicsKind;
    category: DiffCategory;
}

export interface LayerDiff {
    kind: PhysicsKind;
    inA: boolean;
    inB: boolean;
    added: number;
    removed: number;
    modified: number;
}

export interface PhysicsDiff {
    aligned: boolean; // false ⇒ game-layer dims differ or are missing ⇒ no per-cell diff
    gameDims: { a: [number, number] | null; b: [number, number] | null };
    layers: LayerDiff[];
    totals: { added: number; removed: number; modified: number };
    cells: DiffCell[];
}

/** First tile layer of a physics kind in any group — physics layers are singletons by .map convention. */
function findPhysics(scene: MapScene, kind: PhysicsKind): TileLayer | null {
    for (const group of scene.groups) {
        for (const layer of group.layers) {
            if (layer.type === 'tilemap' && layer.kind === kind) return layer;
        }
    }
    return null;
}

function dims(layer: TileLayer | null): [number, number] | null {
    return layer ? [layer.width, layer.height] : null;
}

function physVal(layer: TileLayer, field: keyof PhysicsMeta, i: number): number {
    return layer.physics?.[field]?.[i] ?? 0;
}

/**
 * Whether two cells at the same index differ in a way that matters for this kind. The base graphic is
 * tiles[i].id for every kind; the behaviour-bearing metadata varies (tele/tune number, switch
 * number+delay, speedup force+maxSpeed+angle), so a re-numbered teleport counts even when its id holds.
 */
function cellsDiffer(kind: PhysicsKind, a: TileLayer, b: TileLayer, i: number): boolean {
    if ((a.tiles[i]?.id ?? 0) !== (b.tiles[i]?.id ?? 0)) return true;
    if (kind === 'game' || kind === 'front') return (a.tiles[i]?.flags ?? 0) !== (b.tiles[i]?.flags ?? 0);
    if (kind === 'switch') {
        return (a.tiles[i]?.flags ?? 0) !== (b.tiles[i]?.flags ?? 0)
            || physVal(a, 'number', i) !== physVal(b, 'number', i)
            || physVal(a, 'delay', i) !== physVal(b, 'delay', i);
    }
    if (kind === 'speedup') {
        return physVal(a, 'force', i) !== physVal(b, 'force', i)
            || physVal(a, 'maxSpeed', i) !== physVal(b, 'maxSpeed', i)
            || physVal(a, 'angle', i) !== physVal(b, 'angle', i);
    }
    // tele, tune: keyed on the tele/tune number.
    return physVal(a, 'number', i) !== physVal(b, 'number', i);
}

/**
 * Diff the playable geometry of two parsed maps. Per-cell changes are produced only when both game
 * layers share the same dimensions ("aligned"); otherwise the result is structural only (per-kind
 * presence + dims), reflecting that differently-sized maps under one name are a different map, not an edit.
 */
export function diffPhysics(base: MapScene, target: MapScene): PhysicsDiff {
    const gameA = findPhysics(base, 'game');
    const gameB = findPhysics(target, 'game');
    const aligned = gameA !== null && gameB !== null && gameA.width === gameB.width && gameA.height === gameB.height;

    const layers: LayerDiff[] = [];
    const cells: DiffCell[] = [];
    const totals = { added: 0, removed: 0, modified: 0 };

    for (const kind of PHYSICS_KINDS) {
        const la = findPhysics(base, kind);
        const lb = findPhysics(target, kind);
        const entry: LayerDiff = { kind, inA: la !== null, inB: lb !== null, added: 0, removed: 0, modified: 0 };

        if (aligned && la && lb && la.width === lb.width && la.height === lb.height) {
            const n = la.width * la.height;
            for (let i = 0; i < n; i++) {
                const emptyA = (la.tiles[i]?.id ?? 0) === 0;
                const emptyB = (lb.tiles[i]?.id ?? 0) === 0;
                let category: DiffCategory | null = null;
                if (emptyA && !emptyB) category = 'added';
                else if (!emptyA && emptyB) category = 'removed';
                else if (!emptyA && !emptyB && cellsDiffer(kind, la, lb, i)) category = 'modified';
                if (category) {
                    entry[category]++;
                    totals[category]++;
                    cells.push({ x: i % la.width, y: Math.floor(i / la.width), kind, category });
                }
            }
        }
        layers.push(entry);
    }

    return { aligned, gameDims: { a: dims(gameA), b: dims(gameB) }, layers, totals, cells };
}

/** One-line human verdict for the panel header. */
export function formatVerdict(diff: PhysicsDiff): string {
    if (!diff.aligned) {
        const fmt = (d: [number, number] | null): string => (d ? `${d[0]}×${d[1]}` : '—');
        return `Different dimensions ${fmt(diff.gameDims.a)} → ${fmt(diff.gameDims.b)} — likely a different map.`;
    }
    const { added, removed, modified } = diff.totals;
    const total = added + removed + modified;
    if (total === 0) return 'Identical physics layers — no cell changed.';
    const kinds = diff.layers.filter((l) => l.added + l.removed + l.modified > 0).map((l) => l.kind);
    return `Same dimensions — ${total} physics cell${total === 1 ? '' : 's'} changed: ` +
        `+${added} / -${removed} / ~${modified} across ${kinds.join(', ')}.`;
}
