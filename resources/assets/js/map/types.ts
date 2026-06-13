export interface Tile {
    id: number;
    flags: number;
    skip: number;
}

/**
 * Per-cell metadata for the special physics layers (tele/speedup/switch/tune), parallel to
 * `TileLayer.tiles`. Only the fields meaningful to a layer's kind are populated:
 *   tele/tune → number; switch → number, delay; speedup → force, maxSpeed, angle.
 * The base graphic for every kind is carried in `tiles[i].id` (the struct's m_Type).
 */
export interface PhysicsMeta {
    number?: Uint8Array;
    delay?: Uint8Array;
    force?: Uint8Array;
    maxSpeed?: Uint8Array;
    angle?: Int16Array;
}

export type LayerKind = 'tiles' | 'game' | 'tele' | 'speedup' | 'front' | 'switch' | 'tune';

export interface TileLayer {
    type: 'tilemap';
    kind: LayerKind;
    width: number;
    height: number;
    color: [number, number, number, number];
    colorEnv: number; // -1 if none
    colorEnvOffset: number;
    image: number; // index into MapScene.images, -1 if none
    name: string;
    tiles: Tile[]; // always present (game/design/physics all decode their kind's data here)
    physics?: PhysicsMeta; // present for tele/speedup/switch/tune layers; parallel to `tiles`
}

export interface Quad {
    points: [number, number][]; // 5 (4 corners + pivot), world coords
    colors: [number, number, number, number][]; // 4
    texCoords: [number, number][]; // 4, normalised 0..1
    posEnv: number;
    posEnvOffset: number;
    colorEnv: number;
    colorEnvOffset: number;
}

export interface QuadLayer {
    type: 'quads';
    image: number;
    name: string;
    quads: Quad[];
}

export type Layer = TileLayer | QuadLayer;

export interface Group {
    xOffset: number;
    yOffset: number;
    xParallax: number;
    yParallax: number;
    clipping: boolean;
    clipX: number;
    clipY: number;
    clipW: number;
    clipH: number;
    name: string;
    layers: Layer[];
}

/**
 * Decoded RGBA pixel data for an embedded map image.
 * Mirrors the ImageData interface so it can be passed directly to
 * WebGL's texImage2D / GlRenderer.createTexture in the browser.
 */
export interface PixelData {
    readonly width: number;
    readonly height: number;
    readonly data: Uint8ClampedArray;
}

export interface MapImage {
    width: number;
    height: number;
    external: boolean;
    name: string;
    data: number;
    pixels?: PixelData;
}

export interface EnvPoint {
    time: number;
    curve: number;
    values: [number, number, number, number];
}
export interface Envelope {
    channels: number;
    synchronized: boolean;
    name: string;
    points: EnvPoint[];
}

export interface MapInfo {
    author: string;
    version: string;
    credits: string;
    license: string;
    settings: string[];
}

export interface MapScene {
    info: MapInfo;
    images: MapImage[];
    envelopes: Envelope[];
    groups: Group[];
}
