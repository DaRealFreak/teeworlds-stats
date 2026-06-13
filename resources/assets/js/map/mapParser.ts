import { DataFile } from './datafile';
import type { DataFileItem } from './datafile';
import { decodeCString, decodeI32String } from './encoding';
import { expandTiles } from './tiles';
import type {
    MapInfo,
    MapImage,
    Envelope,
    EnvPoint,
    Group,
    Layer,
    TileLayer,
    QuadLayer,
    LayerKind,
    Quad,
    MapScene,
} from './types';

// ---------------------------------------------------------------------------
// Info (item type 1)
// ---------------------------------------------------------------------------

async function parseInfo(df: DataFile): Promise<MapInfo> {
    const items = df.itemsOfType(1);
    if (items.length === 0) {
        return { author: '', version: '', credits: '', license: '', settings: [] };
    }
    const d = items[0]!.data;

    const readCString = async (idx: number): Promise<string> => {
        const ref = d[idx] ?? -1;
        if (ref < 0) return '';
        return decodeCString(await df.getData(ref));
    };

    const author = await readCString(1);
    const version = await readCString(2);
    const credits = await readCString(3);
    const license = await readCString(4);

    // [5] settings is optional; NUL-split CStrings
    const settingsRef = d[5] ?? -1;
    let settings: string[] = [];
    if (settingsRef >= 0) {
        const raw = await df.getData(settingsRef);
        settings = splitNulStrings(raw);
    }

    return { author, version, credits, license, settings };
}

/** Split a byte block of NUL-terminated CStrings into an array of strings. */
function splitNulStrings(bytes: Uint8Array): string[] {
    const decoder = new TextDecoder();
    const results: string[] = [];
    let start = 0;
    for (let i = 0; i <= bytes.length; i++) {
        if (i === bytes.length || bytes[i] === 0) {
            if (i > start) results.push(decoder.decode(bytes.subarray(start, i)));
            start = i + 1;
        }
    }
    return results;
}

// ---------------------------------------------------------------------------
// Images (item type 2)
// ---------------------------------------------------------------------------

async function parseImages(df: DataFile): Promise<MapImage[]> {
    const items = df.itemsOfType(2);
    return Promise.all(items.map((item) => parseImage(df, item)));
}

async function parseImage(df: DataFile, item: DataFileItem): Promise<MapImage> {
    const d = item.data;
    const width = d[1] ?? 0;
    const height = d[2] ?? 0;
    const external = (d[3] ?? 0) !== 0;
    const nameRef = d[4] ?? -1;
    const dataRef = d[5] ?? -1;

    const name = nameRef >= 0 ? decodeCString(await df.getData(nameRef)) : '';

    // Import PixelData only for the type; avoid pulling in DOM types at runtime.
    let pixels: import('./types').PixelData | undefined;
    if (!external && dataRef >= 0) {
        const bytes = await df.getData(dataRef);
        // Defensive guard: the block must be exactly width*height*4 RGBA bytes.
        if (bytes.length === width * height * 4) {
            pixels = { width, height, data: new Uint8ClampedArray(bytes) };
        }
    }

    return { width, height, external, name, data: dataRef, pixels };
}

// ---------------------------------------------------------------------------
// Envelopes (item type 3) + EnvPoints (item type 6)
// ---------------------------------------------------------------------------

function parseEnvelopes(df: DataFile): Envelope[] {
    const envItems = df.itemsOfType(3);
    if (envItems.length === 0) return [];

    // Determine envpoint stride: stride=22 if any envelope version >= 3, else stride=6
    const useExtendedStride = envItems.some((it) => (it.data[0] ?? 0) >= 3);
    const stride = useExtendedStride ? 22 : 6;

    const allPoints = parseEnvPoints(df, stride);

    return envItems.map((item) => parseEnvelope(item, allPoints));
}

function parseEnvPoints(df: DataFile, stride: number): EnvPoint[] {
    const items = df.itemsOfType(6);
    if (items.length === 0) return [];
    const d = items[0]!.data;
    if (d.length === 0) return [];

    const count = Math.floor(d.length / stride);
    const points: EnvPoint[] = [];
    for (let i = 0; i < count; i++) {
        const base = i * stride;
        const time = d[base] ?? 0;
        const curve = d[base + 1] ?? 0;
        const values: [number, number, number, number] = [
            d[base + 2] ?? 0,
            d[base + 3] ?? 0,
            d[base + 4] ?? 0,
            d[base + 5] ?? 0,
        ];
        points.push({ time, curve, values });
    }
    return points;
}

function parseEnvelope(item: DataFileItem, allPoints: EnvPoint[]): Envelope {
    const d = item.data;
    const channels = d[1] ?? 0;
    const startPoint = d[2] ?? 0;
    const numPoints = d[3] ?? 0;
    // name: i32s at [4..11], 8 ints
    const nameInts = d.subarray(4, 12);
    const name = decodeI32String(nameInts);
    // synchronized: optional (version >= 2), at [12]
    const synchronized = d.length > 12 ? (d[12] ?? 0) !== 0 : false;

    const points = allPoints.slice(startPoint, startPoint + numPoints);
    return { channels, synchronized, name, points };
}

// ---------------------------------------------------------------------------
// Groups (item type 4) and their layers (item type 5)
// ---------------------------------------------------------------------------

async function parseGroups(df: DataFile): Promise<Group[]> {
    const groupItems = df.itemsOfType(4);
    const layerItems = df.itemsOfType(5); // in ascending id order

    return Promise.all(groupItems.map((item) => parseGroup(df, item, layerItems)));
}

async function parseGroup(df: DataFile, item: DataFileItem, allLayers: DataFileItem[]): Promise<Group> {
    const d = item.data;
    const xOffset = d[1] ?? 0;
    const yOffset = d[2] ?? 0;
    const xParallax = d[3] ?? 0;
    const yParallax = d[4] ?? 0;
    const startLayer = d[5] ?? 0;
    const numLayers = d[6] ?? 0;
    // clipping and clip coords are optional (version >= 2, field [7])
    const clipping = d.length > 7 ? (d[7] ?? 0) !== 0 : false;
    const clipX = d[8] ?? 0;
    const clipY = d[9] ?? 0;
    const clipW = d[10] ?? 0;
    const clipH = d[11] ?? 0;
    // name: optional (version >= 3), i32s at [12..14], 3 ints
    const name = d.length > 14 ? decodeI32String(d.subarray(12, 15)) : '';

    // Layer slice: indices startLayer .. startLayer+numLayers-1 into allLayers array
    const layerSlice = allLayers.slice(startLayer, startLayer + numLayers);
    const layers: Layer[] = [];
    for (const layerItem of layerSlice) {
        const layer = await parseLayer(df, layerItem);
        if (layer !== null) layers.push(layer);
    }

    return { xOffset, yOffset, xParallax, yParallax, clipping, clipX, clipY, clipW, clipH, name, layers };
}

async function parseLayer(df: DataFile, item: DataFileItem): Promise<Layer | null> {
    const d = item.data;
    const layerType = d[1] ?? 0;

    if (layerType === 2) return parseTilemapLayer(df, item);
    if (layerType === 3) return parseQuadLayer(df, item);
    return null;
}

// ---------------------------------------------------------------------------
// Tilemap layer
// ---------------------------------------------------------------------------

/** Determine the layer kind from the flags bits. */
function tileKindFromFlags(flags: number): LayerKind {
    if (flags & 1) return 'game';
    if (flags & 2) return 'tele';
    if (flags & 4) return 'speedup';
    if (flags & 8) return 'front';
    if (flags & 16) return 'switch';
    if (flags & 32) return 'tune';
    return 'tiles';
}

/** Select the data block index based on the layer kind. */
function tileDataIndex(d: Int32Array, kind: LayerKind): number {
    // Field indices for kind-specific data blocks:
    //   design/game → [14]data
    //   tele        → [18]
    //   speedup     → [19]
    //   front       → [20]
    //   switch      → [21]
    //   tune        → [22]
    const kindIndex: Record<LayerKind, number> = {
        tiles: 14,
        game: 14,
        tele: 18,
        speedup: 19,
        front: 20,
        switch: 21,
        tune: 22,
    };
    const idx = kindIndex[kind];
    // Guard: if the kind-specific extended field is beyond the item's actual length,
    // the item is truncated — return -1 so the caller produces an all-air layer rather
    // than misreading a design/game data ref as a physics data ref.
    if (idx > 14 && d.length <= idx) return -1;
    return d[idx] ?? -1;
}

async function parseTilemapLayer(df: DataFile, item: DataFileItem): Promise<TileLayer> {
    const d = item.data;
    // [3]version, [4]width, [5]height, [6]flags, [7-10]color, [11]colorEnv,
    // [12]colorEnvOffset, [13]image, [14]data, [15-17]name
    const version = d[3] ?? 0;
    const width = d[4] ?? 0;
    const height = d[5] ?? 0;
    const flags = d[6] ?? 0;
    const color: [number, number, number, number] = [d[7] ?? 255, d[8] ?? 255, d[9] ?? 255, d[10] ?? 255];
    const colorEnv = d[11] ?? -1;
    const colorEnvOffset = d[12] ?? 0;
    const image = d[13] ?? -1;

    // Name: optional (version >= 3), I32String at [15..17], 3 ints
    const name = d.length > 17 ? decodeI32String(d.subarray(15, 18)) : '';

    const kind = tileKindFromFlags(flags);
    const dataRef = tileDataIndex(d, kind);

    let tiles;
    if (dataRef >= 0) {
        const bytes = await df.getData(dataRef);
        // Skip-RLE is used when tilemap version >= 4
        tiles = expandTiles(bytes, width, height, version >= 4);
    } else {
        // Physics-layer item too short to carry its kind-specific pointer (truncated map),
        // or genuinely no data — produce an all-air layer without fetching any data block.
        tiles = expandTiles(new Uint8Array(0), width, height, false);
    }

    return { type: 'tilemap', kind, width, height, color, colorEnv, colorEnvOffset, image, name, tiles };
}

// ---------------------------------------------------------------------------
// Quad layer
// ---------------------------------------------------------------------------

async function parseQuadLayer(df: DataFile, item: DataFileItem): Promise<QuadLayer> {
    const d = item.data;
    // [3]version, [4]num_quads, [5]data, [6]image, [7-9]name(opt v2)
    const numQuads = d[4] ?? 0;
    const dataRef = d[5] ?? -1;
    const image = d[6] ?? -1;
    const name = d.length > 9 ? decodeI32String(d.subarray(7, 10)) : '';

    let quads: Quad[] = [];
    if (dataRef >= 0 && numQuads > 0) {
        const bytes = await df.getData(dataRef);
        quads = parseQuads(bytes, numQuads);
    }

    return { type: 'quads', image, name, quads };
}

/** Parse `count` quads from the raw byte block (38 i32 per quad). */
function parseQuads(bytes: Uint8Array, count: number): Quad[] {
    // The data block is a raw i32 array (little-endian), 38 i32 = 152 bytes per quad
    const QUAD_I32 = 38;
    const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const totalI32 = Math.floor(bytes.byteLength / 4);
    const quads: Quad[] = [];

    for (let q = 0; q < count; q++) {
        const base = q * QUAD_I32;
        if (base + QUAD_I32 > totalI32) break;

        // Helper: read i32 at offset relative to this quad's base
        const ri = (offset: number): number => {
            const absIdx = (base + offset) * 4;
            if (absIdx + 4 > bytes.byteLength) return 0;
            return dv.getInt32(absIdx, true);
        };

        // [0..9] positions: 5 × (x, y) — divide by 512 for world coords
        const points: [number, number][] = [];
        for (let p = 0; p < 5; p++) {
            points.push([ri(p * 2) / 512, ri(p * 2 + 1) / 512]);
        }

        // [10..25] colors: 4 × (r, g, b, a)
        const colors: [number, number, number, number][] = [];
        for (let c = 0; c < 4; c++) {
            const base2 = 10 + c * 4;
            colors.push([ri(base2), ri(base2 + 1), ri(base2 + 2), ri(base2 + 3)]);
        }

        // [26..33] texcoords: 4 × (x, y) — divide by 1024 for normalised 0..1
        const texCoords: [number, number][] = [];
        for (let t = 0; t < 4; t++) {
            texCoords.push([ri(26 + t * 2) / 1024, ri(26 + t * 2 + 1) / 1024]);
        }

        // [34..37] env references
        const posEnv = ri(34);
        const posEnvOffset = ri(35);
        const colorEnv = ri(36);
        const colorEnvOffset = ri(37);

        quads.push({
            points,
            colors,
            texCoords,
            posEnv,
            posEnvOffset,
            colorEnv,
            colorEnvOffset,
        });
    }

    return quads;
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

export async function parseMap(bytes: Uint8Array): Promise<MapScene> {
    const df = new DataFile(bytes);

    const [info, images, envelopes, groups] = await Promise.all([
        parseInfo(df),
        parseImages(df),
        Promise.resolve(parseEnvelopes(df)),
        parseGroups(df),
    ]);

    return { info, images, envelopes, groups };
}
