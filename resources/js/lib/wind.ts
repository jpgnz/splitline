import type { ActivityWeatherSample } from '@/types/activity';

export type WindClassification = 'headwind' | 'crosswind' | 'tailwind' | 'calm';

export interface InterpolatedWeather {
    temperature_c: number | null;
    apparent_temperature_c: number | null;
    humidity_pct: number | null;
    dew_point_c: number | null;
    wind_speed_kmh: number | null;
    wind_gusts_kmh: number | null;
    wind_direction_deg: number | null;
    wind_direction_compass: string | null;
    precipitation_mm: number | null;
    cloud_cover_pct: number | null;
    surface_pressure_hpa: number | null;
    wmo_code: number | null;
}

export interface EffectiveWindResult {
    effectiveKmh: number;
    crosswindKmh: number;
    classification: WindClassification;
}

const COMPASS_POINTS = [
    'N',
    'NNE',
    'NE',
    'ENE',
    'E',
    'ESE',
    'SE',
    'SSE',
    'S',
    'SSW',
    'SW',
    'WSW',
    'W',
    'WNW',
    'NW',
    'NNW',
] as const;

const CALM_WIND_THRESHOLD_KMH = 3;
const CALM_COLOR_HEX = '#94a3b8';

const ROSE_RGB: [number, number, number] = [244, 63, 94]; // #f43f5e
const AMBER_RGB: [number, number, number] = [245, 158, 11]; // #f59e0b
const EMERALD_RGB: [number, number, number] = [16, 185, 129]; // #10b981

const WIND_ARROW_LIGHT_STOPS: ReadonlyArray<{
    kmh: number;
    rgb: [number, number, number];
}> = [
    { kmh: 0, rgb: [8, 145, 178] }, // #0891b2 cyan-600
    { kmh: 12, rgb: [37, 99, 235] }, // #2563eb blue-600
    { kmh: 25, rgb: [124, 58, 237] }, // #7c3aed violet-600
    { kmh: 40, rgb: [192, 38, 211] }, // #c026d3 fuchsia-600
];

const WIND_ARROW_DARK_STOPS: ReadonlyArray<{
    kmh: number;
    rgb: [number, number, number];
}> = [
    { kmh: 0, rgb: [34, 211, 238] }, // #22d3ee cyan-400
    { kmh: 12, rgb: [96, 165, 250] }, // #60a5fa blue-400
    { kmh: 25, rgb: [167, 139, 250] }, // #a78bfa violet-400
    { kmh: 40, rgb: [232, 121, 249] }, // #e879f9 fuchsia-400
];

function degToRad(deg: number): number {
    return (deg * Math.PI) / 180;
}

function normalizeDegrees(deg: number): number {
    return ((deg % 360) + 360) % 360;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

function lerpNullable(
    a: number | null,
    b: number | null,
    alpha: number,
): number | null {
    if (a !== null && b !== null && Number.isFinite(a) && Number.isFinite(b)) {
        return a + (b - a) * alpha;
    }

    return alpha < 0.5 ? (a ?? b) : (b ?? a);
}

function rgbToHex(r: number, g: number, b: number): string {
    const toByte = (channel: number): string =>
        clamp(Math.round(channel), 0, 255).toString(16).padStart(2, '0');

    return `#${toByte(r)}${toByte(g)}${toByte(b)}`;
}

function lerpRgbHex(
    from: [number, number, number],
    to: [number, number, number],
    t: number,
): string {
    const ratio = clamp(t, 0, 1);

    return rgbToHex(
        from[0] + (to[0] - from[0]) * ratio,
        from[1] + (to[1] - from[1]) * ratio,
        from[2] + (to[2] - from[2]) * ratio,
    );
}

function toInterpolatedSample(
    sample: ActivityWeatherSample,
): InterpolatedWeather {
    const direction = sample.wind_direction_deg;

    return {
        temperature_c: sample.temperature_c,
        apparent_temperature_c: sample.apparent_temperature_c,
        humidity_pct: sample.humidity_pct,
        dew_point_c: sample.dew_point_c,
        wind_speed_kmh: sample.wind_speed_kmh,
        wind_gusts_kmh: sample.wind_gusts_kmh,
        wind_direction_deg: direction,
        wind_direction_compass:
            sample.wind_direction_compass ??
            (direction !== null ? formatCompass(direction) : null),
        precipitation_mm: sample.precipitation_mm,
        cloud_cover_pct: sample.cloud_cover_pct,
        surface_pressure_hpa: sample.surface_pressure_hpa,
        wmo_code: sample.wmo_code,
    };
}

/**
 * Format a meteorological wind bearing (0-359 deg) into a 16-point compass string.
 */
export function formatCompass(deg: number): string {
    if (!Number.isFinite(deg)) {
        return 'N';
    }

    const normalized = normalizeDegrees(deg);
    const index = Math.floor(((normalized + 11.25) % 360) / 22.5);

    return COMPASS_POINTS[index] ?? 'N';
}

/**
 * Interpolate weather metrics at a given time offset (and optional GNSS distance)
 * using linear scalar interpolation and circular Cartesian (u, v) wind vector interpolation.
 */
export function interpolateWeatherAt(
    samples: ActivityWeatherSample[],
    timeOffsetS: number,
    distanceM?: number | null,
): InterpolatedWeather | null {
    if (samples.length === 0) {
        return null;
    }

    if (samples.length === 1) {
        return toInterpolatedSample(samples[0]);
    }

    const first = samples[0];
    const last = samples[samples.length - 1];
    const useDistanceAxis =
        (!Number.isFinite(timeOffsetS) ||
            last.time_offset_s <= first.time_offset_s) &&
        distanceM !== null &&
        distanceM !== undefined &&
        Number.isFinite(distanceM) &&
        first.distance_m !== null &&
        last.distance_m !== null &&
        last.distance_m > first.distance_m;

    const sampleValue = (sample: ActivityWeatherSample): number =>
        useDistanceAxis
            ? (sample.distance_m ?? sample.time_offset_s)
            : sample.time_offset_s;

    const target = useDistanceAxis ? (distanceM as number) : timeOffsetS;

    if (target <= sampleValue(first)) {
        return toInterpolatedSample(first);
    }

    if (target >= sampleValue(last)) {
        return toInterpolatedSample(last);
    }

    let s0 = first;
    let s1 = last;

    for (let i = 0; i < samples.length - 1; i++) {
        const current = samples[i];
        const next = samples[i + 1];

        if (target >= sampleValue(current) && target <= sampleValue(next)) {
            s0 = current;
            s1 = next;
            break;
        }
    }

    const span = sampleValue(s1) - sampleValue(s0);
    let alpha = 0;

    if (span > 0) {
        alpha = clamp((target - sampleValue(s0)) / span, 0, 1);
    } else if (
        distanceM !== null &&
        distanceM !== undefined &&
        Number.isFinite(distanceM) &&
        s0.distance_m !== null &&
        s1.distance_m !== null &&
        s1.distance_m > s0.distance_m
    ) {
        alpha = clamp(
            (distanceM - s0.distance_m) / (s1.distance_m - s0.distance_m),
            0,
            1,
        );
    }

    let windSpeedKmh: number | null;
    let windDirectionDeg: number | null;

    if (
        s0.wind_speed_kmh !== null &&
        s1.wind_speed_kmh !== null &&
        s0.wind_direction_deg !== null &&
        s1.wind_direction_deg !== null
    ) {
        const rad0 = degToRad(s0.wind_direction_deg);
        const rad1 = degToRad(s1.wind_direction_deg);

        const u0 = -s0.wind_speed_kmh * Math.sin(rad0);
        const v0 = -s0.wind_speed_kmh * Math.cos(rad0);
        const u1 = -s1.wind_speed_kmh * Math.sin(rad1);
        const v1 = -s1.wind_speed_kmh * Math.cos(rad1);

        const u = u0 + (u1 - u0) * alpha;
        const v = v0 + (v1 - v0) * alpha;

        windSpeedKmh = Math.hypot(u, v);
        windDirectionDeg =
            windSpeedKmh > 1e-6
                ? normalizeDegrees((Math.atan2(-u, -v) * 180) / Math.PI)
                : normalizeDegrees(
                      alpha < 0.5
                          ? s0.wind_direction_deg
                          : s1.wind_direction_deg,
                  );
    } else {
        windSpeedKmh = lerpNullable(
            s0.wind_speed_kmh,
            s1.wind_speed_kmh,
            alpha,
        );
        windDirectionDeg =
            alpha < 0.5
                ? (s0.wind_direction_deg ?? s1.wind_direction_deg)
                : (s1.wind_direction_deg ?? s0.wind_direction_deg);
    }

    const wmoCode =
        alpha < 0.5
            ? (s0.wmo_code ?? s1.wmo_code)
            : (s1.wmo_code ?? s0.wmo_code);

    return {
        temperature_c: lerpNullable(s0.temperature_c, s1.temperature_c, alpha),
        apparent_temperature_c: lerpNullable(
            s0.apparent_temperature_c,
            s1.apparent_temperature_c,
            alpha,
        ),
        humidity_pct: lerpNullable(s0.humidity_pct, s1.humidity_pct, alpha),
        dew_point_c: lerpNullable(s0.dew_point_c, s1.dew_point_c, alpha),
        wind_speed_kmh: windSpeedKmh,
        wind_gusts_kmh: lerpNullable(
            s0.wind_gusts_kmh,
            s1.wind_gusts_kmh,
            alpha,
        ),
        wind_direction_deg: windDirectionDeg,
        wind_direction_compass:
            windDirectionDeg !== null ? formatCompass(windDirectionDeg) : null,
        precipitation_mm: lerpNullable(
            s0.precipitation_mm,
            s1.precipitation_mm,
            alpha,
        ),
        cloud_cover_pct: lerpNullable(
            s0.cloud_cover_pct,
            s1.cloud_cover_pct,
            alpha,
        ),
        surface_pressure_hpa: lerpNullable(
            s0.surface_pressure_hpa,
            s1.surface_pressure_hpa,
            alpha,
        ),
        wmo_code: wmoCode,
    };
}

/**
 * Compute initial spherical forward bearing in degrees [0, 360) between two GNSS coordinates.
 */
export function computeSegmentBearing(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    const phi1 = degToRad(lat1);
    const phi2 = degToRad(lat2);
    const deltaLambda = degToRad(lng2 - lng1);

    const y = Math.sin(deltaLambda) * Math.cos(phi2);
    const x =
        Math.cos(phi1) * Math.sin(phi2) -
        Math.sin(phi1) * Math.cos(phi2) * Math.cos(deltaLambda);

    return normalizeDegrees((Math.atan2(y, x) * 180) / Math.PI);
}

/**
 * Project meteorological wind (blowing FROM `windFromDeg`) onto a GNSS travel bearing.
 * Positive `effectiveKmh` indicates tailwind; negative indicates headwind.
 */
export function computeEffectiveWind(
    windSpeedKmh: number,
    windFromDeg: number,
    routeBearingDeg: number,
): EffectiveWindResult {
    if (!Number.isFinite(windSpeedKmh) || windSpeedKmh <= 0) {
        return {
            effectiveKmh: 0,
            crosswindKmh: 0,
            classification: 'calm',
        };
    }

    const windToDeg = normalizeDegrees(windFromDeg + 180);
    const deltaDeg = ((windToDeg - routeBearingDeg + 540) % 360) - 180;
    const deltaRad = degToRad(deltaDeg);

    const effectiveKmh = windSpeedKmh * Math.cos(deltaRad);
    const crosswindKmh = Math.abs(windSpeedKmh * Math.sin(deltaRad));

    let classification: WindClassification;

    if (windSpeedKmh < CALM_WIND_THRESHOLD_KMH) {
        classification = 'calm';
    } else {
        const absDelta = Math.abs(deltaDeg);

        if (absDelta <= 45) {
            classification = 'tailwind';
        } else if (absDelta >= 135) {
            classification = 'headwind';
        } else {
            classification = 'crosswind';
        }
    }

    return {
        effectiveKmh,
        crosswindKmh,
        classification,
    };
}

/**
 * Map effective wind (negative = headwind, positive = tailwind) onto a diverging
 * rose (#f43f5e) -> amber (#f59e0b) -> emerald (#10b981) palette, with slate (#94a3b8) for calm air.
 */
export function interpolateWindColor(
    effectiveKmh: number,
    maxWindKmh: number,
    windSpeedKmh?: number,
): string {
    if (
        windSpeedKmh !== undefined &&
        (!Number.isFinite(windSpeedKmh) ||
            windSpeedKmh < CALM_WIND_THRESHOLD_KMH)
    ) {
        return CALM_COLOR_HEX;
    }

    if (!Number.isFinite(effectiveKmh)) {
        return CALM_COLOR_HEX;
    }

    const scale = Math.max(15, Number.isFinite(maxWindKmh) ? maxWindKmh : 15);
    const normalized = clamp(effectiveKmh / scale, -1, 1);

    if (normalized < 0) {
        return lerpRgbHex(AMBER_RGB, ROSE_RGB, Math.abs(normalized));
    }

    return lerpRgbHex(AMBER_RGB, EMERALD_RGB, normalized);
}

/**
 * Color directional wind arrows with a dedicated high-contrast meteorological palette
 * (cyan -> electric blue -> violet -> fuchsia) that stays visually distinct from route track colors.
 */
export function windSpeedArrowColor(
    windSpeedKmh: number,
    isDark = false,
): string {
    const stops = isDark ? WIND_ARROW_DARK_STOPS : WIND_ARROW_LIGHT_STOPS;

    if (!Number.isFinite(windSpeedKmh) || windSpeedKmh <= stops[0].kmh) {
        const [r, g, b] = stops[0].rgb;

        return rgbToHex(r, g, b);
    }

    for (let i = 0; i < stops.length - 1; i += 1) {
        const current = stops[i];
        const next = stops[i + 1];

        if (windSpeedKmh <= next.kmh) {
            return lerpRgbHex(
                current.rgb,
                next.rgb,
                (windSpeedKmh - current.kmh) / (next.kmh - current.kmh),
            );
        }
    }

    const [r, g, b] = stops[stops.length - 1].rgb;

    return rgbToHex(r, g, b);
}

/**
 * CSS linear-gradient matching `windSpeedArrowColor()` for light/dark map legends.
 */
export function windArrowLegendGradient(isDark = false): string {
    return isDark
        ? 'linear-gradient(90deg, #22d3ee 0%, #60a5fa 33%, #a78bfa 67%, #e879f9 100%)'
        : 'linear-gradient(90deg, #0891b2 0%, #2563eb 33%, #7c3aed 67%, #c026d3 100%)';
}
