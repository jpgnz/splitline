<script setup lang="ts">
import {
    CloudSun,
    Download,
    EyeOff,
    FileCode,
    Mountain,
    Share2,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { formatDurationClock, formatNumber } from '@/lib/format';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';

export interface ShareCardActivity {
    id?: number;
    name: string;
    started_at_local: string;
    sport_category: string;
    effective_discipline?: string | null;
    location_name?: string | null;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain?: number | null;
    avg_speed?: number | null;
    avg_hr?: number | null;
    avg_power?: number | null;
    accentHex?: string;
    sportLabel?: string;
    usesPace?: boolean;
}

export interface ShareCardSeriesPoint {
    distance?: number | null;
    altitude?: number | null;
}

export interface ShareCardWeather {
    condition?: string | null;
    temperature_c?: number | null;
    wind_speed_kmh?: number | null;
}

type CardThemePreset = 'dark' | 'light' | 'transparent';
type CardAspectPreset = 'landscape' | 'square';

interface StatTile {
    label: string;
    value: string;
}

const PRIVACY_TRIM_METERS = 500;
const EARTH_RADIUS_METERS = 6371000;

const props = withDefaults(
    defineProps<{
        open: boolean;
        activity: ShareCardActivity;
        polyline?: [number, number][];
        series?: ShareCardSeriesPoint[];
        weather?: ShareCardWeather | null;
    }>(),
    {
        polyline: () => [],
        series: () => [],
        weather: null,
    },
);

const emit = defineEmits<{
    close: [];
}>();

const { getSportMeta, resolveActivitySportKey } = useSports();
const {
    formatDistance,
    formatElevation,
    formatPace,
    formatPace100m,
    formatSpeed,
    formatSpeedKmh,
    formatTemp,
} = useUnits();

const themePreset = ref<CardThemePreset>('dark');
const aspectPreset = ref<CardAspectPreset>('landscape');
const showElevation = ref<boolean>(true);
const showWeather = ref<boolean>(true);
const privacyTrim = ref<boolean>(false);
const isExportingPng = ref<boolean>(false);
const svgRef = ref<SVGSVGElement | null>(null);

const STYLE_PRESETS: Array<{ value: CardThemePreset; label: string }> = [
    { value: 'dark', label: 'Studio Dark' },
    { value: 'light', label: 'Studio Light' },
    { value: 'transparent', label: 'Transparent Overlay' },
];

const ASPECT_PRESETS: Array<{
    value: CardAspectPreset;
    label: string;
    dims: string;
}> = [
    { value: 'landscape', label: 'Landscape', dims: '1200×630' },
    { value: 'square', label: 'Square / Story', dims: '1080×1080' },
];

const cardWidth = computed<number>(() =>
    aspectPreset.value === 'square' ? 1080 : 1200,
);
const cardHeight = computed<number>(() =>
    aspectPreset.value === 'square' ? 1080 : 630,
);

const resolvedSportMeta = computed(() => {
    const key = resolveActivitySportKey(
        props.activity.sport_category,
        props.activity.effective_discipline,
    );

    return getSportMeta(key);
});

const accentHex = computed<string>(
    () => props.activity.accentHex || resolvedSportMeta.value.accent_hex,
);

const sportLabel = computed<string>(
    () => props.activity.sportLabel || resolvedSportMeta.value.label,
);

const usesPace = computed<boolean>(() => {
    if (typeof props.activity.usesPace === 'boolean') {
        return props.activity.usesPace;
    }

    return resolvedSportMeta.value.metric_mode === 'pace';
});

const isSwimming = computed<boolean>(
    () => props.activity.sport_category === 'swimming',
);

const palette = computed(() => {
    if (themePreset.value === 'light') {
        return {
            bgStart: '#f7f7f5',
            bgEnd: '#ffffff',
            panelFill: '#ffffff',
            panelStroke: '#e4e4e7',
            frameStroke: '#e4e4e7',
            gridStroke: '#d4d4d8',
            textPrimary: '#09090b',
            textSecondary: '#52525b',
            textMuted: '#71717a',
            routeStroke: accentHex.value,
            routeGlow: accentHex.value,
            routeGlowOpacity: 0.22,
            elevStroke: accentHex.value,
            elevFillOpacity: 0.16,
            brandBg: '#18181b',
            brandDot: '#f4f4f5',
        };
    }

    if (themePreset.value === 'transparent') {
        return {
            bgStart: 'none',
            bgEnd: 'none',
            panelFill: 'none',
            panelStroke: 'rgba(255,255,255,0.22)',
            frameStroke: 'none',
            gridStroke: 'rgba(255,255,255,0.14)',
            textPrimary: '#ffffff',
            textSecondary: 'rgba(255,255,255,0.88)',
            textMuted: 'rgba(255,255,255,0.72)',
            routeStroke: '#ffffff',
            routeGlow: '#09090b',
            routeGlowOpacity: 0.45,
            elevStroke: '#ffffff',
            elevFillOpacity: 0.2,
            brandBg: '#ffffff',
            brandDot: '#09090b',
        };
    }

    return {
        bgStart: '#101013',
        bgEnd: '#16171d',
        panelFill: 'rgba(255,255,255,0.035)',
        panelStroke: 'rgba(255,255,255,0.09)',
        frameStroke: 'rgba(255,255,255,0.08)',
        gridStroke: 'rgba(255,255,255,0.08)',
        textPrimary: '#fafafa',
        textSecondary: '#d4d4d8',
        textMuted: '#a1a1aa',
        routeStroke: accentHex.value,
        routeGlow: accentHex.value,
        routeGlowOpacity: 0.28,
        elevStroke: accentHex.value,
        elevFillOpacity: 0.22,
        brandBg: '#f4f4f5',
        brandDot: '#101012',
    };
});

const formattedDate = computed<string>(() => {
    const raw = props.activity.started_at_local;

    if (!raw) {
        return '';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw.trim());
    const dateObj = match
        ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
        : new Date(raw);

    if (Number.isNaN(dateObj.getTime())) {
        return raw.slice(0, 10);
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(dateObj);
});

function wrapTitle(title: string, maxCharsPerLine: number): string[] {
    const trimmed = title.trim() || 'Activity';
    const words = trimmed.split(/\s+/);
    const lines: string[] = [];
    let current = '';

    for (const word of words) {
        const candidate = current ? `${current} ${word}` : word;

        if (candidate.length <= maxCharsPerLine || current === '') {
            current = candidate;
        } else {
            lines.push(current);
            current = word;

            if (lines.length === 1 && current.length > maxCharsPerLine) {
                break;
            }
        }
    }

    if (current) {
        lines.push(current);
    }

    if (lines.length > 2) {
        const second = lines.slice(1).join(' ');

        return [
            lines[0],
            second.length > maxCharsPerLine
                ? `${second.slice(0, maxCharsPerLine - 1)}…`
                : second,
        ];
    }

    return lines.map((line) =>
        line.length > maxCharsPerLine + 2
            ? `${line.slice(0, maxCharsPerLine)}…`
            : line,
    );
}

const titleLines = computed<string[]>(() =>
    wrapTitle(props.activity.name, aspectPreset.value === 'square' ? 28 : 22),
);

const hasWeatherData = computed<boolean>(() => {
    const w = props.weather;

    return Boolean(
        w &&
        (w.condition ||
            (w.temperature_c !== null && w.temperature_c !== undefined) ||
            (w.wind_speed_kmh !== null && w.wind_speed_kmh !== undefined)),
    );
});

const weatherSummaryText = computed<string | null>(() => {
    if (!showWeather.value || !props.weather) {
        return null;
    }

    const parts: string[] = [];

    if (
        props.weather.temperature_c !== null &&
        props.weather.temperature_c !== undefined
    ) {
        parts.push(formatTemp(props.weather.temperature_c, 0));
    }

    if (props.weather.condition) {
        parts.push(props.weather.condition);
    }

    if (
        props.weather.wind_speed_kmh !== null &&
        props.weather.wind_speed_kmh !== undefined &&
        props.weather.wind_speed_kmh > 0
    ) {
        parts.push(`${formatSpeedKmh(props.weather.wind_speed_kmh, 0)} wind`);
    }

    return parts.length > 0 ? parts.join(' · ') : null;
});

const subtitleLine = computed<string>(() => {
    const parts: string[] = [];

    if (props.activity.location_name && !privacyTrim.value) {
        parts.push(props.activity.location_name);
    }

    if (weatherSummaryText.value) {
        parts.push(weatherSummaryText.value);
    }

    return parts.join('  •  ');
});

const statTiles = computed<StatTile[]>(() => {
    const tiles: StatTile[] = [];
    const act = props.activity;

    if (act.distance_meters > 0) {
        tiles.push({
            label: 'DISTANCE',
            value: formatDistance(act.distance_meters, 2),
        });
    }

    tiles.push({
        label: 'TIME',
        value: formatDurationClock(act.duration_seconds),
    });

    if (act.avg_speed && act.avg_speed > 0) {
        if (isSwimming.value) {
            tiles.push({
                label: 'AVG PACE',
                value: formatPace100m(act.avg_speed),
            });
        } else if (usesPace.value) {
            tiles.push({
                label: 'AVG PACE',
                value: formatPace(act.avg_speed),
            });
        } else {
            tiles.push({
                label: 'AVG SPEED',
                value: formatSpeed(act.avg_speed, 1),
            });
        }
    }

    if (act.elevation_gain && act.elevation_gain > 0) {
        tiles.push({
            label: 'ELEVATION',
            value: formatElevation(act.elevation_gain),
        });
    }

    if (act.avg_power && act.avg_power > 0 && tiles.length < 4) {
        tiles.push({
            label: 'AVG POWER',
            value: `${formatNumber(Math.round(act.avg_power))} W`,
        });
    }

    if (act.avg_hr && act.avg_hr > 0 && tiles.length < 4) {
        tiles.push({
            label: 'AVG HR',
            value: `${formatNumber(Math.round(act.avg_hr))} bpm`,
        });
    }

    return tiles.slice(0, 4);
});

function haversineMeters(a: [number, number], b: [number, number]): number {
    const lat1 = (a[0] * Math.PI) / 180;
    const lat2 = (b[0] * Math.PI) / 180;
    const dLat = lat2 - lat1;
    const dLng = ((b[1] - a[1]) * Math.PI) / 180;
    const sinLat = Math.sin(dLat / 2);
    const sinLng = Math.sin(dLng / 2);
    const h =
        sinLat * sinLat + Math.cos(lat1) * Math.cos(lat2) * sinLng * sinLng;

    return 2 * EARTH_RADIUS_METERS * Math.asin(Math.min(1, Math.sqrt(h)));
}

function trimPolylineByMeters(
    points: [number, number][],
    trimMeters: number,
): [number, number][] {
    if (points.length < 4) {
        return points;
    }

    const cumulative: number[] = [0];

    for (let i = 1; i < points.length; i++) {
        cumulative.push(
            cumulative[i - 1] + haversineMeters(points[i - 1], points[i]),
        );
    }

    const total = cumulative[cumulative.length - 1];

    if (total <= 0) {
        return points;
    }

    const effectiveTrim = total > trimMeters * 2.2 ? trimMeters : total * 0.2;
    const startDist = effectiveTrim;
    const endDist = total - effectiveTrim;

    if (endDist <= startDist) {
        return points;
    }

    const trimmed: [number, number][] = [];

    for (let i = 0; i < points.length - 1; i++) {
        const d0 = cumulative[i];
        const d1 = cumulative[i + 1];
        const segLen = d1 - d0;

        if (d1 < startDist) {
            continue;
        }

        if (d0 > endDist) {
            break;
        }

        if (d0 < startDist && d1 >= startDist && segLen > 0) {
            const t = (startDist - d0) / segLen;
            trimmed.push([
                points[i][0] + (points[i + 1][0] - points[i][0]) * t,
                points[i][1] + (points[i + 1][1] - points[i][1]) * t,
            ]);
        } else if (d0 >= startDist && d0 <= endDist) {
            trimmed.push(points[i]);
        }

        if (d0 <= endDist && d1 > endDist && segLen > 0) {
            const t = (endDist - d0) / segLen;
            trimmed.push([
                points[i][0] + (points[i + 1][0] - points[i][0]) * t,
                points[i][1] + (points[i + 1][1] - points[i][1]) * t,
            ]);
            break;
        }
    }

    if (cumulative[points.length - 1] <= endDist) {
        trimmed.push(points[points.length - 1]);
    }

    return trimmed.length >= 2 ? trimmed : points;
}

const activePolyline = computed<[number, number][]>(() => {
    const raw = (props.polyline ?? []).filter(
        (pt): pt is [number, number] =>
            Array.isArray(pt) &&
            pt.length >= 2 &&
            Number.isFinite(pt[0]) &&
            Number.isFinite(pt[1]),
    );

    if (raw.length < 2) {
        return [];
    }

    if (!privacyTrim.value) {
        return raw;
    }

    return trimPolylineByMeters(raw, PRIVACY_TRIM_METERS);
});

const hasPolyline = computed<boolean>(() => (props.polyline?.length ?? 0) >= 2);

const routeBox = computed(() => {
    if (aspectPreset.value === 'square') {
        return { x: 64, y: 240, w: 952, h: 430, pad: 34 };
    }

    return { x: 584, y: 56, w: 560, h: 518, pad: 40 };
});

const projectedRoute = computed<{
    pathD: string;
    start: { x: number; y: number };
    end: { x: number; y: number };
} | null>(() => {
    const pts = activePolyline.value;

    if (pts.length < 2) {
        return null;
    }

    let minLat = Number.POSITIVE_INFINITY;
    let maxLat = Number.NEGATIVE_INFINITY;
    let minLng = Number.POSITIVE_INFINITY;
    let maxLng = Number.NEGATIVE_INFINITY;

    for (const [lat, lng] of pts) {
        if (lat < minLat) minLat = lat;
        if (lat > maxLat) maxLat = lat;
        if (lng < minLng) minLng = lng;
        if (lng > maxLng) maxLng = lng;
    }

    const midLatRad = (((minLat + maxLat) / 2) * Math.PI) / 180;
    const cosLat = Math.max(0.2, Math.cos(midLatRad));
    const spanX = Math.max(1e-6, (maxLng - minLng) * cosLat);
    const spanY = Math.max(1e-6, maxLat - minLat);

    const box = routeBox.value;
    const innerW = box.w - box.pad * 2;
    const innerH = box.h - box.pad * 2;
    const scale = Math.min(innerW / spanX, innerH / spanY);

    const drawnW = spanX * scale;
    const drawnH = spanY * scale;
    const offsetX = box.x + (box.w - drawnW) / 2;
    const offsetY = box.y + (box.h - drawnH) / 2;

    const coords = pts.map(([lat, lng]) => ({
        x: Number((offsetX + (lng - minLng) * cosLat * scale).toFixed(1)),
        y: Number((offsetY + (maxLat - lat) * scale).toFixed(1)),
    }));

    const pathD = coords
        .map((pt, idx) => `${idx === 0 ? 'M' : 'L'}${pt.x} ${pt.y}`)
        .join(' ');

    return {
        pathD,
        start: coords[0],
        end: coords[coords.length - 1],
    };
});

const elevationBox = computed(() => {
    if (aspectPreset.value === 'square') {
        return { x: 64, y: 696, w: 952, h: 74 };
    }

    return { x: 56, y: 454, w: 492, h: 72 };
});

const elevationData = computed<Array<{ x: number; alt: number }>>(() => {
    const valid = (props.series ?? []).filter(
        (pt): pt is { distance?: number | null; altitude: number } =>
            pt.altitude !== null &&
            pt.altitude !== undefined &&
            Number.isFinite(pt.altitude),
    );

    if (valid.length < 3) {
        return [];
    }

    // Downsample to <= 120 points for crisp SVG paths
    const maxPts = 120;
    const step = Math.max(1, Math.floor(valid.length / maxPts));
    const sampled: Array<{ x: number; alt: number }> = [];

    for (let i = 0; i < valid.length; i += step) {
        const pt = valid[i];
        sampled.push({
            x:
                pt.distance !== null &&
                pt.distance !== undefined &&
                Number.isFinite(pt.distance)
                    ? pt.distance
                    : i,
            alt: pt.altitude,
        });
    }

    const last = valid[valid.length - 1];
    const lastIdx = valid.length - 1;
    if (sampled[sampled.length - 1]?.alt !== last.altitude) {
        sampled.push({
            x:
                last.distance !== null &&
                last.distance !== undefined &&
                Number.isFinite(last.distance)
                    ? last.distance
                    : lastIdx,
            alt: last.altitude,
        });
    }

    return sampled;
});

const hasElevationData = computed<boolean>(
    () => elevationData.value.length >= 3,
);

const elevationSparkline = computed<{
    lineD: string;
    areaD: string;
} | null>(() => {
    if (!showElevation.value || !hasElevationData.value) {
        return null;
    }

    const pts = elevationData.value;
    const box = elevationBox.value;
    const minX = pts[0].x;
    const maxX = pts[pts.length - 1].x;
    const spanX = Math.max(1e-6, maxX - minX);

    let minAlt = Number.POSITIVE_INFINITY;
    let maxAlt = Number.NEGATIVE_INFINITY;

    for (const pt of pts) {
        if (pt.alt < minAlt) minAlt = pt.alt;
        if (pt.alt > maxAlt) maxAlt = pt.alt;
    }

    const spanAlt = Math.max(5, maxAlt - minAlt);
    const padY = 6;
    const usableH = box.h - padY * 2;

    const mapped = pts.map((pt) => ({
        x: Number((box.x + ((pt.x - minX) / spanX) * box.w).toFixed(1)),
        y: Number(
            (
                box.y +
                box.h -
                padY -
                ((pt.alt - minAlt) / spanAlt) * usableH
            ).toFixed(1),
        ),
    }));

    const lineD = mapped
        .map((pt, idx) => `${idx === 0 ? 'M' : 'L'}${pt.x} ${pt.y}`)
        .join(' ');
    const bottomY = Number((box.y + box.h).toFixed(1));
    const areaD = `${lineD} L${mapped[mapped.length - 1].x} ${bottomY} L${mapped[0].x} ${bottomY} Z`;

    return { lineD, areaD };
});

const filenameSlug = computed<string>(() => {
    const cleanName = props.activity.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 48);
    const datePart = (props.activity.started_at_local || '').slice(0, 10);

    return `splitline-${cleanName || 'activity'}${datePart ? `-${datePart}` : ''}`;
});

function serializeCardSvg(): string | null {
    const el = svgRef.value;

    if (!el) {
        return null;
    }

    const clone = el.cloneNode(true) as SVGSVGElement;
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    clone.setAttribute('width', String(cardWidth.value));
    clone.setAttribute('height', String(cardHeight.value));
    clone.removeAttribute('class');

    const serializer = new XMLSerializer();

    return `<?xml version="1.0" encoding="UTF-8"?>\n${serializer.serializeToString(clone)}`;
}

function triggerBlobDownload(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

function downloadSvg(): void {
    const markup = serializeCardSvg();

    if (!markup) {
        toast.error('Could not serialize SVG card.');

        return;
    }

    const blob = new Blob([markup], {
        type: 'image/svg+xml;charset=utf-8',
    });
    triggerBlobDownload(blob, `${filenameSlug.value}.svg`);
    toast.success('Downloaded SVG activity card');
}

async function downloadPng(): Promise<void> {
    if (isExportingPng.value) {
        return;
    }

    const markup = serializeCardSvg();

    if (!markup) {
        toast.error('Could not serialize card for PNG export.');

        return;
    }

    isExportingPng.value = true;

    try {
        const svgBlob = new Blob([markup], {
            type: 'image/svg+xml;charset=utf-8',
        });
        const svgUrl = URL.createObjectURL(svgBlob);

        try {
            const img = await new Promise<HTMLImageElement>(
                (resolve, reject) => {
                    const image = new Image();
                    image.onload = () => resolve(image);
                    image.onerror = () =>
                        reject(new Error('Failed to decode SVG image'));
                    image.src = svgUrl;
                },
            );

            const scale = 2;
            const canvas = document.createElement('canvas');
            canvas.width = cardWidth.value * scale;
            canvas.height = cardHeight.value * scale;

            const ctx = canvas.getContext('2d');

            if (!ctx) {
                throw new Error('Canvas 2D context unavailable');
            }

            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            const pngBlob = await new Promise<Blob | null>((resolve) => {
                canvas.toBlob((res) => resolve(res), 'image/png');
            });

            if (!pngBlob) {
                throw new Error('Canvas PNG export failed');
            }

            triggerBlobDownload(pngBlob, `${filenameSlug.value}.png`);
            toast.success('Downloaded 2× PNG activity card');
        } finally {
            URL.revokeObjectURL(svgUrl);
        }
    } catch {
        toast.error('Could not export PNG activity card.');
    } finally {
        isExportingPng.value = false;
    }
}

function handleKeydown(event: KeyboardEvent): void {
    if (props.open && event.key === 'Escape') {
        emit('close');
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (typeof document === 'undefined') {
            return;
        }

        document.body.style.overflow = isOpen ? 'hidden' : '';
    },
);

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);

    if (typeof document !== 'undefined') {
        document.body.style.overflow = '';
    }
});
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
        role="dialog"
        aria-modal="true"
        aria-label="Share activity summary card"
    >
        <!-- Backdrop -->
        <div
            class="fixed inset-0 bg-zinc-950/70 backdrop-blur-xs"
            @click="emit('close')"
        />

        <!-- Modal Shell -->
        <div
            class="relative z-10 flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-zinc-200/90 bg-white shadow-2xl dark:border-zinc-800 dark:bg-[#141417]"
        >
            <!-- Header -->
            <div
                class="flex items-center justify-between border-b border-zinc-200/80 px-5 py-3.5 dark:border-zinc-800/80"
            >
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex size-8 items-center justify-center rounded-lg bg-emerald-500/12 text-emerald-600 dark:text-emerald-400"
                    >
                        <Share2 class="size-4" />
                    </div>
                    <div>
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Shareable Activity Card
                        </h2>
                        <p
                            class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                        >
                            100% local rendering · PNG & SVG export
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="inline-flex size-8 cursor-pointer items-center justify-center rounded-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    aria-label="Close share modal"
                    @click="emit('close')"
                >
                    <X class="size-4" />
                </button>
            </div>

            <!-- Body -->
            <div class="grid flex-1 gap-6 overflow-y-auto p-5 lg:grid-cols-12">
                <!-- Live SVG Preview Stage -->
                <div
                    class="flex flex-col items-center justify-center rounded-xl border border-zinc-200/80 p-4 lg:col-span-8 dark:border-zinc-800/80"
                    :class="
                        themePreset === 'transparent'
                            ? 'bg-[repeating-conic-gradient(#3f3f46_0%_25%,#27272a_0%_50%)] bg-[length:20px_20px]'
                            : 'bg-zinc-100/80 dark:bg-zinc-950/70'
                    "
                >
                    <div
                        class="w-full overflow-hidden rounded-xl shadow-lg"
                        :class="
                            aspectPreset === 'square'
                                ? 'max-w-[440px]'
                                : 'max-w-full'
                        "
                    >
                        <svg
                            ref="svgRef"
                            :viewBox="`0 0 ${cardWidth} ${cardHeight}`"
                            xmlns="http://www.w3.org/2000/svg"
                            class="block h-auto w-full"
                        >
                            <defs>
                                <linearGradient
                                    id="sl-card-bg"
                                    x1="0%"
                                    y1="0%"
                                    x2="100%"
                                    y2="100%"
                                >
                                    <stop
                                        offset="0%"
                                        :stop-color="palette.bgStart"
                                    />
                                    <stop
                                        offset="100%"
                                        :stop-color="palette.bgEnd"
                                    />
                                </linearGradient>

                                <linearGradient
                                    id="sl-elev-fill"
                                    x1="0%"
                                    y1="0%"
                                    x2="0%"
                                    y2="100%"
                                >
                                    <stop
                                        offset="0%"
                                        :stop-color="palette.elevStroke"
                                        :stop-opacity="palette.elevFillOpacity"
                                    />
                                    <stop
                                        offset="100%"
                                        :stop-color="palette.elevStroke"
                                        stop-opacity="0"
                                    />
                                </linearGradient>

                                <filter
                                    id="sl-transparent-shadow"
                                    x="-10%"
                                    y="-10%"
                                    width="120%"
                                    height="120%"
                                >
                                    <feDropShadow
                                        dx="0"
                                        dy="2"
                                        stdDeviation="4"
                                        flood-color="#000000"
                                        flood-opacity="0.55"
                                    />
                                </filter>
                            </defs>

                            <!-- Solid Background (omitted completely for Transparent Overlay) -->
                            <g v-if="themePreset !== 'transparent'">
                                <rect
                                    x="0"
                                    y="0"
                                    :width="cardWidth"
                                    :height="cardHeight"
                                    fill="url(#sl-card-bg)"
                                />
                                <rect
                                    x="18"
                                    y="18"
                                    :width="cardWidth - 36"
                                    :height="cardHeight - 36"
                                    rx="22"
                                    fill="none"
                                    :stroke="palette.frameStroke"
                                    stroke-width="1.5"
                                />
                            </g>

                            <!-- Main Card Content Group -->
                            <g
                                :filter="
                                    themePreset === 'transparent'
                                        ? 'url(#sl-transparent-shadow)'
                                        : undefined
                                "
                            >
                                <!-- LANDSCAPE LAYOUT (1200x630) -->
                                <template v-if="aspectPreset === 'landscape'">
                                    <!-- Top Eyebrow -->
                                    <circle
                                        cx="64"
                                        cy="68"
                                        r="6"
                                        :fill="
                                            themePreset === 'transparent'
                                                ? '#ffffff'
                                                : accentHex
                                        "
                                    />
                                    <text
                                        x="82"
                                        y="73"
                                        :fill="palette.textMuted"
                                        font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                        font-size="14"
                                        font-weight="600"
                                        letter-spacing="1.8"
                                    >
                                        {{ sportLabel.toUpperCase() }}
                                        {{
                                            formattedDate
                                                ? ` · ${formattedDate.toUpperCase()}`
                                                : ''
                                        }}
                                    </text>

                                    <!-- Activity Title (1–2 lines) -->
                                    <text
                                        v-for="(line, idx) in titleLines"
                                        :key="idx"
                                        x="56"
                                        :y="126 + idx * 46"
                                        :fill="palette.textPrimary"
                                        font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
                                        font-size="40"
                                        font-weight="700"
                                        letter-spacing="-0.8"
                                    >
                                        {{ line }}
                                    </text>

                                    <!-- Subtitle (Location + Weather) -->
                                    <text
                                        v-if="subtitleLine"
                                        x="56"
                                        :y="titleLines.length > 1 ? 204 : 164"
                                        :fill="palette.textSecondary"
                                        font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
                                        font-size="16"
                                        font-weight="500"
                                    >
                                        {{ subtitleLine }}
                                    </text>

                                    <!-- 2x2 Stat Grid -->
                                    <g
                                        v-for="(tile, idx) in statTiles"
                                        :key="tile.label"
                                    >
                                        <rect
                                            v-if="themePreset !== 'transparent'"
                                            :x="56 + (idx % 2) * 252"
                                            :y="236 + Math.floor(idx / 2) * 102"
                                            width="238"
                                            height="88"
                                            rx="14"
                                            :fill="palette.panelFill"
                                            :stroke="palette.panelStroke"
                                            stroke-width="1"
                                        />
                                        <text
                                            :x="
                                                (themePreset === 'transparent'
                                                    ? 56
                                                    : 74) +
                                                (idx % 2) * 252
                                            "
                                            :y="265 + Math.floor(idx / 2) * 102"
                                            :fill="palette.textMuted"
                                            font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                            font-size="11"
                                            font-weight="600"
                                            letter-spacing="1.4"
                                        >
                                            {{ tile.label }}
                                        </text>
                                        <text
                                            :x="
                                                (themePreset === 'transparent'
                                                    ? 56
                                                    : 74) +
                                                (idx % 2) * 252
                                            "
                                            :y="304 + Math.floor(idx / 2) * 102"
                                            :fill="palette.textPrimary"
                                            font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
                                            font-size="30"
                                            font-weight="700"
                                            letter-spacing="-0.4"
                                        >
                                            {{ tile.value }}
                                        </text>
                                    </g>

                                    <!-- Elevation Sparkline (Bottom Left) -->
                                    <g v-if="elevationSparkline">
                                        <text
                                            x="56"
                                            y="448"
                                            :fill="palette.textMuted"
                                            font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                            font-size="10"
                                            font-weight="600"
                                            letter-spacing="1.4"
                                        >
                                            ELEVATION PROFILE
                                        </text>
                                        <path
                                            :d="elevationSparkline.areaD"
                                            fill="url(#sl-elev-fill)"
                                        />
                                        <path
                                            :d="elevationSparkline.lineD"
                                            fill="none"
                                            :stroke="palette.elevStroke"
                                            stroke-width="2.5"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </g>

                                    <!-- Splitline Brand Footer (Bottom Left) -->
                                    <g transform="translate(56, 548)">
                                        <rect
                                            width="26"
                                            height="26"
                                            rx="6"
                                            :fill="palette.brandBg"
                                        />
                                        <path
                                            d="M5 17L9 17L12 9L15 18L17.5 12L21 12"
                                            fill="none"
                                            stroke="#10b981"
                                            stroke-width="2.2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                        <text
                                            x="36"
                                            y="18"
                                            :fill="palette.textMuted"
                                            font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                            font-size="13"
                                            font-weight="600"
                                            letter-spacing="2"
                                        >
                                            SPLITLINE
                                        </text>
                                    </g>

                                    <!-- Right Route Panel -->
                                    <rect
                                        v-if="themePreset !== 'transparent'"
                                        :x="routeBox.x"
                                        :y="routeBox.y"
                                        :width="routeBox.w"
                                        :height="routeBox.h"
                                        rx="20"
                                        :fill="palette.panelFill"
                                        :stroke="palette.panelStroke"
                                        stroke-width="1"
                                    />
                                </template>

                                <!-- SQUARE / STORY LAYOUT (1080x1080) -->
                                <template v-else>
                                    <!-- Top Row: Brand & Sport Eyebrow -->
                                    <g transform="translate(64, 56)">
                                        <rect
                                            width="32"
                                            height="32"
                                            rx="8"
                                            :fill="palette.brandBg"
                                        />
                                        <path
                                            d="M6 21L11 21L14.5 11L18.5 22L21.5 14.5L26 14.5"
                                            fill="none"
                                            stroke="#10b981"
                                            stroke-width="2.6"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                        <text
                                            x="46"
                                            y="21"
                                            :fill="palette.textMuted"
                                            font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                            font-size="14"
                                            font-weight="600"
                                            letter-spacing="1.8"
                                        >
                                            SPLITLINE ·
                                            {{ sportLabel.toUpperCase() }}
                                        </text>
                                    </g>

                                    <text
                                        x="1016"
                                        y="77"
                                        text-anchor="end"
                                        :fill="palette.textMuted"
                                        font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                        font-size="14"
                                        font-weight="600"
                                        letter-spacing="1.4"
                                    >
                                        {{ formattedDate.toUpperCase() }}
                                    </text>

                                    <!-- Title -->
                                    <text
                                        v-for="(line, idx) in titleLines"
                                        :key="idx"
                                        x="64"
                                        :y="142 + idx * 50"
                                        :fill="palette.textPrimary"
                                        font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
                                        font-size="44"
                                        font-weight="700"
                                        letter-spacing="-0.9"
                                    >
                                        {{ line }}
                                    </text>

                                    <text
                                        v-if="subtitleLine"
                                        x="64"
                                        :y="titleLines.length > 1 ? 220 : 182"
                                        :fill="palette.textSecondary"
                                        font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
                                        font-size="18"
                                        font-weight="500"
                                    >
                                        {{ subtitleLine }}
                                    </text>

                                    <!-- Center Route Panel -->
                                    <rect
                                        v-if="themePreset !== 'transparent'"
                                        :x="routeBox.x"
                                        :y="routeBox.y"
                                        :width="routeBox.w"
                                        :height="routeBox.h"
                                        rx="22"
                                        :fill="palette.panelFill"
                                        :stroke="palette.panelStroke"
                                        stroke-width="1"
                                    />

                                    <!-- Elevation Sparkline -->
                                    <g v-if="elevationSparkline">
                                        <text
                                            x="64"
                                            y="690"
                                            :fill="palette.textMuted"
                                            font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                            font-size="11"
                                            font-weight="600"
                                            letter-spacing="1.6"
                                        >
                                            ELEVATION PROFILE
                                        </text>
                                        <path
                                            :d="elevationSparkline.areaD"
                                            fill="url(#sl-elev-fill)"
                                        />
                                        <path
                                            :d="elevationSparkline.lineD"
                                            fill="none"
                                            :stroke="palette.elevStroke"
                                            stroke-width="3"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </g>

                                    <!-- Bottom 2x2 KPI Grid -->
                                    <g
                                        v-for="(tile, idx) in statTiles"
                                        :key="tile.label"
                                    >
                                        <rect
                                            v-if="themePreset !== 'transparent'"
                                            :x="64 + (idx % 2) * 484"
                                            :y="804 + Math.floor(idx / 2) * 108"
                                            width="468"
                                            height="92"
                                            rx="16"
                                            :fill="palette.panelFill"
                                            :stroke="palette.panelStroke"
                                            stroke-width="1"
                                        />
                                        <text
                                            :x="
                                                (themePreset === 'transparent'
                                                    ? 64
                                                    : 88) +
                                                (idx % 2) * 484
                                            "
                                            :y="836 + Math.floor(idx / 2) * 108"
                                            :fill="palette.textMuted"
                                            font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                            font-size="12"
                                            font-weight="600"
                                            letter-spacing="1.6"
                                        >
                                            {{ tile.label }}
                                        </text>
                                        <text
                                            :x="
                                                (themePreset === 'transparent'
                                                    ? 64
                                                    : 88) +
                                                (idx % 2) * 484
                                            "
                                            :y="878 + Math.floor(idx / 2) * 108"
                                            :fill="palette.textPrimary"
                                            font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
                                            font-size="36"
                                            font-weight="700"
                                            letter-spacing="-0.5"
                                        >
                                            {{ tile.value }}
                                        </text>
                                    </g>
                                </template>

                                <!-- SHARED ROUTE SILHOUETTE (both aspects) -->
                                <g v-if="projectedRoute">
                                    <!-- Subtle crosshair grid on solid cards -->
                                    <g v-if="themePreset !== 'transparent'">
                                        <line
                                            :x1="routeBox.x + 24"
                                            :y1="routeBox.y + routeBox.h / 2"
                                            :x2="routeBox.x + routeBox.w - 24"
                                            :y2="routeBox.y + routeBox.h / 2"
                                            :stroke="palette.gridStroke"
                                            stroke-dasharray="4 6"
                                            stroke-width="1"
                                        />
                                        <line
                                            :x1="routeBox.x + routeBox.w / 2"
                                            :y1="routeBox.y + 24"
                                            :x2="routeBox.x + routeBox.w / 2"
                                            :y2="routeBox.y + routeBox.h - 24"
                                            :stroke="palette.gridStroke"
                                            stroke-dasharray="4 6"
                                            stroke-width="1"
                                        />
                                    </g>

                                    <!-- Under-glow / Halo Stroke -->
                                    <path
                                        :d="projectedRoute.pathD"
                                        fill="none"
                                        :stroke="palette.routeGlow"
                                        :stroke-opacity="
                                            palette.routeGlowOpacity
                                        "
                                        stroke-width="14"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />

                                    <!-- Main Route Silhouette -->
                                    <path
                                        :d="projectedRoute.pathD"
                                        fill="none"
                                        :stroke="palette.routeStroke"
                                        stroke-width="5"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />

                                    <!-- Start/Finish Dots (Hidden when Privacy Trim is enabled!) -->
                                    <template v-if="!privacyTrim">
                                        <circle
                                            :cx="projectedRoute.end.x"
                                            :cy="projectedRoute.end.y"
                                            r="7"
                                            fill="#f43f5e"
                                            :stroke="
                                                themePreset === 'light'
                                                    ? '#ffffff'
                                                    : '#101012'
                                            "
                                            stroke-width="2.5"
                                        />
                                        <circle
                                            :cx="projectedRoute.start.x"
                                            :cy="projectedRoute.start.y"
                                            r="7.5"
                                            fill="#10b981"
                                            :stroke="
                                                themePreset === 'light'
                                                    ? '#ffffff'
                                                    : '#101012'
                                            "
                                            stroke-width="2.5"
                                        />
                                    </template>
                                </g>

                                <!-- Fallback when no GPS route (indoor activity) -->
                                <g v-else>
                                    <text
                                        :x="routeBox.x + routeBox.w / 2"
                                        :y="routeBox.y + routeBox.h / 2 - 8"
                                        text-anchor="middle"
                                        :fill="palette.textMuted"
                                        font-family="'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace"
                                        font-size="14"
                                        font-weight="600"
                                        letter-spacing="2"
                                    >
                                        INDOOR / NO GPS TRACK
                                    </text>
                                    <text
                                        :x="routeBox.x + routeBox.w / 2"
                                        :y="routeBox.y + routeBox.h / 2 + 20"
                                        text-anchor="middle"
                                        :fill="palette.textMuted"
                                        font-family="Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
                                        font-size="14"
                                        opacity="0.7"
                                    >
                                        {{ sportLabel }} Session Summary
                                    </text>
                                </g>
                            </g>
                        </svg>
                    </div>
                </div>

                <!-- Controls Sidebar -->
                <div class="flex flex-col justify-between gap-5 lg:col-span-4">
                    <div class="space-y-5">
                        <!-- Style Preset -->
                        <div class="space-y-2">
                            <p
                                class="font-mono text-[10px] font-semibold tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Card Style
                            </p>
                            <div class="grid gap-1.5">
                                <button
                                    v-for="preset in STYLE_PRESETS"
                                    :key="preset.value"
                                    type="button"
                                    :class="[
                                        'flex cursor-pointer items-center justify-between rounded-xl border px-3 py-2 text-left text-xs font-medium transition-colors',
                                        themePreset === preset.value
                                            ? 'border-emerald-500/60 bg-emerald-500/10 text-zinc-950 dark:text-white'
                                            : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-300 dark:hover:bg-zinc-900',
                                    ]"
                                    @click="themePreset = preset.value"
                                >
                                    <span>{{ preset.label }}</span>
                                    <span
                                        v-if="preset.value === 'dark'"
                                        class="size-3.5 rounded-full border border-zinc-600 bg-zinc-900"
                                    />
                                    <span
                                        v-else-if="preset.value === 'light'"
                                        class="size-3.5 rounded-full border border-zinc-300 bg-[#f7f7f5]"
                                    />
                                    <span
                                        v-else
                                        class="size-3.5 rounded-full border border-zinc-400 bg-[repeating-conic-gradient(#71717a_0%_25%,#e4e4e7_0%_50%)] bg-[length:6px_6px]"
                                    />
                                </button>
                            </div>
                        </div>

                        <!-- Aspect Ratio -->
                        <div class="space-y-2">
                            <p
                                class="font-mono text-[10px] font-semibold tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Aspect Ratio
                            </p>
                            <div class="grid grid-cols-2 gap-1.5">
                                <button
                                    v-for="aspect in ASPECT_PRESETS"
                                    :key="aspect.value"
                                    type="button"
                                    :class="[
                                        'cursor-pointer rounded-xl border px-3 py-2 text-left transition-colors',
                                        aspectPreset === aspect.value
                                            ? 'border-emerald-500/60 bg-emerald-500/10 text-zinc-950 dark:text-white'
                                            : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-300 dark:hover:bg-zinc-900',
                                    ]"
                                    @click="aspectPreset = aspect.value"
                                >
                                    <div class="text-xs font-semibold">
                                        {{ aspect.label }}
                                    </div>
                                    <div
                                        class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                    >
                                        {{ aspect.dims }}
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- Optional Toggles -->
                        <div class="space-y-2">
                            <p
                                class="font-mono text-[10px] font-semibold tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Card Options
                            </p>

                            <label
                                class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 px-3 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/50"
                                :class="
                                    !hasElevationData &&
                                    'cursor-not-allowed opacity-50'
                                "
                            >
                                <span
                                    class="flex items-center gap-2 text-xs font-medium text-zinc-800 dark:text-zinc-200"
                                >
                                    <Mountain class="size-3.5 text-amber-500" />
                                    Show Elevation Sparkline
                                </span>
                                <input
                                    v-model="showElevation"
                                    type="checkbox"
                                    :disabled="!hasElevationData"
                                    class="size-4 accent-emerald-600"
                                />
                            </label>

                            <label
                                class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 px-3 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/50"
                                :class="
                                    !hasWeatherData &&
                                    'cursor-not-allowed opacity-50'
                                "
                            >
                                <span
                                    class="flex items-center gap-2 text-xs font-medium text-zinc-800 dark:text-zinc-200"
                                >
                                    <CloudSun class="size-3.5 text-sky-500" />
                                    Show Weather
                                </span>
                                <input
                                    v-model="showWeather"
                                    type="checkbox"
                                    :disabled="!hasWeatherData"
                                    class="size-4 accent-emerald-600"
                                />
                            </label>

                            <label
                                class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 px-3 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/50"
                                :class="
                                    !hasPolyline &&
                                    'cursor-not-allowed opacity-50'
                                "
                            >
                                <div>
                                    <span
                                        class="flex items-center gap-2 text-xs font-medium text-zinc-800 dark:text-zinc-200"
                                    >
                                        <EyeOff
                                            class="size-3.5 text-emerald-500"
                                        />
                                        Hide Start/End 500m
                                    </span>
                                    <p
                                        class="mt-0.5 text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Privacy trim hides home location & pins
                                    </p>
                                </div>
                                <input
                                    v-model="privacyTrim"
                                    type="checkbox"
                                    :disabled="!hasPolyline"
                                    class="size-4 shrink-0 accent-emerald-600"
                                />
                            </label>
                        </div>
                    </div>

                    <!-- Export Actions -->
                    <div
                        class="space-y-2 border-t border-zinc-200/80 pt-4 dark:border-zinc-800/80"
                    >
                        <button
                            type="button"
                            :disabled="isExportingPng"
                            class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-semibold text-white shadow-xs transition-colors hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-60"
                            @click="downloadPng"
                        >
                            <Download class="size-3.5" />
                            <span>
                                {{
                                    isExportingPng
                                        ? 'Rendering PNG...'
                                        : 'Download PNG (2×)'
                                }}
                            </span>
                        </button>

                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2 text-xs font-semibold text-zinc-800 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                            @click="downloadSvg"
                        >
                            <FileCode class="size-3.5" />
                            <span>Download SVG</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
