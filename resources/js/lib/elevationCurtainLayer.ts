import {
    MercatorCoordinate,
    type CustomLayerInterface,
    type CustomRenderMethodInput,
    type Map as MapLibreMap,
} from 'maplibre-gl';

export interface ElevationCurtainPoint {
    seriesIndex: number;
    lng: number;
    lat: number;
    heightMeters: number;
    color: string;
    dimmed?: boolean;
    highlighted?: boolean;
}

export interface ElevationCurtainMarker {
    lng: number;
    lat: number;
    heightMeters: number;
    fillColor: string;
    strokeColor: string;
    radiusPx: number;
    strokeWidthPx: number;
    showPlumbLine?: boolean;
    plumbColor?: string;
}

export interface ElevationCurtainArrow {
    lng: number;
    lat: number;
    heightMeters: number;
    aheadLng: number;
    aheadLat: number;
    aheadHeightMeters: number;
    fillColor: string;
    strokeColor: string;
    sizePx: number;
    strokeWidthPx: number;
    alpha?: number;
}

export interface ElevationCurtainData {
    points: ElevationCurtainPoint[];
    markers?: ElevationCurtainMarker[];
    arrows?: ElevationCurtainArrow[];
    hoverMarker?: ElevationCurtainMarker | null;
    isDark: boolean;
}

interface ProjectedCurtainPoint {
    seriesIndex: number;
    dx: number;
    dy: number;
    dz: number;
    rgb: [number, number, number];
    dimmed: boolean;
    highlighted: boolean;
}

const CURTAIN_VERTEX_SHADER = `#version 300 es
precision highp float;

uniform mat4 u_matrix;

in vec3 a_pos;
in vec3 a_color;
in float a_heightRatio;
in float a_alphaScale;

out vec3 v_color;
out float v_heightRatio;
out float v_alphaScale;

void main() {
    gl_Position = u_matrix * vec4(a_pos, 1.0);
    v_color = a_color;
    v_heightRatio = a_heightRatio;
    v_alphaScale = a_alphaScale;
}
`;

const CURTAIN_FRAGMENT_SHADER = `#version 300 es
precision mediump float;

uniform float u_bottomAlpha;
uniform float u_topAlpha;

in vec3 v_color;
in float v_heightRatio;
in float v_alphaScale;

out vec4 fragColor;

void main() {
    float gradient = mix(u_bottomAlpha, u_topAlpha, pow(clamp(v_heightRatio, 0.0, 1.0), 0.85));
    float alpha = clamp(gradient * v_alphaScale, 0.0, 0.78);
    fragColor = vec4(v_color * alpha, alpha);
}
`;

const RIBBON_VERTEX_SHADER = `#version 300 es
precision highp float;

uniform mat4 u_matrix;
uniform vec2 u_viewport;

in vec3 a_pos;
in vec3 a_other;
in float a_dir;
in float a_side;
in float a_halfWidth;
in vec3 a_color;
in float a_alpha;

out vec3 v_color;
out float v_alpha;
out float v_edge;

void main() {
    vec4 clipPos = u_matrix * vec4(a_pos, 1.0);
    vec4 clipOther = u_matrix * vec4(a_other, 1.0);

    float wPos = max(abs(clipPos.w), 0.0001);
    float wOther = max(abs(clipOther.w), 0.0001);

    vec2 screenPos = (clipPos.xy / wPos) * 0.5 * u_viewport;
    vec2 screenOther = (clipOther.xy / wOther) * 0.5 * u_viewport;

    vec2 segDir = (screenOther - screenPos) * a_dir;
    float segLen = length(segDir);
    vec2 tangent = segLen > 0.001 ? segDir / segLen : vec2(1.0, 0.0);
    vec2 normal = vec2(-tangent.y, tangent.x);

    vec2 pxOffset = normal * (a_side * a_halfWidth) - tangent * (a_dir * a_halfWidth * 0.35);
    vec2 ndcOffset = (pxOffset * 2.0) / max(u_viewport, vec2(1.0));

    gl_Position = vec4(clipPos.xy + ndcOffset * clipPos.w, clipPos.z - 0.00002 * clipPos.w, clipPos.w);
    v_color = a_color;
    v_alpha = a_alpha;
    v_edge = a_side;
}
`;

const RIBBON_FRAGMENT_SHADER = `#version 300 es
precision mediump float;

in vec3 v_color;
in float v_alpha;
in float v_edge;

out vec4 fragColor;

void main() {
    float edge = abs(v_edge);
    float aa = 1.0 - smoothstep(0.62, 1.0, edge);
    float alpha = clamp(v_alpha * aa, 0.0, 1.0);

    if (alpha < 0.005) {
        discard;
    }

    fragColor = vec4(v_color * alpha, alpha);
}
`;

const DOT_VERTEX_SHADER = `#version 300 es
precision highp float;

uniform mat4 u_matrix;
uniform vec2 u_viewport;

in vec3 a_pos;
in vec2 a_corner;
in float a_radius;
in float a_stroke;
in vec3 a_fillColor;
in vec3 a_strokeColor;

out vec2 v_corner;
out float v_radius;
out float v_stroke;
out float v_totalRadius;
out vec3 v_fillColor;
out vec3 v_strokeColor;

void main() {
    vec4 clipPos = u_matrix * vec4(a_pos, 1.0);
    float totalRadius = a_radius + a_stroke + 1.0;
    vec2 ndcOffset = (a_corner * totalRadius * 2.0) / max(u_viewport, vec2(1.0));

    gl_Position = vec4(clipPos.xy + ndcOffset * clipPos.w, clipPos.z - 0.00005 * clipPos.w, clipPos.w);
    v_corner = a_corner;
    v_radius = a_radius;
    v_stroke = a_stroke;
    v_totalRadius = totalRadius;
    v_fillColor = a_fillColor;
    v_strokeColor = a_strokeColor;
}
`;

const DOT_FRAGMENT_SHADER = `#version 300 es
precision mediump float;

in vec2 v_corner;
in float v_radius;
in float v_stroke;
in float v_totalRadius;
in vec3 v_fillColor;
in vec3 v_strokeColor;

out vec4 fragColor;

void main() {
    float distPx = length(v_corner) * v_totalRadius;
    float outerRadius = v_radius + v_stroke;

    float outerAlpha = 1.0 - smoothstep(outerRadius - 0.75, outerRadius + 0.5, distPx);

    if (outerAlpha < 0.005) {
        discard;
    }

    float strokeMix = smoothstep(v_radius - 0.75, v_radius + 0.35, distPx);
    vec3 color = mix(v_fillColor, v_strokeColor, strokeMix);

    fragColor = vec4(color * outerAlpha, outerAlpha);
}
`;

const ARROW_VERTEX_SHADER = `#version 300 es
precision highp float;

uniform mat4 u_matrix;
uniform vec2 u_viewport;

in vec3 a_pos;
in vec3 a_ahead;
in vec2 a_corner;
in float a_size;
in float a_stroke;
in vec3 a_fillColor;
in vec3 a_strokeColor;
in float a_alpha;

out vec2 v_localPx;
out float v_size;
out float v_stroke;
out vec3 v_fillColor;
out vec3 v_strokeColor;
out float v_alpha;

void main() {
    vec4 clipPos = u_matrix * vec4(a_pos, 1.0);
    vec4 clipAhead = u_matrix * vec4(a_ahead, 1.0);

    float wPos = max(abs(clipPos.w), 0.0001);
    float wAhead = max(abs(clipAhead.w), 0.0001);

    vec2 screenPos = (clipPos.xy / wPos) * 0.5 * u_viewport;
    vec2 screenAhead = (clipAhead.xy / wAhead) * 0.5 * u_viewport;

    vec2 segDir = screenAhead - screenPos;
    float segLen = length(segDir);
    vec2 tangent = segLen > 0.001 ? segDir / segLen : vec2(1.0, 0.0);
    vec2 normal = vec2(-tangent.y, tangent.x);

    float extent = a_size + a_stroke + 2.0;
    vec2 localPx = a_corner * extent;
    vec2 pxOffset = tangent * localPx.x + normal * localPx.y;
    vec2 ndcOffset = (pxOffset * 2.0) / max(u_viewport, vec2(1.0));

    gl_Position = vec4(clipPos.xy + ndcOffset * clipPos.w, clipPos.z - 0.00004 * clipPos.w, clipPos.w);
    v_localPx = localPx;
    v_size = a_size;
    v_stroke = a_stroke;
    v_fillColor = a_fillColor;
    v_strokeColor = a_strokeColor;
    v_alpha = a_alpha;
}
`;

const ARROW_FRAGMENT_SHADER = `#version 300 es
precision mediump float;

in vec2 v_localPx;
in float v_size;
in float v_stroke;
in vec3 v_fillColor;
in vec3 v_strokeColor;
in float v_alpha;

out vec4 fragColor;

float distToSegment(vec2 p, vec2 a, vec2 b) {
    vec2 pa = p - a;
    vec2 ba = b - a;
    float h = clamp(dot(pa, ba) / max(dot(ba, ba), 0.0001), 0.0, 1.0);
    return length(pa - ba * h);
}

void main() {
    vec2 p = vec2(v_localPx.x, abs(v_localPx.y));
    vec2 A = vec2(-0.72, 0.96) * v_size;
    vec2 B = vec2(0.92, 0.0) * v_size;
    vec2 C = vec2(-0.22, 0.0) * v_size;

    float uDist = min(distToSegment(p, A, B), distToSegment(p, A, C));
    vec2 v1 = B - A;
    vec2 v2 = C - A;
    vec2 pa = p - A;
    float c1 = v1.x * pa.y - v1.y * pa.x;
    float c2 = v2.x * pa.y - v2.y * pa.x;
    bool inside = (c1 <= 0.0) && (c2 >= 0.0);
    float signedDist = inside ? -uDist : uDist;

    float outerAlpha = 1.0 - smoothstep(v_stroke - 0.65, v_stroke + 0.55, signedDist);

    if (outerAlpha < 0.005) {
        discard;
    }

    float strokeMix = smoothstep(-0.55, 0.45, signedDist);
    vec3 color = mix(v_fillColor, v_strokeColor, strokeMix);
    float finalAlpha = outerAlpha * v_alpha;

    fragColor = vec4(color * finalAlpha, finalAlpha);
}
`;

function parseCssColorToRgb(color: string): [number, number, number] {
    const trimmed = color.trim();

    if (trimmed.startsWith('#')) {
        const hex = trimmed.slice(1);

        if (hex.length === 3) {
            const r = parseInt(hex[0] + hex[0], 16) / 255;
            const g = parseInt(hex[1] + hex[1], 16) / 255;
            const b = parseInt(hex[2] + hex[2], 16) / 255;

            if (
                Number.isFinite(r) &&
                Number.isFinite(g) &&
                Number.isFinite(b)
            ) {
                return [r, g, b];
            }
        }

        if (hex.length >= 6) {
            const r = parseInt(hex.slice(0, 2), 16) / 255;
            const g = parseInt(hex.slice(2, 4), 16) / 255;
            const b = parseInt(hex.slice(4, 6), 16) / 255;

            if (
                Number.isFinite(r) &&
                Number.isFinite(g) &&
                Number.isFinite(b)
            ) {
                return [r, g, b];
            }
        }
    }

    const rgbMatch = /^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/i.exec(
        trimmed,
    );

    if (rgbMatch) {
        return [
            Math.max(0, Math.min(1, Number(rgbMatch[1]) / 255)),
            Math.max(0, Math.min(1, Number(rgbMatch[2]) / 255)),
            Math.max(0, Math.min(1, Number(rgbMatch[3]) / 255)),
        ];
    }

    return [0.96, 0.62, 0.04];
}

function compileShader(
    gl: WebGL2RenderingContext,
    type: number,
    source: string,
): WebGLShader | null {
    const shader = gl.createShader(type);

    if (!shader) {
        return null;
    }

    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        console.warn(
            '[ElevationCurtainLayer] Shader compile failed:',
            gl.getShaderInfoLog(shader),
        );
        gl.deleteShader(shader);

        return null;
    }

    return shader;
}

function createProgram(
    gl: WebGL2RenderingContext,
    vertexSource: string,
    fragmentSource: string,
): WebGLProgram | null {
    const vert = compileShader(gl, gl.VERTEX_SHADER, vertexSource);
    const frag = compileShader(gl, gl.FRAGMENT_SHADER, fragmentSource);

    if (!vert || !frag) {
        if (vert) {
            gl.deleteShader(vert);
        }

        if (frag) {
            gl.deleteShader(frag);
        }

        return null;
    }

    const program = gl.createProgram();

    if (!program) {
        gl.deleteShader(vert);
        gl.deleteShader(frag);

        return null;
    }

    gl.attachShader(program, vert);
    gl.attachShader(program, frag);
    gl.linkProgram(program);

    gl.deleteShader(vert);
    gl.deleteShader(frag);

    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
        console.warn(
            '[ElevationCurtainLayer] Program link failed:',
            gl.getProgramInfoLog(program),
        );
        gl.deleteProgram(program);

        return null;
    }

    return program;
}

function pointToSegmentDistSq(
    px: number,
    py: number,
    ax: number,
    ay: number,
    bx: number,
    by: number,
): number {
    const dx = bx - ax;
    const dy = by - ay;
    const lenSq = dx * dx + dy * dy;

    if (lenSq <= 1e-6) {
        const ex = px - ax;
        const ey = py - ay;

        return ex * ex + ey * ey;
    }

    const t = Math.max(
        0,
        Math.min(1, ((px - ax) * dx + (py - ay) * dy) / lenSq),
    );
    const projX = ax + t * dx;
    const projY = ay + t * dy;
    const ex = px - projX;
    const ey = py - projY;

    return ex * ex + ey * ey;
}

function pushRibbonSegment(
    out: number[],
    ax: number,
    ay: number,
    az: number,
    bx: number,
    by: number,
    bz: number,
    halfWidth: number,
    r: number,
    g: number,
    b: number,
    alpha: number,
): void {
    // Triangle 1: A-, A+, B-
    out.push(ax, ay, az, bx, by, bz, 1, -1, halfWidth, r, g, b, alpha);
    out.push(ax, ay, az, bx, by, bz, 1, 1, halfWidth, r, g, b, alpha);
    out.push(bx, by, bz, ax, ay, az, -1, -1, halfWidth, r, g, b, alpha);

    // Triangle 2: A+, B+, B-
    out.push(ax, ay, az, bx, by, bz, 1, 1, halfWidth, r, g, b, alpha);
    out.push(bx, by, bz, ax, ay, az, -1, 1, halfWidth, r, g, b, alpha);
    out.push(bx, by, bz, ax, ay, az, -1, -1, halfWidth, r, g, b, alpha);
}

function pushDotBillboard(
    out: number[],
    x: number,
    y: number,
    z: number,
    radiusPx: number,
    strokePx: number,
    fillRgb: [number, number, number],
    strokeRgb: [number, number, number],
): void {
    const [fr, fg, fb] = fillRgb;
    const [sr, sg, sb] = strokeRgb;

    // 2 triangles forming a [-1, 1] x [-1, 1] quad
    out.push(x, y, z, -1, -1, radiusPx, strokePx, fr, fg, fb, sr, sg, sb);
    out.push(x, y, z, 1, -1, radiusPx, strokePx, fr, fg, fb, sr, sg, sb);
    out.push(x, y, z, -1, 1, radiusPx, strokePx, fr, fg, fb, sr, sg, sb);

    out.push(x, y, z, 1, -1, radiusPx, strokePx, fr, fg, fb, sr, sg, sb);
    out.push(x, y, z, 1, 1, radiusPx, strokePx, fr, fg, fb, sr, sg, sb);
    out.push(x, y, z, -1, 1, radiusPx, strokePx, fr, fg, fb, sr, sg, sb);
}

const BILLBOARD_CORNERS: ReadonlyArray<readonly [number, number]> = [
    [-1, -1],
    [1, -1],
    [-1, 1],
    [1, -1],
    [1, 1],
    [-1, 1],
];

function pushArrowBillboard(
    out: number[],
    x: number,
    y: number,
    z: number,
    aheadX: number,
    aheadY: number,
    aheadZ: number,
    sizePx: number,
    strokePx: number,
    fillRgb: [number, number, number],
    strokeRgb: [number, number, number],
    alpha: number,
): void {
    const [fr, fg, fb] = fillRgb;
    const [sr, sg, sb] = strokeRgb;

    for (const [cx, cy] of BILLBOARD_CORNERS) {
        out.push(
            x,
            y,
            z,
            aheadX,
            aheadY,
            aheadZ,
            cx,
            cy,
            sizePx,
            strokePx,
            fr,
            fg,
            fb,
            sr,
            sg,
            sb,
            alpha,
        );
    }
}

export class ElevationCurtainLayer implements CustomLayerInterface {
    public readonly id: string;
    public readonly type = 'custom' as const;
    public readonly renderingMode = '3d' as const;

    private map: MapLibreMap | null = null;
    private gl: WebGL2RenderingContext | null = null;

    private curtainProgram: WebGLProgram | null = null;
    private ribbonProgram: WebGLProgram | null = null;
    private dotProgram: WebGLProgram | null = null;
    private arrowProgram: WebGLProgram | null = null;

    private curtainVao: WebGLVertexArrayObject | null = null;
    private curtainBuffer: WebGLBuffer | null = null;
    private curtainVertexCount = 0;

    private ribbonVao: WebGLVertexArrayObject | null = null;
    private ribbonBuffer: WebGLBuffer | null = null;
    private ribbonVertexCount = 0;

    private dynamicRibbonVao: WebGLVertexArrayObject | null = null;
    private dynamicRibbonBuffer: WebGLBuffer | null = null;
    private dynamicRibbonVertexCount = 0;

    private dotVao: WebGLVertexArrayObject | null = null;
    private dotBuffer: WebGLBuffer | null = null;
    private dotVertexCount = 0;

    private arrowVao: WebGLVertexArrayObject | null = null;
    private arrowBuffer: WebGLBuffer | null = null;
    private arrowVertexCount = 0;

    private curtainUniforms: {
        matrix: WebGLUniformLocation | null;
        bottomAlpha: WebGLUniformLocation | null;
        topAlpha: WebGLUniformLocation | null;
    } = { matrix: null, bottomAlpha: null, topAlpha: null };

    private ribbonUniforms: {
        matrix: WebGLUniformLocation | null;
        viewport: WebGLUniformLocation | null;
    } = { matrix: null, viewport: null };

    private dotUniforms: {
        matrix: WebGLUniformLocation | null;
        viewport: WebGLUniformLocation | null;
    } = { matrix: null, viewport: null };

    private arrowUniforms: {
        matrix: WebGLUniformLocation | null;
        viewport: WebGLUniformLocation | null;
    } = { matrix: null, viewport: null };

    private originX = 0;
    private originY = 0;
    private isDark = false;
    private visible = false;

    private projectedPoints: ProjectedCurtainPoint[] = [];
    private staticMarkers: ElevationCurtainMarker[] = [];
    private staticArrows: ElevationCurtainArrow[] = [];
    private hoverMarker: ElevationCurtainMarker | null = null;

    private curtainDataArray: Float32Array = new Float32Array(0);
    private ribbonDataArray: Float32Array = new Float32Array(0);
    private dynamicRibbonDataArray: Float32Array = new Float32Array(0);
    private dotDataArray: Float32Array = new Float32Array(0);
    private arrowDataArray: Float32Array = new Float32Array(0);

    private routeDirty = false;
    private overlayDirty = false;

    private lastTranslatedMatrix: Float64Array | null = null;
    private lastViewportWidth = 0;
    private lastViewportHeight = 0;

    public constructor(id: string) {
        this.id = id;
    }

    public setVisible(visible: boolean): void {
        if (this.visible === visible) {
            return;
        }

        this.visible = visible;
        this.map?.triggerRepaint();
    }

    public setData(data: ElevationCurtainData): void {
        this.isDark = data.isDark;
        this.staticMarkers = data.markers ?? [];
        this.staticArrows = data.arrows ?? [];
        this.hoverMarker = data.hoverMarker ?? null;

        this.rebuildRouteGeometry(data.points);
        this.rebuildArrowGeometry();
        this.rebuildDynamicGeometry();
        this.routeDirty = true;
        this.overlayDirty = true;
        this.map?.triggerRepaint();
    }

    public setHoverMarker(marker: ElevationCurtainMarker | null): void {
        this.hoverMarker = marker;
        this.rebuildDynamicGeometry();
        this.overlayDirty = true;
        this.map?.triggerRepaint();
    }

    public pickNearestSeriesIndex(
        screenX: number,
        screenY: number,
        maxDistancePx = 16,
    ): number | null {
        if (
            !this.visible ||
            !this.lastTranslatedMatrix ||
            this.projectedPoints.length < 2 ||
            this.lastViewportWidth <= 0 ||
            this.lastViewportHeight <= 0
        ) {
            return null;
        }

        const m = this.lastTranslatedMatrix;
        const w = this.lastViewportWidth;
        const h = this.lastViewportHeight;
        const maxDistSq = maxDistancePx * maxDistancePx;

        let bestSeriesIndex: number | null = null;
        let bestDistSq = maxDistSq;

        let prevTopX = 0;
        let prevTopY = 0;
        let prevValid = false;

        for (let i = 0; i < this.projectedPoints.length; i += 1) {
            const pt = this.projectedPoints[i];
            const clipW = m[3] * pt.dx + m[7] * pt.dy + m[11] * pt.dz + m[15];

            if (clipW <= 1e-6) {
                prevValid = false;
                continue;
            }

            const clipX = m[0] * pt.dx + m[4] * pt.dy + m[8] * pt.dz + m[12];
            const clipY = m[1] * pt.dx + m[5] * pt.dy + m[9] * pt.dz + m[13];

            const topX = ((clipX / clipW) * 0.5 + 0.5) * w;
            const topY = (0.5 - (clipY / clipW) * 0.5) * h;

            if (prevValid) {
                const distSq = pointToSegmentDistSq(
                    screenX,
                    screenY,
                    prevTopX,
                    prevTopY,
                    topX,
                    topY,
                );

                if (distSq < bestDistSq) {
                    bestDistSq = distSq;
                    bestSeriesIndex = pt.seriesIndex;
                }
            }

            // Also check vertical drop between ridge and ground so hovering the filled wall works naturally
            const baseClipW = m[3] * pt.dx + m[7] * pt.dy + m[15];

            if (baseClipW > 1e-6) {
                const baseClipX = m[0] * pt.dx + m[4] * pt.dy + m[12];
                const baseClipY = m[1] * pt.dx + m[5] * pt.dy + m[13];
                const baseX = ((baseClipX / baseClipW) * 0.5 + 0.5) * w;
                const baseY = (0.5 - (baseClipY / baseClipW) * 0.5) * h;

                const wallDistSq = pointToSegmentDistSq(
                    screenX,
                    screenY,
                    topX,
                    topY,
                    baseX,
                    baseY,
                );

                if (wallDistSq < bestDistSq) {
                    bestDistSq = wallDistSq;
                    bestSeriesIndex = pt.seriesIndex;
                }
            }

            prevTopX = topX;
            prevTopY = topY;
            prevValid = true;
        }

        return bestSeriesIndex;
    }

    public onAdd(map: MapLibreMap, gl: WebGL2RenderingContext): void {
        this.map = map;
        this.gl = gl;

        this.curtainProgram = createProgram(
            gl,
            CURTAIN_VERTEX_SHADER,
            CURTAIN_FRAGMENT_SHADER,
        );
        this.ribbonProgram = createProgram(
            gl,
            RIBBON_VERTEX_SHADER,
            RIBBON_FRAGMENT_SHADER,
        );
        this.dotProgram = createProgram(
            gl,
            DOT_VERTEX_SHADER,
            DOT_FRAGMENT_SHADER,
        );
        this.arrowProgram = createProgram(
            gl,
            ARROW_VERTEX_SHADER,
            ARROW_FRAGMENT_SHADER,
        );

        if (this.curtainProgram) {
            this.curtainUniforms = {
                matrix: gl.getUniformLocation(this.curtainProgram, 'u_matrix'),
                bottomAlpha: gl.getUniformLocation(
                    this.curtainProgram,
                    'u_bottomAlpha',
                ),
                topAlpha: gl.getUniformLocation(
                    this.curtainProgram,
                    'u_topAlpha',
                ),
            };

            this.curtainVao = gl.createVertexArray();
            this.curtainBuffer = gl.createBuffer();
            gl.bindVertexArray(this.curtainVao);
            gl.bindBuffer(gl.ARRAY_BUFFER, this.curtainBuffer);

            const stride = 8 * 4;
            const posLoc = gl.getAttribLocation(this.curtainProgram, 'a_pos');
            const colorLoc = gl.getAttribLocation(
                this.curtainProgram,
                'a_color',
            );
            const heightLoc = gl.getAttribLocation(
                this.curtainProgram,
                'a_heightRatio',
            );
            const alphaScaleLoc = gl.getAttribLocation(
                this.curtainProgram,
                'a_alphaScale',
            );

            if (posLoc >= 0) {
                gl.enableVertexAttribArray(posLoc);
                gl.vertexAttribPointer(posLoc, 3, gl.FLOAT, false, stride, 0);
            }
            if (colorLoc >= 0) {
                gl.enableVertexAttribArray(colorLoc);
                gl.vertexAttribPointer(
                    colorLoc,
                    3,
                    gl.FLOAT,
                    false,
                    stride,
                    3 * 4,
                );
            }
            if (heightLoc >= 0) {
                gl.enableVertexAttribArray(heightLoc);
                gl.vertexAttribPointer(
                    heightLoc,
                    1,
                    gl.FLOAT,
                    false,
                    stride,
                    6 * 4,
                );
            }
            if (alphaScaleLoc >= 0) {
                gl.enableVertexAttribArray(alphaScaleLoc);
                gl.vertexAttribPointer(
                    alphaScaleLoc,
                    1,
                    gl.FLOAT,
                    false,
                    stride,
                    7 * 4,
                );
            }
            gl.bindVertexArray(null);
        }

        if (this.ribbonProgram) {
            this.ribbonUniforms = {
                matrix: gl.getUniformLocation(this.ribbonProgram, 'u_matrix'),
                viewport: gl.getUniformLocation(
                    this.ribbonProgram,
                    'u_viewport',
                ),
            };

            const setupRibbonVao = (): {
                vao: WebGLVertexArrayObject | null;
                buffer: WebGLBuffer | null;
            } => {
                const vao = gl.createVertexArray();
                const buffer = gl.createBuffer();
                gl.bindVertexArray(vao);
                gl.bindBuffer(gl.ARRAY_BUFFER, buffer);

                const stride = 13 * 4;
                const posLoc = gl.getAttribLocation(
                    this.ribbonProgram!,
                    'a_pos',
                );
                const otherLoc = gl.getAttribLocation(
                    this.ribbonProgram!,
                    'a_other',
                );
                const dirLoc = gl.getAttribLocation(
                    this.ribbonProgram!,
                    'a_dir',
                );
                const sideLoc = gl.getAttribLocation(
                    this.ribbonProgram!,
                    'a_side',
                );
                const widthLoc = gl.getAttribLocation(
                    this.ribbonProgram!,
                    'a_halfWidth',
                );
                const colorLoc = gl.getAttribLocation(
                    this.ribbonProgram!,
                    'a_color',
                );
                const alphaLoc = gl.getAttribLocation(
                    this.ribbonProgram!,
                    'a_alpha',
                );

                if (posLoc >= 0) {
                    gl.enableVertexAttribArray(posLoc);
                    gl.vertexAttribPointer(
                        posLoc,
                        3,
                        gl.FLOAT,
                        false,
                        stride,
                        0,
                    );
                }
                if (otherLoc >= 0) {
                    gl.enableVertexAttribArray(otherLoc);
                    gl.vertexAttribPointer(
                        otherLoc,
                        3,
                        gl.FLOAT,
                        false,
                        stride,
                        3 * 4,
                    );
                }
                if (dirLoc >= 0) {
                    gl.enableVertexAttribArray(dirLoc);
                    gl.vertexAttribPointer(
                        dirLoc,
                        1,
                        gl.FLOAT,
                        false,
                        stride,
                        6 * 4,
                    );
                }
                if (sideLoc >= 0) {
                    gl.enableVertexAttribArray(sideLoc);
                    gl.vertexAttribPointer(
                        sideLoc,
                        1,
                        gl.FLOAT,
                        false,
                        stride,
                        7 * 4,
                    );
                }
                if (widthLoc >= 0) {
                    gl.enableVertexAttribArray(widthLoc);
                    gl.vertexAttribPointer(
                        widthLoc,
                        1,
                        gl.FLOAT,
                        false,
                        stride,
                        8 * 4,
                    );
                }
                if (colorLoc >= 0) {
                    gl.enableVertexAttribArray(colorLoc);
                    gl.vertexAttribPointer(
                        colorLoc,
                        3,
                        gl.FLOAT,
                        false,
                        stride,
                        9 * 4,
                    );
                }
                if (alphaLoc >= 0) {
                    gl.enableVertexAttribArray(alphaLoc);
                    gl.vertexAttribPointer(
                        alphaLoc,
                        1,
                        gl.FLOAT,
                        false,
                        stride,
                        12 * 4,
                    );
                }

                gl.bindVertexArray(null);

                return { vao, buffer };
            };

            const mainRibbon = setupRibbonVao();
            this.ribbonVao = mainRibbon.vao;
            this.ribbonBuffer = mainRibbon.buffer;

            const dynamicRibbon = setupRibbonVao();
            this.dynamicRibbonVao = dynamicRibbon.vao;
            this.dynamicRibbonBuffer = dynamicRibbon.buffer;
        }

        if (this.dotProgram) {
            this.dotUniforms = {
                matrix: gl.getUniformLocation(this.dotProgram, 'u_matrix'),
                viewport: gl.getUniformLocation(this.dotProgram, 'u_viewport'),
            };

            this.dotVao = gl.createVertexArray();
            this.dotBuffer = gl.createBuffer();
            gl.bindVertexArray(this.dotVao);
            gl.bindBuffer(gl.ARRAY_BUFFER, this.dotBuffer);

            const stride = 13 * 4;
            const posLoc = gl.getAttribLocation(this.dotProgram, 'a_pos');
            const cornerLoc = gl.getAttribLocation(this.dotProgram, 'a_corner');
            const radiusLoc = gl.getAttribLocation(this.dotProgram, 'a_radius');
            const strokeLoc = gl.getAttribLocation(this.dotProgram, 'a_stroke');
            const fillColorLoc = gl.getAttribLocation(
                this.dotProgram,
                'a_fillColor',
            );
            const strokeColorLoc = gl.getAttribLocation(
                this.dotProgram,
                'a_strokeColor',
            );

            if (posLoc >= 0) {
                gl.enableVertexAttribArray(posLoc);
                gl.vertexAttribPointer(posLoc, 3, gl.FLOAT, false, stride, 0);
            }
            if (cornerLoc >= 0) {
                gl.enableVertexAttribArray(cornerLoc);
                gl.vertexAttribPointer(
                    cornerLoc,
                    2,
                    gl.FLOAT,
                    false,
                    stride,
                    3 * 4,
                );
            }
            if (radiusLoc >= 0) {
                gl.enableVertexAttribArray(radiusLoc);
                gl.vertexAttribPointer(
                    radiusLoc,
                    1,
                    gl.FLOAT,
                    false,
                    stride,
                    5 * 4,
                );
            }
            if (strokeLoc >= 0) {
                gl.enableVertexAttribArray(strokeLoc);
                gl.vertexAttribPointer(
                    strokeLoc,
                    1,
                    gl.FLOAT,
                    false,
                    stride,
                    6 * 4,
                );
            }
            if (fillColorLoc >= 0) {
                gl.enableVertexAttribArray(fillColorLoc);
                gl.vertexAttribPointer(
                    fillColorLoc,
                    3,
                    gl.FLOAT,
                    false,
                    stride,
                    7 * 4,
                );
            }
            if (strokeColorLoc >= 0) {
                gl.enableVertexAttribArray(strokeColorLoc);
                gl.vertexAttribPointer(
                    strokeColorLoc,
                    3,
                    gl.FLOAT,
                    false,
                    stride,
                    10 * 4,
                );
            }
            gl.bindVertexArray(null);
        }

        if (this.arrowProgram) {
            this.arrowUniforms = {
                matrix: gl.getUniformLocation(this.arrowProgram, 'u_matrix'),
                viewport: gl.getUniformLocation(
                    this.arrowProgram,
                    'u_viewport',
                ),
            };

            this.arrowVao = gl.createVertexArray();
            this.arrowBuffer = gl.createBuffer();
            gl.bindVertexArray(this.arrowVao);
            gl.bindBuffer(gl.ARRAY_BUFFER, this.arrowBuffer);

            const stride = 17 * 4;
            const posLoc = gl.getAttribLocation(this.arrowProgram, 'a_pos');
            const aheadLoc = gl.getAttribLocation(this.arrowProgram, 'a_ahead');
            const cornerLoc = gl.getAttribLocation(
                this.arrowProgram,
                'a_corner',
            );
            const sizeLoc = gl.getAttribLocation(this.arrowProgram, 'a_size');
            const strokeLoc = gl.getAttribLocation(
                this.arrowProgram,
                'a_stroke',
            );
            const fillColorLoc = gl.getAttribLocation(
                this.arrowProgram,
                'a_fillColor',
            );
            const strokeColorLoc = gl.getAttribLocation(
                this.arrowProgram,
                'a_strokeColor',
            );
            const alphaLoc = gl.getAttribLocation(this.arrowProgram, 'a_alpha');

            if (posLoc >= 0) {
                gl.enableVertexAttribArray(posLoc);
                gl.vertexAttribPointer(posLoc, 3, gl.FLOAT, false, stride, 0);
            }
            if (aheadLoc >= 0) {
                gl.enableVertexAttribArray(aheadLoc);
                gl.vertexAttribPointer(
                    aheadLoc,
                    3,
                    gl.FLOAT,
                    false,
                    stride,
                    3 * 4,
                );
            }
            if (cornerLoc >= 0) {
                gl.enableVertexAttribArray(cornerLoc);
                gl.vertexAttribPointer(
                    cornerLoc,
                    2,
                    gl.FLOAT,
                    false,
                    stride,
                    6 * 4,
                );
            }
            if (sizeLoc >= 0) {
                gl.enableVertexAttribArray(sizeLoc);
                gl.vertexAttribPointer(
                    sizeLoc,
                    1,
                    gl.FLOAT,
                    false,
                    stride,
                    8 * 4,
                );
            }
            if (strokeLoc >= 0) {
                gl.enableVertexAttribArray(strokeLoc);
                gl.vertexAttribPointer(
                    strokeLoc,
                    1,
                    gl.FLOAT,
                    false,
                    stride,
                    9 * 4,
                );
            }
            if (fillColorLoc >= 0) {
                gl.enableVertexAttribArray(fillColorLoc);
                gl.vertexAttribPointer(
                    fillColorLoc,
                    3,
                    gl.FLOAT,
                    false,
                    stride,
                    10 * 4,
                );
            }
            if (strokeColorLoc >= 0) {
                gl.enableVertexAttribArray(strokeColorLoc);
                gl.vertexAttribPointer(
                    strokeColorLoc,
                    3,
                    gl.FLOAT,
                    false,
                    stride,
                    13 * 4,
                );
            }
            if (alphaLoc >= 0) {
                gl.enableVertexAttribArray(alphaLoc);
                gl.vertexAttribPointer(
                    alphaLoc,
                    1,
                    gl.FLOAT,
                    false,
                    stride,
                    16 * 4,
                );
            }
            gl.bindVertexArray(null);
        }

        this.routeDirty = true;
        this.overlayDirty = true;
    }

    public onRemove(_map: MapLibreMap, gl: WebGL2RenderingContext): void {
        if (this.curtainBuffer) {
            gl.deleteBuffer(this.curtainBuffer);
            this.curtainBuffer = null;
        }
        if (this.curtainVao) {
            gl.deleteVertexArray(this.curtainVao);
            this.curtainVao = null;
        }
        if (this.ribbonBuffer) {
            gl.deleteBuffer(this.ribbonBuffer);
            this.ribbonBuffer = null;
        }
        if (this.ribbonVao) {
            gl.deleteVertexArray(this.ribbonVao);
            this.ribbonVao = null;
        }
        if (this.dynamicRibbonBuffer) {
            gl.deleteBuffer(this.dynamicRibbonBuffer);
            this.dynamicRibbonBuffer = null;
        }
        if (this.dynamicRibbonVao) {
            gl.deleteVertexArray(this.dynamicRibbonVao);
            this.dynamicRibbonVao = null;
        }
        if (this.dotBuffer) {
            gl.deleteBuffer(this.dotBuffer);
            this.dotBuffer = null;
        }
        if (this.dotVao) {
            gl.deleteVertexArray(this.dotVao);
            this.dotVao = null;
        }
        if (this.arrowBuffer) {
            gl.deleteBuffer(this.arrowBuffer);
            this.arrowBuffer = null;
        }
        if (this.arrowVao) {
            gl.deleteVertexArray(this.arrowVao);
            this.arrowVao = null;
        }
        if (this.curtainProgram) {
            gl.deleteProgram(this.curtainProgram);
            this.curtainProgram = null;
        }
        if (this.ribbonProgram) {
            gl.deleteProgram(this.ribbonProgram);
            this.ribbonProgram = null;
        }
        if (this.dotProgram) {
            gl.deleteProgram(this.dotProgram);
            this.dotProgram = null;
        }
        if (this.arrowProgram) {
            gl.deleteProgram(this.arrowProgram);
            this.arrowProgram = null;
        }

        this.gl = null;
        this.map = null;
        this.lastTranslatedMatrix = null;
    }

    public render(
        gl: WebGL2RenderingContext,
        options: CustomRenderMethodInput,
    ): void {
        if (!this.visible || this.curtainVertexCount === 0) {
            return;
        }

        this.flushDirtyBuffers(gl);

        const canvas = this.map?.getCanvas();
        const viewportWidth = canvas?.clientWidth || gl.drawingBufferWidth || 1;
        const viewportHeight =
            canvas?.clientHeight || gl.drawingBufferHeight || 1;
        this.lastViewportWidth = viewportWidth;
        this.lastViewportHeight = viewportHeight;

        const m = options.defaultProjectionData.mainMatrix;
        const ox = this.originX;
        const oy = this.originY;

        const translated64 = new Float64Array(16);

        for (let i = 0; i < 12; i += 1) {
            translated64[i] = m[i];
        }

        translated64[12] = m[0] * ox + m[4] * oy + m[12];
        translated64[13] = m[1] * ox + m[5] * oy + m[13];
        translated64[14] = m[2] * ox + m[6] * oy + m[14];
        translated64[15] = m[3] * ox + m[7] * oy + m[15];
        this.lastTranslatedMatrix = translated64;

        const matrix32 = new Float32Array(translated64);

        gl.enable(gl.BLEND);
        gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
        gl.disable(gl.CULL_FACE);
        gl.enable(gl.DEPTH_TEST);
        gl.depthFunc(gl.LEQUAL);
        gl.depthMask(false);

        // Pass 1: Translucent filled vertical curtain wall
        if (
            this.curtainProgram &&
            this.curtainVao &&
            this.curtainVertexCount > 0
        ) {
            gl.useProgram(this.curtainProgram);
            gl.uniformMatrix4fv(this.curtainUniforms.matrix, false, matrix32);
            gl.uniform1f(
                this.curtainUniforms.bottomAlpha,
                this.isDark ? 0.1 : 0.08,
            );
            gl.uniform1f(
                this.curtainUniforms.topAlpha,
                this.isDark ? 0.5 : 0.44,
            );

            gl.bindVertexArray(this.curtainVao);
            gl.drawArrays(gl.TRIANGLES, 0, this.curtainVertexCount);
            gl.bindVertexArray(null);
        }

        // Pass 2: Crisp elevated top ridge line
        if (
            this.ribbonProgram &&
            this.ribbonVao &&
            this.ribbonVertexCount > 0
        ) {
            gl.useProgram(this.ribbonProgram);
            gl.uniformMatrix4fv(this.ribbonUniforms.matrix, false, matrix32);
            gl.uniform2f(
                this.ribbonUniforms.viewport,
                viewportWidth,
                viewportHeight,
            );

            gl.bindVertexArray(this.ribbonVao);
            gl.drawArrays(gl.TRIANGLES, 0, this.ribbonVertexCount);
            gl.bindVertexArray(null);
        }

        // Pass 3: Plumb lines (for hover and start/finish anchors)
        if (
            this.ribbonProgram &&
            this.dynamicRibbonVao &&
            this.dynamicRibbonVertexCount > 0
        ) {
            gl.useProgram(this.ribbonProgram);
            gl.uniformMatrix4fv(this.ribbonUniforms.matrix, false, matrix32);
            gl.uniform2f(
                this.ribbonUniforms.viewport,
                viewportWidth,
                viewportHeight,
            );

            gl.bindVertexArray(this.dynamicRibbonVao);
            gl.drawArrays(gl.TRIANGLES, 0, this.dynamicRibbonVertexCount);
            gl.bindVertexArray(null);
        }

        // Pass 3.5: Elevated directional chevrons along the ridge
        if (this.arrowProgram && this.arrowVao && this.arrowVertexCount > 0) {
            gl.useProgram(this.arrowProgram);
            gl.uniformMatrix4fv(this.arrowUniforms.matrix, false, matrix32);
            gl.uniform2f(
                this.arrowUniforms.viewport,
                viewportWidth,
                viewportHeight,
            );

            gl.bindVertexArray(this.arrowVao);
            gl.drawArrays(gl.TRIANGLES, 0, this.arrowVertexCount);
            gl.bindVertexArray(null);
        }

        // Pass 4: Elevated start / finish / hover circular markers
        if (this.dotProgram && this.dotVao && this.dotVertexCount > 0) {
            gl.useProgram(this.dotProgram);
            gl.uniformMatrix4fv(this.dotUniforms.matrix, false, matrix32);
            gl.uniform2f(
                this.dotUniforms.viewport,
                viewportWidth,
                viewportHeight,
            );

            gl.bindVertexArray(this.dotVao);
            gl.drawArrays(gl.TRIANGLES, 0, this.dotVertexCount);
            gl.bindVertexArray(null);
        }

        gl.depthMask(true);
    }

    private flushDirtyBuffers(gl: WebGL2RenderingContext): void {
        if (this.routeDirty) {
            if (this.curtainBuffer) {
                gl.bindBuffer(gl.ARRAY_BUFFER, this.curtainBuffer);
                gl.bufferData(
                    gl.ARRAY_BUFFER,
                    this.curtainDataArray,
                    gl.STATIC_DRAW,
                );
            }
            if (this.ribbonBuffer) {
                gl.bindBuffer(gl.ARRAY_BUFFER, this.ribbonBuffer);
                gl.bufferData(
                    gl.ARRAY_BUFFER,
                    this.ribbonDataArray,
                    gl.STATIC_DRAW,
                );
            }
            if (this.arrowBuffer) {
                gl.bindBuffer(gl.ARRAY_BUFFER, this.arrowBuffer);
                gl.bufferData(
                    gl.ARRAY_BUFFER,
                    this.arrowDataArray,
                    gl.STATIC_DRAW,
                );
            }
            this.routeDirty = false;
        }

        if (this.overlayDirty) {
            if (this.dynamicRibbonBuffer) {
                gl.bindBuffer(gl.ARRAY_BUFFER, this.dynamicRibbonBuffer);
                gl.bufferData(
                    gl.ARRAY_BUFFER,
                    this.dynamicRibbonDataArray,
                    gl.DYNAMIC_DRAW,
                );
            }
            if (this.dotBuffer) {
                gl.bindBuffer(gl.ARRAY_BUFFER, this.dotBuffer);
                gl.bufferData(
                    gl.ARRAY_BUFFER,
                    this.dotDataArray,
                    gl.DYNAMIC_DRAW,
                );
            }
            this.overlayDirty = false;
        }

        gl.bindBuffer(gl.ARRAY_BUFFER, null);
    }

    private rebuildRouteGeometry(points: ElevationCurtainPoint[]): void {
        if (points.length < 2) {
            this.projectedPoints = [];
            this.curtainDataArray = new Float32Array(0);
            this.ribbonDataArray = new Float32Array(0);
            this.curtainVertexCount = 0;
            this.ribbonVertexCount = 0;

            return;
        }

        let minX = Number.POSITIVE_INFINITY;
        let maxX = Number.NEGATIVE_INFINITY;
        let minY = Number.POSITIVE_INFINITY;
        let maxY = Number.NEGATIVE_INFINITY;

        const rawMercator = points.map((pt) => {
            const mc = MercatorCoordinate.fromLngLat(
                [pt.lng, pt.lat],
                Math.max(0, pt.heightMeters),
            );

            if (mc.x < minX) {
                minX = mc.x;
            }
            if (mc.x > maxX) {
                maxX = mc.x;
            }
            if (mc.y < minY) {
                minY = mc.y;
            }
            if (mc.y > maxY) {
                maxY = mc.y;
            }

            return {
                seriesIndex: pt.seriesIndex,
                x: mc.x,
                y: mc.y,
                z: mc.z,
                rgb: parseCssColorToRgb(pt.color),
                dimmed: Boolean(pt.dimmed),
                highlighted: Boolean(pt.highlighted),
            };
        });

        this.originX = (minX + maxX) / 2;
        this.originY = (minY + maxY) / 2;

        this.projectedPoints = rawMercator.map((pt) => ({
            seriesIndex: pt.seriesIndex,
            dx: pt.x - this.originX,
            dy: pt.y - this.originY,
            dz: pt.z,
            rgb: pt.rgb,
            dimmed: pt.dimmed,
            highlighted: pt.highlighted,
        }));

        const curtainVerts: number[] = [];
        const baseRibbonVerts: number[] = [];
        const highlightRibbonVerts: number[] = [];

        for (let i = 0; i < this.projectedPoints.length - 1; i += 1) {
            const a = this.projectedPoints[i];
            const b = this.projectedPoints[i + 1];

            const isHighlighted = a.highlighted && b.highlighted;
            const isDimmed = !isHighlighted && (a.dimmed || b.dimmed);
            const alphaScale = isHighlighted ? 1.35 : isDimmed ? 0.32 : 1.0;

            const [ar, ag, ab] = a.rgb;
            const [br, bg, bb] = b.rgb;

            // Curtain quad (2 triangles = 6 vertices, 8 floats/vertex)
            // Bottom-A, Top-A, Bottom-B
            curtainVerts.push(a.dx, a.dy, 0, ar, ag, ab, 0, alphaScale);
            curtainVerts.push(a.dx, a.dy, a.dz, ar, ag, ab, 1, alphaScale);
            curtainVerts.push(b.dx, b.dy, 0, br, bg, bb, 0, alphaScale);

            // Top-A, Top-B, Bottom-B
            curtainVerts.push(a.dx, a.dy, a.dz, ar, ag, ab, 1, alphaScale);
            curtainVerts.push(b.dx, b.dy, b.dz, br, bg, bb, 1, alphaScale);
            curtainVerts.push(b.dx, b.dy, 0, br, bg, bb, 0, alphaScale);

            const midR = (ar + br) * 0.5;
            const midG = (ag + bg) * 0.5;
            const midB = (ab + bb) * 0.5;

            if (isHighlighted) {
                pushRibbonSegment(
                    highlightRibbonVerts,
                    a.dx,
                    a.dy,
                    a.dz,
                    b.dx,
                    b.dy,
                    b.dz,
                    2.8,
                    midR,
                    midG,
                    midB,
                    1.0,
                );
            } else {
                pushRibbonSegment(
                    baseRibbonVerts,
                    a.dx,
                    a.dy,
                    a.dz,
                    b.dx,
                    b.dy,
                    b.dz,
                    isDimmed ? 1.4 : 2.1,
                    midR,
                    midG,
                    midB,
                    isDimmed ? 0.52 : 0.96,
                );
            }
        }

        const combinedRibbonVerts =
            highlightRibbonVerts.length > 0
                ? [...baseRibbonVerts, ...highlightRibbonVerts]
                : baseRibbonVerts;

        this.curtainDataArray = new Float32Array(curtainVerts);
        this.curtainVertexCount = curtainVerts.length / 8;

        this.ribbonDataArray = new Float32Array(combinedRibbonVerts);
        this.ribbonVertexCount = combinedRibbonVerts.length / 13;
    }

    private rebuildArrowGeometry(): void {
        if (this.staticArrows.length === 0) {
            this.arrowDataArray = new Float32Array(0);
            this.arrowVertexCount = 0;

            return;
        }

        const arrowVerts: number[] = [];

        for (const arrow of this.staticArrows) {
            const mc = MercatorCoordinate.fromLngLat(
                [arrow.lng, arrow.lat],
                Math.max(0, arrow.heightMeters),
            );
            const aheadMc = MercatorCoordinate.fromLngLat(
                [arrow.aheadLng, arrow.aheadLat],
                Math.max(0, arrow.aheadHeightMeters),
            );

            pushArrowBillboard(
                arrowVerts,
                mc.x - this.originX,
                mc.y - this.originY,
                mc.z,
                aheadMc.x - this.originX,
                aheadMc.y - this.originY,
                aheadMc.z,
                arrow.sizePx,
                arrow.strokeWidthPx,
                parseCssColorToRgb(arrow.fillColor),
                parseCssColorToRgb(arrow.strokeColor),
                arrow.alpha ?? 1.0,
            );
        }

        this.arrowDataArray = new Float32Array(arrowVerts);
        this.arrowVertexCount = arrowVerts.length / 17;
    }

    private rebuildDynamicGeometry(): void {
        const plumbVerts: number[] = [];
        const dotVerts: number[] = [];

        const allMarkers = [...this.staticMarkers];

        if (this.hoverMarker) {
            allMarkers.push(this.hoverMarker);
        }

        for (const marker of allMarkers) {
            const mc = MercatorCoordinate.fromLngLat(
                [marker.lng, marker.lat],
                Math.max(0, marker.heightMeters),
            );
            const dx = mc.x - this.originX;
            const dy = mc.y - this.originY;
            const dz = mc.z;

            if (marker.showPlumbLine && dz > 0) {
                const [pr, pg, pb] = parseCssColorToRgb(
                    marker.plumbColor ?? marker.fillColor,
                );
                pushRibbonSegment(
                    plumbVerts,
                    dx,
                    dy,
                    0,
                    dx,
                    dy,
                    dz,
                    1.0,
                    pr,
                    pg,
                    pb,
                    this.isDark ? 0.72 : 0.65,
                );
            }

            pushDotBillboard(
                dotVerts,
                dx,
                dy,
                dz,
                marker.radiusPx,
                marker.strokeWidthPx,
                parseCssColorToRgb(marker.fillColor),
                parseCssColorToRgb(marker.strokeColor),
            );
        }

        this.dynamicRibbonDataArray = new Float32Array(plumbVerts);
        this.dynamicRibbonVertexCount = plumbVerts.length / 13;

        this.dotDataArray = new Float32Array(dotVerts);
        this.dotVertexCount = dotVerts.length / 13;
    }
}
