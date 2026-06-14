import { describe, it, expect } from 'vitest';
import { groupTransform } from '../../../resources/assets/js/map/viewer';
import { Camera } from '../../../resources/assets/js/map/camera';

// Minimal Group stand-in (groupTransform only reads parallax + offset).
const grp = (over: Partial<{ xParallax: number; yParallax: number; xOffset: number; yOffset: number }> = {}) =>
    ({ xParallax: 100, yParallax: 100, xOffset: 0, yOffset: 0, ...over }) as never;

describe('groupTransform', () => {
    it('applies the group pixel offset (32 px/tile) to the translation', () => {
        const cam = new Camera(1000, 800);
        cam.zoom = 10;
        cam.center = [0, 0];
        const sx = (2 * 10) / 1000; // 0.02

        const base = groupTransform(grp(), cam);
        expect(base[2]).toBeCloseTo(0, 9); // center 0, no offset → no x translation

        const shifted = groupTransform(grp({ xOffset: 64 }), cam); // 64 px = 2 tiles
        expect(shifted[2]).toBeCloseTo(-2 * sx, 9);
    });

    it('adds the offset raw — not scaled by parallax', () => {
        const cam = new Camera(1000, 800);
        cam.zoom = 10;
        cam.center = [100, 0];
        const sx = (2 * 10) / 1000;

        const a = groupTransform(grp({ xParallax: 50 }), cam);
        const b = groupTransform(grp({ xParallax: 50, xOffset: 320 }), cam); // 320 px = 10 tiles
        expect(b[2] - a[2]).toBeCloseTo(-10 * sx, 9); // 10 tiles regardless of the 50% parallax
    });
});
