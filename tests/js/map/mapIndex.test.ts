import { describe, it, expect } from 'vitest';
import { buildMapIndex } from '../../../resources/assets/js/map/mapIndex';
import type { MapScene, TileLayer, Group, LayerKind } from '../../../resources/assets/js/map/types';

function layer(ids: number[], w: number, h: number, kind: LayerKind = 'game'): TileLayer {
    return {
        type: 'tilemap',
        kind,
        width: w,
        height: h,
        color: [255, 255, 255, 255],
        colorEnv: -1,
        colorEnvOffset: 0,
        image: -1,
        name: '',
        tiles: ids.map((id) => ({ id, flags: 0, skip: 0 })),
    };
}

function sceneOf(...layers: TileLayer[]): MapScene {
    const group: Group = {
        xOffset: 0, yOffset: 0, xParallax: 100, yParallax: 100,
        clipping: false, clipX: 0, clipY: 0, clipW: 0, clipH: 0, name: 'Game', layers,
    };
    return { info: { author: '', version: '', credits: '', license: '', settings: [] }, images: [], envelopes: [], groups: [group] };
}

describe('buildMapIndex', () => {
    it('lists only present categories, in HIGHLIGHT_CATEGORIES order, with counts', () => {
        // ids: spawn(192) appears twice, start(33) once, air(0). start is ordered before spawn.
        const { entries } = buildMapIndex(sceneOf(layer([33, 192, 0, 192], 2, 2)), 0);
        expect(entries.map((e) => e.key)).toEqual(['start', 'spawn']);
        expect(entries.find((e) => e.key === 'start')!.count).toBe(1);
        expect(entries.find((e) => e.key === 'spawn')!.count).toBe(2);
    });

    it('splits checkpoints into ascending numbered children with a summed parent count', () => {
        const { entries, positions } = buildMapIndex(sceneOf(layer([35, 37, 59], 3, 1)), 0);
        const cp = entries.find((e) => e.key === 'checkpoints')!;
        expect(cp.jumpable).toBe(false);
        expect(cp.toggleable).toBe(true);
        expect(cp.count).toBe(3);
        expect(cp.children!.map((c) => c.label)).toEqual(['Checkpoint 1', 'Checkpoint 3', 'Checkpoint 25']);
        expect(cp.children!.every((c) => c.jumpable && !c.toggleable && c.color === null)).toBe(true);
        expect(positions.get('cp:25')).toEqual([[2, 0]]);
    });

    it('marks freeze toggle-only and stores no jump positions for it', () => {
        const { entries, positions } = buildMapIndex(sceneOf(layer([9, 11], 2, 1)), 0);
        const freeze = entries.find((e) => e.key === 'freeze')!;
        expect(freeze.count).toBe(2);
        expect(freeze.jumpable).toBe(false);
        expect(positions.has('freeze')).toBe(false);
    });

    it('records jump positions in cell coordinates for jumpable categories', () => {
        // 3-wide row: shotgun(199) at x=0 and x=2.
        const { positions } = buildMapIndex(sceneOf(layer([199, 0, 199], 3, 1)), 0);
        expect(positions.get('shotgun')).toEqual([[0, 0], [2, 0]]);
    });

    it('reads teleports from the tele layer', () => {
        const { entries } = buildMapIndex(sceneOf(layer([26], 1, 1, 'tele')), 0);
        expect(entries.map((e) => e.key)).toContain('teleport');
    });

    it('returns an empty index when the game group is missing', () => {
        const empty = buildMapIndex({ info: { author: '', version: '', credits: '', license: '', settings: [] }, images: [], envelopes: [], groups: [] }, 0);
        expect(empty.entries).toEqual([]);
        expect(empty.positions.size).toBe(0);
    });

    it('ignores design (tiles) layers', () => {
        // id 33 is TILE_START in a physics layer, but a design layer must never be scanned.
        const { entries } = buildMapIndex(sceneOf(layer([33], 1, 1, 'tiles')), 0);
        expect(entries).toEqual([]);
    });

    it('accumulates a category across the game and front layers', () => {
        const scene = sceneOf(layer([33], 1, 1, 'game'), layer([33], 1, 1, 'front'));
        expect(buildMapIndex(scene, 0).entries.find((e) => e.key === 'start')!.count).toBe(2);
    });
});
