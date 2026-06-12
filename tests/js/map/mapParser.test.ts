import { describe, it, expect, beforeAll } from 'vitest';
import { readFileSync } from 'node:fs';
import { parseMap } from '../../../resources/assets/js/map/mapParser';
import type { MapScene, TileLayer, QuadLayer } from '../../../resources/assets/js/map/types';

let scene: MapScene;
beforeAll(async () => {
    const bytes = new Uint8Array(readFileSync(new URL('../fixtures/gravity.map', import.meta.url)));
    scene = await parseMap(bytes);
});

describe('parseMap (gravity.map)', () => {
    it('parses info (version 1, no optional strings)', () => {
        expect(scene.info.author).toBe('');
        expect(scene.info.license).toBe('');
    });
    it('parses 4 images (2 embedded 1024x1024, 2 external)', () => {
        expect(scene.images).toHaveLength(4);
        expect(scene.images.filter((i) => !i.external)).toHaveLength(2);
        expect(scene.images[0]).toMatchObject({ width: 1024, height: 1024, external: false });
        expect(scene.images.filter((i) => i.external).every((i) => i.name.length > 0)).toBe(true);
    });
    it('has no envelopes (empty envpoints item)', () => {
        expect(scene.envelopes).toHaveLength(0);
    });
    it('parses 4 groups; the Game group has parallax 100/100, name "Game", 8 layers', () => {
        expect(scene.groups).toHaveLength(4);
        const game = scene.groups.find((g) => g.name === 'Game');
        expect(game).toBeDefined();
        expect(game!.xParallax).toBe(100);
        expect(game!.yParallax).toBe(100);
        expect(game!.layers).toHaveLength(8);
    });
    it('parses the game/tele/tune tilemap layers; every tile layer decodes to 50*120 tiles', () => {
        const tileLayers = scene.groups.flatMap((g) => g.layers).filter((l): l is TileLayer => l.type === 'tilemap');
        expect(tileLayers).toHaveLength(8);
        expect(tileLayers.every((l) => l.width === 50 && l.height === 120 && l.tiles.length === 6000)).toBe(true);
        expect(tileLayers.some((l) => l.kind === 'game')).toBe(true);
        expect(tileLayers.some((l) => l.kind === 'tele')).toBe(true);
        expect(tileLayers.some((l) => l.kind === 'tune')).toBe(true);
    });
    it('parses quad layers (one has 13 quads with normalised texcoords)', () => {
        const quadLayers = scene.groups.flatMap((g) => g.layers).filter((l): l is QuadLayer => l.type === 'quads');
        expect(quadLayers).toHaveLength(3);
        const big = quadLayers.find((q) => q.quads.length === 13);
        expect(big).toBeDefined();
        expect(big!.quads[0]!.colors).toHaveLength(4);
        expect(big!.quads[0]!.points).toHaveLength(5);
        const tc = big!.quads[0]!.texCoords[0]!;
        expect(tc[0]).toBeGreaterThanOrEqual(-2); // normalised (÷1024); allow a small margin for out-of-[0,1] authoring
        expect(tc[0]).toBeLessThanOrEqual(2);
    });
});
