import { describe, it, expect } from 'vitest';
import { groupZoom, neutralZoom } from '../../../resources/assets/js/map/viewer';

// DDNet renders each group with a "parallax zoom": low-parallax backdrops (sky, clouds, stars)
// respond to zoom LESS than the foreground, like depth. At one neutral zoom every layer scales
// alike; zooming past it makes backdrops lag. Without this, zooming in shrinks the backdrop's
// world window until the clouds fall outside it and vanish (they only survived at full-map fit
// because that window is enormous). These tests pin the relative behaviour, not magic constants.
describe('parallax zoom', () => {
    const VW = 1600;
    const VH = 900;
    const Z0 = neutralZoom(VW, VH);

    it('has a positive neutral zoom', () => {
        expect(Z0).toBeGreaterThan(0);
    });

    it('leaves the foreground (parallax 100) at the raw zoom for any zoom', () => {
        for (const z of [0.2, 1, Z0, 30, 200]) {
            expect(groupZoom(100, 100, z, VW, VH)).toBeCloseTo(z, 6);
        }
    });

    it('aligns every layer with the foreground at the neutral zoom', () => {
        expect(groupZoom(12, 12, Z0, VW, VH)).toBeCloseTo(Z0, 6);
        expect(groupZoom(2, 2, Z0, VW, VH)).toBeCloseTo(Z0, 6);
        expect(groupZoom(0, 0, Z0, VW, VH)).toBeCloseTo(Z0, 6);
    });

    it('zooms a backdrop in LESS than the foreground when zoomed past neutral', () => {
        const z = Z0 * 4; // zoomed in
        const bd = groupZoom(12, 12, z, VW, VH);
        expect(bd).toBeLessThan(z); // lags the foreground...
        expect(bd).toBeGreaterThan(Z0); // ...but is still larger than at neutral
    });

    it('keeps a backdrop relatively larger than the foreground when zoomed out', () => {
        const z = Z0 / 4; // zoomed out
        expect(groupZoom(12, 12, z, VW, VH)).toBeGreaterThan(z);
    });

    it('makes lower parallax lag more (stars respond to zoom less than clouds)', () => {
        const z = Z0 * 4;
        const stars = groupZoom(2, 2, z, VW, VH);
        const clouds = groupZoom(12, 12, z, VW, VH);
        expect(stars).toBeLessThan(clouds);
    });

    it('uses the larger of the two parallax axes (matches DDNet max(px,py))', () => {
        const z = Z0 * 4;
        expect(groupZoom(2, 12, z, VW, VH)).toBeCloseTo(groupZoom(12, 12, z, VW, VH), 6);
    });
});
