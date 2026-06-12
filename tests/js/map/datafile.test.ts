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

    it('rejects a buffer with valid magic but truncated header', () => {
        // "DATA" only (4 bytes): magic passes but there is no header — must be a clean datafile error
        const justMagic = new Uint8Array([0x44, 0x41, 0x54, 0x41]);
        expect(() => new DataFile(justMagic)).toThrow(/datafile/);
    });
});
