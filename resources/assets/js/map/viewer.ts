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
    private entities = false;

    // Game-layer dimensions for fit()
    private gameW = 0;
    private gameH = 0;

    // rAF management
    private rafId: number | null = null;
    private renderScheduled = false;

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
    ) {
        this.canvas = canvas;
        this.renderer = renderer;
        this.camera = camera;
        this.scene = scene;
        this.textures = textures;
        this.batches = batches;
        this.gameW = gameW;
        this.gameH = gameH;

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
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** Re-fit the view to encompass the game layer. */
    fit(): void {
        this.camera.fitBounds(0, 0, this.gameW, this.gameH);
        this.requestRender();
    }

    /** Toggle physics / entities layer overlay and re-render. */
    toggleEntities(): void {
        this.entities = !this.entities;
        this.requestRender();
    }

    /** Release all GL resources and detach event listeners. */
    destroy(): void {
        if (this.rafId !== null) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
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
            this.render();
        });
    }

    private render(): void {
        this.renderer.clear();

        for (let gi = 0; gi < this.scene.groups.length; gi++) {
            const group = this.scene.groups[gi]!;
            const transform = groupTransform(group, this.camera);

            for (const batch of this.batches) {
                if (batch.groupIndex !== gi) continue;
                if (batch.vertices.length === 0) continue;

                if (batch.isPhysics) {
                    // Physics (entities) layers are only drawn when entities mode is on.
                    if (!this.entities) continue;
                    const tex = this.entitiesTex;
                    this.renderer.draw(tex, batch.vertices, transform);
                } else {
                    this.renderer.draw(batch.texture, batch.vertices, transform);
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
                const htmlImg = new Image();
                htmlImg.onerror = () => {};
                htmlImg.onload = () => {
                    textures.set(idx, renderer.createTexture(htmlImg));
                    viewerRef[0]?.requestRender();
                };
                htmlImg.src = `${opts.mapresBase}/${img.name}.png`;
            }
        }

        // --- entities texture (async) ---
        let entitiesTex: WebGLTexture | null = null;
        const entImg = new Image();
        entImg.onerror = () => {};
        entImg.onload = () => {
            entitiesTex = renderer.createTexture(entImg);
            const v = viewerRef[0];
            if (v) {
                v.entitiesTex = entitiesTex;
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

        // --- precompute static geometry ---
        const batches: LayerBatch[] = [];

        for (let gi = 0; gi < scene.groups.length; gi++) {
            const group = scene.groups[gi]!;

            for (const layer of group.layers) {
                const isPhysics = layer.type === 'tilemap' && layer.kind !== 'tiles';

                if (layer.type === 'tilemap') {
                    const tl = layer as TileLayer;
                    // Evaluate color envelope at t=0 if set.
                    let colorMul: [number, number, number, number] = [1, 1, 1, 1];
                    if (tl.colorEnv >= 0 && tl.colorEnv < scene.envelopes.length) {
                        const env = scene.envelopes[tl.colorEnv]!;
                        const vals = evalEnvelope(env.channels, env.points, tl.colorEnvOffset);
                        colorMul = [
                            vals[0] ?? 1,
                            vals[1] ?? 1,
                            vals[2] ?? 1,
                            vals[3] ?? 1,
                        ];
                    }
                    const verts = buildTileLayerVertices(tl, colorMul);
                    const texture = textures.get(tl.image) ?? null;
                    batches.push({
                        groupIndex: gi,
                        texture,
                        isPhysics,
                        vertices: new Float32Array(verts),
                    });
                } else if (layer.type === 'quads') {
                    const ql = layer as QuadLayer;
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
                    });
                }
            }
        }

        // --- construct viewer ---
        const viewer = new MapViewer(canvas, renderer, camera, scene, textures, batches, gameW, gameH);
        viewerRef[0] = viewer;
        // Wire the entities texture that may have already loaded synchronously.
        if (entitiesTex) viewer.entitiesTex = entitiesTex;

        viewer.requestRender();
        return viewer;
    }
}
