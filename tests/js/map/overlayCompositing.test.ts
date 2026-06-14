import { describe, it, expect } from 'vitest';
import { overlayLayerAlpha } from '../../../resources/assets/js/map/overlayCompositing';

describe('overlayLayerAlpha', () => {
    it('fades physics (entity) layers IN with the overlay', () => {
        expect(overlayLayerAlpha('physics', 0)).toBe(0);
        expect(overlayLayerAlpha('physics', 0.5)).toBe(0.5);
        expect(overlayLayerAlpha('physics', 1)).toBe(1);
    });

    it('fades normal design/quad layers OUT with the overlay', () => {
        expect(overlayLayerAlpha('design', 0)).toBe(1);
        expect(overlayLayerAlpha('design', 0.5)).toBe(0.5);
        expect(overlayLayerAlpha('design', 1)).toBe(0);
    });

    it('keeps the parallax backdrop (sky) fully opaque across the partial range', () => {
        // The whole point: at a half overlay the sky must still show through, so faded
        // foreground lines read as translucent (like DDNet) rather than solid over grey.
        expect(overlayLayerAlpha('backdrop', 0)).toBe(1);
        expect(overlayLayerAlpha('backdrop', 0.5)).toBe(1);
        expect(overlayLayerAlpha('backdrop', 0.79)).toBe(1);
    });

    it('drops the backdrop out only as the overlay approaches full (grey entities background)', () => {
        expect(overlayLayerAlpha('backdrop', 0.9)).toBeCloseTo(0.5, 5);
        expect(overlayLayerAlpha('backdrop', 1)).toBe(0);
    });
});
