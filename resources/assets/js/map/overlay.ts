import type { Camera } from './camera';
import type { MapScene } from './types';

/**
 * Vector/text annotations the WebGL tile pipeline can't draw, rendered on a transparent 2D
 * canvas layered over the GL canvas and projected through the same camera: teleport numbers,
 * speedup arrows + force/maxSpeed, switch number/delay, and the follow-teleport pulse.
 */

export interface Annotation {
    kind: 'tele' | 'tune' | 'switch' | 'speedup';
    x: number; // tile coords (cell top-left); center is x+0.5
    y: number;
    number: number;
    delay: number;
    force: number;
    maxSpeed: number;
    angle: number; // degrees
}

export interface PulseTarget {
    x: number;
    y: number;
    startMs: number;
}

const PULSE_MS = 1500;
const ARROW_MIN_ZOOM = 14; // px/tile below which even arrows are hidden
const LABEL_MIN_ZOOM = 22; // px/tile below which text labels are hidden

/** Whether a pulse is still animating (the viewer drives frames while this is true). */
export function pulseActive(pulse: PulseTarget | null, nowMs: number): boolean {
    return pulse !== null && nowMs - pulse.startMs < PULSE_MS;
}

/** Collect annotations for every non-air special-physics tile in the game group (computed once). */
export function buildAnnotations(scene: MapScene, gameGroupIndex: number): Annotation[] {
    const out: Annotation[] = [];
    const group = scene.groups[gameGroupIndex];
    if (!group) return out;
    for (const layer of group.layers) {
        if (layer.type !== 'tilemap' || !layer.physics) continue;
        const k = layer.kind;
        if (k !== 'tele' && k !== 'tune' && k !== 'switch' && k !== 'speedup') continue;
        const ph = layer.physics;
        for (let y = 0; y < layer.height; y++) {
            for (let x = 0; x < layer.width; x++) {
                const i = y * layer.width + x;
                const tile = layer.tiles[i];
                if (!tile || tile.id === 0) continue;
                out.push({
                    kind: k,
                    x,
                    y,
                    number: ph.number?.[i] ?? 0,
                    delay: ph.delay?.[i] ?? 0,
                    force: ph.force?.[i] ?? 0,
                    maxSpeed: ph.maxSpeed?.[i] ?? 0,
                    angle: ph.angle?.[i] ?? 0,
                });
            }
        }
    }
    return out;
}

export class Overlay {
    private readonly ctx: CanvasRenderingContext2D;
    private dpr = 1;
    private cssW = 0;
    private cssH = 0;

    constructor(private readonly canvas: HTMLCanvasElement) {
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Overlay: 2d context unavailable');
        this.ctx = ctx;
    }

    resize(cssW: number, cssH: number, dpr: number): void {
        this.cssW = cssW;
        this.cssH = cssH;
        this.dpr = dpr;
        this.canvas.width = Math.max(1, Math.round(cssW * dpr));
        this.canvas.height = Math.max(1, Math.round(cssH * dpr));
    }

    draw(
        camera: Camera,
        annotations: Annotation[],
        parallaxX: number,
        parallaxY: number,
        entitiesOpacity: number,
        pulse: PulseTarget | null,
        nowMs: number,
    ): void {
        const ctx = this.ctx;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0); // draw in CSS px, crisp on hi-DPI

        if (entitiesOpacity > 0 && camera.zoom >= ARROW_MIN_ZOOM && annotations.length > 0) {
            this.drawAnnotations(camera, annotations, parallaxX, parallaxY);
        }
        if (pulse) this.drawPulse(camera, parallaxX, parallaxY, pulse, nowMs);
    }

    private drawAnnotations(camera: Camera, annotations: Annotation[], px: number, py: number): void {
        const ctx = this.ctx;
        const zoom = camera.zoom;
        const showText = zoom >= LABEL_MIN_ZOOM;
        const fontPx = Math.max(8, Math.min(zoom * 0.42, 20));
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.lineJoin = 'round';

        for (const a of annotations) {
            const [sx, sy] = camera.worldToScreen(a.x + 0.5, a.y + 0.5, px, py);
            if (sx < -zoom || sy < -zoom || sx > this.cssW + zoom || sy > this.cssH + zoom) continue;

            if (a.kind === 'speedup') {
                this.arrow(sx, sy, (a.angle * Math.PI) / 180, zoom * 0.42);
                if (showText) this.text(`${a.force}/${a.maxSpeed}`, sx, sy + zoom * 0.34, fontPx * 0.72);
            } else if (showText) {
                const label = a.kind === 'switch' && a.delay > 0 ? `${a.number}:${a.delay}` : `${a.number}`;
                if (a.number > 0 || a.kind === 'switch') this.text(label, sx, sy, fontPx);
            }
        }
    }

    private text(s: string, x: number, y: number, size = 12): void {
        const ctx = this.ctx;
        ctx.font = `bold ${size}px sans-serif`;
        ctx.lineWidth = Math.max(2, size / 6);
        ctx.strokeStyle = 'rgba(0,0,0,0.85)';
        ctx.fillStyle = '#fff';
        ctx.strokeText(s, x, y);
        ctx.fillText(s, x, y);
    }

    private arrow(cx: number, cy: number, angleRad: number, len: number): void {
        const ctx = this.ctx;
        const dx = Math.cos(angleRad);
        const dy = Math.sin(angleRad);
        const hx = cx + dx * len * 0.5;
        const hy = cy + dy * len * 0.5; // head
        const tx = cx - dx * len * 0.5;
        const ty = cy - dy * len * 0.5; // tail
        const wing = len * 0.3;
        const a1 = angleRad + Math.PI * 0.82;
        const a2 = angleRad - Math.PI * 0.82;

        const stroke = (style: string, width: number): void => {
            ctx.strokeStyle = style;
            ctx.lineWidth = width;
            ctx.beginPath();
            ctx.moveTo(tx, ty);
            ctx.lineTo(hx, hy);
            ctx.moveTo(hx, hy);
            ctx.lineTo(hx + Math.cos(a1) * wing, hy + Math.sin(a1) * wing);
            ctx.moveTo(hx, hy);
            ctx.lineTo(hx + Math.cos(a2) * wing, hy + Math.sin(a2) * wing);
            ctx.stroke();
        };
        stroke('rgba(0,0,0,0.85)', Math.max(3, len * 0.18)); // dark halo
        stroke('#ffd54a', Math.max(1.5, len * 0.09)); // bright arrow
    }

    private drawPulse(camera: Camera, px: number, py: number, pulse: PulseTarget, nowMs: number): void {
        const t = Math.min(1, Math.max(0, (nowMs - pulse.startMs) / PULSE_MS));
        const [sx, sy] = camera.worldToScreen(pulse.x + 0.5, pulse.y + 0.5, px, py);
        const ctx = this.ctx;
        const alpha = (1 - t) * 0.9;
        ctx.strokeStyle = `rgba(255,220,80,${alpha})`;
        ctx.lineWidth = Math.max(2, camera.zoom * 0.1);
        ctx.beginPath();
        ctx.arc(sx, sy, camera.zoom * (0.6 + t * 1.6), 0, Math.PI * 2);
        ctx.stroke();
        ctx.strokeRect(sx - camera.zoom * 0.5, sy - camera.zoom * 0.5, camera.zoom, camera.zoom);
    }
}
