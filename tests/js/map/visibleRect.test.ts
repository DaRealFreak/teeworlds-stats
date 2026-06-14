import { describe, it, expect } from 'vitest';
import { groupTransform, groupVisibleTileRect } from '../../../resources/assets/js/map/viewer';
import { Camera } from '../../../resources/assets/js/map/camera';

// Minimal Group stand-in (the transform/rect only read parallax + offset).
const grp = (over: Partial<{ xParallax: number; yParallax: number; xOffset: number; yOffset: number }> = {}) =>
    ({ xParallax: 100, yParallax: 100, xOffset: 0, yOffset: 0, ...over }) as never;

// The visible tile-rect is by definition the world box that maps to clip space [-1,1]^2 under the
// group's own transform. Pinning the rect against groupTransform keeps the two in lock-step: border
// geometry built from the rect lands exactly at the screen edges (clip ±1), whatever the parallax,
// offset or parallax-zoom. Y is flipped (clip-up), so the smaller world-y is the top (+1).
describe('groupVisibleTileRect', () => {
    const cases = [
        { name: 'foreground', group: grp(), cam: { z: 10, c: [0, 0] as [number, number] } },
        { name: 'panned foreground', group: grp(), cam: { z: 24, c: [250, 200] as [number, number] } },
        { name: 'low-parallax backdrop', group: grp({ xParallax: 2, yParallax: 1 }), cam: { z: 30, c: [120, 90] as [number, number] } },
        { name: 'offset detail group', group: grp({ xParallax: 100, yParallax: 100, xOffset: 320, yOffset: -64 }), cam: { z: 8, c: [40, 60] as [number, number] } },
    ];

    for (const { name, group, cam: cc } of cases) {
        it(`maps its corners to clip ±1 (${name})`, () => {
            const cam = new Camera(960, 540);
            cam.zoom = cc.z;
            cam.center = cc.c;
            const [x0, y0, x1, y1] = groupVisibleTileRect(group, cam);
            const [sx, sy, tx, ty] = groupTransform(group, cam);
            expect(x0 * sx + tx).toBeCloseTo(-1, 6); // left edge
            expect(x1 * sx + tx).toBeCloseTo(1, 6); // right edge
            expect(y0 * sy + ty).toBeCloseTo(1, 6); // top (y flipped)
            expect(y1 * sy + ty).toBeCloseTo(-1, 6); // bottom
            expect(x1).toBeGreaterThan(x0);
            expect(y1).toBeGreaterThan(y0);
        });
    }
});
