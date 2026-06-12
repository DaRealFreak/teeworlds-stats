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
