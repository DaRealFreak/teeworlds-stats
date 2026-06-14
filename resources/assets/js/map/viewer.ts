/**
 * MapViewer — orchestrator tying parser, camera, geometry and GL renderer into
 * an interactive canvas element. Instantiate via the static `init` factory.
 *
 * Two layered canvases: a WebGL canvas for tiles/quads/highlights and a 2D overlay
 * canvas (drawn through the same camera) for teleport numbers, speedup arrows, switch
 * delays and the follow-teleport pulse.
 */

import { parseMap } from './mapParser';
import { Camera } from './camera';
import { GlRenderer } from './glRenderer';
import { buildTileLayerVertices, buildQuadVertices } from './geometry';
import { evalEnvelope } from './envelope';
import { Overlay, buildAnnotations, pulseActive } from './overlay';
import type { Annotation, PulseTarget } from './overlay';
import { HIGHLIGHT_CATEGORIES, buildHighlightVertices } from './highlights';
import { overlayLayerAlpha } from './overlayCompositing';
import type { MapScene, Group, TileLayer, QuadLayer, PixelData } from './types';

/** Tele tile m_Type values that act as a teleport source / destination (DDNet mapitems.h). */
const TELE_IN_TYPES = new Set([26, 10, 14, 15, 31, 63]); // TELEIN/EVIL/WEAPON/HOOK/CHECKIN/CHECKINEVIL
const TELE_OUT_TYPES = new Set([27, 30]); // TELEOUT, TELECHECKOUT

/** A click (vs a drag) is a short press that barely moved. */
const CLICK_MAX_MOVE_PX = 5;
const CLICK_MAX_MS = 400;

/** Native tileset scale: a 1024px tileset has 64px cells, so 1 tile = 64 CSS px ("actual size"). */
const NATIVE_ZOOM = 64;

/** Identifies a drawable layer batch. */
interface LayerBatch {
    groupIndex: number;
    /**
     * Index into {@link MapScene.images} for this layer's texture (-1 = none → white fallback).
     * Resolved live at render time rather than cached: external mapres images (clouds/stars) load
     * asynchronously, after batches are built, so a cached texture reference would stay null.
     */
    imageIndex: number;
    /** Physics (entities) layer: only drawn when the entities overlay is visible. */
    isPhysics: boolean;
    /** Parallax backdrop (sky/clouds, parallax ≠ 100): kept opaque through a partial overlay. */
    isBackdrop: boolean;
    vertices: Float32Array;
    animatedTileLayer?: TileLayer;
    animatedQuadLayer?: QuadLayer;
}

interface TeleEntry {
    ins: [number, number][];
    outs: [number, number][];
}

interface ViewerParts {
    canvas: HTMLCanvasElement;
    overlay: Overlay;
    renderer: GlRenderer;
    camera: Camera;
    scene: MapScene;
    textures: Map<number, WebGLTexture>;
    batches: LayerBatch[];
    gameW: number;
    gameH: number;
    gameGroupIndex: number;
    hasAnimation: boolean;
}

/** Teeworlds world units per tile: group offsets are stored in these px, tiles span 32 of them. */
const TILE_WORLD_SIZE = 32;

/**
 * Base world-space view size (in world px, before zoom) for an aspect ratio — DDNet's
 * CalcScreenParams (engine/graphics.cpp). Defines the world extent shown at the client's neutral
 * zoom, from which {@link neutralZoom} converts to our px-per-tile units.
 */
function baseViewWidth(aspect: number): number {
    const AMOUNT = 1150 * 1000;
    const W_MAX = 1500;
    const H_MAX = 1050;
    const f = Math.sqrt(AMOUNT) / Math.sqrt(aspect);
    let w = f * aspect;
    let h = f;
    if (w > W_MAX) {
        w = W_MAX;
        h = w / aspect;
    }
    if (h > H_MAX) {
        h = H_MAX;
        w = h * aspect;
    }
    return w;
}

/**
 * The px-per-tile zoom at which the client's parallax zoom is neutral (DDNet Zoom == 1): every
 * group — foreground and backdrop — scales identically here. Zooming away from it makes backdrops
 * lag (see {@link groupZoom}). Derived from {@link baseViewWidth} so it tracks the real client.
 */
export function neutralZoom(viewW: number, viewH: number): number {
    const aspect = viewW / Math.max(1, viewH);
    return (TILE_WORLD_SIZE * viewW) / baseViewWidth(aspect);
}

/**
 * Effective px-per-tile zoom for a group, applying DDNet's parallax zoom (render_layer.cpp:275 +
 * graphics.cpp MapScreenToWorld): ParallaxZoom = clamp(max(parallaxX, parallaxY), 0, 100), and the
 * group's world window scales by (ParallaxZoom·(Zoom−1) + 100) / 100 / Zoom. The foreground
 * (parallax 100) stays at the raw zoom; low-parallax backdrops respond to zoom less, so the sky,
 * clouds and stars stay in view when zoomed in instead of sliding out of a too-narrow window.
 */
export function groupZoom(
    parallaxX: number,
    parallaxY: number,
    zoom: number,
    viewW: number,
    viewH: number,
): number {
    const pz = Math.min(100, Math.max(0, Math.max(parallaxX, parallaxY)));
    const zd = neutralZoom(viewW, viewH) / zoom; // DDNet Zoom equivalent
    const denom = pz * (zd - 1) + 100;
    if (denom <= 0) return zoom; // degenerate (only at extreme zoom); fall back to foreground scale
    return (zoom * 100 * zd) / denom;
}

/** Compute the world→clip transform for a group given the current camera. */
export function groupTransform(group: Group, camera: Camera): [number, number, number, number] {
    const { center, viewW, viewH } = camera;
    // Match DDNet MapScreenToWorld: the group offset shifts the view raw (not parallax-scaled),
    // in world px → tiles. Skipping it left offset groups (e.g. detail layers) drawn at raw
    // position, so their tiles appeared where the client has none in the non-entity view.
    const ecx = center[0] * (group.xParallax / 100) + group.xOffset / TILE_WORLD_SIZE;
    const ecy = center[1] * (group.yParallax / 100) + group.yOffset / TILE_WORLD_SIZE;
    // Backdrops zoom less than the foreground (parallax zoom); the center above is unaffected.
    const gz = groupZoom(group.xParallax, group.yParallax, camera.zoom, viewW, viewH);
    const sx = (2 * gz) / viewW;
    const sy = -(2 * gz) / viewH; // Y-flip: clip-space Y is up, screen Y is down
    return [sx, sy, -ecx * sx, -ecy * sy];
}

/**
 * Resolve the texture a batch should draw with. Physics layers use the shared entities texture;
 * everything else looks its image up in the live textures map by index. The lookup is intentionally
 * live (not cached on the batch) so externally-loaded mapres images — clouds, stars, any external
 * tileset — appear as soon as their async load completes instead of staying the white fallback.
 */
export function resolveBatchTexture(
    batch: { isPhysics: boolean; imageIndex: number },
    textures: Map<number, WebGLTexture>,
    entitiesTex: WebGLTexture | null,
): WebGLTexture | null {
    if (batch.isPhysics) return entitiesTex;
    return textures.get(batch.imageIndex) ?? null;
}

/** Convert PixelData to a real ImageData for WebGL upload. */
function pixelDataToImageData(p: PixelData): ImageData {
    return new ImageData(new Uint8ClampedArray(p.data), p.width, p.height);
}

export class MapViewer {
    private readonly camera: Camera;
    private readonly renderer: GlRenderer;
    private readonly overlay: Overlay;
    private readonly batches: LayerBatch[];
    private readonly canvas: HTMLCanvasElement;
    private readonly scene: MapScene;
    private readonly gameGroupIndex: number;

    private textures: Map<number, WebGLTexture>;
    private entitiesTex: WebGLTexture | null = null;
    // Entities-overlay strength, 0..1 (DDNet's cl_overlay_entities / 100).
    private entitiesOpacity = 0;

    // Inspection state
    private readonly annotations: Annotation[];
    private readonly teleIndex = new Map<number, TeleEntry>();
    private teleLayer: TileLayer | null = null;
    private readonly highlightBatches = new Map<string, Float32Array>();
    private pulse: PulseTarget | null = null;
    private zoomListener: ((fraction: number) => void) | null = null;

    private gameW = 0;
    private gameH = 0;

    // rAF management
    private rafId: number | null = null;
    private renderScheduled = false;
    private readonly hasAnimation: boolean;
    private animRafId: number | null = null;
    private pulseRafId: number | null = null;
    private resizeObserver: ResizeObserver | null = null;
    private firstSized = false;

    // Pointer drag / click state
    private pointerDown = false;
    private pointerMoved = false;
    private pointerDownTime = 0;
    private lastPointerX = 0;
    private lastPointerY = 0;

    private readonly onPointerDown: (e: PointerEvent) => void;
    private readonly onPointerMove: (e: PointerEvent) => void;
    private readonly onPointerUp: (e: PointerEvent) => void;
    private readonly onWheel: (e: WheelEvent) => void;

    private constructor(p: ViewerParts) {
        this.canvas = p.canvas;
        this.overlay = p.overlay;
        this.renderer = p.renderer;
        this.camera = p.camera;
        this.scene = p.scene;
        this.textures = p.textures;
        this.batches = p.batches;
        this.gameW = p.gameW;
        this.gameH = p.gameH;
        this.gameGroupIndex = p.gameGroupIndex;
        this.hasAnimation = p.hasAnimation;

        this.annotations = buildAnnotations(p.scene, p.gameGroupIndex);
        this.buildTeleIndex();

        const canvas = p.canvas;
        canvas.style.cursor = 'grab';

        this.onPointerDown = (e: PointerEvent) => {
            this.pointerDown = true;
            this.pointerMoved = false;
            this.pointerDownTime = performance.now();
            this.lastPointerX = e.offsetX;
            this.lastPointerY = e.offsetY;
            canvas.style.cursor = 'grabbing';
            canvas.setPointerCapture(e.pointerId);
        };

        this.onPointerMove = (e: PointerEvent) => {
            if (!this.pointerDown) return;
            const dx = e.offsetX - this.lastPointerX;
            const dy = e.offsetY - this.lastPointerY;
            if (Math.abs(dx) + Math.abs(dy) > CLICK_MAX_MOVE_PX) this.pointerMoved = true;
            this.lastPointerX = e.offsetX;
            this.lastPointerY = e.offsetY;
            this.camera.panPixels(dx, dy);
            this.requestRender();
        };

        this.onPointerUp = (e: PointerEvent) => {
            this.pointerDown = false;
            canvas.style.cursor = 'grab';
            const heldMs = performance.now() - this.pointerDownTime;
            if (!this.pointerMoved && heldMs < CLICK_MAX_MS) this.handleClick(e.offsetX, e.offsetY);
        };

        this.onWheel = (e: WheelEvent) => {
            e.preventDefault();
            const factor = e.deltaY < 0 ? 1.1 : 1 / 1.1;
            this.camera.zoomAt(e.offsetX, e.offsetY, factor);
            this.requestRender();
            this.notifyZoom();
        };

        canvas.addEventListener('pointerdown', this.onPointerDown);
        canvas.addEventListener('pointermove', this.onPointerMove);
        canvas.addEventListener('pointerup', this.onPointerUp);
        canvas.addEventListener('wheel', this.onWheel, { passive: false });

        // Keep both canvases sized to the displayed CSS box × devicePixelRatio.
        this.resizeObserver = new ResizeObserver(() => this.resizeToDisplay());
        this.resizeObserver.observe(canvas);
        this.resizeToDisplay(); // immediate first fit (ResizeObserver also fires async)

        if (this.hasAnimation) this.startAnimLoop();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** Re-fit the view to encompass the game layer. */
    fit(): void {
        this.camera.fitBounds(0, 0, this.gameW, this.gameH);
        this.requestRender();
        this.notifyZoom();
    }

    /**
     * Set zoom from a 0..1 slider position spanning fit-the-whole-map (0) to the tileset's
     * native scale (1, 1 tile = its authored 64px). Log-interpolated so the steps feel even.
     */
    setZoomFraction(fraction: number): void {
        const { lo, hi } = this.zoomBounds();
        const f = Math.max(0, Math.min(1, fraction));
        this.camera.zoom = hi > lo ? lo * Math.pow(hi / lo, f) : lo;
        this.requestRender();
        this.notifyZoom();
    }

    /** The current zoom as a 0..1 fraction over the fit↔native range (clamped). */
    getZoomFraction(): number {
        const { lo, hi } = this.zoomBounds();
        if (hi <= lo) return 0;
        return Math.max(0, Math.min(1, Math.log(this.camera.zoom / lo) / Math.log(hi / lo)));
    }

    /** Register a listener notified (immediately, then on every zoom change) with the 0..1 fraction. */
    setZoomChangeListener(cb: (fraction: number) => void): void {
        this.zoomListener = cb;
        cb(this.getZoomFraction());
    }

    /** Slider bounds: fit-the-game-layer zoom and the native 64px/tile, low→high. */
    private zoomBounds(): { lo: number; hi: number } {
        const fit = this.camera.fitZoomFor(this.gameW || 1, this.gameH || 1);
        return { lo: Math.min(fit, NATIVE_ZOOM), hi: Math.max(fit, NATIVE_ZOOM) };
    }

    private notifyZoom(): void {
        this.zoomListener?.(this.getZoomFraction());
    }

    /** Set the entities-overlay strength (0 = design only, 1 = entities only). */
    setEntitiesOpacity(opacity: number): void {
        this.entitiesOpacity = Math.max(0, Math.min(1, opacity));
        this.requestRender();
    }

    /** Toggle a highlight category (see {@link MapViewer.highlightCategories}). */
    setHighlight(key: string, on: boolean): void {
        if (on) {
            const cat = HIGHLIGHT_CATEGORIES.find((c) => c.key === key);
            if (!cat) return;
            this.highlightBatches.set(key, buildHighlightVertices(this.scene, this.gameGroupIndex, cat));
        } else {
            this.highlightBatches.delete(key);
        }
        this.requestRender();
    }

    /** The available highlight categories (key, label, 0..1 RGB color) for building a legend. */
    static highlightCategories(): { key: string; label: string; color: [number, number, number] }[] {
        return HIGHLIGHT_CATEGORIES.map((c) => ({ key: c.key, label: c.label, color: c.color }));
    }

    /** Release all GL resources and detach event listeners. */
    destroy(): void {
        for (const id of [this.rafId, this.animRafId, this.pulseRafId]) {
            if (id !== null) cancelAnimationFrame(id);
        }
        this.rafId = this.animRafId = this.pulseRafId = null;
        this.resizeObserver?.disconnect();
        this.renderer.dispose();
        this.canvas.removeEventListener('pointerdown', this.onPointerDown);
        this.canvas.removeEventListener('pointermove', this.onPointerMove);
        this.canvas.removeEventListener('pointerup', this.onPointerUp);
        this.canvas.removeEventListener('wheel', this.onWheel);
    }

    // -------------------------------------------------------------------------
    // Resize / DPR
    // -------------------------------------------------------------------------

    private resizeToDisplay(): void {
        const cssW = this.canvas.clientWidth || this.camera.viewW;
        const cssH = this.canvas.clientHeight || this.camera.viewH;
        if (cssW <= 0 || cssH <= 0) return;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        this.renderer.resize(Math.round(cssW * dpr), Math.round(cssH * dpr));
        this.overlay.resize(cssW, cssH, dpr);
        this.camera.viewW = cssW;
        this.camera.viewH = cssH;
        if (!this.firstSized) {
            this.firstSized = true;
            this.camera.fitBounds(0, 0, this.gameW, this.gameH);
        }
        this.requestRender();
        this.notifyZoom(); // fit bounds depend on view size, so the fraction can shift on resize
    }

    // -------------------------------------------------------------------------
    // Follow teleport
    // -------------------------------------------------------------------------

    private buildTeleIndex(): void {
        const group = this.scene.groups[this.gameGroupIndex];
        const tele = group?.layers.find(
            (l): l is TileLayer => l.type === 'tilemap' && l.kind === 'tele',
        );
        const nums = tele?.physics?.number;
        if (!tele || !nums) return;
        this.teleLayer = tele;
        for (let y = 0; y < tele.height; y++) {
            for (let x = 0; x < tele.width; x++) {
                const i = y * tele.width + x;
                const type = tele.tiles[i]?.id ?? 0;
                const number = nums[i] ?? 0;
                if (number <= 0) continue;
                const isIn = TELE_IN_TYPES.has(type);
                if (!isIn && !TELE_OUT_TYPES.has(type)) continue;
                let entry = this.teleIndex.get(number);
                if (!entry) {
                    entry = { ins: [], outs: [] };
                    this.teleIndex.set(number, entry);
                }
                (isIn ? entry.ins : entry.outs).push([x, y]);
            }
        }
    }

    private handleClick(offsetX: number, offsetY: number): void {
        const tele = this.teleLayer;
        if (this.entitiesOpacity <= 0 || !tele) return; // follow only while teleports are visible
        const group = this.scene.groups[this.gameGroupIndex];
        const px = group?.xParallax ?? 100;
        const py = group?.yParallax ?? 100;
        const [wx, wy] = this.camera.screenToWorld(offsetX, offsetY, px, py);
        const tx = Math.floor(wx);
        const ty = Math.floor(wy);
        if (tx < 0 || ty < 0 || tx >= tele.width || ty >= tele.height) return;
        const i = ty * tele.width + tx;
        const type = tele.tiles[i]?.id ?? 0;
        const number = tele.physics?.number?.[i] ?? 0;
        if (number <= 0) return;
        const entry = this.teleIndex.get(number);
        if (!entry) return;
        const targets = TELE_IN_TYPES.has(type) ? entry.outs : TELE_OUT_TYPES.has(type) ? entry.ins : null;
        if (!targets || targets.length === 0) return;

        // Jump to the nearest partner tile and pulse it.
        let best = targets[0]!;
        let bestD = Infinity;
        for (const p of targets) {
            const d = (p[0] - tx) ** 2 + (p[1] - ty) ** 2;
            if (d < bestD) {
                bestD = d;
                best = p;
            }
        }
        this.camera.center = [best[0] + 0.5, best[1] + 0.5];
        this.startPulse(best[0], best[1]);
    }

    private startPulse(x: number, y: number): void {
        this.pulse = { x, y, startMs: performance.now() };
        this.requestRender();
        // If no continuous loop is already running, drive frames until the pulse fades.
        if (this.animRafId === null && this.pulseRafId === null) {
            const tick = (t: number): void => {
                this.render(t);
                this.pulseRafId = this.pulse !== null ? requestAnimationFrame(tick) : null;
            };
            this.pulseRafId = requestAnimationFrame(tick);
        }
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    requestRender(): void {
        if (this.renderScheduled) return;
        this.renderScheduled = true;
        this.rafId = requestAnimationFrame(() => {
            this.renderScheduled = false;
            this.rafId = null;
            this.render(performance.now());
        });
    }

    private startAnimLoop(): void {
        const tick = (timeMs: number): void => {
            this.render(timeMs);
            this.animRafId = requestAnimationFrame(tick);
        };
        this.animRafId = requestAnimationFrame(tick);
    }

    private buildAnimatedBatch(batch: LayerBatch, timeMs: number): void {
        if (batch.animatedTileLayer) {
            const tl = batch.animatedTileLayer;
            let colorMul: [number, number, number, number] = [1, 1, 1, 1];
            if (tl.colorEnv >= 0 && tl.colorEnv < this.scene.envelopes.length) {
                const env = this.scene.envelopes[tl.colorEnv]!;
                const vals = evalEnvelope(env.channels, env.points, timeMs + tl.colorEnvOffset);
                colorMul = [vals[0] ?? 1, vals[1] ?? 1, vals[2] ?? 1, vals[3] ?? 1];
            }
            batch.vertices = new Float32Array(buildTileLayerVertices(tl, colorMul));
            return;
        }

        if (batch.animatedQuadLayer) {
            const ql = batch.animatedQuadLayer;
            const combined: number[] = [];
            for (const quad of ql.quads) {
                let posOffset: [number, number] = [0, 0];
                let rotation = 0;
                if (quad.posEnv >= 0 && quad.posEnv < this.scene.envelopes.length) {
                    const env = this.scene.envelopes[quad.posEnv]!;
                    const vals = evalEnvelope(env.channels, env.points, timeMs + quad.posEnvOffset);
                    // evalEnvelope yields fx2f world units (32/tile); quad points are in tiles, so
                    // scale the position-envelope offset to tiles too or drifting decals move 32× fast.
                    posOffset = [(vals[0] ?? 0) / TILE_WORLD_SIZE, (vals[1] ?? 0) / TILE_WORLD_SIZE];
                    // Channel 2 is the pivot rotation, stored in degrees (DDNet: Rot = b/180·π rad).
                    rotation = ((vals[2] ?? 0) / 180) * Math.PI;
                }
                let colorMul: [number, number, number, number] = [1, 1, 1, 1];
                if (quad.colorEnv >= 0 && quad.colorEnv < this.scene.envelopes.length) {
                    const env = this.scene.envelopes[quad.colorEnv]!;
                    const vals = evalEnvelope(env.channels, env.points, timeMs + quad.colorEnvOffset);
                    colorMul = [vals[0] ?? 1, vals[1] ?? 1, vals[2] ?? 1, vals[3] ?? 1];
                }
                const verts = buildQuadVertices(quad, posOffset, colorMul, rotation);
                for (const v of verts) combined.push(v);
            }
            batch.vertices = new Float32Array(combined);
        }
    }

    private render(timeMs: number): void {
        for (const batch of this.batches) {
            if (batch.animatedTileLayer || batch.animatedQuadLayer) this.buildAnimatedBatch(batch, timeMs);
        }

        // As the entities overlay fades in, fade the backdrop from the dark viewer background
        // toward DDNet's grey "entities background" instead of dimming to black.
        const t = this.entitiesOpacity;
        const lerp = (a: number, b: number): number => a + (b - a) * t;
        this.renderer.clear([lerp(0.07, 0.5), lerp(0.07, 0.5), lerp(0.09, 0.5)]);

        for (let gi = 0; gi < this.scene.groups.length; gi++) {
            const group = this.scene.groups[gi]!;
            const transform = groupTransform(group, this.camera);

            for (const batch of this.batches) {
                if (batch.groupIndex !== gi || batch.vertices.length === 0) continue;
                const role = batch.isPhysics ? 'physics' : batch.isBackdrop ? 'backdrop' : 'design';
                const alpha = overlayLayerAlpha(role, this.entitiesOpacity);
                if (alpha <= 0) continue;
                const texture = resolveBatchTexture(batch, this.textures, this.entitiesTex);
                this.renderer.draw(texture, batch.vertices, transform, [1, 1, 1, alpha]);
            }
        }

        // Highlight outlines, drawn on top in the game group's space (white texture + vertex color).
        const gameGroup = this.scene.groups[this.gameGroupIndex];
        if (gameGroup && this.highlightBatches.size > 0) {
            const transform = groupTransform(gameGroup, this.camera);
            for (const verts of this.highlightBatches.values()) {
                if (verts.length > 0) this.renderer.draw(null, verts, transform, [1, 1, 1, 1]);
            }
        }

        // 2D annotations (numbers, arrows, pulse) on the overlay canvas.
        this.overlay.draw(
            this.camera,
            this.annotations,
            gameGroup?.xParallax ?? 100,
            gameGroup?.yParallax ?? 100,
            this.entitiesOpacity,
            this.pulse,
            timeMs,
        );
        if (this.pulse && !pulseActive(this.pulse, timeMs)) this.pulse = null;
    }

    // -------------------------------------------------------------------------
    // Static factory
    // -------------------------------------------------------------------------

    static async init(
        canvas: HTMLCanvasElement,
        opts: { mapUrl: string; mapresBase: string; entitiesUrl: string; overlayCanvas: HTMLCanvasElement },
    ): Promise<MapViewer | null> {
        const res = await fetch(opts.mapUrl);
        const scene = await parseMap(new Uint8Array(await res.arrayBuffer()));

        const gl = canvas.getContext('webgl');
        if (!gl) {
            const ctx2d = canvas.getContext('2d');
            if (ctx2d) {
                ctx2d.fillStyle = '#111';
                ctx2d.fillRect(0, 0, canvas.width, canvas.height);
                ctx2d.fillStyle = '#ccc';
                ctx2d.font = '16px sans-serif';
                ctx2d.textAlign = 'center';
                ctx2d.fillText('WebGL unavailable', canvas.width / 2, canvas.height / 2);
            } else {
                const msg = document.createElement('p');
                msg.textContent = 'WebGL unavailable';
                canvas.parentElement?.appendChild(msg);
            }
            return null;
        }

        const renderer = new GlRenderer(gl);
        const overlay = new Overlay(opts.overlayCanvas);

        // --- textures: embedded images ---
        const textures = new Map<number, WebGLTexture>();
        for (let i = 0; i < scene.images.length; i++) {
            const img = scene.images[i]!;
            if (img.pixels) textures.set(i, renderer.createTexture(pixelDataToImageData(img.pixels)));
        }

        const viewerRef: [MapViewer | null] = [null];

        // --- textures: external images (async) ---
        for (let i = 0; i < scene.images.length; i++) {
            const img = scene.images[i]!;
            if (img.external) {
                const idx = i;
                const src = `${opts.mapresBase}/${img.name}.png`;
                const htmlImg = new Image();
                htmlImg.onerror = () => console.warn('map viewer: failed to load texture', src);
                htmlImg.onload = () => {
                    textures.set(idx, renderer.createTexture(htmlImg));
                    viewerRef[0]?.requestRender();
                };
                htmlImg.src = src;
            }
        }

        // --- entities texture (async) ---
        const entImg = new Image();
        entImg.onerror = () => console.warn('map viewer: failed to load texture', opts.entitiesUrl);
        entImg.onload = () => {
            const tex = renderer.createTexture(entImg);
            const v = viewerRef[0];
            if (v) {
                v.entitiesTex = tex;
                v.requestRender();
            }
        };
        entImg.src = opts.entitiesUrl;

        // --- locate the game layer (dimensions + group index) ---
        let gameW = 0;
        let gameH = 0;
        let gameGroupIndex = 0;
        for (let gi = 0; gi < scene.groups.length; gi++) {
            const layer = scene.groups[gi]!.layers.find(
                (l): l is TileLayer => l.type === 'tilemap' && l.kind === 'game',
            );
            if (layer) {
                gameW = layer.width;
                gameH = layer.height;
                gameGroupIndex = gi;
                break;
            }
        }

        const camera = new Camera(canvas.clientWidth || canvas.width, canvas.clientHeight || canvas.height);

        // --- precompute static geometry; tag animated batches ---
        const batches: LayerBatch[] = [];
        let hasAnimation = false;

        for (let gi = 0; gi < scene.groups.length; gi++) {
            const group = scene.groups[gi]!;
            // Parallax (≠100) groups are the sky/cloud backdrop; kept opaque through a partial overlay.
            const groupIsBackdrop = group.xParallax !== 100 || group.yParallax !== 100;
            for (const layer of group.layers) {
                if (layer.type === 'tilemap') {
                    const tl: TileLayer = layer;
                    const isPhysics = tl.kind !== 'tiles';
                    const isAnimated = tl.colorEnv >= 0 && tl.colorEnv < scene.envelopes.length;
                    if (isAnimated) hasAnimation = true;

                    let colorMul: [number, number, number, number] = [1, 1, 1, 1];
                    if (isAnimated) {
                        const env = scene.envelopes[tl.colorEnv]!;
                        const vals = evalEnvelope(env.channels, env.points, tl.colorEnvOffset);
                        colorMul = [vals[0] ?? 1, vals[1] ?? 1, vals[2] ?? 1, vals[3] ?? 1];
                    }
                    batches.push({
                        groupIndex: gi,
                        imageIndex: tl.image,
                        isPhysics,
                        isBackdrop: !isPhysics && groupIsBackdrop,
                        vertices: new Float32Array(buildTileLayerVertices(tl, colorMul)),
                        animatedTileLayer: isAnimated ? tl : undefined,
                    });
                } else if (layer.type === 'quads') {
                    const ql: QuadLayer = layer;
                    const isAnimated = ql.quads.some(
                        (q) =>
                            (q.posEnv >= 0 && q.posEnv < scene.envelopes.length) ||
                            (q.colorEnv >= 0 && q.colorEnv < scene.envelopes.length),
                    );
                    if (isAnimated) hasAnimation = true;

                    const combined: number[] = [];
                    for (const quad of ql.quads) {
                        for (const v of buildQuadVertices(quad, [0, 0], [1, 1, 1, 1])) combined.push(v);
                    }
                    batches.push({
                        groupIndex: gi,
                        imageIndex: ql.image,
                        isPhysics: false,
                        isBackdrop: groupIsBackdrop,
                        vertices: new Float32Array(combined),
                        animatedQuadLayer: isAnimated ? ql : undefined,
                    });
                }
            }
        }

        const viewer = new MapViewer({
            canvas,
            overlay,
            renderer,
            camera,
            scene,
            textures,
            batches,
            gameW,
            gameH,
            gameGroupIndex,
            hasAnimation,
        });
        viewerRef[0] = viewer;

        viewer.requestRender();
        return viewer;
    }
}
