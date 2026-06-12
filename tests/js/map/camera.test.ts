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
});
