# Map Viewer — Phase B (TS datafile parser) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse a Teeworlds/DDNet `.map` datafile in strict browser TypeScript into a typed
`MapScene` (info, images, envelopes, groups, tile + quad layers), with zlib inflate via the
native `DecompressionStream` — the data the Phase C WebGL viewer renders.

**Architecture:** Two layers. `datafile.ts` is the generic container reader (header, item
index, item-data as `Int32Array`, async `getData(index)` that inflates the zlib data blocks).
`mapParser.ts` interprets the items into a `MapScene` using the documented item layouts. All
pure, fully typed, no DOM dependency beyond `DataView`/`TextDecoder`/`DecompressionStream`.
Tests run under **vitest** (node 22, where `DecompressionStream` exists) against a vendored
`gravity.map` fixture.

**Tech Stack:** TypeScript (strict, ES2022, `noUncheckedIndexedAccess`), vitest, Vite. No new
runtime dependency (inflate is native).

---

## Format reference (authoritative — from libtw2 docs + `ddnet/src/game/mapitems.h`)

All integers little-endian. Datafile layout:
```
version_header: magic[4]="DATA" (or reversed "ATAD"), version:i32 (3 or 4)
header(7×i32): size, swaplen, num_item_types, num_items, num_data, item_size, data_size
item_types: num_item_types × (type_id:i32, start:i32, num:i32)
item_offsets: num_items × i32   (offset of item i relative to items section start)
data_offsets: num_data × i32    (offset of data block i relative to data section start)
data_sizes:  num_data × i32     (v4 ONLY; uncompressed size of data block i)
items[item_size bytes]: each item = (type_id__id:i32 [hi16=type, lo16=id], size:i32, item_data[size bytes])
data[data_size bytes]: v4 → each block is zlib-compressed (inflate to data_sizes[i] bytes)
```
Data block i spans `data_offsets[i] .. (i+1<num_data ? data_offsets[i+1] : data_size)`.

Item type_ids: 0=Version, 1=Info, 2=Images, 3=Envelopes, 4=Groups, 5=Layers, 6=EnvPoints,
7=Sounds(DDNet), 0xffff=UUID-index(DDNet). `opt`/`&`/`*` fields use `-1` for "unused".

Item layouts (item_data as i32 array, index → field):
- **Version (0):** `[0]=1`.
- **Info (1):** `[0]version, [1]&author, [2]&version, [3]&credits, [4]&license, [5]&settings(DDNet)`. Each `&` is a data-block index of a CString (settings = array of CStrings).
- **Image (2):** `[0]version, [1]width, [2]height, [3]external(bool), [4]&name, [5]&data(opt), [6]variant(vanilla v2)`. Embedded (external=0): `data`→RGBA (v1) or per-variant pixels. External (external=1): `data`=-1, load by `name`.
- **Envelope (3):** `[0]version, [1]channels(1=sound,3=pos,4=color), [2]start_point, [3]num_points, [4..11]name(I32String,8 i32), [12]synchronized(v2 bool)`.
- **Group (4):** `[0]version, [1]x_offset, [2]y_offset, [3]x_parallax, [4]y_parallax, [5]start_layer, [6]num_layers, [7]clipping(v2), [8]clip_x, [9]clip_y, [10]clip_w, [11]clip_h, [12..14]name(I32String,3 i32,v3)`.
- **Layer base (5):** `[0]_version(unused), [1]type(2=tilemap,3=quads,9/10=sounds), [2]flags(detail=1)`.
  - **Tilemap ext:** `[3]version, [4]width, [5]height, [6]flags(kind: 0=tiles,1=game,2=tele,4=speedup,8=front,16=switch,32=tune), [7-10]color(r,g,b,a i32), [11]color_env(opt *), [12]color_env_offset, [13]image(opt *), [14]data(&), [15-17]name(I32String v3), [18]tele,[19]speedup,[20]front,[21]switch,[22]tune` (DDNet). Tile data for the layer comes from the field matching its kind: tiles/game→`data`(14), tele→`tele`(18), speedup→`speedup`(19), front→`front`(20), switch→`switch`(21), tune→`tune`(22). Read an extended field ONLY if the item is large enough (`item_data.length` > that index); else treat as -1.
  - **Quads ext:** `[3]version, [4]num_quads, [5]data(&), [6]image(opt *), [7-9]name(I32String v2)`.
- **EnvPoints (6):** one item; item_data is all points concatenated. Each point = 6 i32 if every envelope version ≤2, else 22 i32 (bezier). First 6: `[0]time, [1]curve_type, [2..5]values` (pos: x,y,rot,-; color: r,g,b,a; sound: volume,-,-,-).

Tile structs (bytes, row-major, length = w·h after expansion):
- **Tile (4 B):** id, flags(1=vflip,2=hflip,4=opaque,8=rot90), skip, unused. If tilemap layer `version ≥ 4`, the data is RLE: each entry repeats `skip+1` times (set skip=0 after expansion). gravity.map layers are v3 → no RLE (one Tile per cell).
- **Tele (2 B):** number, id. **Speedup (6 B):** force, max_speed, id, pad, angle:i16(LE). **Switch (4 B):** number, id, flags, delay. **Tune (2 B):** number, id.

Quad struct (152 B = 38 i32): `[0..9]positions[5]=(x,y)` (÷512 → world; els 0-3 corners TL,TR,BL,BR; el 4 pivot), `[10..25]corner_colors[4]=(r,g,b,a)`, `[26..33]texcoords[4]=(x,y)` (÷1024), `[34]pos_env(opt *), [35]pos_env_offset, [36]color_env(opt *), [37]color_env_offset`.

I32String: take the i32s as big-endian bytes, concat, drop the final (null) byte, wrapping-subtract 128 from each remaining byte → a NUL-padded CString; trim at first NUL, UTF-8 decode.

## gravity.map ground truth (the test fixture)

magic DATA, version 4; num_item_types 6 (ids 0,1,2,4,5,6), num_items 22, num_data 19,
item_size 1392, data_size 19333. Version item `[1]`. Info `[1,-1,-1,-1,-1]` (v1, no opt fields).
4 images: id0/id1 embedded 1024×1024 (data blocks 1,3 inflate to 4194304 B each), id2/id3
external (data -1). 4 groups: g3 is the Game group — x_parallax=100, y_parallax=100, name
"Game", start_layer 3, num_layers 8. 11 layers: L0/L1 quads (1 quad), L2 quads (13 quads,
image 3), L3 tilemap kind=game w50 h120 data=9, L4-L7/L9 tilemap kind=tiles, L8 tilemap
kind=tele (tele=15 → data block 15, 12000 B = 6000×2), L10 tilemap kind=tune (tune=18 → block
18, 12000 B). EnvPoints item is **0 bytes → 0 envelopes**. Every tile layer is 50×120 → 6000
tiles.

---

## File structure

| File | Responsibility |
| --- | --- |
| `package.json` (modify) | add `vitest` devDep + `"test": "vitest run"` |
| `vitest.config.ts` (create) | node env, include `tests/js/**/*.test.ts` |
| `eslint.config.js` (modify) | lint block for `tests/js/**/*.ts` (node+browser globals) |
| `.github/workflows/theme.yml` (modify) | add `npm run test` step |
| `tests/js/fixtures/gravity.map` (create) | vendored copy of the fixture (binary) |
| `resources/assets/js/map/encoding.ts` (create) | I32String / CString / color decode helpers |
| `resources/assets/js/map/datafile.ts` (create) | container reader: header, items, async `getData` |
| `resources/assets/js/map/mapParser.ts` (create) | items → `MapScene` |
| `resources/assets/js/map/types.ts` (create) | `MapScene` and member types |
| `tests/js/map/*.test.ts` (create) | vitest tests against the fixture |

**Conventions:** strict TS, `noUncheckedIndexedAccess` (array indexing yields `T|undefined` —
guard or assert). No node types in `resources/assets/js` (browser bundle). Tests live OUTSIDE
`resources/assets/js` so the browser `tsc` stays node-free. Commit `package-lock.json`.

---

## Task 1: vitest harness + vendored fixture

**Files:** modify `package.json`, `.github/workflows/theme.yml`, `eslint.config.js`; create
`vitest.config.ts`, `tests/js/fixtures/gravity.map`, `tests/js/sanity.test.ts`.

- [ ] **Step 1: Install vitest + add the script.**

Run: `npm install -D vitest@^4`
Then in `package.json` `scripts`, add: `"test": "vitest run"`.
Verify `package-lock.json` updated.

- [ ] **Step 2: vitest config.** Create `vitest.config.ts`:

```ts
import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.ts'],
        environment: 'node', // parser needs no DOM; DecompressionStream is global in node 22
    },
});
```

- [ ] **Step 3: ESLint block for tests.** In `eslint.config.js`, add a config object (before the
final `prettier`) so test files lint with node+browser globals and aren't held to browser-only
rules:

```js
    {
        // vitest tests run in Node but exercise browser/Web APIs (DecompressionStream, etc.)
        files: ['tests/js/**/*.ts'],
        extends: [js.configs.recommended, ...tseslint.configs.recommended],
        languageOptions: {
            globals: { ...globals.node, ...globals.browser },
        },
    },
```

- [ ] **Step 4: Vendor the fixture.** Copy the real map into the repo (it currently lives only in
the untracked `ddnet-web` checkout):

Run: `mkdir -p tests/js/fixtures && cp ddnet-web/www/map/gravity.map tests/js/fixtures/gravity.map`
Confirm: `ls -l tests/js/fixtures/gravity.map` shows ~21073 bytes.

- [ ] **Step 5: Sanity test.** Create `tests/js/sanity.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

describe('vitest harness', () => {
    it('reads the gravity.map fixture and sees the DATA magic', () => {
        const buf = readFileSync(new URL('./fixtures/gravity.map', import.meta.url));
        expect(buf.byteLength).toBe(21073);
        expect(String.fromCharCode(buf[0], buf[1], buf[2], buf[3])).toBe('DATA');
    });

    it('has a working DecompressionStream', () => {
        expect(typeof DecompressionStream).toBe('function');
    });
});
```

- [ ] **Step 6: Verify the whole toolchain stays green.**

Run: `npm run test` → Expected: 2 passing tests.
Run: `npm run type-check` → Expected: no errors (tests are outside the browser `include`, so this is unaffected).
Run: `npm run lint` → Expected: no errors.

- [ ] **Step 7: Add CI step.** In `.github/workflows/theme.yml`, add after the Lint step and before Build:

```yaml
      - name: JS tests
        run: npm run test
```

- [ ] **Step 8: Commit.**

```bash
git add package.json package-lock.json vitest.config.ts eslint.config.js \
        .github/workflows/theme.yml tests/js/fixtures/gravity.map tests/js/sanity.test.ts
git commit -m "test(js): add vitest harness + vendored gravity.map fixture"
```

---

## Task 2: encoding helpers (I32String / CString / color)

**Files:** create `resources/assets/js/map/encoding.ts`, `tests/js/map/encoding.test.ts`.

- [ ] **Step 1: Failing test.** Create `tests/js/map/encoding.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { decodeI32String, decodeCString } from '../../../resources/assets/js/map/encoding';

describe('encoding', () => {
    it('decodes an I32String ("Game" group name from gravity.map)', () => {
        // the three i32 of the Game group's name field, as observed in the fixture
        expect(decodeI32String(new Int32Array([-941494811, -2139062144, -2139062272]))).toBe('Game');
    });

    it('decodes a NUL-terminated CString from bytes', () => {
        expect(decodeCString(new Uint8Array([0x68, 0x69, 0x00, 0x99]))).toBe('hi');
    });

    it('returns empty string for an immediately-NUL CString', () => {
        expect(decodeCString(new Uint8Array([0x00]))).toBe('');
    });
});
```

- [ ] **Step 2: Run → fail.** `npx vitest run tests/js/map/encoding.test.ts` → module not found.

- [ ] **Step 3: Implement.** Create `resources/assets/js/map/encoding.ts`:

```ts
/** Decode a CString (NUL-terminated UTF-8) from a byte view, stopping at the first NUL. */
export function decodeCString(bytes: Uint8Array): string {
    let end = bytes.indexOf(0);
    if (end < 0) end = bytes.length;
    return new TextDecoder().decode(bytes.subarray(0, end));
}

/**
 * Decode an I32String: the i32s are big-endian byte groups; drop the trailing NUL byte, then
 * wrapping-subtract 128 from each remaining byte to recover a NUL-padded CString.
 * (See the libtw2 map format doc.)
 */
export function decodeI32String(ints: Int32Array): string {
    const bytes = new Uint8Array(ints.length * 4);
    const dv = new DataView(bytes.buffer);
    for (let i = 0; i < ints.length; i++) dv.setInt32(i * 4, ints[i] ?? 0, false); // big-endian
    // last byte is the terminator; subtract 128 (wrapping) from the rest
    const out = new Uint8Array(bytes.length - 1);
    for (let i = 0; i < out.length; i++) out[i] = ((bytes[i] ?? 0) - 128) & 0xff;
    return decodeCString(out);
}
```

- [ ] **Step 4: Run → pass.** `npx vitest run tests/js/map/encoding.test.ts` → 3 passing.
- [ ] **Step 5: type-check + lint.** `npm run type-check` and `npm run lint` → green.
- [ ] **Step 6: Commit.**

```bash
git add resources/assets/js/map/encoding.ts tests/js/map/encoding.test.ts
git commit -m "feat(map): I32String/CString decode helpers"
```

---

## Task 3: datafile container reader

**Files:** create `resources/assets/js/map/datafile.ts`, `tests/js/map/datafile.test.ts`.

- [ ] **Step 1: Failing test.** Create `tests/js/map/datafile.test.ts`:

```ts
import { describe, it, expect, beforeAll } from 'vitest';
import { readFileSync } from 'node:fs';
import { DataFile } from '../../../resources/assets/js/map/datafile';

let bytes: Uint8Array;
beforeAll(() => {
    bytes = new Uint8Array(readFileSync(new URL('../fixtures/gravity.map', import.meta.url)));
});

describe('DataFile', () => {
    it('parses the header of gravity.map', () => {
        const df = new DataFile(bytes);
        expect(df.version).toBe(4);
        expect(df.numItemTypes).toBe(6);
        expect(df.numItems).toBe(22);
        expect(df.numData).toBe(19);
    });

    it('exposes the Version item (type 0) with data [1]', () => {
        const df = new DataFile(bytes);
        const versionItems = df.itemsOfType(0);
        expect(versionItems).toHaveLength(1);
        expect(Array.from(versionItems[0]!.data)).toEqual([1]);
    });

    it('counts items per type (images=4, groups=4, layers=11)', () => {
        const df = new DataFile(bytes);
        expect(df.itemsOfType(2)).toHaveLength(4); // images
        expect(df.itemsOfType(4)).toHaveLength(4); // groups
        expect(df.itemsOfType(5)).toHaveLength(11); // layers
    });

    it('inflates an embedded image data block to 1024*1024*4 bytes', async () => {
        const df = new DataFile(bytes);
        const data = await df.getData(1); // image id0 pixel block
        expect(data.byteLength).toBe(1024 * 1024 * 4);
    });

    it('rejects a non-datafile buffer', () => {
        expect(() => new DataFile(new Uint8Array([1, 2, 3, 4, 5, 6, 7, 8]))).toThrow();
    });
});
```

- [ ] **Step 2: Run → fail.** `npx vitest run tests/js/map/datafile.test.ts` → module not found.

- [ ] **Step 3: Implement.** Create `resources/assets/js/map/datafile.ts`. Parse per the format
reference above. Key points: validate magic is `DATA` or reversed `ATAD` (else throw); read the
7-int header; build per-type item lists; for each item resolve `(type, id, data:Int32Array)`;
`getData(i)` slices the compressed block and inflates with `DecompressionStream('deflate')`,
returning a `Uint8Array`. Suggested shape:

```ts
export interface DataFileItem {
    readonly type: number;
    readonly id: number;
    readonly data: Int32Array; // item_data as i32
}

export class DataFile {
    readonly version: number;
    readonly numItemTypes: number;
    readonly numItems: number;
    readonly numData: number;
    private readonly items: DataFileItem[] = [];
    private readonly dataStart: number;
    private readonly dataOffsets: Int32Array;
    private readonly dataSize: number;
    private readonly buf: Uint8Array;
    private readonly dv: DataView;

    constructor(bytes: Uint8Array) {
        this.buf = bytes;
        this.dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        const magic = String.fromCharCode(bytes[0]!, bytes[1]!, bytes[2]!, bytes[3]!);
        if (magic !== 'DATA' && magic !== 'ATAD') throw new Error('not a Teeworlds datafile');
        let p = 4;
        const i32 = () => { const v = this.dv.getInt32(p, true); p += 4; return v; };
        this.version = i32();
        if (this.version !== 3 && this.version !== 4) throw new Error(`unsupported datafile version ${this.version}`);
        /* size */ i32(); /* swaplen */ i32();
        this.numItemTypes = i32();
        this.numItems = i32();
        this.numData = i32();
        const itemSize = i32();
        this.dataSize = i32();
        // item_types
        const types: { typeId: number; start: number; num: number }[] = [];
        for (let i = 0; i < this.numItemTypes; i++) types.push({ typeId: i32(), start: i32(), num: i32() });
        // item_offsets, data_offsets, [data_sizes v4]
        const itemOffsets = new Int32Array(this.numItems);
        for (let i = 0; i < this.numItems; i++) itemOffsets[i] = i32();
        this.dataOffsets = new Int32Array(this.numData);
        for (let i = 0; i < this.numData; i++) this.dataOffsets[i] = i32();
        if (this.version === 4) { for (let i = 0; i < this.numData; i++) i32(); } // data_sizes (unused: we slice by offsets)
        const itemsStart = p;
        this.dataStart = itemsStart + itemSize;
        // resolve every item; map index→type via the type table
        const typeOf = (index: number) => types.find((t) => index >= t.start && index < t.start + t.num)!.typeId;
        for (let i = 0; i < this.numItems; i++) {
            let q = itemsStart + (itemOffsets[i] ?? 0);
            const typeIdId = this.dv.getInt32(q, true);
            const size = this.dv.getInt32(q + 4, true);
            const data = new Int32Array(size / 4);
            for (let k = 0; k < data.length; k++) data[k] = this.dv.getInt32(q + 8 + k * 4, true);
            this.items.push({ type: typeOf(i), id: typeIdId & 0xffff, data });
        }
    }

    itemsOfType(type: number): DataFileItem[] {
        return this.items.filter((it) => it.type === type);
    }

    async getData(index: number): Promise<Uint8Array> {
        const start = this.dataStart + (this.dataOffsets[index] ?? 0);
        const end = index + 1 < this.numData
            ? this.dataStart + (this.dataOffsets[index + 1] ?? 0)
            : this.dataStart + this.dataSize;
        const slice = this.buf.subarray(start, end);
        if (this.version === 3) return slice; // v3 data is uncompressed
        const stream = new Response(slice).body!.pipeThrough(new DecompressionStream('deflate'));
        const out = new Uint8Array(await new Response(stream).arrayBuffer());
        return out;
    }
}
```

- [ ] **Step 4: Run → pass.** `npx vitest run tests/js/map/datafile.test.ts` → 5 passing.
- [ ] **Step 5: type-check + lint.** `npm run type-check`, `npm run lint` → green (mind `noUncheckedIndexedAccess`).
- [ ] **Step 6: Commit.**

```bash
git add resources/assets/js/map/datafile.ts tests/js/map/datafile.test.ts
git commit -m "feat(map): datafile container reader with native inflate"
```

---

## Task 4: tile expansion (skip-RLE) + scene types

**Files:** create `resources/assets/js/map/types.ts`, `resources/assets/js/map/tiles.ts`,
`tests/js/map/tiles.test.ts`.

- [ ] **Step 1: Failing test.** Create `tests/js/map/tiles.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { expandTiles } from '../../../resources/assets/js/map/tiles';

describe('expandTiles', () => {
    it('reads one Tile per cell when not RLE-compressed (layer version < 4)', () => {
        // 2 cells: id=5 flags=8(rot), id=1 flags=0 — each 4 bytes (id, flags, skip, unused)
        const bytes = new Uint8Array([5, 8, 0, 0, 1, 0, 0, 0]);
        const tiles = expandTiles(bytes, 2, 1, false);
        expect(tiles).toHaveLength(2);
        expect(tiles[0]).toMatchObject({ id: 5, flags: 8 });
        expect(tiles[1]).toMatchObject({ id: 1, flags: 0 });
    });

    it('expands skip-RLE (layer version >= 4): skip=2 means 3 copies', () => {
        // one Tile id=7 with skip=2 then a Tile id=9 skip=0 → [7,7,7,9]
        const bytes = new Uint8Array([7, 0, 2, 0, 9, 0, 0, 0]);
        const tiles = expandTiles(bytes, 4, 1, true);
        expect(tiles.map((t) => t.id)).toEqual([7, 7, 7, 9]);
        expect(tiles.every((t) => t.skip === 0)).toBe(true);
    });
});
```

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement.** Create `resources/assets/js/map/types.ts` with the scene types (fill
in as later tasks need; start with the tile/layer/scene shapes used here and in Task 5):

```ts
export interface Tile { id: number; flags: number; skip: number }
export interface TeleTile { number: number; id: number }
export interface SpeedupTile { force: number; maxSpeed: number; id: number; angle: number }
export interface SwitchTile { number: number; id: number; flags: number; delay: number }
export interface TuneTile { number: number; id: number }

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

export interface QuadLayer { type: 'quads'; image: number; name: string; quads: Quad[] }

export type Layer = TileLayer | QuadLayer;

export interface Group {
    xOffset: number; yOffset: number;
    xParallax: number; yParallax: number;
    clipping: boolean; clipX: number; clipY: number; clipW: number; clipH: number;
    name: string;
    layers: Layer[];
}

export interface MapImage { width: number; height: number; external: boolean; name: string; data: number }

export interface EnvPoint { time: number; curve: number; values: [number, number, number, number] }
export interface Envelope { channels: number; synchronized: boolean; name: string; points: EnvPoint[] }

export interface MapInfo { author: string; version: string; credits: string; license: string; settings: string[] }

export interface MapScene {
    info: MapInfo;
    images: MapImage[];
    envelopes: Envelope[];
    groups: Group[];
}
```

Create `resources/assets/js/map/tiles.ts`:

```ts
import type { Tile } from './types';

/**
 * Decode a 'Tile' byte array (4 bytes/tile: id, flags, skip, unused) into w*h tiles.
 * When `useSkip` (tilemap layer version >= 4, the 0.7 RLE), each entry repeats `skip+1` times;
 * the expanded tiles carry skip=0 (Teeworlds rendering assumes skip is 0).
 */
export function expandTiles(bytes: Uint8Array, width: number, height: number, useSkip: boolean): Tile[] {
    const total = width * height;
    const tiles: Tile[] = [];
    let p = 0;
    while (tiles.length < total && p + 4 <= bytes.length) {
        const id = bytes[p] ?? 0;
        const flags = bytes[p + 1] ?? 0;
        const skip = bytes[p + 2] ?? 0;
        p += 4;
        const repeat = useSkip ? skip + 1 : 1;
        for (let r = 0; r < repeat && tiles.length < total; r++) tiles.push({ id, flags, skip: 0 });
    }
    while (tiles.length < total) tiles.push({ id: 0, flags: 0, skip: 0 }); // pad a truncated layer
    return tiles;
}
```

- [ ] **Step 4: Run → pass.** `npx vitest run tests/js/map/tiles.test.ts` → 2 passing.
- [ ] **Step 5: type-check + lint** → green.
- [ ] **Step 6: Commit.**

```bash
git add resources/assets/js/map/types.ts resources/assets/js/map/tiles.ts tests/js/map/tiles.test.ts
git commit -m "feat(map): tile decode (skip-RLE) + MapScene types"
```

---

## Task 5: mapParser — info, images, envelopes, groups, layers

**Files:** create `resources/assets/js/map/mapParser.ts`, `tests/js/map/mapParser.test.ts`.

- [ ] **Step 1: Failing test.** Create `tests/js/map/mapParser.test.ts`:

```ts
import { describe, it, expect, beforeAll } from 'vitest';
import { readFileSync } from 'node:fs';
import { parseMap } from '../../../resources/assets/js/map/mapParser';
import type { MapScene, TileLayer, QuadLayer } from '../../../resources/assets/js/map/types';

let scene: MapScene;
beforeAll(async () => {
    const bytes = new Uint8Array(readFileSync(new URL('../fixtures/gravity.map', import.meta.url)));
    scene = await parseMap(bytes);
});

describe('parseMap (gravity.map)', () => {
    it('parses info (version 1, no optional strings)', () => {
        expect(scene.info.author).toBe('');
        expect(scene.info.license).toBe('');
    });

    it('parses 4 images (2 embedded 1024x1024, 2 external)', () => {
        expect(scene.images).toHaveLength(4);
        expect(scene.images.filter((i) => !i.external)).toHaveLength(2);
        expect(scene.images[0]).toMatchObject({ width: 1024, height: 1024, external: false });
        expect(scene.images.filter((i) => i.external).every((i) => i.name.length > 0)).toBe(true);
    });

    it('has no envelopes (empty envpoints item)', () => {
        expect(scene.envelopes).toHaveLength(0);
    });

    it('parses 4 groups; the Game group has parallax 100/100, name "Game", 8 layers', () => {
        expect(scene.groups).toHaveLength(4);
        const game = scene.groups.find((g) => g.name === 'Game');
        expect(game).toBeDefined();
        expect(game!.xParallax).toBe(100);
        expect(game!.yParallax).toBe(100);
        expect(game!.layers).toHaveLength(8);
    });

    it('parses the game/tele/tune tilemap layers; every tile layer decodes to 50*120 tiles', () => {
        const tileLayers = scene.groups.flatMap((g) => g.layers).filter((l): l is TileLayer => l.type === 'tilemap');
        expect(tileLayers).toHaveLength(8);
        expect(tileLayers.every((l) => l.width === 50 && l.height === 120 && l.tiles.length === 6000)).toBe(true);
        expect(tileLayers.some((l) => l.kind === 'game')).toBe(true);
        expect(tileLayers.some((l) => l.kind === 'tele')).toBe(true);
        expect(tileLayers.some((l) => l.kind === 'tune')).toBe(true);
    });

    it('parses quad layers (one has 13 quads with normalised texcoords)', () => {
        const quadLayers = scene.groups.flatMap((g) => g.layers).filter((l): l is QuadLayer => l.type === 'quads');
        expect(quadLayers).toHaveLength(3);
        const big = quadLayers.find((q) => q.quads.length === 13);
        expect(big).toBeDefined();
        expect(big!.quads[0]!.colors).toHaveLength(4);
        expect(big!.quads[0]!.points).toHaveLength(5);
    });
});
```

- [ ] **Step 2: Run → fail.**

- [ ] **Step 3: Implement** `resources/assets/js/map/mapParser.ts`. Use `DataFile` + the item
layouts + `decodeI32String`/`decodeCString` + `expandTiles`. Algorithm:
  - `parseMap(bytes)`: build `DataFile`; parse images (decode `name` via `getData(&name)` when set,
    embedded `data` index kept as `images[i].data`), envelopes (from type 3 items; points from
    the single type-6 item, stride 6 or 22 by max envelope version — gravity has none), groups
    (type 4) and, per group, its layers `start_layer..start_layer+num_layers` (type-5 items in
    order). For each layer item read base `[1]type`: type 2 → tilemap, type 3 → quads.
  - Tilemap: read fields per the offset table; pick the data index by kind
    (`tiles|game`→`data[14]`, `tele`→`[18]`, `speedup`→`[19]`, `front`→`[20]`, `switch`→`[21]`,
    `tune`→`[22]`), guarding `data.length > index` (else -1); if the index ≥ 0,
    `expandTiles(await getData(index), w, h, layerVersion >= 4)`; decode `name` (I32String at
    15-17 when present). Map `image` to its index in `scene.images` (the raw value already is the
    image item id, which equals the array index since image ids count from 0).
  - Quads: read `num_quads`, `&data`, `image`; decode each 38-i32 quad (positions ÷512, texcoords
    ÷1024, colors as-is). `name` I32String at 7-9 when present.
  - Info: from the single type-1 item, decode each optional `&` string via `getData` when ≥ 0
    (settings = split the CString-array block on NUL).

  Keep functions small (one per item type). Mind `noUncheckedIndexedAccess` on every `data[k]`.

- [ ] **Step 4: Run → pass.** `npx vitest run tests/js/map/mapParser.test.ts` → 6 passing.
- [ ] **Step 5: type-check + lint** → green.
- [ ] **Step 6: Commit.**

```bash
git add resources/assets/js/map/mapParser.ts tests/js/map/mapParser.test.ts
git commit -m "feat(map): parse datafile items into a typed MapScene"
```

---

## Task 6: integration + bundle health

**Files:** create `resources/assets/js/map/index.ts`, `tests/js/map/integration.test.ts`.

This task adds a public barrel (`index.ts`) and a holistic cross-item consistency test.

- [ ] **Step 1: Failing integration test.** Create `tests/js/map/integration.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { parseMap } from '../../../resources/assets/js/map/index';

describe('parseMap integration', () => {
    it('produces a self-consistent scene for gravity.map', async () => {
        const bytes = new Uint8Array(readFileSync(new URL('../fixtures/gravity.map', import.meta.url)));
        const scene = await parseMap(bytes);
        // every group's layers are well-formed
        const layers = scene.groups.flatMap((g) => g.layers);
        expect(layers.length).toBe(11);
        for (const l of layers) {
            if (l.type === 'tilemap') expect(l.tiles.length).toBe(l.width * l.height);
            else for (const q of l.quads) { expect(q.points).toHaveLength(5); expect(q.colors).toHaveLength(4); }
        }
        // image indices referenced by layers are valid or -1
        for (const l of layers) if ('image' in l) expect(l.image === -1 || l.image < scene.images.length).toBe(true);
    });
});
```

- [ ] **Step 2: Run → fail** (missing `index.ts`). `npx vitest run tests/js/map/integration.test.ts`.
- [ ] **Step 3: Implement the barrel.** Create `resources/assets/js/map/index.ts`:

```ts
export { parseMap } from './mapParser';
export type { MapScene, Group, Layer, TileLayer, QuadLayer, Tile, Quad, MapImage, Envelope } from './types';
```

- [ ] **Step 4: Run → pass.** `npm run test` → all map tests green.
- [ ] **Step 5: Full bundle health.**

Run: `npm run test` (all green), `npm run type-check` (green), `npm run lint` (green),
`npm run build` (the new modules aren't imported by `app.ts` yet, so the build must still succeed
unchanged — confirm no error).

- [ ] **Step 6: Commit.**

```bash
git add resources/assets/js/map/index.ts tests/js/map/integration.test.ts
git commit -m "feat(map): public parseMap barrel + integration coverage"
```

---

## Self-review notes (spec coverage)

- Spec **Phase B** = `datafile.ts` (container, native `DecompressionStream`) + `mapParser.ts`
  (typed `MapScene`: info, images embedded+external, envelopes+points, groups parallax/clip,
  tilemap layers incl. flags/kind + the DDNet physics data-pointer selection + 0.7 skip-RLE,
  quad layers). All covered: Task 3 (container), Task 2 (encoding), Task 4 (tiles+types), Task 5
  (parser), Task 6 (barrel+integration). Task 1 establishes the test harness the spec's testing
  section calls for ("a `datafile.ts` parse test against gravity.map").
- **Deferred (not Phase B):** envelope *evaluation* + the WebGL renderer (Phase C), texture
  loading + asset vendoring (Phase C), Playwright (Phase D), UDP source (Phase E). The parser
  only models envelope *data*; evaluation is Phase C.
- **Type consistency:** `DataFile.itemsOfType(n): DataFileItem[]`, `DataFileItem.data: Int32Array`,
  `DataFile.getData(i): Promise<Uint8Array>`, `expandTiles(bytes,w,h,useSkip): Tile[]`,
  `parseMap(bytes): Promise<MapScene>` — referenced identically across Tasks 3-6.
- **Fixture limitation logged:** gravity.map uses tilemap layer version 3, so the live file
  exercises the non-RLE path; the skip-RLE path is covered by a synthetic unit test in Task 4
  (no v4-layer fixture is vendored).
