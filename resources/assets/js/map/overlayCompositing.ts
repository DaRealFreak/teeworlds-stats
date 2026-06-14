/**
 * Per-layer draw alpha for the entities overlay, by layer role and overlay strength (0..1).
 *
 * Mirrors DDNet's cl_overlay_entities compositing, with one deliberate refinement for partial
 * overlays: physics (entity) layers fade IN with the overlay and normal design/quad layers fade
 * OUT, but the parallax *backdrop* (sky/clouds/stars) stays fully opaque across the partial range
 * and only drops out as the overlay approaches full. Without that, fading the sky in lock-step
 * with the overlay washes the whole view to the grey entities background at a half overlay, which
 * makes faded foreground lines read as solid bars "all the way through" — the opposite of the
 * in-client look, where the bright sky keeps showing through and those lines stay translucent.
 */
export type LayerRole = 'physics' | 'design' | 'backdrop';

/**
 * Fraction of the top of the overlay range over which the backdrop fades out, so the grey
 * entities background can take over by full overlay without the sky popping away abruptly.
 */
export const BACKDROP_FADE_BAND = 0.2;

export function overlayLayerAlpha(role: LayerRole, opacity: number): number {
    const o = Math.min(1, Math.max(0, opacity));
    if (role === 'physics') return o;
    if (role === 'design') return 1 - o;
    // backdrop: opaque until (1 - BACKDROP_FADE_BAND), then linearly to 0 at full overlay
    return Math.min(1, Math.max(0, (1 - o) / BACKDROP_FADE_BAND));
}
