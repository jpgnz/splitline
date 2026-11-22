<script setup lang="ts">
import type { Feature, FeatureCollection, LineString, Point } from 'geojson';
import {
    AttributionControl,
    LngLatBounds,
    Map as MapLibreMap,
    NavigationControl,
    Popup,
    type GeoJSONSource,
} from 'maplibre-gl';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    emptyFeatureCollection,
    ensureMapLibreWorker,
    getMapStyleUrl,
    toLngLat,
} from '@/lib/maplibre';
import { M_TO_FT, useUnits } from '@/lib/units';
import {
    computeEffectiveWind,
    computeSegmentBearing,
    formatCompass,
    interpolateWeatherAt,
    interpolateWindColor,
    windArrowLegendGradient,
    windSpeedArrowColor,
} from '@/lib/wind';
import type {
    ActivityWeatherPayload,
    HighlightRange,
    JumpEvent,
    MapColorMode,
    StreamPoint,
} from '@/types/activity';

export type { MapColorMode, StreamPoint };

const props = withDefaults(
    defineProps<{
        polyline: [number, number][];
        series?: StreamPoint[];
        colorMode?: MapColorMode;
        accentColor?: string;
        activeIndex?: number | null;
        highlightRange?: HighlightRange | null;
        jumps?: JumpEvent[];
        height?: number | string;
        weather?: ActivityWeatherPayload | null;
        showWindArrows?: boolean;
    }>(),
    {
        series: () => [],
        colorMode: 'solid',
        accentColor: '#f59e0b',
        activeIndex: null,
        highlightRange: null,
        jumps: () => [],
        height: 380,
        weather: null,
        showWindArrows: false,
    },
);

const emit = defineEmits<{
    hoverIndex: [index: number | null];
}>();

const WIND_ARROW_IMAGE_ID = 'wind-arrow';
const WIND_AMBIENT_SOURCE_ID = 'activity-wind-ambient';
const WIND_AMBIENT_LAYER_ID = 'activity-wind-ambient-arrows';
const ROUTE_SOURCE_ID = 'activity-route';
const ROUTE_LAYER_ID = 'activity-route-line';
const ROUTE_HIT_LAYER_ID = 'activity-route-hit';
const HIGHLIGHT_SOURCE_ID = 'activity-highlight-route';
const HIGHLIGHT_CASING_LAYER_ID = 'activity-highlight-casing';
const HIGHLIGHT_LAYER_ID = 'activity-highlight-line';
const HIGHLIGHT_ENDPOINTS_SOURCE_ID = 'activity-highlight-endpoints';
const HIGHLIGHT_ENDPOINTS_LAYER_ID = 'activity-highlight-endpoints-circles';
const JUMPS_SOURCE_ID = 'activity-jumps';
const JUMPS_LAYER_ID = 'activity-jumps-circles';
const ENDPOINTS_SOURCE_ID = 'activity-endpoints';
const ENDPOINTS_LAYER_ID = 'activity-endpoints-circles';
const HOVER_SOURCE_ID = 'activity-hover-point';
const HOVER_LAYER_ID = 'activity-hover-circle';

const TELEMETRY_STOPS: Array<[number, number, number]> = [
    [14, 165, 233], // #0ea5e9 sky-500
    [16, 185, 129], // #10b981 emerald-500
    [245, 158, 11], // #f59e0b amber-500
    [244, 63, 94], // #f43f5e rose-500
];

const EARTH_RADIUS_METERS = 6371000;
const WIND_ARROW_CANVAS_SIZE = 64;
const WIND_ARROW_SDF_RADIUS = 10;
const WIND_GRID_MARGIN_X = 34;
const WIND_GRID_MARGIN_Y = 30;
const WIND_GRID_TARGET_SPACING_X = 110;
const WIND_GRID_TARGET_SPACING_Y = 88;

let cachedWindArrowImageData: ImageData | null = null;

const containerRef = ref<HTMLElement | null>(null);
const { isDark } = useChartTheme();
const { units, isImperial, formatElevation, formatSpeed, formatSpeedKmh } =
    useUnits();

let map: MapLibreMap | null = null;
let endpointPopup: Popup | null = null;
let activePopupSource: 'endpoint' | 'jump' | 'wind' | null = null;
let windRefreshFrameId: number | null = null;
let resizeObserver: ResizeObserver | null = null;
let styleReady = false;

const containerStyle = computed(() => ({
    height:
        typeof props.height === 'number' ? `${props.height}px` : props.height,
}));

function getMetricValue(point: StreamPoint, mode: MapColorMode): number | null {
    switch (mode) {
        case 'speed':
            return point.speed !== null && Number.isFinite(point.speed)
                ? point.speed
                : null;
        case 'hr':
            return point.hr !== null && Number.isFinite(point.hr)
                ? point.hr
                : null;
        case 'altitude':
            return point.altitude !== null && Number.isFinite(point.altitude)
                ? point.altitude
                : null;
        case 'power':
            return point.power !== null && Number.isFinite(point.power)
                ? point.power
                : null;
        case 'stamina':
            return point.stamina !== null &&
                point.stamina !== undefined &&
                Number.isFinite(point.stamina)
                ? point.stamina
                : null;
        case 'grit':
            return point.grit !== null &&
                point.grit !== undefined &&
                Number.isFinite(point.grit)
                ? point.grit
                : null;
        case 'flow':
            return point.flow !== null &&
                point.flow !== undefined &&
                Number.isFinite(point.flow)
                ? point.flow
                : null;
        default:
            return null;
    }
}

function interpolateTelemetryColor(ratio: number): string {
    const clamped = Math.max(0, Math.min(1, ratio));
    const scaled = clamped * (TELEMETRY_STOPS.length - 1);
    const index = Math.min(Math.floor(scaled), TELEMETRY_STOPS.length - 2);
    const localT = scaled - index;

    const start = TELEMETRY_STOPS[index];
    const end = TELEMETRY_STOPS[index + 1];

    const r = Math.round(start[0] + (end[0] - start[0]) * localT);
    const g = Math.round(start[1] + (end[1] - start[1]) * localT);
    const b = Math.round(start[2] + (end[2] - start[2]) * localT);

    return `rgb(${r}, ${g}, ${b})`;
}

interface MetricRange {
    min: number;
    max: number;
}

const gnssSeriesPoints = computed<
    Array<{ index: number; point: StreamPoint; latLng: [number, number] }>
>(() => {
    const list: Array<{
        index: number;
        point: StreamPoint;
        latLng: [number, number];
    }> = [];

    for (let i = 0; i < props.series.length; i += 1) {
        const point = props.series[i];

        if (
            point.lat !== null &&
            point.lng !== null &&
            Number.isFinite(point.lat) &&
            Number.isFinite(point.lng)
        ) {
            list.push({
                index: i,
                point,
                latLng: [point.lat, point.lng],
            });
        }
    }

    return list;
});

function haversineMeters(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    const phi1 = (lat1 * Math.PI) / 180;
    const phi2 = (lat2 * Math.PI) / 180;
    const dPhi = ((lat2 - lat1) * Math.PI) / 180;
    const dLambda = ((lng2 - lng1) * Math.PI) / 180;

    const a =
        Math.sin(dPhi / 2) * Math.sin(dPhi / 2) +
        Math.cos(phi1) *
            Math.cos(phi2) *
            Math.sin(dLambda / 2) *
            Math.sin(dLambda / 2);

    return 2 * EARTH_RADIUS_METERS * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function getWindArrowImageData(): ImageData | null {
    if (cachedWindArrowImageData) {
        return cachedWindArrowImageData;
    }

    if (typeof document === 'undefined') {
        return null;
    }

    const size = WIND_ARROW_CANVAS_SIZE;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (!ctx) {
        return null;
    }

    ctx.clearRect(0, 0, size, size);
    ctx.fillStyle = '#ffffff';
    ctx.beginPath();
    ctx.moveTo(32, 10);
    ctx.lineTo(47, 29);
    ctx.lineTo(36.5, 26.5);
    ctx.lineTo(36.5, 53);
    ctx.lineTo(27.5, 53);
    ctx.lineTo(27.5, 26.5);
    ctx.lineTo(17, 29);
    ctx.closePath();
    ctx.fill();

    const raw = ctx.getImageData(0, 0, size, size);
    const inside = new Uint8Array(size * size);

    for (let i = 0; i < size * size; i += 1) {
        inside[i] = raw.data[i * 4 + 3] >= 128 ? 1 : 0;
    }

    const sdfData = new Uint8ClampedArray(size * size * 4);
    const radius = WIND_ARROW_SDF_RADIUS;
    const radiusSq = radius * radius;

    for (let y = 0; y < size; y += 1) {
        const yMin = Math.max(0, y - radius);
        const yMax = Math.min(size - 1, y + radius);

        for (let x = 0; x < size; x += 1) {
            const idx = y * size + x;
            const isIn = inside[idx];
            const xMin = Math.max(0, x - radius);
            const xMax = Math.min(size - 1, x + radius);
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
                Math.min(1, 0.75 + (signedDist / radius) * 0.75),
            );
            const offset = idx * 4;
            sdfData[offset] = 255;
            sdfData[offset + 1] = 255;
            sdfData[offset + 2] = 255;
            sdfData[offset + 3] = Math.round(normalized * 255);
        }
    }

    cachedWindArrowImageData = new ImageData(sdfData, size, size);

    return cachedWindArrowImageData;
}

function computeWindArrowSize(speedKmh: number): number {
    if (!Number.isFinite(speedKmh) || speedKmh <= 0) {
        return 0.62;
    }

    return 0.62 + Math.min(1, speedKmh / 35) * 0.3;
}

function computeSmoothedBearing(
    pts: Array<{ index: number; point: StreamPoint; latLng: [number, number] }>,
    segmentIndex: number,
): number {
    const startIdx = Math.max(0, Math.min(segmentIndex, pts.length - 2));
    const endIdx = startIdx + 1;

    let backIdx = startIdx;
    let aheadIdx = endIdx;

    const baseDist = pts[startIdx].point.distance;
    const endDist = pts[endIdx].point.distance;
    const useStreamDist =
        baseDist !== null &&
        endDist !== null &&
        Number.isFinite(baseDist) &&
        Number.isFinite(endDist);

    let backSteps = 0;
    let backAccum = 0;

    while (backIdx > 0 && backSteps < 20) {
        const prevIdx = backIdx - 1;

        if (
            useStreamDist &&
            pts[prevIdx].point.distance !== null &&
            Number.isFinite(pts[prevIdx].point.distance)
        ) {
            backAccum = baseDist - (pts[prevIdx].point.distance as number);
        } else {
            backAccum += haversineMeters(
                pts[prevIdx].latLng[0],
                pts[prevIdx].latLng[1],
                pts[backIdx].latLng[0],
                pts[backIdx].latLng[1],
            );
        }

        backIdx = prevIdx;
        backSteps += 1;

        if (backAccum >= 30) {
            break;
        }
    }

    let aheadSteps = 0;
    let aheadAccum = 0;

    while (aheadIdx < pts.length - 1 && aheadSteps < 20) {
        const nextIdx = aheadIdx + 1;

        if (
            useStreamDist &&
            pts[nextIdx].point.distance !== null &&
            Number.isFinite(pts[nextIdx].point.distance)
        ) {
            aheadAccum = (pts[nextIdx].point.distance as number) - endDist;
        } else {
            aheadAccum += haversineMeters(
                pts[aheadIdx].latLng[0],
                pts[aheadIdx].latLng[1],
                pts[nextIdx].latLng[0],
                pts[nextIdx].latLng[1],
            );
        }

        aheadIdx = nextIdx;
        aheadSteps += 1;

        if (aheadAccum >= 30) {
            break;
        }
    }

    const spanMeters = haversineMeters(
        pts[backIdx].latLng[0],
        pts[backIdx].latLng[1],
        pts[aheadIdx].latLng[0],
        pts[aheadIdx].latLng[1],
    );

    if (spanMeters >= 2) {
        return computeSegmentBearing(
            pts[backIdx].latLng[0],
            pts[backIdx].latLng[1],
            pts[aheadIdx].latLng[0],
            pts[aheadIdx].latLng[1],
        );
    }

    return computeSegmentBearing(
        pts[startIdx].latLng[0],
        pts[startIdx].latLng[1],
        pts[endIdx].latLng[0],
        pts[endIdx].latLng[1],
    );
}

interface WindFieldSample {
    lat: number;
    lng: number;
    wind_speed_kmh: number;
    wind_direction_deg: number;
    wind_gusts_kmh: number | null;
}

const hasWeatherSamples = computed<boolean>(
    () => (props.weather?.samples?.length ?? 0) > 0,
);

const validWindSamples = computed<WindFieldSample[]>(() => {
    const samples = props.weather?.samples ?? [];
    const valid: WindFieldSample[] = [];

    for (const sample of samples) {
        if (
            sample.wind_speed_kmh !== null &&
            Number.isFinite(sample.wind_speed_kmh) &&
            sample.wind_direction_deg !== null &&
            Number.isFinite(sample.wind_direction_deg) &&
            Number.isFinite(sample.lat) &&
            Number.isFinite(sample.lng)
        ) {
            valid.push({
                lat: sample.lat,
                lng: sample.lng,
                wind_speed_kmh: sample.wind_speed_kmh,
                wind_direction_deg: sample.wind_direction_deg,
                wind_gusts_kmh:
                    sample.wind_gusts_kmh !== null &&
                    Number.isFinite(sample.wind_gusts_kmh)
                        ? sample.wind_gusts_kmh
                        : null,
            });
        }
    }

    if (
        valid.length === 0 &&
        props.weather &&
        props.weather.wind_speed_kmh !== null &&
        Number.isFinite(props.weather.wind_speed_kmh) &&
        props.weather.wind_direction_deg !== null &&
        Number.isFinite(props.weather.wind_direction_deg)
    ) {
        const boundsPoints = getBoundsPoints();
        const anchor =
            boundsPoints.length > 0
                ? boundsPoints[Math.floor(boundsPoints.length / 2)]
                : ([0, 0] as [number, number]);

        valid.push({
            lat: anchor[0],
            lng: anchor[1],
            wind_speed_kmh: props.weather.wind_speed_kmh,
            wind_direction_deg: props.weather.wind_direction_deg,
            wind_gusts_kmh:
                props.weather.wind_gusts_kmh !== null &&
                Number.isFinite(props.weather.wind_gusts_kmh)
                    ? props.weather.wind_gusts_kmh
                    : null,
        });
    }

    return valid;
});

const hasWindFieldData = computed<boolean>(
    () => validWindSamples.value.length > 0,
);

const windMaxScaleKmh = computed<number>(() => {
    const samples = props.weather?.samples ?? [];
    let peak = props.weather?.wind_speed_kmh ?? 0;

    for (const sample of samples) {
        if (
            sample.wind_speed_kmh !== null &&
            Number.isFinite(sample.wind_speed_kmh) &&
            sample.wind_speed_kmh > peak
        ) {
            peak = sample.wind_speed_kmh;
        }
    }

    return Math.max(15, peak);
});

const activeMetricRange = computed<MetricRange | null>(() => {
    if (
        props.colorMode === 'solid' ||
        props.colorMode === 'wind' ||
        gnssSeriesPoints.value.length < 2
    ) {
        return null;
    }

    let min = Number.POSITIVE_INFINITY;
    let max = Number.NEGATIVE_INFINITY;
    let count = 0;

    for (const item of gnssSeriesPoints.value) {
        const val = getMetricValue(item.point, props.colorMode);

        if (val !== null) {
            if (val < min) {
                min = val;
            }

            if (val > max) {
                max = val;
            }

            count += 1;
        }
    }

    if (count < 2 || !Number.isFinite(min) || !Number.isFinite(max)) {
        return null;
    }

    return { min, max };
});

function formatLegendValue(value: number, mode: MapColorMode): string {
    switch (mode) {
        case 'speed':
            return formatSpeed(value, 1);
        case 'hr':
            return `${Math.round(value)} bpm`;
        case 'altitude':
            return formatElevation(value);
        case 'power':
            return `${Math.round(value)} W`;
        case 'stamina':
            return `${Math.round(value)}%`;
        case 'grit':
            return value.toFixed(1);
        case 'flow':
            return value.toFixed(1);
        default:
            return String(value);
    }
}

const legendLabel = computed<string>(() => {
    switch (props.colorMode) {
        case 'speed':
            return 'Speed';
        case 'hr':
            return 'HR';
        case 'altitude':
            return 'Elev';
        case 'power':
            return 'Power';
        case 'stamina':
            return 'Stamina';
        case 'grit':
            return 'Grit';
        case 'flow':
            return 'Flow';
        default:
            return '';
    }
});

const windArrowsLegendDisplay = computed<{
    gradient: string;
    maxLabel: string;
} | null>(() => {
    if (!props.showWindArrows || !hasWindFieldData.value) {
        return null;
    }

    return {
        gradient: windArrowLegendGradient(isDark.value),
        maxLabel: `${formatSpeedKmh(40, 0)}+`,
    };
});

const windLegendDisplay = computed<{ head: string; tail: string } | null>(
    () => {
        if (props.colorMode !== 'wind' || !hasWeatherSamples.value) {
            return null;
        }

        const scale = windMaxScaleKmh.value;

        return {
            head: `-${formatSpeedKmh(scale, 0)}`,
            tail: `+${formatSpeedKmh(scale, 0)}`,
        };
    },
);

const legendDisplay = computed<{ min: string; max: string } | null>(() => {
    if (
        !activeMetricRange.value ||
        props.colorMode === 'solid' ||
        props.colorMode === 'wind'
    ) {
        return null;
    }

    return {
        min: formatLegendValue(activeMetricRange.value.min, props.colorMode),
        max: formatLegendValue(activeMetricRange.value.max, props.colorMode),
    };
});

function findNearestSeriesIndex(lat: number, lng: number): number | null {
    const gnssPoints = gnssSeriesPoints.value;

    if (gnssPoints.length === 0) {
        return null;
    }

    let bestIndex = gnssPoints[0].index;
    let bestDistSq = Number.POSITIVE_INFINITY;

    for (const item of gnssPoints) {
        const dLat = item.latLng[0] - lat;
        const dLng = item.latLng[1] - lng;
        const distSq = dLat * dLat + dLng * dLng;

        if (distSq < bestDistSq) {
            bestDistSq = distSq;
            bestIndex = item.index;
        }
    }

    return bestIndex;
}

function getBoundsPoints(): [number, number][] {
    if (props.polyline.length > 0) {
        return props.polyline;
    }

    return gnssSeriesPoints.value.map((item) => item.latLng);
}

function computeLngLatBounds(points: [number, number][]): LngLatBounds | null {
    if (points.length === 0) {
        return null;
    }

    const bounds = new LngLatBounds();

    for (const point of points) {
        bounds.extend(toLngLat(point));
    }

    return bounds.isEmpty() ? null : bounds;
}

function buildRouteGeoJson(): {
    data: FeatureCollection<LineString>;
    lineWidth: number;
} {
    const boundsPoints = getBoundsPoints();

    if (boundsPoints.length < 2) {
        return {
            data: { type: 'FeatureCollection', features: [] },
            lineWidth: 3.5,
        };
    }

    if (
        props.colorMode === 'wind' &&
        hasWeatherSamples.value &&
        gnssSeriesPoints.value.length >= 2 &&
        props.weather
    ) {
        const samples = props.weather.samples;
        const pts = gnssSeriesPoints.value;
        const maxScale = windMaxScaleKmh.value;
        const features: Array<Feature<LineString>> = [];

        for (let i = 0; i < pts.length - 1; i += 1) {
            const current = pts[i];
            const next = pts[i + 1];
            const midTime = (current.point.time + next.point.time) / 2;
            const midDist =
                current.point.distance !== null && next.point.distance !== null
                    ? (current.point.distance + next.point.distance) / 2
                    : (current.point.distance ?? next.point.distance);
            const interpolated = interpolateWeatherAt(
                samples,
                midTime,
                midDist,
            );

            let color = '#94a3b8';

            if (
                interpolated &&
                interpolated.wind_speed_kmh !== null &&
                interpolated.wind_direction_deg !== null
            ) {
                const routeBearing = computeSmoothedBearing(pts, i);
                const effective = computeEffectiveWind(
                    interpolated.wind_speed_kmh,
                    interpolated.wind_direction_deg,
                    routeBearing,
                );
                color = interpolateWindColor(
                    effective.effectiveKmh,
                    maxScale,
                    interpolated.wind_speed_kmh,
                );
            }

            features.push({
                type: 'Feature',
                properties: {
                    color,
                    seriesIndex: current.index,
                },
                geometry: {
                    type: 'LineString',
                    coordinates: [
                        toLngLat(current.latLng),
                        toLngLat(next.latLng),
                    ],
                },
            });
        }

        return {
            data: { type: 'FeatureCollection', features },
            lineWidth: 4,
        };
    }

    const useColoredSegments =
        props.colorMode !== 'solid' &&
        props.colorMode !== 'wind' &&
        activeMetricRange.value !== null &&
        gnssSeriesPoints.value.length >= 2;

    if (useColoredSegments && activeMetricRange.value) {
        const { min, max } = activeMetricRange.value;
        const span = max - min;
        const pts = gnssSeriesPoints.value;
        const features: Array<Feature<LineString>> = [];

        for (let i = 0; i < pts.length - 1; i += 1) {
            const current = pts[i];
            const next = pts[i + 1];
            const v1 = getMetricValue(current.point, props.colorMode);
            const v2 = getMetricValue(next.point, props.colorMode);

            let segValue: number | null = null;

            if (v1 !== null && v2 !== null) {
                segValue = (v1 + v2) / 2;
            } else if (v1 !== null) {
                segValue = v1;
            } else if (v2 !== null) {
                segValue = v2;
            }

            const ratio =
                segValue === null
                    ? 0.5
                    : span > 0
                      ? (segValue - min) / span
                      : 0.5;
            const color =
                segValue === null
                    ? props.accentColor
                    : interpolateTelemetryColor(ratio);

            features.push({
                type: 'Feature',
                properties: {
                    color,
                    seriesIndex: current.index,
                },
                geometry: {
                    type: 'LineString',
                    coordinates: [
                        toLngLat(current.latLng),
                        toLngLat(next.latLng),
                    ],
                },
            });
        }

        return {
            data: { type: 'FeatureCollection', features },
            lineWidth: 4,
        };
    }

    return {
        data: {
            type: 'FeatureCollection',
            features: [
                {
                    type: 'Feature',
                    properties: {
                        color: props.accentColor,
                    },
                    geometry: {
                        type: 'LineString',
                        coordinates: boundsPoints.map(toLngLat),
                    },
                },
            ],
        },
        lineWidth: 3.5,
    };
}

function buildHighlightGeoJson(): {
    line: FeatureCollection<LineString>;
    endpoints: FeatureCollection<Point>;
} {
    const range = props.highlightRange;

    if (!range || gnssSeriesPoints.value.length < 2) {
        return {
            line: { type: 'FeatureCollection', features: [] },
            endpoints: { type: 'FeatureCollection', features: [] },
        };
    }

    const coords: [number, number][] = [];

    for (const item of gnssSeriesPoints.value) {
        const dist = item.point.distance;

        if (
            dist !== null &&
            dist >= range.startDistance &&
            dist <= range.endDistance
        ) {
            coords.push(toLngLat(item.latLng));
        }
    }

    if (coords.length < 2) {
        return {
            line: { type: 'FeatureCollection', features: [] },
            endpoints: { type: 'FeatureCollection', features: [] },
        };
    }

    return {
        line: {
            type: 'FeatureCollection',
            features: [
                {
                    type: 'Feature',
                    properties: {
                        label: range.label,
                    },
                    geometry: {
                        type: 'LineString',
                        coordinates: coords,
                    },
                },
            ],
        },
        endpoints: {
            type: 'FeatureCollection',
            features: [
                {
                    type: 'Feature',
                    properties: {
                        label: `${range.label} Start`,
                        color: '#10b981',
                    },
                    geometry: {
                        type: 'Point',
                        coordinates: coords[0],
                    },
                },
                {
                    type: 'Feature',
                    properties: {
                        label: `${range.label} Finish`,
                        color: '#ea580c',
                    },
                    geometry: {
                        type: 'Point',
                        coordinates: coords[coords.length - 1],
                    },
                },
            ],
        },
    };
}

function buildJumpsGeoJson(): FeatureCollection<Point> {
    if (!props.jumps || props.jumps.length === 0) {
        return { type: 'FeatureCollection', features: [] };
    }

    const features: Array<Feature<Point>> = [];

    for (let i = 0; i < props.jumps.length; i += 1) {
        const jump = props.jumps[i];

        if (
            jump.lat === null ||
            jump.lng === null ||
            !Number.isFinite(jump.lat) ||
            !Number.isFinite(jump.lng)
        ) {
            continue;
        }

        const speedText = jump.speed !== null ? formatSpeed(jump.speed, 1) : '';
        const distText = isImperial.value
            ? `${(jump.distance_meters * M_TO_FT).toFixed(1)} ft`
            : `${jump.distance_meters.toFixed(1)} m`;
        const label = `Jump #${i + 1} · ${distText} · ${jump.hang_time_seconds.toFixed(2)}s${speedText ? ` · ${speedText}` : ''}`;

        features.push({
            type: 'Feature',
            properties: { label },
            geometry: {
                type: 'Point',
                coordinates: [jump.lng, jump.lat],
            },
        });
    }

    return {
        type: 'FeatureCollection',
        features,
    };
}

function buildEndpointsGeoJson(): FeatureCollection<Point> {
    const boundsPoints = getBoundsPoints();

    if (boundsPoints.length === 0) {
        return { type: 'FeatureCollection', features: [] };
    }

    const features: Array<Feature<Point>> = [
        {
            type: 'Feature',
            properties: {
                label: 'Start',
                color: '#10b981',
            },
            geometry: {
                type: 'Point',
                coordinates: toLngLat(boundsPoints[0]),
            },
        },
    ];

    if (boundsPoints.length > 1) {
        features.push({
            type: 'Feature',
            properties: {
                label: 'Finish',
                color: '#f43f5e',
            },
            geometry: {
                type: 'Point',
                coordinates: toLngLat(boundsPoints[boundsPoints.length - 1]),
            },
        });
    }

    return {
        type: 'FeatureCollection',
        features,
    };
}

function buildHoverGeoJson(index: number | null): FeatureCollection<Point> {
    if (index === null || index < 0 || index >= props.series.length) {
        return { type: 'FeatureCollection', features: [] };
    }

    let point = props.series[index];

    if (point.lat === null || point.lng === null) {
        let nearest: StreamPoint | null = null;
        let bestDelta = Number.POSITIVE_INFINITY;

        for (const item of gnssSeriesPoints.value) {
            const delta = Math.abs(item.index - index);

            if (delta < bestDelta) {
                bestDelta = delta;
                nearest = item.point;
            }
        }

        if (!nearest || nearest.lat === null || nearest.lng === null) {
            return { type: 'FeatureCollection', features: [] };
        }

        point = nearest;
    }

    return {
        type: 'FeatureCollection',
        features: [
            {
                type: 'Feature',
                properties: {},
                geometry: {
                    type: 'Point',
                    coordinates: [point.lng!, point.lat!],
                },
            },
        ],
    };
}

function interpolateWindFieldPoint(
    samples: WindFieldSample[],
    lat: number,
    lng: number,
): {
    bearing: number;
    speed: number;
    gusts: number | null;
    compass: string;
} | null {
    if (samples.length === 0) {
        return null;
    }

    if (samples.length === 1) {
        const only = samples[0];
        const windFromDeg = ((only.wind_direction_deg % 360) + 360) % 360;

        return {
            bearing: (windFromDeg + 180) % 360,
            speed: only.wind_speed_kmh,
            gusts: only.wind_gusts_kmh,
            compass: formatCompass(windFromDeg),
        };
    }

    let weightSum = 0;
    let uSum = 0;
    let vSum = 0;
    let gustWeightSum = 0;
    let gustSum = 0;

    for (const sample of samples) {
        const dist = Math.max(
            10,
            haversineMeters(lat, lng, sample.lat, sample.lng),
        );
        const weight = 1 / (dist * dist);
        const rad = (sample.wind_direction_deg * Math.PI) / 180;
        const u = -sample.wind_speed_kmh * Math.sin(rad);
        const v = -sample.wind_speed_kmh * Math.cos(rad);

        weightSum += weight;
        uSum += weight * u;
        vSum += weight * v;

        if (sample.wind_gusts_kmh !== null) {
            gustWeightSum += weight;
            gustSum += weight * sample.wind_gusts_kmh;
        }
    }

    if (weightSum <= 0) {
        return null;
    }

    const uAvg = uSum / weightSum;
    const vAvg = vSum / weightSum;
    const speed = Math.hypot(uAvg, vAvg);
    const windFromDeg =
        speed > 1e-6
            ? ((((Math.atan2(-uAvg, -vAvg) * 180) / Math.PI) % 360) + 360) % 360
            : ((samples[0].wind_direction_deg % 360) + 360) % 360;

    return {
        bearing: (windFromDeg + 180) % 360,
        speed,
        gusts: gustWeightSum > 0 ? gustSum / gustWeightSum : null,
        compass: formatCompass(windFromDeg),
    };
}

function buildAmbientWindArrowsGeoJson(): FeatureCollection<Point> {
    if (!props.showWindArrows || !hasWindFieldData.value || !map) {
        return { type: 'FeatureCollection', features: [] };
    }

    const samples = validWindSamples.value;
    const dark = isDark.value;
    const gridCoords: Array<[number, number]> = [];

    const canvas = map.getCanvas();
    const width = canvas.clientWidth || containerRef.value?.clientWidth || 0;
    const height = canvas.clientHeight || containerRef.value?.clientHeight || 0;

    if (width > 0 && height > 0) {
        const marginX = Math.min(WIND_GRID_MARGIN_X, width * 0.12);
        const marginY = Math.min(WIND_GRID_MARGIN_Y, height * 0.14);
        const usableW = Math.max(0, width - marginX * 2);
        const usableH = Math.max(0, height - marginY * 2);

        const cols = Math.max(
            4,
            Math.min(9, Math.round(usableW / WIND_GRID_TARGET_SPACING_X) + 1),
        );
        const rows = Math.max(
            3,
            Math.min(6, Math.round(usableH / WIND_GRID_TARGET_SPACING_Y) + 1),
        );

        for (let row = 0; row < rows; row += 1) {
            const py =
                rows === 1
                    ? height / 2
                    : marginY + (row / (rows - 1)) * usableH;

            for (let col = 0; col < cols; col += 1) {
                const px =
                    cols === 1
                        ? width / 2
                        : marginX + (col / (cols - 1)) * usableW;
                const lngLat = map.unproject([px, py]);

                if (
                    Number.isFinite(lngLat.lat) &&
                    Number.isFinite(lngLat.lng) &&
                    Math.abs(lngLat.lat) <= 85
                ) {
                    gridCoords.push([lngLat.lng, lngLat.lat]);
                }
            }
        }
    } else {
        const bounds = map.getBounds();
        const south = Math.max(-85, bounds.getSouth());
        const north = Math.min(85, bounds.getNorth());
        const west = bounds.getWest();
        const east = bounds.getEast();
        const latSpan = north - south;
        const lngSpan = east - west;

        if (latSpan > 0 && lngSpan > 0) {
            const rows = 4;
            const cols = 7;

            for (let row = 0; row < rows; row += 1) {
                const lat =
                    south + (0.08 + (row / (rows - 1)) * 0.84) * latSpan;

                for (let col = 0; col < cols; col += 1) {
                    const lng =
                        west + (0.08 + (col / (cols - 1)) * 0.84) * lngSpan;
                    gridCoords.push([lng, lat]);
                }
            }
        }
    }

    const features: Array<Feature<Point>> = [];

    for (const [lng, lat] of gridCoords) {
        const interpolated = interpolateWindFieldPoint(samples, lat, lng);

        if (!interpolated) {
            continue;
        }

        features.push({
            type: 'Feature',
            properties: {
                bearing: interpolated.bearing,
                speed: interpolated.speed,
                gusts: interpolated.gusts,
                compass: interpolated.compass,
                iconSize: computeWindArrowSize(interpolated.speed),
                iconColor: windSpeedArrowColor(interpolated.speed, dark),
            },
            geometry: {
                type: 'Point',
                coordinates: [lng, lat],
            },
        });
    }

    return {
        type: 'FeatureCollection',
        features,
    };
}

function markerStrokeColor(): string {
    return isDark.value ? '#141417' : '#ffffff';
}

function windArrowHaloColor(): string {
    return isDark.value ? '#09090b' : '#ffffff';
}

function windArrowOpacity(): number {
    return isDark.value ? 0.68 : 0.62;
}

function ensureOverlayLayers(): void {
    if (!map) {
        return;
    }

    const strokeColor = markerStrokeColor();

    if (!map.hasImage(WIND_ARROW_IMAGE_ID)) {
        const arrowImage = getWindArrowImageData();

        if (arrowImage) {
            map.addImage(WIND_ARROW_IMAGE_ID, arrowImage, {
                sdf: true,
                pixelRatio: 2,
            });
        }
    }

    if (!map.getSource(WIND_AMBIENT_SOURCE_ID)) {
        map.addSource(WIND_AMBIENT_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(WIND_AMBIENT_LAYER_ID)) {
        map.addLayer(
            {
                id: WIND_AMBIENT_LAYER_ID,
                type: 'symbol',
                source: WIND_AMBIENT_SOURCE_ID,
                layout: {
                    'icon-image': WIND_ARROW_IMAGE_ID,
                    'icon-rotate': ['get', 'bearing'],
                    'icon-rotation-alignment': 'map',
                    'icon-size': ['get', 'iconSize'],
                    'icon-allow-overlap': true,
                    'icon-ignore-placement': true,
                    visibility: 'none',
                },
                paint: {
                    'icon-color': ['get', 'iconColor'],
                    'icon-halo-color': windArrowHaloColor(),
                    'icon-halo-width': 1.2,
                    'icon-halo-blur': 0.3,
                    'icon-opacity': windArrowOpacity(),
                },
            },
            map.getLayer(ROUTE_LAYER_ID) ? ROUTE_LAYER_ID : undefined,
        );
    }

    if (!map.getSource(ROUTE_SOURCE_ID)) {
        map.addSource(ROUTE_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(ROUTE_LAYER_ID)) {
        map.addLayer({
            id: ROUTE_LAYER_ID,
            type: 'line',
            source: ROUTE_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 3.5,
                'line-opacity': 0.92,
            },
        });
    }

    if (!map.getLayer(ROUTE_HIT_LAYER_ID)) {
        map.addLayer({
            id: ROUTE_HIT_LAYER_ID,
            type: 'line',
            source: ROUTE_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': '#000000',
                'line-width': 16,
                'line-opacity': 0,
            },
        });
    }

    if (!map.getSource(HIGHLIGHT_SOURCE_ID)) {
        map.addSource(HIGHLIGHT_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(HIGHLIGHT_CASING_LAYER_ID)) {
        map.addLayer({
            id: HIGHLIGHT_CASING_LAYER_ID,
            type: 'line',
            source: HIGHLIGHT_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': strokeColor,
                'line-width': 9,
                'line-opacity': 0.95,
            },
        });
    }

    if (!map.getLayer(HIGHLIGHT_LAYER_ID)) {
        map.addLayer({
            id: HIGHLIGHT_LAYER_ID,
            type: 'line',
            source: HIGHLIGHT_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': '#ea580c',
                'line-width': 5.5,
                'line-opacity': 1,
            },
        });
    }

    if (!map.getSource(HIGHLIGHT_ENDPOINTS_SOURCE_ID)) {
        map.addSource(HIGHLIGHT_ENDPOINTS_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(HIGHLIGHT_ENDPOINTS_LAYER_ID)) {
        map.addLayer({
            id: HIGHLIGHT_ENDPOINTS_LAYER_ID,
            type: 'circle',
            source: HIGHLIGHT_ENDPOINTS_SOURCE_ID,
            paint: {
                'circle-radius': 6,
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 2.5,
                'circle-stroke-color': strokeColor,
            },
        });
    }

    if (!map.getSource(JUMPS_SOURCE_ID)) {
        map.addSource(JUMPS_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(JUMPS_LAYER_ID)) {
        map.addLayer({
            id: JUMPS_LAYER_ID,
            type: 'circle',
            source: JUMPS_SOURCE_ID,
            paint: {
                'circle-radius': 6.5,
                'circle-color': '#6366f1',
                'circle-stroke-width': 2,
                'circle-stroke-color': strokeColor,
            },
        });
    }

    if (!map.getSource(ENDPOINTS_SOURCE_ID)) {
        map.addSource(ENDPOINTS_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(ENDPOINTS_LAYER_ID)) {
        map.addLayer({
            id: ENDPOINTS_LAYER_ID,
            type: 'circle',
            source: ENDPOINTS_SOURCE_ID,
            paint: {
                'circle-radius': 6,
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 2,
                'circle-stroke-color': strokeColor,
            },
        });
    }

    if (!map.getSource(HOVER_SOURCE_ID)) {
        map.addSource(HOVER_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(HOVER_LAYER_ID)) {
        map.addLayer({
            id: HOVER_LAYER_ID,
            type: 'circle',
            source: HOVER_SOURCE_ID,
            paint: {
                'circle-radius': 7,
                'circle-color': props.accentColor,
                'circle-stroke-width': 2.5,
                'circle-stroke-color': strokeColor,
            },
        });
    }
}

function fitRouteBounds(): void {
    if (!map) {
        return;
    }

    const bounds = computeLngLatBounds(getBoundsPoints());

    if (bounds) {
        map.fitBounds(bounds, {
            padding: 28,
            maxZoom: 16,
            duration: 0,
        });
    }
}

function updateWindArrows(): void {
    if (!map || !styleReady) {
        return;
    }

    const showWind = props.showWindArrows && hasWindFieldData.value;
    const windVisibility = showWind ? 'visible' : 'none';

    const windAmbientSource = map.getSource(WIND_AMBIENT_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    windAmbientSource?.setData(
        showWind ? buildAmbientWindArrowsGeoJson() : emptyFeatureCollection(),
    );

    if (map.getLayer(WIND_AMBIENT_LAYER_ID)) {
        map.setLayoutProperty(
            WIND_AMBIENT_LAYER_ID,
            'visibility',
            windVisibility,
        );
        map.setPaintProperty(
            WIND_AMBIENT_LAYER_ID,
            'icon-halo-color',
            windArrowHaloColor(),
        );
        map.setPaintProperty(
            WIND_AMBIENT_LAYER_ID,
            'icon-opacity',
            windArrowOpacity(),
        );
    }

    if (!showWind && activePopupSource === 'wind') {
        endpointPopup?.remove();
        activePopupSource = null;
    }
}

function scheduleWindArrowsRefresh(): void {
    if (!props.showWindArrows || !hasWindFieldData.value) {
        return;
    }

    if (typeof window === 'undefined') {
        updateWindArrows();
        return;
    }

    if (windRefreshFrameId !== null) {
        window.cancelAnimationFrame(windRefreshFrameId);
    }

    windRefreshFrameId = window.requestAnimationFrame(() => {
        windRefreshFrameId = null;
        updateWindArrows();
    });
}

function renderRoute(fitBounds = false): void {
    if (!map) {
        return;
    }

    if (fitBounds) {
        fitRouteBounds();
    }

    if (!styleReady) {
        return;
    }

    ensureOverlayLayers();

    const { data: routeData, lineWidth } = buildRouteGeoJson();
    const routeSource = map.getSource(ROUTE_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    routeSource?.setData(routeData);

    if (map.getLayer(ROUTE_LAYER_ID)) {
        map.setPaintProperty(ROUTE_LAYER_ID, 'line-width', lineWidth);
        map.setPaintProperty(
            ROUTE_LAYER_ID,
            'line-color',
            props.highlightRange
                ? isDark.value
                    ? '#64748b'
                    : '#94a3b8'
                : ['get', 'color'],
        );
        map.setPaintProperty(
            ROUTE_LAYER_ID,
            'line-opacity',
            props.highlightRange ? 0.55 : 0.92,
        );
    }

    const highlight = buildHighlightGeoJson();
    const highlightSource = map.getSource(HIGHLIGHT_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    highlightSource?.setData(highlight.line);

    const highlightEndpointsSource = map.getSource(
        HIGHLIGHT_ENDPOINTS_SOURCE_ID,
    ) as GeoJSONSource | undefined;
    highlightEndpointsSource?.setData(highlight.endpoints);

    const jumpsSource = map.getSource(JUMPS_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    jumpsSource?.setData(buildJumpsGeoJson());

    const endpointsSource = map.getSource(ENDPOINTS_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    endpointsSource?.setData(buildEndpointsGeoJson());

    updateWindArrows();

    const strokeColor = markerStrokeColor();

    if (map.getLayer(HIGHLIGHT_CASING_LAYER_ID)) {
        map.setPaintProperty(
            HIGHLIGHT_CASING_LAYER_ID,
            'line-color',
            strokeColor,
        );
    }

    if (map.getLayer(HIGHLIGHT_ENDPOINTS_LAYER_ID)) {
        map.setPaintProperty(
            HIGHLIGHT_ENDPOINTS_LAYER_ID,
            'circle-stroke-color',
            strokeColor,
        );
    }

    if (map.getLayer(JUMPS_LAYER_ID)) {
        map.setPaintProperty(
            JUMPS_LAYER_ID,
            'circle-stroke-color',
            strokeColor,
        );
    }

    if (map.getLayer(ENDPOINTS_LAYER_ID)) {
        map.setPaintProperty(
            ENDPOINTS_LAYER_ID,
            'circle-stroke-color',
            strokeColor,
        );
    }

    if (map.getLayer(HOVER_LAYER_ID)) {
        map.setPaintProperty(HOVER_LAYER_ID, 'circle-color', props.accentColor);
        map.setPaintProperty(
            HOVER_LAYER_ID,
            'circle-stroke-color',
            strokeColor,
        );
    }
}

function updateHoverMarker(index: number | null): void {
    if (!map || !styleReady) {
        return;
    }

    const hoverSource = map.getSource(HOVER_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    hoverSource?.setData(buildHoverGeoJson(index));
}

onMounted(() => {
    if (!containerRef.value) {
        return;
    }

    ensureMapLibreWorker();

    const initialBounds = computeLngLatBounds(getBoundsPoints());

    map = new MapLibreMap({
        container: containerRef.value,
        style: getMapStyleUrl(isDark.value),
        scrollZoom: false,
        attributionControl: false,
        ...(initialBounds
            ? {
                  bounds: initialBounds,
                  fitBoundsOptions: {
                      padding: 28,
                      maxZoom: 16,
                  },
              }
            : {
                  center: [0, 0],
                  zoom: 2,
              }),
    });

    map.addControl(new NavigationControl({ showCompass: false }), 'top-left');
    map.addControl(new AttributionControl({ compact: true }), 'bottom-right');

    endpointPopup = new Popup({
        closeButton: false,
        closeOnClick: false,
        offset: 10,
        className: 'route-marker-tooltip',
    });

    map.on('style.load', () => {
        styleReady = true;
        renderRoute(false);
        updateHoverMarker(props.activeIndex);
    });

    map.on('moveend', () => {
        scheduleWindArrowsRefresh();
    });

    map.on('mousemove', ROUTE_HIT_LAYER_ID, (event) => {
        if (!map) {
            return;
        }

        if (activePopupSource === 'wind') {
            endpointPopup?.remove();
            activePopupSource = null;
        }

        map.getCanvas().style.cursor = 'pointer';

        const nearest = findNearestSeriesIndex(
            event.lngLat.lat,
            event.lngLat.lng,
        );

        if (nearest !== null) {
            emit('hoverIndex', nearest);
        }
    });

    map.on('mouseleave', ROUTE_HIT_LAYER_ID, () => {
        if (map && activePopupSource === null) {
            map.getCanvas().style.cursor = '';
        }

        emit('hoverIndex', null);
    });

    map.on('mouseenter', ENDPOINTS_LAYER_ID, (event) => {
        if (!map || !endpointPopup) {
            return;
        }

        const feature = event.features?.[0];
        const label =
            typeof feature?.properties?.label === 'string'
                ? feature.properties.label
                : null;
        const coordinates =
            feature?.geometry.type === 'Point'
                ? (feature.geometry.coordinates as [number, number])
                : null;

        if (!label || !coordinates) {
            return;
        }

        activePopupSource = 'endpoint';
        map.getCanvas().style.cursor = 'pointer';
        endpointPopup.setLngLat(coordinates).setText(label).addTo(map);
    });

    map.on('mouseleave', ENDPOINTS_LAYER_ID, () => {
        if (activePopupSource === 'endpoint') {
            if (map) {
                map.getCanvas().style.cursor = '';
            }

            endpointPopup?.remove();
            activePopupSource = null;
        }
    });

    map.on('mouseenter', JUMPS_LAYER_ID, (event) => {
        if (!map || !endpointPopup) {
            return;
        }

        const feature = event.features?.[0];
        const label =
            typeof feature?.properties?.label === 'string'
                ? feature.properties.label
                : null;
        const coordinates =
            feature?.geometry.type === 'Point'
                ? (feature.geometry.coordinates as [number, number])
                : null;

        if (!label || !coordinates) {
            return;
        }

        activePopupSource = 'jump';
        map.getCanvas().style.cursor = 'pointer';
        endpointPopup.setLngLat(coordinates).setText(label).addTo(map);
    });

    map.on('mouseleave', JUMPS_LAYER_ID, () => {
        if (activePopupSource === 'jump') {
            if (map) {
                map.getCanvas().style.cursor = '';
            }

            endpointPopup?.remove();
            activePopupSource = null;
        }
    });

    map.on('mousemove', WIND_AMBIENT_LAYER_ID, (event) => {
        if (!map || !endpointPopup) {
            return;
        }

        const blockingLayers = [
            ROUTE_HIT_LAYER_ID,
            ENDPOINTS_LAYER_ID,
            JUMPS_LAYER_ID,
        ].filter((layerId) => Boolean(map?.getLayer(layerId)));

        if (
            blockingLayers.length > 0 &&
            map.queryRenderedFeatures(event.point, { layers: blockingLayers })
                .length > 0
        ) {
            if (activePopupSource === 'wind') {
                endpointPopup.remove();
                activePopupSource = null;
            }

            return;
        }

        const feature = event.features?.[0];
        const featureProps = feature?.properties as
            | Record<string, unknown>
            | undefined;
        const coordinates =
            feature?.geometry.type === 'Point'
                ? (feature.geometry.coordinates as [number, number])
                : null;

        if (!featureProps || !coordinates) {
            return;
        }

        const speed = Number(featureProps.speed);
        const gusts =
            featureProps.gusts !== null &&
            featureProps.gusts !== undefined &&
            featureProps.gusts !== ''
                ? Number(featureProps.gusts)
                : null;
        const compass =
            typeof featureProps.compass === 'string'
                ? featureProps.compass
                : '';

        if (!Number.isFinite(speed)) {
            return;
        }

        let text = `Wind ${formatSpeedKmh(speed, 1)}${compass ? ` ${compass}` : ''}`;

        if (gusts !== null && Number.isFinite(gusts) && gusts - speed >= 5) {
            text = `${text} · Gusts ${formatSpeedKmh(gusts, 0)}`;
        }

        activePopupSource = 'wind';
        map.getCanvas().style.cursor = 'pointer';
        endpointPopup.setLngLat(coordinates).setText(text).addTo(map);
    });

    map.on('mouseleave', WIND_AMBIENT_LAYER_ID, () => {
        if (activePopupSource === 'wind') {
            if (map) {
                map.getCanvas().style.cursor = '';
            }

            endpointPopup?.remove();
            activePopupSource = null;
        }
    });

    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
            map?.resize();
            scheduleWindArrowsRefresh();
        });
        resizeObserver.observe(containerRef.value);
    }
});

watch(isDark, (dark) => {
    if (!map) {
        return;
    }

    styleReady = false;
    map.setStyle(getMapStyleUrl(dark), { diff: false });
});

watch(
    () => [
        props.colorMode,
        props.accentColor,
        props.highlightRange,
        props.jumps,
        props.weather,
        props.showWindArrows,
        units.value,
    ],
    () => {
        renderRoute(false);
        updateHoverMarker(props.activeIndex);
    },
);

watch(
    () => [props.polyline, props.series],
    () => {
        renderRoute(true);
        updateHoverMarker(props.activeIndex);
    },
);

watch(
    () => props.activeIndex,
    (nextIndex) => {
        updateHoverMarker(nextIndex);
    },
);

onBeforeUnmount(() => {
    if (windRefreshFrameId !== null && typeof window !== 'undefined') {
        window.cancelAnimationFrame(windRefreshFrameId);
        windRefreshFrameId = null;
    }

    resizeObserver?.disconnect();
    resizeObserver = null;
    endpointPopup?.remove();
    endpointPopup = null;
    activePopupSource = null;
    styleReady = false;
    map?.remove();
    map = null;
});
</script>

<template>
    <div
        class="relative isolate w-full overflow-hidden rounded-lg border border-zinc-200/80 bg-[#f4f4f2] dark:border-zinc-800/80 dark:bg-[#191a1a]"
        :style="containerStyle"
    >
        <div ref="containerRef" class="h-full w-full" />

        <div
            v-if="windArrowsLegendDisplay || windLegendDisplay || legendDisplay"
            class="pointer-events-none absolute bottom-3 left-3 z-[400] flex flex-col items-start gap-1.5"
        >
            <div
                v-if="windArrowsLegendDisplay"
                class="flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-white/95 px-2.5 py-1.5 font-mono text-[11px] text-zinc-700 backdrop-blur-xs dark:border-zinc-800/90 dark:bg-[#141417]/95 dark:text-zinc-200"
            >
                <span
                    class="inline-flex items-center gap-1 text-[10px] tracking-wider text-cyan-600 uppercase dark:text-cyan-400"
                >
                    <svg
                        viewBox="0 0 16 16"
                        class="size-3 shrink-0 fill-current"
                        aria-hidden="true"
                    >
                        <path
                            d="M8 1.5L13 8.25L9.5 7.25V14.5H6.5V7.25L3 8.25L8 1.5Z"
                        />
                    </svg>
                    Wind
                </span>
                <span class="text-[10px] text-zinc-500 dark:text-zinc-400">
                    Light
                </span>
                <span
                    class="h-1.5 w-16 rounded-full"
                    :style="{ background: windArrowsLegendDisplay.gradient }"
                />
                <span class="text-violet-600 tabular-nums dark:text-violet-400">
                    {{ windArrowsLegendDisplay.maxLabel }}
                </span>
            </div>

            <div
                v-if="windLegendDisplay"
                class="flex flex-wrap items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white/95 px-2.5 py-1.5 font-mono text-[11px] text-zinc-700 backdrop-blur-xs dark:border-zinc-800/90 dark:bg-[#141417]/95 dark:text-zinc-200"
            >
                <span class="text-rose-600 tabular-nums dark:text-rose-400">
                    Headwind ({{ windLegendDisplay.head }})
                </span>
                <span
                    class="h-1.5 w-8 rounded-l-full"
                    style="
                        background: linear-gradient(
                            90deg,
                            #f43f5e 0%,
                            #f59e0b 100%
                        );
                    "
                />
                <span class="text-[10px] text-amber-600 dark:text-amber-400">
                    Cross
                </span>
                <span
                    class="h-1.5 w-8 rounded-r-full"
                    style="
                        background: linear-gradient(
                            90deg,
                            #f59e0b 0%,
                            #10b981 100%
                        );
                    "
                />
                <span
                    class="text-emerald-600 tabular-nums dark:text-emerald-400"
                >
                    Tailwind ({{ windLegendDisplay.tail }})
                </span>
            </div>

            <div
                v-else-if="legendDisplay"
                class="flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-white/95 px-2.5 py-1.5 font-mono text-[11px] text-zinc-700 backdrop-blur-xs dark:border-zinc-800/90 dark:bg-[#141417]/95 dark:text-zinc-200"
            >
                <span
                    class="text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                >
                    {{ legendLabel }}
                </span>
                <span class="tabular-nums">{{ legendDisplay.min }}</span>
                <span
                    class="h-1.5 w-16 rounded-full"
                    style="
                        background: linear-gradient(
                            90deg,
                            #0ea5e9 0%,
                            #10b981 33%,
                            #f59e0b 66%,
                            #f43f5e 100%
                        );
                    "
                />
                <span class="tabular-nums">{{ legendDisplay.max }}</span>
            </div>
        </div>
    </div>
</template>
