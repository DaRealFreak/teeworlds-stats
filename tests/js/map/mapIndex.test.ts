import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { buildMapIndex } from '../../../resources/assets/js/map/mapIndex';
import { parseMap } from '../../../resources/assets/js/map/index';
import type { MapScene, TileLayer, Group, LayerKind } from '../../../resources/assets/js/map/types';

function layer(ids: number[], w: number, h: number, kind: LayerKind = 'game', numbers?: number[]): TileLayer {
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
        tiles: ids.map((id) => ({ id, flags: 0, skip: 0 })),
    };
    if (numbers) tl.physics = { number: Uint8Array.from(numbers) };
    return tl;
}

function sceneOf(...layers: TileLayer[]): MapScene {
    const group: Group = {
        xOffset: 0, yOffset: 0, xParallax: 100, yParallax: 100,
        clipping: false, clipX: 0, clipY: 0, clipW: 0, clipH: 0, name: 'Game', layers,
    };
    return { info: { author: '', version: '', credits: '', license: '', settings: [] }, images: [], envelopes: [], groups: [group] };
}

describe('buildMapIndex', () => {
    it('lists present flat categories in display order with counts', () => {
        // start(33) once, spawn(192) twice, air(0). start is ordered before spawn.
        const { entries } = buildMapIndex(sceneOf(layer([33, 192, 0, 192], 2, 2)), 0);
        expect(entries.map((e) => e.key)).toEqual(['start', 'spawn']);
        expect(entries.find((e) => e.key === 'start')!.count).toBe(1);
        expect(entries.find((e) => e.key === 'spawn')!.count).toBe(2);
        expect(entries.every((e) => e.toggleable && e.jumpable && !e.children)).toBe(true);
    });

    it('groups weapons under a master with per-weapon children (present-only, own colors)', () => {
        // shotgun(199) x1, laser(202) x2; grenade/ninja absent.
        const { entries, positions } = buildMapIndex(sceneOf(layer([199, 202, 202], 3, 1)), 0);
        const weapons = entries.find((e) => e.key === 'weapons')!;
        expect(weapons.toggleable).toBe(true);
        expect(weapons.jumpable).toBe(false);
        expect(weapons.color).toBeNull();
        expect(weapons.count).toBe(3);
        expect(weapons.children!.map((c) => c.key)).toEqual(['shotgun', 'laser']);
        expect(weapons.children!.every((c) => c.toggleable && c.jumpable && Array.isArray(c.color))).toBe(true);
        expect(positions.get('shotgun')).toEqual([[0, 0]]);
        expect(positions.get('laser')).toEqual([[1, 0], [2, 0]]);
    });

    it('groups health and armor under Pickups', () => {
        const { entries } = buildMapIndex(sceneOf(layer([198, 197], 2, 1)), 0);
        const pickups = entries.find((e) => e.key === 'pickups')!;
        expect(pickups.children!.map((c) => c.key)).toEqual(['health', 'armor']);
        expect(pickups.count).toBe(2);
    });

    it('splits teleports into ascending numbered children by tele number', () => {
        // tele layer: TELEIN(26) x2 with number 5, TELEOUT(27) x1 with number 3.
        const tele = layer([26, 26, 27], 3, 1, 'tele', [5, 5, 3]);
        const { entries, positions } = buildMapIndex(sceneOf(tele), 0);
        const group = entries.find((e) => e.key === 'teleport')!;
        expect(group.toggleable).toBe(true);
        expect(group.jumpable).toBe(false);
        expect(group.count).toBe(3);
        expect(group.children!.map((c) => c.label)).toEqual(['Teleport 3', 'Teleport 5']);
        expect(group.children!.every((c) => !c.toggleable && c.jumpable && c.color === null)).toBe(true);
        expect(positions.get('tele:5')).toEqual([[0, 0], [1, 0]]);
        expect(positions.get('tele:3')).toEqual([[2, 0]]);
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

    it('emits groups in order: flat, weapons, pickups, teleports, checkpoints', () => {
        const scene = sceneOf(
            layer([33, 199, 198, 35], 4, 1), // start, shotgun, health, checkpoint 1
            layer([26], 1, 1, 'tele', [1]), // teleport number 1
        );
        expect(buildMapIndex(scene, 0).entries.map((e) => e.key)).toEqual([
            'start',
            'weapons',
            'pickups',
            'teleport',
            'checkpoints',
        ]);
    });

    it('no longer surfaces freeze tiles', () => {
        // 9/11/12/13 were the old freeze ids; they are no longer any category.
        const { entries } = buildMapIndex(sceneOf(layer([9, 11, 12, 13], 4, 1)), 0);
        expect(entries).toEqual([]);
    });

    it('ignores design (tiles) layers', () => {
        const { entries } = buildMapIndex(sceneOf(layer([33], 1, 1, 'tiles')), 0);
        expect(entries).toEqual([]);
    });

    it('accumulates a flat category across the game and front layers', () => {
        const scene = sceneOf(layer([33], 1, 1, 'game'), layer([33], 1, 1, 'front'));
        expect(buildMapIndex(scene, 0).entries.find((e) => e.key === 'start')!.count).toBe(2);
    });

    it('returns an empty index when the game group is missing', () => {
        const empty = buildMapIndex(
            { info: { author: '', version: '', credits: '', license: '', settings: [] }, images: [], envelopes: [], groups: [] },
            0,
        );
        expect(empty.entries).toEqual([]);
        expect(empty.positions.size).toBe(0);
    });

    it('builds a well-formed index from the real gravity.map fixture', async () => {
        const bytes = new Uint8Array(readFileSync(new URL('../fixtures/gravity.map', import.meta.url)));
        const scene = await parseMap(bytes);
        const found = scene.groups.findIndex((g) => g.layers.some((l) => l.type === 'tilemap' && l.kind === 'game'));
        const gameGroupIndex = found < 0 ? 0 : found;
        const { entries, positions } = buildMapIndex(scene, gameGroupIndex);
        expect(Array.isArray(entries)).toBe(true);
        expect(positions).toBeInstanceOf(Map);
        for (const e of entries) {
            expect(typeof e.key).toBe('string');
            expect(e.count).toBeGreaterThan(0);
            if (e.children) {
                for (const c of e.children) {
                    if (c.jumpable) expect((positions.get(c.key) ?? []).length).toBeGreaterThan(0);
                }
            } else if (e.jumpable) {
                expect((positions.get(e.key) ?? []).length).toBeGreaterThan(0);
            }
        }
    });
});
