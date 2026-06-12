import { describe, it, expect } from 'vitest';
import { evalEnvelope } from '../../../resources/assets/js/map/envelope';
import type { EnvPoint } from '../../../resources/assets/js/map/types';

const pt = (time: number, curve: number, v: number): EnvPoint => ({ time, curve, values: [v, 0, 0, 0] });

describe('evalEnvelope', () => {
    it('returns defaults for zero points', () => {
        expect(evalEnvelope(1, [], 0)).toEqual([0]);
    });
    it('returns the single point value (fx2f /1024)', () => {
        expect(evalEnvelope(1, [pt(0, 1, 1024)], 999)).toEqual([1]);
    });
    it('linearly interpolates between two points', () => {
        const pts = [pt(0, 1, 0), pt(1000, 1, 1024)];
        expect(evalEnvelope(1, pts, 500)[0]).toBeCloseTo(0.5, 5);
    });
    it('STEP curve holds the start value until the next point', () => {
        const pts = [pt(0, 0, 0), pt(1000, 0, 1024)];
        expect(evalEnvelope(1, pts, 999)[0]).toBe(0);
    });
    it('SMOOTH curve eases (3a^2-2a^3) — midpoint stays 0.5 but quarter < 0.25', () => {
        const pts = [pt(0, 4, 0), pt(1000, 4, 1024)];
        expect(evalEnvelope(1, pts, 500)[0]).toBeCloseTo(0.5, 5);
        expect(evalEnvelope(1, pts, 250)[0]).toBeLessThan(0.25);
    });
    it('wraps time past the last point', () => {
        const pts = [pt(0, 1, 0), pt(1000, 1, 1024)];
        expect(evalEnvelope(1, pts, 1500)[0]).toBeCloseTo(0.5, 5);
    });
    it('interpolates each channel independently (4-channel color envelope)', () => {
        const a: EnvPoint = { time: 0, curve: 1, values: [0, 1024, 2048, 0] };
        const b: EnvPoint = { time: 1000, curve: 1, values: [1024, 1024, 0, 512] };
        const r = evalEnvelope(4, [a, b], 500);
        expect(r[0]).toBeCloseTo(0.5, 5);   // 0 → 1
        expect(r[1]).toBeCloseTo(1.0, 5);   // 1 → 1 (constant)
        expect(r[2]).toBeCloseTo(1.0, 5);   // 2 → 0, midpoint 1
        expect(r[3]).toBeCloseTo(0.25, 5);  // 0 → 0.5, midpoint 0.25
    });
});
