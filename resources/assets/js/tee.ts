/**
 * Tee skin renderer. Composites a Teeworlds tee onto a <canvas> from the default client skins,
 * reproducing the client's tinting and part layout. Two systems are supported:
 *   - 0.6: one 256x128 sheet + optional body/feet HSL colors (grayscale + body renormalize + tint).
 *   - 0.7: six separate part sheets, each tinted by its own HSL color.
 * Ported from ddnet/teeworlds render.cpp (RenderTee6/RenderTee7) + skins.cpp + color.h. All geometry
 * is in the engine's 64-unit "BaseSize" space, then scaled to the canvas. A neutral forward gaze is
 * used (Direction = 0,0) since these are static avatars.
 *
 * Descriptors come from App\Utility\TeeSkin via a `data-tee` JSON attribute on the canvas:
 *   06: { mode:'06', url, colorBody:int|null, colorFeet:int|null }
 *   07: { mode:'07', parts:{ body:{url,color}, marking:{url,color}, feet:{url,color}, eyes:{url,color}, decoration:{url,color} } }
 */

const DARKEST_06 = 0.5; // ddnet DARKEST_LGT — 0.6 tee lightness floor
const DARKEST_07 = 61 / 255; // teeworlds DARKEST_COLOR_LGT — 0.7 tee lightness floor
const BODY_NEW_WEIGHT = 192; // skins.cpp body grayscale renormalize target

// standing tee: body centered at origin, feet at the idle keyframes. The gaze direction drives the
// eye offset (RenderTee*): point it up-left so the eyes sit high toward the top-left like the
// in-game default sprite, instead of centered.
const FOOT = { back: { x: -10, y: 14 }, front: { x: 10, y: 14 } };
const DIR = { x: -0.6, y: -0.5 };

// a sub-rect [sx, sy, sw, sh] within a skin sheet
type Rect = [number, number, number, number];

interface Rgba {
    r: number;
    g: number;
    b: number;
    a: number;
}

type PartName = 'body' | 'marking' | 'feet' | 'eyes' | 'decoration';

interface TeePart {
    url: string;
    color: number | null;
}

interface Tee06Descriptor {
    mode: '06';
    url: string;
    external?: boolean;
    fallbackUrl?: string;
    colorBody?: number | null;
    colorFeet?: number | null;
}

interface Tee07Descriptor {
    mode: '07';
    parts: Partial<Record<PartName, TeePart>>;
}

type TeeDescriptor = Tee06Descriptor | Tee07Descriptor;

// the 2d context is always available for a freshly created canvas; bail loudly if a browser ever disagrees
function context2d(canvas: HTMLCanvasElement): CanvasRenderingContext2D {
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        throw new Error('2D canvas context unavailable');
    }
    return ctx;
}

// --- color ----------------------------------------------------------------------------------------

// ddnet color.h color_cast(ColorHSLA): h,s,l in 0..1 -> {r,g,b} in 0..1
function hslToRgb(h: number, s: number, l: number): Rgba {
    const h1 = h * 6;
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs((h1 % 2) - 1));
    let r = 0,
        g = 0,
        b = 0;
    switch (Math.trunc(h1) % 6) {
        case 0:
            r = c;
            g = x;
            break;
        case 1:
            r = x;
            g = c;
            break;
        case 2:
            g = c;
            b = x;
            break;
        case 3:
            g = x;
            b = c;
            break;
        case 4:
            r = x;
            b = c;
            break;
        default:
            r = c;
            b = x;
            break;
    }
    const m = l - c / 2;
    return { r: r + m, g: g + m, b: b + m, a: 1 };
}

// decode a packed HSL(A) color int with the given lightness floor; alpha only when useAlpha
function decodeColor(code: number, darkest: number, useAlpha: boolean): Rgba {
    const h = ((code >> 16) & 0xff) / 255;
    const s = ((code >> 8) & 0xff) / 255;
    const l = darkest + ((code & 0xff) / 255) * (1 - darkest);
    const rgb = hslToRgb(h, s, l);
    rgb.a = useAlpha ? ((code >>> 24) & 0xff) / 255 : 1;
    return rgb;
}

// --- pixel ops ------------------------------------------------------------------------------------

// draw a source-image sub-rect to a fresh sw x sh offscreen canvas
function cut(img: CanvasImageSource, sx: number, sy: number, sw: number, sh: number): HTMLCanvasElement {
    const off = document.createElement('canvas');
    off.width = sw;
    off.height = sh;
    context2d(off).drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);
    return off;
}

// Rec.709 luma grayscale, in place
function grayscale(data: ImageData): void {
    const p = data.data;
    for (let i = 0; i < p.length; i += 4) {
        const v = (0.2126 * p[i]! + 0.7152 * p[i + 1]! + 0.0722 * p[i + 2]!) | 0;
        p[i] = p[i + 1] = p[i + 2] = v;
    }
}

// skins.cpp body renormalize: stretch the gray range around the most common opaque value -> 192
function renormalizeBody(data: ImageData): void {
    const p = data.data;
    const freq = new Array<number>(256).fill(0);
    for (let i = 0; i < p.length; i += 4) {
        if (p[i + 3]! > 128) freq[p[i]!]!++;
    }
    let org = 1;
    for (let i = 1; i < 256; i++) {
        if (freq[org]! < freq[i]!) org = i;
    }
    for (let i = 0; i < p.length; i += 4) {
        const v = p[i]!;
        const nv =
            v <= org
                ? (v / org) * BODY_NEW_WEIGHT
                : ((v - org) / (255 - org)) * (255 - BODY_NEW_WEIGHT) + BODY_NEW_WEIGHT;
        p[i] = p[i + 1] = p[i + 2] = nv | 0;
    }
}

// multiply rgb channels by a tint, in place
function tint(data: ImageData, rgb: Rgba): void {
    const p = data.data;
    for (let i = 0; i < p.length; i += 4) {
        p[i] = p[i]! * rgb.r;
        p[i + 1] = p[i + 1]! * rgb.g;
        p[i + 2] = p[i + 2]! * rgb.b;
    }
}

interface PartOptions {
    gray?: boolean;
    renorm?: boolean;
}

/**
 * Produce a drawable part: a sub-rect of img, optionally grayscaled/renormalized/tinted.
 * @param rgb tint to multiply by, or null to keep original colors
 */
function part(
    img: CanvasImageSource,
    sx: number,
    sy: number,
    sw: number,
    sh: number,
    rgb: Rgba | null,
    { gray = false, renorm = false }: PartOptions = {},
): HTMLCanvasElement {
    const off = cut(img, sx, sy, sw, sh);
    if (!rgb && !gray) return off;

    const ctx = context2d(off);
    const data = ctx.getImageData(0, 0, sw, sh);
    if (gray) grayscale(data);
    if (renorm) renormalizeBody(data);
    if (rgb) tint(data, rgb);
    ctx.putImageData(data, 0, 0);
    return off;
}

// --- compositor -----------------------------------------------------------------------------------

// draw a part canvas centered at (x,y) with tee-unit width/height, optionally flipped in x
type DrawFn = (partCanvas: CanvasImageSource, x: number, y: number, w: number, h: number, flip?: boolean) => void;

// a drawing context in tee-unit space (64 = BaseSize), centered with vertical centering of the tee
function makeStage(canvas: HTMLCanvasElement): DrawFn {
    const dpr = window.devicePixelRatio || 1;
    const size = canvas.clientWidth || parseInt(canvas.getAttribute('width') ?? '', 10) || 96;
    canvas.width = size * dpr;
    canvas.height = size * dpr;
    const ctx = context2d(canvas);
    ctx.imageSmoothingQuality = 'high';

    // the tee spans roughly x[-40,40], y[-32,26]; scale to fit with a little margin, center on y mid (-3)
    const scale = (size * dpr) / 84;
    ctx.setTransform(scale, 0, 0, scale, (size * dpr) / 2, (size * dpr) / 2 + 3 * scale);

    return (partCanvas, x, y, w, h, flip = false) => {
        ctx.save();
        ctx.translate(x, y);
        if (flip) ctx.scale(-1, 1);
        ctx.drawImage(partCanvas, -w / 2, -h / 2, w, h);
        ctx.restore();
    };
}

function loadImage(url: string, crossOrigin = false): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        // CORS-clean load so getImageData (the colored path) works on DDNet-DB skins; the DB sends
        // Access-Control-Allow-Origin: *
        if (crossOrigin) img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = url;
    });
}

// Load the skin sheet following the client's local > fetch > default order: try the descriptor's
// url (a local asset, or a DDNet-DB fetch when external), and on failure fall back to the bundled
// default tee. Returns the loaded image.
async function loadSkinSheet(d: Tee06Descriptor): Promise<HTMLImageElement> {
    try {
        return await loadImage(d.url, !!d.external);
    } catch (e) {
        if (!d.fallbackUrl) throw e;
        return loadImage(d.fallbackUrl);
    }
}

// --- 0.6 single-sheet skin ------------------------------------------------------------------------

// rects in the canonical 256x128 sheet; scaled to the actual sheet below so HD skins (512x256,
// etc. — common in the DDNet DB) render from the same layout
const SHEET: Record<string, Rect> = {
    body: [0, 0, 96, 96],
    bodyOutline: [96, 0, 96, 96],
    foot: [192, 32, 64, 32],
    footOutline: [192, 64, 64, 32],
    eye: [64, 96, 32, 32],
};
const SHEET_W = 256;
const SHEET_H = 128;

async function render06(canvas: HTMLCanvasElement, d: Tee06Descriptor): Promise<void> {
    const img = await loadSkinSheet(d);
    const draw = makeStage(canvas);
    const colored = d.colorBody !== null && d.colorBody !== undefined;
    const bodyRgb = colored ? decodeColor(d.colorBody!, DARKEST_06, false) : null;
    const feetRgb = colored ? decodeColor(d.colorFeet!, DARKEST_06, false) : null;

    // scale the canonical sheet rects to the real sheet size
    const fx = img.width / SHEET_W;
    const fy = img.height / SHEET_H;
    const p = (rect: Rect, rgb: Rgba | null, opts?: PartOptions) =>
        part(img, rect[0] * fx, rect[1] * fy, rect[2] * fx, rect[3] * fy, rgb, opts);
    const body = p(SHEET.body!, bodyRgb, { gray: colored, renorm: colored });
    const bodyOutline = p(SHEET.bodyOutline!, bodyRgb, { gray: colored });
    const foot = p(SHEET.foot!, feetRgb, { gray: colored });
    const footOutline = p(SHEET.footOutline!, feetRgb, { gray: colored });
    const eye = p(SHEET.eye!, bodyRgb, { gray: colored });

    // eye placement (RenderTee6): two mirrored eyes near the upper body
    const eyeScale = 64 * 0.4;
    const sep = (0.075 - 0.01 * Math.abs(DIR.x)) * 64;
    const ox = DIR.x * 0.125 * 64;
    const oy = (-0.05 + DIR.y * 0.1) * 64;

    // z-order: foot outlines, body outline, then fills, eyes, front foot
    draw(footOutline, FOOT.back.x, FOOT.back.y, 64, 32);
    draw(bodyOutline, 0, 0, 64, 64);
    draw(footOutline, FOOT.front.x, FOOT.front.y, 64, 32);
    draw(foot, FOOT.back.x, FOOT.back.y, 64, 32);
    draw(body, 0, 0, 64, 64);
    draw(eye, -sep + ox, oy, eyeScale, eyeScale);
    draw(eye, sep + ox, oy, eyeScale, eyeScale, true);
    draw(foot, FOOT.front.x, FOOT.front.y, 64, 32);
}

// --- 0.7 six-part skin ----------------------------------------------------------------------------

// cells within each part sheet: [sx, sy, sw, sh]
const CELL: Record<string, Rect> = {
    bodyOutline: [0, 0, 128, 128],
    body: [128, 0, 128, 128],
    bodyShadow: [0, 128, 128, 128],
    bodyUpperOutline: [128, 128, 128, 128],
    decoration: [0, 0, 128, 128],
    decorationOutline: [128, 0, 128, 128],
    marking: [0, 0, 128, 128],
    foot: [0, 0, 64, 64],
    footOutline: [64, 0, 64, 64],
    eyeNormal: [0, 0, 64, 32],
};

async function render07(canvas: HTMLCanvasElement, d: Tee07Descriptor): Promise<void> {
    const draw = makeStage(canvas);
    const has = (name: PartName) => Boolean(d.parts[name]?.url);
    const urls: Partial<Record<PartName, HTMLImageElement>> = {};
    for (const name of ['body', 'marking', 'decoration', 'feet', 'eyes'] as PartName[]) {
        if (has(name)) urls[name] = await loadImage(d.parts[name]!.url);
    }

    const colorOf = (name: PartName, useAlpha: boolean): Rgba | null => {
        const c = d.parts[name]?.color;
        return c === null || c === undefined ? null : decodeColor(c, DARKEST_07, useAlpha);
    };
    const pp = (img: CanvasImageSource, cell: Rect, rgb: Rgba | null) => part(img, cell[0], cell[1], cell[2], cell[3], rgb);

    const feetRgb = colorOf('feet', false);
    const drawFoot = (outline: boolean, pos: { x: number; y: number }) => {
        if (!urls.feet) return;
        const cell = outline ? CELL.footOutline! : CELL.foot!;
        draw(pp(urls.feet, cell, outline ? null : feetRgb), pos.x, pos.y, 64 / 2.1, 64 / 2.1);
    };

    // outline pass: feet + decoration + body outlines (white, drawn as-is)
    drawFoot(true, FOOT.back);
    if (urls.decoration) draw(pp(urls.decoration, CELL.decorationOutline!, colorOf('decoration', false)), 0, 0, 64, 64);
    draw(pp(urls.body!, CELL.bodyOutline!, null), 0, 0, 64, 64);
    drawFoot(true, FOOT.front);

    // fill pass: back foot, decoration, body, marking, shading overlays, eyes, front foot
    drawFoot(false, FOOT.back);
    if (urls.decoration) draw(pp(urls.decoration, CELL.decoration!, colorOf('decoration', false)), 0, 0, 64, 64);
    draw(pp(urls.body!, CELL.body!, colorOf('body', false)), 0, 0, 64, 64);
    if (urls.marking) {
        const m = colorOf('marking', true);
        if (m) {
            m.r *= m.a;
            m.g *= m.a;
            m.b *= m.a;
        } // teeworlds premultiplies marking
        draw(pp(urls.marking, CELL.marking!, m), 0, 0, 64, 64);
    }
    draw(pp(urls.body!, CELL.bodyShadow!, null), 0, 0, 64, 64);
    draw(pp(urls.body!, CELL.bodyUpperOutline!, null), 0, 0, 64, 64);
    if (urls.eyes) {
        const oy = (-0.05 + DIR.y * 0.1) * 64;
        const ox = DIR.x * 0.125 * 64;
        draw(pp(urls.eyes, CELL.eyeNormal!, colorOf('eyes', false)), ox, oy, 64 * 0.6, 64 * 0.3);
    }
    drawFoot(false, FOOT.front);
}

// --- bootstrap ------------------------------------------------------------------------------------

// A tee is composed once per distinct skin descriptor to a cached offscreen canvas, then blitted to
// each target. Caching the *result* (not a "rendered" flag) is what makes this robust: a target can
// be drawn any number of times — so the server browser's popover, which clones fresh canvases on
// every hover and may churn faster than the skin images load, always ends up with a real tee instead
// of a permanently-blank flagged canvas. It also means thousands of identical "default" tees cost one
// compose. COMPOSE_SIZE is the offscreen resolution; targets downscale from it.
const COMPOSE_SIZE = 96;
const teeCache = new Map<string, Promise<HTMLCanvasElement>>(); // descriptor JSON -> composed canvas

function composeTee(descriptor: TeeDescriptor): Promise<HTMLCanvasElement> {
    const key = JSON.stringify(descriptor);
    let cached = teeCache.get(key);
    if (!cached) {
        const off = document.createElement('canvas');
        off.setAttribute('width', String(COMPOSE_SIZE));
        off.setAttribute('height', String(COMPOSE_SIZE));
        // resolve to the offscreen whether the skin loads or not (a failed load leaves it blank)
        const render = descriptor.mode === '07' ? render07(off, descriptor) : render06(off, descriptor);
        cached = render.then(
            () => off,
            () => off,
        );
        teeCache.set(key, cached);
    }
    return cached;
}

export function renderTee(canvas: HTMLCanvasElement): void {
    const attr = canvas.getAttribute('data-tee');
    if (!attr) return;
    let d: TeeDescriptor;
    try {
        d = JSON.parse(attr);
    } catch {
        return;
    }
    if (!d || !d.mode) return;
    composeTee(d).then((composed) => {
        const dpr = window.devicePixelRatio || 1;
        const size = canvas.clientWidth || parseInt(canvas.getAttribute('width') ?? '', 10) || 24;
        canvas.width = Math.round(size * dpr);
        canvas.height = Math.round(size * dpr);
        const ctx = context2d(canvas);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(composed, 0, 0, canvas.width, canvas.height);
    });
}

// Render every tee in root. By default only visible canvases are drawn, so the page-load pass skips
// the server browser's thousands of hidden roster tees (those are drawn into the popover clone when
// it opens — pass {onlyVisible:false} for that). offsetParent is null for display:none elements.
export function renderAllTees(root: ParentNode = document, { onlyVisible = true }: { onlyVisible?: boolean } = {}): void {
    root.querySelectorAll<HTMLCanvasElement>('canvas[data-tee]').forEach((canvas) => {
        if (onlyVisible && canvas.offsetParent === null) return;
        renderTee(canvas);
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => renderAllTees());
    } else {
        renderAllTees();
    }
}
