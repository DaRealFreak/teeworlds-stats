/**
 * MapViewer — orchestrator tying parser, camera, geometry and GL renderer into
 * an interactive canvas element. Instantiate via the static `init` factory.
 */

import { parseMap } from './mapParser';
import { Camera } from './camera';
import { GlRenderer } from './glRenderer';
import { buildTileLayerVertices, buildQuadVertices } from './geometry';
import { evalEnvelope } from './envelope';
import type { MapScene, Group, TileLayer, QuadLayer, PixelData } from './types';

/** Identifies a drawable layer batch. */
interface LayerBatch {
    groupIndex: number;
    texture: WebGLTexture | null;
    /** 'entities' means: skip unless entities mode on, then draw with entitiesTex. */
    isPhysics: boolean;
    vertices: Float32Array;
    /**
     * Set when this tile-layer batch has a color envelope; vertices are rebuilt
     * each animation frame rather than kept from the static precompute.
     */
    animatedTileLayer?: TileLayer;
    /**
     * Set when at least one quad in this quad-layer batch references an envelope;
     * vertices are rebuilt each animation frame.
     */
    animatedQuadLayer?: QuadLayer;
}

/** Compute the world→clip transform for a group given the current camera. */
function groupTransform(
    group: Group,
    camera: Camera,
): [number, number, number, number] {
    const { center, zoom, viewW, viewH } = camera;
    const px = group.xParallax;
    const py = group.yParallax;
    const ecx = center[0] * (px / 100);
    const ecy = center[1] * (py / 100);
    const sx = (2 * zoom) / viewW;
    const sy = -(2 * zoom) / viewH; // Y-flip: clip-space Y is up, screen Y is down
    const ox = -ecx * sx;
    const oy = -ecy * sy;
    return [sx, sy, ox, oy];
}

/** Convert PixelData to a real ImageData for WebGL upload. */
function pixelDataToImageData(p: PixelData): ImageData {
    return new ImageData(new Uint8ClampedArray(p.data), p.width, p.height);
}

export class MapViewer {
    private readonly camera: Camera;
    private readonly renderer: GlRenderer;
    private readonly batches: LayerBatch[];
    private readonly canvas: HTMLCanvasElement;
    private readonly scene: MapScene;

    private textures: Map<number, WebGLTexture>;
    private entitiesTex: WebGLTexture | null = null;
    // Entities-overlay strength, 0..1 (DDNet's cl_overlay_entities / 100). As it rises the
    // entities tileset fades in while the design/background layers fade out; at 1 only
    // entities remain.
    private entitiesOpacity = 0;

    // Game-layer dimensions for fit()
    private gameW = 0;
    private gameH = 0;

    // rAF management — static (request-driven) path
    private rafId: number | null = null;
    private renderScheduled = false;

    // Continuous animation loop — only active when the map has envelopes
    private readonly hasAnimation: boolean;
    private animRafId: number | null = null;

    // Pointer drag state
    private pointerDown = false;
    private lastPointerX = 0;
    private lastPointerY = 0;

    // Bound event handlers (kept for removeEventListener in destroy())
    private readonly onPointerDown: (e: PointerEvent) => void;
    private readonly onPointerMove: (e: PointerEvent) => void;
    private readonly onPointerUp: (e: PointerEvent) => void;
    private readonly onWheel: (e: WheelEvent) => void;

    private constructor(
        canvas: HTMLCanvasElement,
        renderer: GlRenderer,
        camera: Camera,
        scene: MapScene,
        textures: Map<number, WebGLTexture>,
        batches: LayerBatch[],
        gameW: number,
        gameH: number,
        hasAnimation: boolean,
    ) {
        this.canvas = canvas;
        this.renderer = renderer;
        this.camera = camera;
        this.scene = scene;
        this.textures = textures;
        this.batches = batches;
        this.gameW = gameW;
        this.gameH = gameH;
        this.hasAnimation = hasAnimation;

        // --- input handlers ---
        canvas.style.cursor = 'grab';

        this.onPointerDown = (e: PointerEvent) => {
            this.pointerDown = true;
            this.lastPointerX = e.offsetX;
            this.lastPointerY = e.offsetY;
            canvas.style.cursor = 'grabbing';
            canvas.setPointerCapture(e.pointerId);
        };

        this.onPointerMove = (e: PointerEvent) => {
            if (!this.pointerDown) return;
            const dx = e.offsetX - this.lastPointerX;
            const dy = e.offsetY - this.lastPointerY;
            this.lastPointerX = e.offsetX;
            this.lastPointerY = e.offsetY;
            this.camera.panPixels(dx, dy);
            this.requestRender();
        };

        this.onPointerUp = () => {
            this.pointerDown = false;
            canvas.style.cursor = 'grab';
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

        // Start the continuous animation loop only for maps that have envelopes.
        // Static maps (no envelopes) stay entirely request-driven.
        if (this.hasAnimation) {
            this.startAnimLoop();
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** Re-fit the view to encompass the game layer. */
    fit(): void {
        this.camera.fitBounds(0, 0, this.gameW, this.gameH);
        this.requestRender();
    }

    /**
     * Set the entities-overlay strength (0 = design only, 1 = entities only).
     * Values in between blend the entities tileset over a dimmed background.
     */
    setEntitiesOpacity(opacity: number): void {
        this.entitiesOpacity = Math.max(0, Math.min(1, opacity));
        this.requestRender();
    }

    /** Release all GL resources and detach event listeners. */
    destroy(): void {
        if (this.rafId !== null) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
        if (this.animRafId !== null) {
            cancelAnimationFrame(this.animRafId);
            this.animRafId = null;
        }
        this.renderer.dispose();
        this.canvas.removeEventListener('pointerdown', this.onPointerDown);
        this.canvas.removeEventListener('pointermove', this.onPointerMove);
        this.canvas.removeEventListener('pointerup', this.onPointerUp);
        this.canvas.removeEventListener('wheel', this.onWheel);
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

    /**
     * Continuous animation loop — only started when `hasAnimation` is true.
     * Runs every frame so envelope-animated layers update smoothly.
     */
    private startAnimLoop(): void {
        const tick = (timeMs: number) => {
            this.render(timeMs);
            this.animRafId = requestAnimationFrame(tick);
        };
        this.animRafId = requestAnimationFrame(tick);
    }

    /**
     * Rebuild the vertex buffer for one animated batch using the current time.
     * Only called for batches that actually reference an envelope; static
     * batches are never touched here.
     */
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
                // Position envelope: 3 channels — x, y, rotation (rotation ignored).
                let posOffset: [number, number] = [0, 0];
                if (quad.posEnv >= 0 && quad.posEnv < this.scene.envelopes.length) {
                    const env = this.scene.envelopes[quad.posEnv]!;
                    const vals = evalEnvelope(env.channels, env.points, timeMs + quad.posEnvOffset);
                    posOffset = [vals[0] ?? 0, vals[1] ?? 0];
                }
                // Color envelope: 4 channels — r, g, b, a multipliers.
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
        // Rebuild vertex buffers for animated batches before drawing.
        for (const batch of this.batches) {
            if (batch.animatedTileLayer || batch.animatedQuadLayer) {
                this.buildAnimatedBatch(batch, timeMs);
            }
        }

        // Match the client's transition: as the entities overlay fades in, fade the backdrop
        // from the dark viewer background toward the grey "entities background"
        // (DDNet cl_background_entities_color, default ~0.5 grey) instead of dimming to black.
        const t = this.entitiesOpacity;
        const lerp = (a: number, b: number): number => a + (b - a) * t;
        this.renderer.clear([lerp(0.07, 0.5), lerp(0.07, 0.5), lerp(0.09, 0.5)]);

        for (let gi = 0; gi < this.scene.groups.length; gi++) {
            const group = this.scene.groups[gi]!;
            const transform = groupTransform(group, this.camera);

            for (const batch of this.batches) {
                if (batch.groupIndex !== gi) continue;
                if (batch.vertices.length === 0) continue;

                if (batch.isPhysics) {
                    // Entities overlay: fade in with the slider; skip when fully hidden.
                    if (this.entitiesOpacity <= 0) continue;
                    this.renderer.draw(this.entitiesTex, batch.vertices, transform, [
                        1,
                        1,
                        1,
                        this.entitiesOpacity,
                    ]);
                } else {
                    // Design/background: fade out as the overlay fades in.
                    const bgAlpha = 1 - this.entitiesOpacity;
                    if (bgAlpha <= 0) continue;
                    this.renderer.draw(batch.texture, batch.vertices, transform, [1, 1, 1, bgAlpha]);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Static factory
    // -------------------------------------------------------------------------

    static async init(
        canvas: HTMLCanvasElement,
        opts: { mapUrl: string; mapresBase: string; entitiesUrl: string },
    ): Promise<MapViewer | null> {
        // --- fetch & parse map ---
        const res = await fetch(opts.mapUrl);
        const scene = await parseMap(new Uint8Array(await res.arrayBuffer()));

        // --- WebGL context ---
        const gl = canvas.getContext('webgl');
        if (!gl) {
            // Graceful degradation: show message in parent or 2d canvas
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
        renderer.resize(canvas.width, canvas.height);

        // --- textures: embedded images ---
        const textures = new Map<number, WebGLTexture>();
        for (let i = 0; i < scene.images.length; i++) {
            const img = scene.images[i]!;
            if (img.pixels) {
                // Embedded: pixels already decoded; upload immediately.
                textures.set(i, renderer.createTexture(pixelDataToImageData(img.pixels)));
            }
        }

        // Mutable slot that closures below capture; filled once the instance is built.
        // Using a single-element array lets us treat the reference as effectively-const
        // while still being writable after construction.
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

        // --- camera: fit to game layer ---
        let gameW = 0;
        let gameH = 0;
        for (const group of scene.groups) {
            for (const layer of group.layers) {
                if (layer.type === 'tilemap' && layer.kind === 'game') {
                    gameW = layer.width;
                    gameH = layer.height;
                    break;
                }
            }
            if (gameW > 0) break;
        }
        const camera = new Camera(canvas.width, canvas.height);
        camera.fitBounds(0, 0, gameW, gameH);

        // --- precompute static geometry; tag animated batches ---
        const batches: LayerBatch[] = [];
        let hasAnimation = false;

        for (let gi = 0; gi < scene.groups.length; gi++) {
            const group = scene.groups[gi]!;

            for (const layer of group.layers) {
                const isPhysics = layer.type === 'tilemap' && layer.kind !== 'tiles';

                // The entities overlay only renders game + front layers: both are plain CTile
                // arrays that index the entities tileset directly. tele/speedup/switch/tune use
                // their own per-tile structs (CTeleTile/CSpeedupTile/…) that mapParser does not
                // decode yet, so we skip them rather than draw garbage.
                if (isPhysics && layer.type === 'tilemap' && layer.kind !== 'game' && layer.kind !== 'front') {
                    continue;
                }

                if (layer.type === 'tilemap') {
                    const tl = layer as TileLayer;
                    // A tile layer is animated when it references a valid color envelope.
                    const isAnimated = tl.colorEnv >= 0 && tl.colorEnv < scene.envelopes.length;
                    if (isAnimated) hasAnimation = true;

                    // Evaluate at t=0 for the initial static frame.
                    let colorMul: [number, number, number, number] = [1, 1, 1, 1];
                    if (isAnimated) {
                        const env = scene.envelopes[tl.colorEnv]!;
                        const vals = evalEnvelope(env.channels, env.points, tl.colorEnvOffset);
                        colorMul = [vals[0] ?? 1, vals[1] ?? 1, vals[2] ?? 1, vals[3] ?? 1];
                    }
                    const verts = buildTileLayerVertices(tl, colorMul);
                    const texture = textures.get(tl.image) ?? null;
                    batches.push({
                        groupIndex: gi,
                        texture,
                        isPhysics,
                        vertices: new Float32Array(verts),
                        // Tag animated batches so render() knows to rebuild them each frame.
                        animatedTileLayer: isAnimated ? tl : undefined,
                    });
                } else if (layer.type === 'quads') {
                    const ql = layer as QuadLayer;
                    // A quad layer is animated if any quad references a valid envelope.
                    const isAnimated = ql.quads.some(
                        (q) =>
                            (q.posEnv >= 0 && q.posEnv < scene.envelopes.length) ||
                            (q.colorEnv >= 0 && q.colorEnv < scene.envelopes.length),
                    );
                    if (isAnimated) hasAnimation = true;

                    const combined: number[] = [];
                    for (const quad of ql.quads) {
                        const verts = buildQuadVertices(quad, [0, 0], [1, 1, 1, 1]);
                        for (const v of verts) combined.push(v);
                    }
                    const texture = textures.get(ql.image) ?? null;
                    batches.push({
                        groupIndex: gi,
                        texture,
                        isPhysics: false,
                        vertices: new Float32Array(combined),
                        animatedQuadLayer: isAnimated ? ql : undefined,
                    });
                }
            }
        }

        // --- construct viewer ---
        const viewer = new MapViewer(
            canvas,
            renderer,
            camera,
            scene,
            textures,
            batches,
            gameW,
            gameH,
            hasAnimation,
        );
        viewerRef[0] = viewer;

        viewer.requestRender();
        return viewer;
    }
}
