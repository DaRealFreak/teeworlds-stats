import { describe, it, expect } from 'vitest';
import { diffPhysics, formatVerdict, overlayState } from '../../../resources/assets/js/map/diff';
import type { MapScene, TileLayer, Group, LayerKind } from '../../../resources/assets/js/map/types';

// Build a tile layer; flags/number arrays are parallel to ids when supplied.
function layer(
    ids: number[],
    w: number,
    h: number,
    kind: LayerKind = 'game',
    opts: { flags?: number[]; number?: number[] } = {},
): TileLayer {
    const tl: TileLayer = {
        type: 'tilemap',
        kind,
        width: w,
        height: h,
        color: [255, 255, 255, 255],
        colorEnv: -1,
        colorEnvOffset: 0,
        image: -1,
        name: '',
        tiles: ids.map((id, i) => ({ id, flags: opts.flags?.[i] ?? 0, skip: 0 })),
    };
    if (opts.number) tl.physics = { number: Uint8Array.from(opts.number) };
    return tl;
}

function sceneOf(...layers: TileLayer[]): MapScene {
    const group: Group = {
        xOffset: 0, yOffset: 0, xParallax: 100, yParallax: 100,
        clipping: false, clipX: 0, clipY: 0, clipW: 0, clipH: 0, name: 'Game', layers,
    };
    return { info: { author: '', version: '', credits: '', license: '', settings: [] }, images: [], envelopes: [], groups: [group] };
}

describe('diffPhysics', () => {
    it('reports zero changes for identical scenes', () => {
        const d = diffPhysics(sceneOf(layer([1, 0, 1, 0], 2, 2)), sceneOf(layer([1, 0, 1, 0], 2, 2)));
        expect(d.aligned).toBe(true);
        expect(d.totals).toEqual({ added: 0, removed: 0, modified: 0 });
        expect(d.cells).toEqual([]);
    });

    it('classifies added and modified game cells', () => {
        // base [1,1,0,0] → target [1,2,1,0]: i1 1→2 modified, i2 0→1 added.
        const d = diffPhysics(sceneOf(layer([1, 1, 0, 0], 2, 2)), sceneOf(layer([1, 2, 1, 0], 2, 2)));
        expect(d.totals).toEqual({ added: 1, removed: 0, modified: 1 });
        expect(d.cells).toContainEqual({ x: 0, y: 1, kind: 'game', category: 'added' });
        expect(d.cells).toContainEqual({ x: 1, y: 0, kind: 'game', category: 'modified' });
    });

    it('treats a solid→air game cell as removed', () => {
        const d = diffPhysics(sceneOf(layer([1], 1, 1)), sceneOf(layer([0], 1, 1)));
        expect(d.totals).toEqual({ added: 0, removed: 1, modified: 0 });
        expect(d.cells).toEqual([{ x: 0, y: 0, kind: 'game', category: 'removed' }]);
    });

    it('treats a flipped game tile (same id, different flags) as modified', () => {
        const a = sceneOf(layer([1], 1, 1, 'game', { flags: [0] }));
        const b = sceneOf(layer([1], 1, 1, 'game', { flags: [1] }));
        expect(diffPhysics(a, b).totals.modified).toBe(1);
    });

    it('treats a re-numbered tele cell (same id, different number) as modified', () => {
        const game = layer([1], 1, 1, 'game'); // shared so dims align
        const a = sceneOf(game, layer([26], 1, 1, 'tele', { number: [5] }));
        const b = sceneOf(game, layer([26], 1, 1, 'tele', { number: [7] }));
        const d = diffPhysics(a, b);
        expect(d.aligned).toBe(true);
        expect(d.layers.find((l) => l.kind === 'tele')!.modified).toBe(1);
        expect(d.cells).toContainEqual({ x: 0, y: 0, kind: 'tele', category: 'modified' });
    });

    it('reports a physics kind present on only one side structurally, with no cells', () => {
        const a = sceneOf(layer([1], 1, 1, 'game'));
        const b = sceneOf(layer([1], 1, 1, 'game'), layer([0], 1, 1, 'front'));
        const d = diffPhysics(a, b);
        const front = d.layers.find((l) => l.kind === 'front')!;
        expect(front.inA).toBe(false);
        expect(front.inB).toBe(true);
        expect(d.cells.every((c) => c.kind !== 'front')).toBe(true);
    });

    it('is not aligned and emits no cells when game dims differ', () => {
        const d = diffPhysics(sceneOf(layer([1, 1], 2, 1)), sceneOf(layer([1, 1, 1, 1], 2, 2)));
        expect(d.aligned).toBe(false);
        expect(d.cells).toEqual([]);
        expect(d.gameDims).toEqual({ a: [2, 1], b: [2, 2] });
    });

    it('ignores design (tiles) layers entirely', () => {
        const a = sceneOf(layer([1], 1, 1, 'game'), layer([5], 1, 1, 'tiles'));
        const b = sceneOf(layer([1], 1, 1, 'game'), layer([9], 1, 1, 'tiles'));
        expect(diffPhysics(a, b).totals).toEqual({ added: 0, removed: 0, modified: 0 });
    });

    it('skips cells for a physics layer whose dims differ even when the game layers align', () => {
        // game layers align (1×1 both), but the tele layer is 1×1 vs 2×1 — the per-layer gate skips it.
        const a = sceneOf(layer([1], 1, 1, 'game'), layer([26], 1, 1, 'tele', { number: [1] }));
        const b = sceneOf(layer([1], 1, 1, 'game'), layer([26, 26], 2, 1, 'tele', { number: [1, 1] }));
        const d = diffPhysics(a, b);
        expect(d.aligned).toBe(true);
        const tele = d.layers.find((l) => l.kind === 'tele')!;
        expect(tele.inA).toBe(true);
        expect(tele.inB).toBe(true);
        expect(tele.added + tele.removed + tele.modified).toBe(0);
        expect(d.cells.some((c) => c.kind === 'tele')).toBe(false);
    });
});

describe('formatVerdict', () => {
    it('summarises an aligned diff with counts and changed kinds', () => {
        const d = diffPhysics(sceneOf(layer([1, 1, 0, 0], 2, 2)), sceneOf(layer([1, 2, 1, 0], 2, 2)));
        expect(formatVerdict(d)).toBe('Same dimensions — 2 physics cells changed: +1 / -0 / ~1 across game.');
    });
    it('reports identical layers', () => {
        const d = diffPhysics(sceneOf(layer([1], 1, 1)), sceneOf(layer([1], 1, 1)));
        expect(formatVerdict(d)).toBe('Identical physics layers — no cell changed.');
    });
    it('reports a dimension mismatch as a likely different map', () => {
        const d = diffPhysics(sceneOf(layer([1, 1], 2, 1)), sceneOf(layer([1, 1, 1, 1], 2, 2)));
        expect(formatVerdict(d)).toBe('Different dimensions 2×1 → 2×2 — likely a different map.');
    });
});

describe('overlayState', () => {
    it('returns "shown" for an aligned diff with at least one changed cell', () => {
        const d = diffPhysics(sceneOf(layer([1, 1, 0, 0], 2, 2)), sceneOf(layer([1, 2, 1, 0], 2, 2)));
        expect(overlayState(d)).toBe('shown');
    });
    it('returns "identical" for an aligned diff with no changed cells', () => {
        const d = diffPhysics(sceneOf(layer([1], 1, 1)), sceneOf(layer([1], 1, 1)));
        expect(overlayState(d)).toBe('identical');
    });
    it('returns "misaligned" when the game dimensions differ', () => {
        const d = diffPhysics(sceneOf(layer([1, 1], 2, 1)), sceneOf(layer([1, 1, 1, 1], 2, 2)));
        expect(overlayState(d)).toBe('misaligned');
    });
});
