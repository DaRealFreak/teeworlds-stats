import type { EnvPoint } from './types';

/** Curve types (libtw2 / DDNet): 0 step, 1 linear, 2 slow, 3 fast, 4 smooth, 5 bezier. */
function applyCurve(curve: number, a: number): number {
    switch (curve) {
        case 0:
            return 0; // STEP
        case 2:
            return a * a * a; // SLOW
        case 3: {
            const i = 1 - a;
            return 1 - i * i * i; // FAST
        }
        case 4:
            return -2 * a * a * a + 3 * a * a; // SMOOTH
        default:
            return a; // LINEAR (1) is identity; BEZIER (5) is unimplemented and treated as linear
    }
}

/**
 * Evaluate an envelope at time `timeMs`, returning `channels` floats (values are fx2f: /1024).
 * Mirrors ddnet RenderEvalEnvelope: wrap by the last point's time, find the active segment,
 * transform the interpolation factor by the segment's start-point curve, then lerp.
 */
export function evalEnvelope(channels: number, points: EnvPoint[], timeMs: number): number[] {
    const out = new Array<number>(channels).fill(0);
    if (points.length === 0) return out;

    const fx = (v: number) => v / 1024;

    if (points.length === 1) {
        for (let c = 0; c < channels; c++) out[c] = fx(points[0]!.values[c] ?? 0);
        return out;
    }

    const last = points[points.length - 1]!;
    const maxTime = last.time;
    const t = maxTime > 0 ? ((timeMs % maxTime) + maxTime) % maxTime : 0;

    let i = 0;
    while (i < points.length - 1 && !(points[i]!.time <= t && t < points[i + 1]!.time)) i++;

    if (i >= points.length - 1) {
        for (let c = 0; c < channels; c++) out[c] = fx(last.values[c] ?? 0);
        return out;
    }

    const cur = points[i]!;
    const next = points[i + 1]!;
    const delta = next.time - cur.time;

    if (delta <= 0) {
        for (let c = 0; c < channels; c++) out[c] = fx(cur.values[c] ?? 0);
        return out;
    }

    const a = applyCurve(cur.curve, (t - cur.time) / delta);

    for (let c = 0; c < channels; c++) {
        const v0 = fx(cur.values[c] ?? 0);
        const v1 = fx(next.values[c] ?? 0);
        out[c] = v0 + (v1 - v0) * a;
    }

    return out;
}
