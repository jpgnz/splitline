<script setup lang="ts">
import { computed } from 'vue';
import { decodePolyline } from '@/lib/polyline';
import { computeSvgPathArrows, type SvgPathArrow } from '@/lib/routeDirection';

const props = withDefaults(
    defineProps<{
        encodedPolyline: string | null;
        accentHex?: string;
        isLoop?: boolean;
    }>(),
    {
        accentHex: '#10b981',
        isLoop: false,
    },
);

const VIEW_WIDTH = 320;
const VIEW_HEIGHT = 128;
const PADDING = 14;

const projected = computed<{
    pathD: string;
    start: { x: number; y: number };
    end: { x: number; y: number };
    arrows: SvgPathArrow[];
} | null>(() => {
    if (!props.encodedPolyline) {
        return null;
    }

    const points = decodePolyline(props.encodedPolyline);

    if (points.length < 2) {
        return null;
    }

    let minLat = Number.POSITIVE_INFINITY;
    let maxLat = Number.NEGATIVE_INFINITY;
    let minLng = Number.POSITIVE_INFINITY;
    let maxLng = Number.NEGATIVE_INFINITY;

    for (const [lat, lng] of points) {
        if (lat < minLat) {
            minLat = lat;
        }

        if (lat > maxLat) {
            maxLat = lat;
        }

        if (lng < minLng) {
            minLng = lng;
        }

        if (lng > maxLng) {
            maxLng = lng;
        }
    }

    const midLatRad = (((minLat + maxLat) / 2) * Math.PI) / 180;
    const cosLat = Math.max(0.2, Math.cos(midLatRad));

    const spanX = Math.max(1e-6, (maxLng - minLng) * cosLat);
    const spanY = Math.max(1e-6, maxLat - minLat);

    const innerW = VIEW_WIDTH - PADDING * 2;
    const innerH = VIEW_HEIGHT - PADDING * 2;
    const scale = Math.min(innerW / spanX, innerH / spanY);

    const drawnW = spanX * scale;
    const drawnH = spanY * scale;
    const offsetX = (VIEW_WIDTH - drawnW) / 2;
    const offsetY = (VIEW_HEIGHT - drawnH) / 2;

    const coords = points.map(([lat, lng]) => ({
        x: Number((offsetX + (lng - minLng) * cosLat * scale).toFixed(1)),
        y: Number((offsetY + (maxLat - lat) * scale).toFixed(1)),
    }));

    const pathD = coords
        .map((pt, idx) => `${idx === 0 ? 'M' : 'L'}${pt.x} ${pt.y}`)
        .join(' ');

    const arrows = computeSvgPathArrows(coords);

    return {
        pathD,
        start: coords[0],
        end: coords[coords.length - 1],
        arrows,
    };
});
</script>

<template>
    <div
        class="relative h-32 w-full overflow-hidden rounded-lg border border-zinc-200/70 bg-zinc-50/90 dark:border-zinc-800/80 dark:bg-zinc-950/60"
    >
        <svg
            v-if="projected"
            :viewBox="`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`"
            class="h-full w-full"
            preserveAspectRatio="xMidYMid meet"
            aria-hidden="true"
        >
            <!-- Subtle dot/grid background lines -->
            <line
                x1="0"
                y1="64"
                x2="320"
                y2="64"
                stroke="currentColor"
                stroke-opacity="0.06"
                stroke-dasharray="3 4"
            />
            <line
                x1="160"
                y1="0"
                x2="160"
                y2="128"
                stroke="currentColor"
                stroke-opacity="0.06"
                stroke-dasharray="3 4"
            />

            <!-- Soft glow under-stroke -->
            <path
                :d="projected.pathD"
                fill="none"
                :stroke="accentHex"
                stroke-opacity="0.22"
                stroke-width="6.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            />

            <!-- Crisp route line -->
            <path
                :d="projected.pathD"
                fill="none"
                :stroke="accentHex"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            />

            <!-- Directional chevrons along the route -->
            <g
                v-for="(arrow, idx) in projected.arrows"
                :key="idx"
                :transform="`translate(${arrow.x} ${arrow.y}) rotate(${arrow.angleDeg})`"
            >
                <path
                    d="M-2.6 -3.6L3.2 0L-2.6 3.6L-0.8 0Z"
                    fill="none"
                    class="stroke-white dark:stroke-zinc-950"
                    stroke-width="2.4"
                    stroke-linejoin="round"
                />
                <path d="M-2.6 -3.6L3.2 0L-2.6 3.6L-0.8 0Z" :fill="accentHex" />
            </g>

            <!-- Finish point (shown when not a closed loop) -->
            <circle
                v-if="!isLoop"
                :cx="projected.end.x"
                :cy="projected.end.y"
                r="3.75"
                fill="#f43f5e"
                class="stroke-white dark:stroke-zinc-950"
                stroke-width="1.5"
            />

            <!-- Start point -->
            <circle
                :cx="projected.start.x"
                :cy="projected.start.y"
                r="4"
                fill="#10b981"
                class="stroke-white dark:stroke-zinc-950"
                stroke-width="1.5"
            />
        </svg>

        <div
            v-else
            class="flex h-full w-full items-center justify-center font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
        >
            No route preview
        </div>
    </div>
</template>
