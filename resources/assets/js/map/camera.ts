/**
 * Zoom bounds in pixels-per-tile. The floor is well below 1 so very large maps
 * (e.g. 4200-wide sky maps) can fit a normal-sized viewport — clamping the fit
 * zoom up to 1 would otherwise show only a slice and strand the default view in
 * the map's middle. The ceiling caps how far a single tile can be magnified.
 */
const MIN_ZOOM = 0.05;
const MAX_ZOOM = 256;

export class Camera {
    center: [number, number] = [0, 0];
    zoom = 32; // pixels per tile
    constructor(
        public viewW: number,
        public viewH: number,
    ) {}

    private effectiveCenter(parallaxX: number, parallaxY: number): [number, number] {
        return [this.center[0] * (parallaxX / 100), this.center[1] * (parallaxY / 100)];
    }

    worldToScreen(wx: number, wy: number, parallaxX = 100, parallaxY = 100): [number, number] {
        const [ecx, ecy] = this.effectiveCenter(parallaxX, parallaxY);
        return [this.viewW / 2 + (wx - ecx) * this.zoom, this.viewH / 2 + (wy - ecy) * this.zoom];
    }

    screenToWorld(sx: number, sy: number, parallaxX = 100, parallaxY = 100): [number, number] {
        const [ecx, ecy] = this.effectiveCenter(parallaxX, parallaxY);
        return [(sx - this.viewW / 2) / this.zoom + ecx, (sy - this.viewH / 2) / this.zoom + ecy];
    }

    /** Zoom by `factor` keeping the world point under (sx,sy) fixed on screen. */
    zoomAt(sx: number, sy: number, factor: number): void {
        const before = this.screenToWorld(sx, sy);
        this.zoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, this.zoom * factor));
        const after = this.screenToWorld(sx, sy);
        this.center = [this.center[0] + (before[0] - after[0]), this.center[1] + (before[1] - after[1])];
    }

    /** Pan by a screen-pixel delta. */
    panPixels(dx: number, dy: number): void {
        this.center = [this.center[0] - dx / this.zoom, this.center[1] - dy / this.zoom];
    }

    /** The zoom (px/tile) at which a w×h world box fills the view, with a small margin. */
    fitZoomFor(w: number, h: number): number {
        const ww = Math.max(1e-6, w);
        const hh = Math.max(1e-6, h);
        return Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, Math.min(this.viewW / ww, this.viewH / hh) * 0.95));
    }

    /** Center on a world box and zoom to contain it (with a small margin). */
    fitBounds(x0: number, y0: number, x1: number, y1: number): void {
        this.center = [(x0 + x1) / 2, (y0 + y1) / 2];
        this.zoom = this.fitZoomFor(x1 - x0, y1 - y0);
    }
}
