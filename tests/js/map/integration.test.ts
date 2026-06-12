import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { parseMap } from '../../../resources/assets/js/map/index';

describe('parseMap integration', () => {
    it('produces a self-consistent scene for gravity.map', async () => {
        const bytes = new Uint8Array(readFileSync(new URL('../fixtures/gravity.map', import.meta.url)));
        const scene = await parseMap(bytes);
        const layers = scene.groups.flatMap((g) => g.layers);
        expect(layers.length).toBe(11);
        for (const l of layers) {
            if (l.type === 'tilemap') expect(l.tiles.length).toBe(l.width * l.height);
            else for (const q of l.quads) { expect(q.points).toHaveLength(5); expect(q.colors).toHaveLength(4); }
        }
        for (const l of layers) if ('image' in l) expect(l.image === -1 || l.image < scene.images.length).toBe(true);
    });
});
