import { describe, it, expect } from 'vitest';
import { resolveBatchTexture } from '../../../resources/assets/js/map/viewer';

// Stand-in texture objects: the resolver only stores/returns references, never touches GL.
const tex = (id: string): WebGLTexture => ({ id }) as unknown as WebGLTexture;

describe('resolveBatchTexture', () => {
    it('returns the shared entities texture for physics layers', () => {
        const ent = tex('entities');
        expect(resolveBatchTexture({ isPhysics: true, imageIndex: -1 }, new Map(), ent)).toBe(ent);
    });

    it('resolves a design layer texture by its image index', () => {
        const clouds = tex('clouds');
        const textures = new Map<number, WebGLTexture>([[2, clouds]]);
        expect(resolveBatchTexture({ isPhysics: false, imageIndex: 2 }, textures, null)).toBe(clouds);
    });

    it('picks up a texture added AFTER the batch was built (async external image load)', () => {
        // Regression: external mapres images (clouds, stars) load asynchronously, so their texture
        // is absent when batches are first built. The resolver must read the map live — not a value
        // captured at build time — or those layers keep drawing the 1×1 white fallback forever.
        const textures = new Map<number, WebGLTexture>();
        const batch = { isPhysics: false, imageIndex: 8 };
        expect(resolveBatchTexture(batch, textures, null)).toBe(null);

        const stars = tex('stars');
        textures.set(8, stars); // external image finishes loading later
        expect(resolveBatchTexture(batch, textures, null)).toBe(stars);
    });

    it('falls back to null (white texture) for a layer with no image', () => {
        expect(resolveBatchTexture({ isPhysics: false, imageIndex: -1 }, new Map(), null)).toBe(null);
    });
});
