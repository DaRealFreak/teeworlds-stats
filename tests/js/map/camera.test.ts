import { describe, it, expect } from 'vitest';
import { Camera } from '../../../resources/assets/js/map/camera';

describe('Camera', () => {
    it('maps the center to the canvas middle', () => {
        const cam = new Camera(800, 600);
        cam.center = [10, 20];
        cam.zoom = 32;
        expect(cam.worldToScreen(10, 20, 100, 100)).toEqual([400, 300]);
    });
    it('scales by zoom (pixels per tile)', () => {
        const cam = new Camera(800, 600);
        cam.center = [0, 0];
        cam.zoom = 32;
        expect(cam.worldToScreen(1, 0, 100, 100)).toEqual([400 + 32, 300]);
    });
    it('applies parallax: a 0-parallax (bg) layer ignores camera panning', () => {
        const cam = new Camera(800, 600);
        cam.center = [50, 50];
        cam.zoom = 10;
        expect(cam.worldToScreen(0, 0, 0, 0)).toEqual([400, 300]);
    });
    it('round-trips screen→world→screen', () => {
        const cam = new Camera(640, 480);
        cam.center = [3, 4];
        cam.zoom = 25;
        const [wx, wy] = cam.screenToWorld(120, 200, 100, 100);
        expect(cam.worldToScreen(wx, wy, 100, 100)).toEqual([120, 200]);
    });
    it('fitBounds centers and zooms to contain a box', () => {
        const cam = new Camera(800, 600);
        cam.fitBounds(0, 0, 50, 30);
        expect(cam.center).toEqual([25, 15]);
        expect(cam.zoom).toBeGreaterThan(0);
        expect(cam.zoom).toBeLessThanOrEqual(800 / 50);
        expect(cam.zoom).toBeCloseTo(15.2, 5);
    });
    it('fitZoomFor matches the zoom fitBounds applies (and clamps the upper bound)', () => {
        const cam = new Camera(800, 600);
        expect(cam.fitZoomFor(50, 30)).toBeCloseTo(15.2, 5);
        // a tiny box clamps to the maximum zoom
        expect(cam.fitZoomFor(0.001, 0.001)).toBe(256);
    });
    it('fits a map wider than the viewport in tiles (not clamped at 1 px/tile)', () => {
        // "Back in the sky" is 4200 tiles wide; a 1200px view needs ~0.27 px/tile to show it all.
        const cam = new Camera(1200, 800);
        const z = cam.fitZoomFor(4200, 630);
        expect(z).toBeLessThan(1); // would be clamped to 1 by the old floor
        expect(z * 4200).toBeLessThanOrEqual(1200); // whole width is visible
    });
    it('lets wheel-zoom-out follow a fitted large map below 1 px/tile (no snap-up)', () => {
        const cam = new Camera(1200, 800);
        cam.zoom = 0.27; // already fitted to a wide map
        cam.zoomAt(600, 400, 1 / 1.1); // zoom out one wheel notch
        expect(cam.zoom).toBeLessThan(0.27); // must go further out, not snap up toward 1
    });
});
