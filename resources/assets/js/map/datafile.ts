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
        // 4 (magic) + 7×4 (header ints) = 36; reject truncated/garbage buffers with a clean error
        if (bytes.byteLength < 36) throw new Error('not a Teeworlds datafile');
        this.dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        const magic = String.fromCharCode(bytes[0]!, bytes[1]!, bytes[2]!, bytes[3]!);
        if (magic !== 'DATA' && magic !== 'ATAD') throw new Error('not a Teeworlds datafile');
        let p = 4;
        const i32 = () => {
            const v = this.dv.getInt32(p, true);
            p += 4;
            return v;
        };
        this.version = i32();
        if (this.version !== 3 && this.version !== 4) throw new Error(`unsupported datafile version ${this.version}`);
        /* size */ i32();
        /* swaplen */ i32();
        this.numItemTypes = i32();
        this.numItems = i32();
        this.numData = i32();
        const itemSize = i32();
        this.dataSize = i32();
        const types: { typeId: number; start: number; num: number }[] = [];
        for (let i = 0; i < this.numItemTypes; i++) types.push({ typeId: i32(), start: i32(), num: i32() });
        const itemOffsets = new Int32Array(this.numItems);
        for (let i = 0; i < this.numItems; i++) itemOffsets[i] = i32();
        this.dataOffsets = new Int32Array(this.numData);
        for (let i = 0; i < this.numData; i++) this.dataOffsets[i] = i32();
        if (this.version === 4) {
            for (let i = 0; i < this.numData; i++) i32();
        } // data_sizes (unused: we slice by offsets)
        const itemsStart = p;
        this.dataStart = itemsStart + itemSize;
        const typeOf = (index: number) => {
            const t = types.find((tt) => index >= tt.start && index < tt.start + tt.num);
            if (!t) throw new Error(`malformed datafile: item ${index} has no item type`);
            return t.typeId;
        };
        for (let i = 0; i < this.numItems; i++) {
            const q = itemsStart + (itemOffsets[i] ?? 0);
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
        if (index < 0 || index >= this.numData) {
            throw new RangeError(`data index ${index} out of range [0, ${this.numData})`);
        }
        const start = this.dataStart + (this.dataOffsets[index] ?? 0);
        const end =
            index + 1 < this.numData
                ? this.dataStart + (this.dataOffsets[index + 1] ?? 0)
                : this.dataStart + this.dataSize;
        const slice = this.buf.subarray(start, end);
        if (this.version === 3) return slice; // v3 data is uncompressed
        // Copy into a plain ArrayBuffer so Uint8Array satisfies BodyInit (DOM lib requires ArrayBuffer, not ArrayBufferLike).
        const compressed = new Uint8Array(slice) as Uint8Array<ArrayBuffer>;
        const stream = new Response(compressed).body!.pipeThrough(new DecompressionStream('deflate'));
        return new Uint8Array(await new Response(stream).arrayBuffer());
    }
}
