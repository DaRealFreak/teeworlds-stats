/**
 * Minimal WebGL1 renderer for the map viewer.
 *
 * Vertex format: interleaved x,y,u,v,r,g,b,a (8 floats / 32 bytes per vertex).
 * The caller supplies pre-built Float32Arrays and a world→clip transform; this
 * class owns only the GL objects (program, buffer, textures).
 */

const VERTEX_GLSL = `
attribute vec2 aPos; attribute vec2 aUV; attribute vec4 aColor;
uniform vec4 uTransform; varying vec2 vUV; varying vec4 vColor;
void main() {
    vUV = aUV; vColor = aColor;
    gl_Position = vec4(aPos.x * uTransform.x + uTransform.z, aPos.y * uTransform.y + uTransform.w, 0.0, 1.0);
}
`.trim();

const FRAGMENT_GLSL = `
precision mediump float;
uniform sampler2D uTex; uniform vec4 uColorMul; varying vec2 vUV; varying vec4 vColor;
void main() {
    vec4 c = texture2D(uTex, vUV) * vColor * uColorMul;
    if (c.a < 0.01) discard;
    gl_FragColor = c;
}
`.trim();

/** Stride in bytes: 8 floats × 4 bytes each. */
const STRIDE = 32;

/** Floats per vertex. */
const FLOATS_PER_VERTEX = 8;

function compileShader(gl: WebGLRenderingContext, type: number, source: string): WebGLShader {
    const shader = gl.createShader(type);
    if (!shader) throw new Error('GlRenderer: gl.createShader returned null');
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        const log = gl.getShaderInfoLog(shader) ?? '(no log)';
        gl.deleteShader(shader);
        const typeName = type === gl.VERTEX_SHADER ? 'vertex' : 'fragment';
        throw new Error(`GlRenderer: ${typeName} shader compile error:\n${log}`);
    }
    return shader;
}

export class GlRenderer {
    private readonly program: WebGLProgram;
    private readonly buffer: WebGLBuffer;
    private readonly whiteTexture: WebGLTexture;

    // attribute locations
    private readonly aPos: number;
    private readonly aUV: number;
    private readonly aColor: number;

    // uniform locations
    private readonly uTransform: WebGLUniformLocation;
    private readonly uTex: WebGLUniformLocation;
    private readonly uColorMul: WebGLUniformLocation;

    constructor(private readonly gl: WebGLRenderingContext) {
        // --- compile shaders & link program ---
        const vert = compileShader(gl, gl.VERTEX_SHADER, VERTEX_GLSL);
        const frag = compileShader(gl, gl.FRAGMENT_SHADER, FRAGMENT_GLSL);

        const program = gl.createProgram();
        if (!program) throw new Error('GlRenderer: gl.createProgram returned null');
        gl.attachShader(program, vert);
        gl.attachShader(program, frag);
        gl.linkProgram(program);

        // shaders are fully compiled into the program; individual objects no longer needed
        gl.deleteShader(vert);
        gl.deleteShader(frag);

        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            const log = gl.getProgramInfoLog(program) ?? '(no log)';
            gl.deleteProgram(program);
            throw new Error(`GlRenderer: program link error:\n${log}`);
        }

        this.program = program;

        // --- attribute / uniform locations ---
        this.aPos = gl.getAttribLocation(program, 'aPos');
        this.aUV = gl.getAttribLocation(program, 'aUV');
        this.aColor = gl.getAttribLocation(program, 'aColor');

        const uTransform = gl.getUniformLocation(program, 'uTransform');
        if (!uTransform) throw new Error('GlRenderer: uTransform uniform not found');
        this.uTransform = uTransform;

        const uTex = gl.getUniformLocation(program, 'uTex');
        if (!uTex) throw new Error('GlRenderer: uTex uniform not found');
        this.uTex = uTex;

        const uColorMul = gl.getUniformLocation(program, 'uColorMul');
        if (!uColorMul) throw new Error('GlRenderer: uColorMul uniform not found');
        this.uColorMul = uColorMul;

        // --- shared vertex buffer ---
        const buffer = gl.createBuffer();
        if (!buffer) throw new Error('GlRenderer: gl.createBuffer returned null');
        this.buffer = buffer;

        // --- blending (premultiplied alpha is not used; standard src-alpha blend) ---
        gl.enable(gl.BLEND);
        gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

        // --- 1×1 white fallback texture (used when draw() receives null) ---
        this.whiteTexture = this.createTexture(
            (() => {
                // Build a 1×1 RGBA ImageData to use as a TexImageSource
                const data = new Uint8ClampedArray([255, 255, 255, 255]);
                return new ImageData(data, 1, 1);
            })(),
        );
    }

    /**
     * Upload a TexImageSource as a WebGL texture.
     * Uses NEAREST filter and CLAMP_TO_EDGE wrap so non-power-of-two images
     * (e.g. external tileset images) are valid in WebGL1.
     */
    createTexture(source: TexImageSource): WebGLTexture {
        const gl = this.gl;
        const tex = gl.createTexture();
        if (!tex) throw new Error('GlRenderer: gl.createTexture returned null');
        gl.bindTexture(gl.TEXTURE_2D, tex);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, source);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
        gl.bindTexture(gl.TEXTURE_2D, null);
        return tex;
    }

    /** Resize the canvas drawing-buffer and update the GL viewport. */
    resize(width: number, height: number): void {
        const canvas = this.gl.canvas;
        canvas.width = width;
        canvas.height = height;
        this.gl.viewport(0, 0, width, height);
    }

    /** Clear the framebuffer to `color` (default: the dark viewer background). */
    clear(color: [number, number, number] = [0.07, 0.07, 0.09]): void {
        this.gl.clearColor(color[0], color[1], color[2], 1.0);
        this.gl.clear(this.gl.COLOR_BUFFER_BIT);
    }

    /**
     * Draw a batch of pre-built vertices.
     *
     * @param texture  WebGLTexture to bind, or null to use the 1×1 white fallback.
     * @param vertices Interleaved x,y,u,v,r,g,b,a float data.
     * @param transform [scaleX, scaleY, offX, offY] world→clip mapping.
     * @param colorMul  RGBA multiplied over every fragment (default opaque white).
     *                  Used to fade layers in/out for the entities overlay.
     */
    draw(
        texture: WebGLTexture | null,
        vertices: Float32Array,
        transform: [number, number, number, number],
        colorMul: [number, number, number, number] = [1, 1, 1, 1],
    ): void {
        if (vertices.length === 0) return;

        const gl = this.gl;

        gl.useProgram(this.program);

        // upload vertex data
        gl.bindBuffer(gl.ARRAY_BUFFER, this.buffer);
        gl.bufferData(gl.ARRAY_BUFFER, vertices, gl.DYNAMIC_DRAW);

        // aPos: vec2 @ offset 0
        gl.enableVertexAttribArray(this.aPos);
        gl.vertexAttribPointer(this.aPos, 2, gl.FLOAT, false, STRIDE, 0);

        // aUV: vec2 @ offset 8
        gl.enableVertexAttribArray(this.aUV);
        gl.vertexAttribPointer(this.aUV, 2, gl.FLOAT, false, STRIDE, 8);

        // aColor: vec4 @ offset 16
        gl.enableVertexAttribArray(this.aColor);
        gl.vertexAttribPointer(this.aColor, 4, gl.FLOAT, false, STRIDE, 16);

        // bind texture to unit 0
        gl.activeTexture(gl.TEXTURE0);
        gl.bindTexture(gl.TEXTURE_2D, texture ?? this.whiteTexture);
        gl.uniform1i(this.uTex, 0);

        // world→clip transform
        gl.uniform4f(this.uTransform, transform[0], transform[1], transform[2], transform[3]);

        // per-draw color multiplier (entities overlay fade)
        gl.uniform4f(this.uColorMul, colorMul[0], colorMul[1], colorMul[2], colorMul[3]);

        const vertexCount = (vertices.length / FLOATS_PER_VERTEX) | 0;
        gl.drawArrays(gl.TRIANGLES, 0, vertexCount);
    }

    /** Release all GL objects owned by this renderer. */
    dispose(): void {
        const gl = this.gl;
        gl.deleteProgram(this.program);
        gl.deleteBuffer(this.buffer);
        gl.deleteTexture(this.whiteTexture);
    }
}
