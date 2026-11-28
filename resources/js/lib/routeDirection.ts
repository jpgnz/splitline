import { computeSegmentBearing } from '@/lib/wind';

export interface SvgPoint {
    x: number;
    y: number;
}

export interface SvgPathArrow {
    x: number;
    y: number;
    angleDeg: number;
}

export interface ComputeSvgPathArrowsOptions {
    minPathLength?: number;
    targetSpacing?: number;
    maxArrows?: number;
    minEndpointClearance?: number;
    minArrowClearance?: number;
    tangentWindow?: number;
}

export interface GeoRouteVertex {
    lat: number;
    lng: number;
    color: string;
    heightMeters?: number;
    highlighted?: boolean;
}

export interface GeoRouteArrow {
    lat: number;
    lng: number;
    aheadLat: number;
    aheadLng: number;
    heightMeters: number;
    aheadHeightMeters: number;
    bearing: number;
    color: string;
}

export interface ComputeGeoRouteArrowsOptions {
    virtualWidth?: number;
    virtualHeight?: number;
    virtualPadding?: number;
    minPathLength?: number;
    targetSpacing?: number;
    minArrows?: number;
    maxArrows?: number;
    minEndpointClearance?: number;
    minArrowClearance?: number;
    tangentWindow?: number;
    boundsVertices?: ReadonlyArray<{ lat: number; lng: number }>;
    skipHighlighted?: boolean;
    avoidPoints?: ReadonlyArray<{ lat: number; lng: number }>;
}

/**
 * Rasterize a 2D canvas path into a MapLibre-compatible Signed Distance Field
 * (SDF) ImageData texture for crisp scalable tinting and halos.
 */
export function buildSdfImageData(
    size: number,
    sdfRadius: number,
    drawShape: (ctx: CanvasRenderingContext2D, size: number) => void,
): ImageData | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (!ctx) {
        return null;
    }

    ctx.clearRect(0, 0, size, size);
    ctx.fillStyle = '#ffffff';
    drawShape(ctx, size);

    const raw = ctx.getImageData(0, 0, size, size);
    const inside = new Uint8Array(size * size);

    for (let i = 0; i < size * size; i += 1) {
        inside[i] = raw.data[i * 4 + 3] >= 128 ? 1 : 0;
    }

    const sdfData = new Uint8ClampedArray(size * size * 4);
    const radiusSq = sdfRadius * sdfRadius;

    for (let y = 0; y < size; y += 1) {
        const yMin = Math.max(0, y - sdfRadius);
        const yMax = Math.min(size - 1, y + sdfRadius);

        for (let x = 0; x < size; x += 1) {
            const idx = y * size + x;
            const isIn = inside[idx];
            const xMin = Math.max(0, x - sdfRadius);
            const xMax = Math.min(size - 1, x + sdfRadius);
            let minDistSq = radiusSq;

            for (let ny = yMin; ny <= yMax; ny += 1) {
                const dy = ny - y;
                const dySq = dy * dy;

                if (dySq >= minDistSq) {
                    continue;
                }

                const rowOffset = ny * size;

                for (let nx = xMin; nx <= xMax; nx += 1) {
                    if (inside[rowOffset + nx] !== isIn) {
                        const dx = nx - x;
                        const dSq = dx * dx + dySq;

                        if (dSq < minDistSq) {
                            minDistSq = dSq;
                        }
                    }
                }
            }

            const dist = Math.sqrt(minDistSq);
            const signedDist = isIn ? dist : -dist;
            const normalized = Math.max(
                0,
                Math.min(1, 0.75 + (signedDist / sdfRadius) * 0.75),
            );
            const offset = idx * 4;
            sdfData[offset] = 255;
            sdfData[offset + 1] = 255;
            sdfData[offset + 2] = 255;
            sdfData[offset + 3] = Math.round(normalized * 255);
        }
    }

    return new ImageData(sdfData, size, size);
}

/**
 * Compute a static set of evenly spaced directional arrows along a geographic
 * route normalized to a default-zoom virtual viewport so arrow density stays
 * balanced and never multiplies when zooming in.
 */
export function computeGeoRouteArrows(
    vertices: GeoRouteVertex[],
    options: ComputeGeoRouteArrowsOptions = {},
): GeoRouteArrow[] {
    if (vertices.length < 2) {
        return [];
    }

    const {
        virtualWidth = 600,
        virtualHeight = 380,
        virtualPadding = 28,
        minPathLength = 42,
        targetSpacing = 105,
        minArrows = 2,
        maxArrows = 12,
        minEndpointClearance = 24,
        minArrowClearance = 34,
        tangentWindow = 12,
        boundsVertices,
        skipHighlighted = false,
        avoidPoints = [],
    } = options;

    const boundsSource =
        boundsVertices && boundsVertices.length > 0 ? boundsVertices : vertices;

    let minLat = Number.POSITIVE_INFINITY;
    let maxLat = Number.NEGATIVE_INFINITY;
    let minLng = Number.POSITIVE_INFINITY;
    let maxLng = Number.NEGATIVE_INFINITY;

    for (const v of boundsSource) {
        if (v.lat < minLat) {
            minLat = v.lat;
        }
        if (v.lat > maxLat) {
            maxLat = v.lat;
        }
        if (v.lng < minLng) {
            minLng = v.lng;
        }
        if (v.lng > maxLng) {
            maxLng = v.lng;
        }
    }

    const midLatRad = (((minLat + maxLat) / 2) * Math.PI) / 180;
    const cosLat = Math.max(0.2, Math.cos(midLatRad));
    const spanX = Math.max(1e-7, (maxLng - minLng) * cosLat);
    const spanY = Math.max(1e-7, maxLat - minLat);

    const innerW = Math.max(1, virtualWidth - virtualPadding * 2);
    const innerH = Math.max(1, virtualHeight - virtualPadding * 2);
    const scale = Math.min(innerW / spanX, innerH / spanY);

    const drawnW = spanX * scale;
    const drawnH = spanY * scale;
    const offsetX = (virtualWidth - drawnW) / 2;
    const offsetY = (virtualHeight - drawnH) / 2;

    const projectLatLng = (lat: number, lng: number): SvgPoint => ({
        x: offsetX + (lng - minLng) * cosLat * scale,
        y: offsetY + (maxLat - lat) * scale,
    });

    const projX = new Float64Array(vertices.length);
    const projY = new Float64Array(vertices.length);
    const cumLen = new Float64Array(vertices.length);

    for (let i = 0; i < vertices.length; i += 1) {
        const projected = projectLatLng(vertices[i].lat, vertices[i].lng);
        projX[i] = projected.x;
        projY[i] = projected.y;

        if (i === 0) {
            cumLen[0] = 0;
        } else {
            cumLen[i] =
                cumLen[i - 1] +
                Math.hypot(projX[i] - projX[i - 1], projY[i] - projY[i - 1]);
        }
    }

    const totalLen = cumLen[vertices.length - 1];

    if (!Number.isFinite(totalLen) || totalLen < minPathLength) {
        return [];
    }

    const projectedAvoidPoints: SvgPoint[] = avoidPoints.map((pt) =>
        projectLatLng(pt.lat, pt.lng),
    );

    const sampleAt = (distance: number) => {
        const clamped = Math.max(0, Math.min(totalLen, distance));
        let lo = 0;
        let hi = vertices.length - 1;

        while (lo + 1 < hi) {
            const mid = (lo + hi) >> 1;

            if (cumLen[mid] <= clamped) {
                lo = mid;
            } else {
                hi = mid;
            }
        }

        const segStart = cumLen[lo];
        const segLen = cumLen[hi] - segStart;

        if (segLen <= 1e-6) {
            return {
                x: projX[lo],
                y: projY[lo],
                lat: vertices[lo].lat,
                lng: vertices[lo].lng,
                heightMeters: vertices[lo].heightMeters ?? 0,
                color: vertices[lo].color,
                highlighted: Boolean(vertices[lo].highlighted),
            };
        }

        const t = (clamped - segStart) / segLen;
        const h0 = vertices[lo].heightMeters ?? 0;
        const h1 = vertices[hi].heightMeters ?? 0;

        return {
            x: projX[lo] + (projX[hi] - projX[lo]) * t,
            y: projY[lo] + (projY[hi] - projY[lo]) * t,
            lat: vertices[lo].lat + (vertices[hi].lat - vertices[lo].lat) * t,
            lng: vertices[lo].lng + (vertices[hi].lng - vertices[lo].lng) * t,
            heightMeters: h0 + (h1 - h0) * t,
            color: t < 0.5 ? vertices[lo].color : vertices[hi].color,
            highlighted: Boolean(
                vertices[lo].highlighted || vertices[hi].highlighted,
            ),
        };
    };

    const tangentSpan = Math.min(tangentWindow, totalLen * 0.08);

    const resolveBearingAndAhead = (distance: number) => {
        const pMid = sampleAt(distance);
        const pBack = sampleAt(Math.max(0, distance - tangentSpan));
        const pAhead = sampleAt(Math.min(totalLen, distance + tangentSpan));

        const spanPx = Math.hypot(pAhead.x - pBack.x, pAhead.y - pBack.y);

        if (spanPx >= 2) {
            return {
                pMid,
                bearing: computeSegmentBearing(
                    pBack.lat,
                    pBack.lng,
                    pAhead.lat,
                    pAhead.lng,
                ),
                aheadLat: pMid.lat + (pAhead.lat - pBack.lat) * 0.5,
                aheadLng: pMid.lng + (pAhead.lng - pBack.lng) * 0.5,
                aheadHeightMeters:
                    pMid.heightMeters +
                    (pAhead.heightMeters - pBack.heightMeters) * 0.5,
            };
        }

        const fwdPx = Math.hypot(pAhead.x - pMid.x, pAhead.y - pMid.y);

        if (fwdPx >= 0.75) {
            return {
                pMid,
                bearing: computeSegmentBearing(
                    pMid.lat,
                    pMid.lng,
                    pAhead.lat,
                    pAhead.lng,
                ),
                aheadLat: pAhead.lat,
                aheadLng: pAhead.lng,
                aheadHeightMeters: pAhead.heightMeters,
            };
        }

        const backPx = Math.hypot(pMid.x - pBack.x, pMid.y - pBack.y);

        if (backPx >= 0.75) {
            return {
                pMid,
                bearing: computeSegmentBearing(
                    pBack.lat,
                    pBack.lng,
                    pMid.lat,
                    pMid.lng,
                ),
                aheadLat: pMid.lat + (pMid.lat - pBack.lat),
                aheadLng: pMid.lng + (pMid.lng - pBack.lng),
                aheadHeightMeters:
                    pMid.heightMeters +
                    (pMid.heightMeters - pBack.heightMeters),
            };
        }

        return null;
    };

    const count =
        totalLen < 120
            ? Math.max(1, Math.min(minArrows, maxArrows))
            : Math.max(
                  minArrows,
                  Math.min(maxArrows, Math.round(totalLen / targetSpacing)),
              );

    const startX = projX[0];
    const startY = projY[0];
    const endX = projX[vertices.length - 1];
    const endY = projY[vertices.length - 1];
    const minDist = totalLen * 0.06;
    const maxDist = totalLen * 0.94;
    const nudgeStep = Math.min(36, totalLen * 0.08);
    const candidateOffsets = [
        0,
        nudgeStep,
        -nudgeStep,
        nudgeStep * 1.75,
        -nudgeStep * 1.75,
    ];

    const placedScreen: SvgPoint[] = [];
    const arrows: GeoRouteArrow[] = [];

    for (let k = 0; k < count; k += 1) {
        const baseDist = ((k + 1) / (count + 1)) * totalLen;

        if (skipHighlighted && sampleAt(baseDist).highlighted) {
            continue;
        }

        for (const offset of candidateOffsets) {
            const dist = baseDist + offset;

            if (dist < minDist || dist > maxDist) {
                continue;
            }

            const resolved = resolveBearingAndAhead(dist);

            if (!resolved) {
                continue;
            }

            const { pMid } = resolved;

            if (skipHighlighted && pMid.highlighted) {
                continue;
            }

            if (
                Math.hypot(pMid.x - startX, pMid.y - startY) <
                    minEndpointClearance ||
                Math.hypot(pMid.x - endX, pMid.y - endY) < minEndpointClearance
            ) {
                continue;
            }

            const collidesAvoid = projectedAvoidPoints.some(
                (avoid) =>
                    Math.hypot(pMid.x - avoid.x, pMid.y - avoid.y) <
                    minEndpointClearance,
            );

            if (collidesAvoid) {
                continue;
            }

            const collidesExisting = placedScreen.some(
                (existing) =>
                    Math.hypot(pMid.x - existing.x, pMid.y - existing.y) <
                    minArrowClearance,
            );

            if (collidesExisting) {
                continue;
            }

            placedScreen.push({ x: pMid.x, y: pMid.y });
            arrows.push({
                lat: pMid.lat,
                lng: pMid.lng,
                aheadLat: resolved.aheadLat,
                aheadLng: resolved.aheadLng,
                heightMeters: pMid.heightMeters,
                aheadHeightMeters: resolved.aheadHeightMeters,
                bearing: resolved.bearing,
                color: pMid.color,
            });
            break;
        }
    }

    return arrows;
}

/**
 * Compute evenly spaced directional arrow positions and tangent angles along a
 * 2D SVG polyline, keeping clear of start/finish markers and avoiding overlaps
 * on out-and-back or crossing paths.
 */
export function computeSvgPathArrows(
    coords: SvgPoint[],
    options: ComputeSvgPathArrowsOptions = {},
): SvgPathArrow[] {
    if (coords.length < 2) {
        return [];
    }

    const {
        minPathLength = 28,
        targetSpacing = 74,
        maxArrows = 6,
        minEndpointClearance = 13,
        minArrowClearance = 15,
        tangentWindow = 8,
    } = options;

    const cumLen = new Float64Array(coords.length);
    cumLen[0] = 0;

    for (let i = 1; i < coords.length; i += 1) {
        const dx = coords[i].x - coords[i - 1].x;
        const dy = coords[i].y - coords[i - 1].y;
        cumLen[i] = cumLen[i - 1] + Math.hypot(dx, dy);
    }

    const totalLen = cumLen[coords.length - 1];

    if (!Number.isFinite(totalLen) || totalLen < minPathLength) {
        return [];
    }

    const sampleAt = (distance: number): SvgPoint => {
        const clamped = Math.max(0, Math.min(totalLen, distance));
        let lo = 0;
        let hi = coords.length - 1;

        while (lo + 1 < hi) {
            const mid = (lo + hi) >> 1;

            if (cumLen[mid] <= clamped) {
                lo = mid;
            } else {
                hi = mid;
            }
        }

        const segStart = cumLen[lo];
        const segLen = cumLen[hi] - segStart;

        if (segLen <= 1e-6) {
            return coords[lo];
        }

        const t = (clamped - segStart) / segLen;

        return {
            x: coords[lo].x + (coords[hi].x - coords[lo].x) * t,
            y: coords[lo].y + (coords[hi].y - coords[lo].y) * t,
        };
    };

    const tangentSpan = Math.min(tangentWindow, totalLen * 0.08);

    const tangentAngleAt = (distance: number): number | null => {
        const pBack = sampleAt(Math.max(0, distance - tangentSpan));
        const pAhead = sampleAt(Math.min(totalLen, distance + tangentSpan));
        const dx = pAhead.x - pBack.x;
        const dy = pAhead.y - pBack.y;

        if (Math.hypot(dx, dy) >= 1.5) {
            return (Math.atan2(dy, dx) * 180) / Math.PI;
        }

        const pMid = sampleAt(distance);
        const fdx = pAhead.x - pMid.x;
        const fdy = pAhead.y - pMid.y;

        if (Math.hypot(fdx, fdy) >= 0.5) {
            return (Math.atan2(fdy, fdx) * 180) / Math.PI;
        }

        const bdx = pMid.x - pBack.x;
        const bdy = pMid.y - pBack.y;

        if (Math.hypot(bdx, bdy) >= 0.5) {
            return (Math.atan2(bdy, bdx) * 180) / Math.PI;
        }

        return null;
    };

    const count =
        totalLen < 56
            ? 1
            : Math.max(
                  1,
                  Math.min(maxArrows, Math.round(totalLen / targetSpacing)),
              );

    const start = coords[0];
    const end = coords[coords.length - 1];
    const minDist = totalLen * 0.07;
    const maxDist = totalLen * 0.93;
    const nudgeStep = Math.min(18, totalLen * 0.08);
    const candidateOffsets = [
        0,
        nudgeStep,
        -nudgeStep,
        nudgeStep * 1.75,
        -nudgeStep * 1.75,
    ];

    const arrows: SvgPathArrow[] = [];

    for (let k = 0; k < count; k += 1) {
        const baseDist = ((k + 1) / (count + 1)) * totalLen;

        for (const offset of candidateOffsets) {
            const dist = baseDist + offset;

            if (dist < minDist || dist > maxDist) {
                continue;
            }

            const pt = sampleAt(dist);

            if (
                Math.hypot(pt.x - start.x, pt.y - start.y) <
                    minEndpointClearance ||
                Math.hypot(pt.x - end.x, pt.y - end.y) < minEndpointClearance
            ) {
                continue;
            }

            const collidesExisting = arrows.some(
                (existing) =>
                    Math.hypot(pt.x - existing.x, pt.y - existing.y) <
                    minArrowClearance,
            );

            if (collidesExisting) {
                continue;
            }

            const angleDeg = tangentAngleAt(dist);

            if (angleDeg === null) {
                continue;
            }

            arrows.push({
                x: Number(pt.x.toFixed(1)),
                y: Number(pt.y.toFixed(1)),
                angleDeg: Number(angleDeg.toFixed(1)),
            });
            break;
        }
    }

    return arrows;
}
