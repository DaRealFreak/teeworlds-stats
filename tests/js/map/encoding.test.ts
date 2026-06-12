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
