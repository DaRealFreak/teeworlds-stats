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
import type { MapScene, Group, TileLayer, QuadLayer, PixelData } from './types';

/** Tele tile m_Type values that act as a teleport source / destination (DDNet mapitems.h). */
const TELE_IN_TYPES = new Set([26, 10, 14, 15, 31, 63]); // TELEIN/EVIL/WEAPON/HOOK/CHECKIN/CHECKINEVIL
const TELE_OUT_TYPES = new Set([27, 30]); // TELEOUT, TELECHECKOUT

/** A click (vs a drag) is a short press that barely moved. */
const CLICK_MAX_MOVE_PX = 5;
const CLICK_MAX_MS = 400;

/** Identifies a drawable layer batch. */
interface LayerBatch {
    groupIndex: number;
    texture: WebGLTexture | null;
    /** Physics (entities) layer: only drawn when the entities overlay is visible. */
    isPhysics: boolean;
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

/** Compute the world→clip transform for a group given the current camera. */
function groupTransform(group: Group, camera: Camera): [number, number, number, number] {
    const { center, zoom, viewW, viewH } = camera;
    const ecx = center[0] * (group.xParallax / 100);
    const ecy = center[1] * (group.yParallax / 100);
    const sx = (2 * zoom) / viewW;
    const sy = -(2 * zoom) / viewH; // Y-flip: clip-space Y is up, screen Y is down
    return [sx, sy, -ecx * sx, -ecy * sy];
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
    }

    /** Reset to the tileset's native scale — 1 tile = its authored pixel size (1024/16) — in place. */
    actualSize(): void {
        this.camera.zoom = 64;
        this.requestRender();
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
                if (quad.posEnv >= 0 && quad.posEnv < this.scene.envelopes.length) {
                    const env = this.scene.envelopes[quad.posEnv]!;
                    const vals = evalEnvelope(env.channels, env.points, timeMs + quad.posEnvOffset);
                    posOffset = [vals[0] ?? 0, vals[1] ?? 0];
                }
                let colorMul: [number, number, number, number] = [1, 1, 1, 1];
                if (quad.colorEnv >= 0 && quad.colorEnv < this.scene.envelopes.length) {
                    const env = this.scene.envelopes[quad.colorEnv]!;
                    const vals = evalEnvelope(env.channels, env.points, timeMs + quad.colorEnvOffset);
                    colorMul = [vals[0] ?? 1, vals[1] ?? 1, vals[2] ?? 1, vals[3] ?? 1];
                }
                const verts = buildQuadVertices(quad, posOffset, colorMul);
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
                if (batch.isPhysics) {
                    if (this.entitiesOpacity <= 0) continue;
                    this.renderer.draw(this.entitiesTex, batch.vertices, transform, [1, 1, 1, this.entitiesOpacity]);
                } else {
                    const bgAlpha = 1 - this.entitiesOpacity;
                    if (bgAlpha <= 0) continue;
                    this.renderer.draw(batch.texture, batch.vertices, transform, [1, 1, 1, bgAlpha]);
                }
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
                        texture: textures.get(tl.image) ?? null,
                        isPhysics,
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
                        texture: textures.get(ql.image) ?? null,
                        isPhysics: false,
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
