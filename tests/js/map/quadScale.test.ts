import { describe, it, expect } from 'vitest';
import { fxToTiles } from '../../../resources/assets/js/map/mapParser';

// Quad point coordinates are stored as DDNet fixed-point world units (i2fx: ×1024) in a space where
// one tile spans 32 units (engine renders tiles at x*32, quads at fx2f(point)). The viewer's world
// is one unit per tile, so a quad point converts to tiles by dividing by 1024·32 = 32768. Parsing
// them with the wrong scale put decals (clouds/stars/background quads) 64× too far and too large,
// so they only showed at full-map fit and vanished the moment you zoomed in.
describe('fxToTiles (quad fixed-point → tiles)', () => {
    it('maps one tile (32 world units, i2fx ×1024) to 1 tile', () => {
        expect(fxToTiles(32 * 1024)).toBeCloseTo(1, 9);
    });

    it('scales linearly through the origin', () => {
        expect(fxToTiles(0)).toBe(0);
        expect(fxToTiles(10 * 32 * 1024)).toBeCloseTo(10, 9);
        expect(fxToTiles(-5 * 32 * 1024)).toBeCloseTo(-5, 9);
    });
});
