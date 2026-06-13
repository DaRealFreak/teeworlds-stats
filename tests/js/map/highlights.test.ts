import { describe, it, expect } from 'vitest';
import { buildHighlightVertices, HIGHLIGHT_CATEGORIES } from '../../../resources/assets/js/map/highlights';
import type { MapScene, TileLayer, Group, LayerKind } from '../../../resources/assets/js/map/types';

function sceneWith(ids: number[], w: number, h: number, kind: LayerKind = 'game'): MapScene {
    const layer: TileLayer = {
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
    const group: Group = {
        xOffset: 0, yOffset: 0, xParallax: 100, yParallax: 100,
        clipping: false, clipX: 0, clipY: 0, clipW: 0, clipH: 0, name: 'Game', layers: [layer],
    };
    return {
        info: { author: '', version: '', credits: '', license: '', settings: [] },
        images: [], envelopes: [], groups: [group],
    };
}

const cat = (key: string) => HIGHLIGHT_CATEGORIES.find((c) => c.key === key)!;

describe('buildHighlightVertices', () => {
    it('outlines start/finish tiles and nothing else', () => {
        const scene = sceneWith([33, 0], 2, 1); // [TILE_START, air]
        const v = buildHighlightVertices(scene, 0, cat('startfinish'));
        // one tile × 4 border rects × 2 tris × 3 verts × 8 floats
        expect(v.length).toBe(4 * 2 * 3 * 8);
        expect(buildHighlightVertices(scene, 0, cat('freeze')).length).toBe(0);
    });

    it('matches tele types on the tele layer', () => {
        const scene = sceneWith([26], 1, 1, 'tele'); // TELEIN
        expect(buildHighlightVertices(scene, 0, cat('teleport')).length).toBeGreaterThan(0);
    });

    it('ignores design (tiles) layers so cell index 9 is not treated as freeze', () => {
        const scene = sceneWith([9], 1, 1, 'tiles');
        expect(buildHighlightVertices(scene, 0, cat('freeze')).length).toBe(0);
    });
});
