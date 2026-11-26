<script setup lang="ts">
import {
    ArrowLeftRight,
    Crosshair,
    SlidersHorizontal,
    Wind,
    X,
    Zap,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { formatDurationClock, formatNumber } from '@/lib/format';
import {
    computeStreamSelectionStats,
    findMidpointIndex,
    type DetectedInterval,
    type StreamSelectionStats,
} from '@/lib/streamSelection';
import { useUnits } from '@/lib/units';
import type { ActivityWeatherPayload, StreamPoint } from '@/types/activity';

const props = withDefaults(
    defineProps<{
        series: StreamPoint[];
        startIndex: number | null;
        endIndex: number | null;
        selectionLabel?: string;
        pendingStartIndex?: number | null;
        inspectMode: boolean;
        detectedIntervals: DetectedInterval[];
        activeIntervalId?: string | null;
        usesPace: boolean;
        isRunning: boolean;
        thresholdPower?: number | null;
        weather?: ActivityWeatherPayload | null;
    }>(),
    {
        selectionLabel: 'Selected Slice',
        pendingStartIndex: null,
        activeIntervalId: null,
        thresholdPower: null,
        weather: null,
    },
);

const emit = defineEmits<{
    updateRange: [
        payload: {
            startIndex: number;
            endIndex: number;
            label?: string;
            intervalId?: string | null;
        },
    ];
    clear: [];
    toggleInspectMode: [];
}>();

const {
    isImperial,
    elevationUnit,
    elevationFromMeters,
    paceSecondsFromMps,
    speedFromMps,
    speedUnit,
    paceUnit,
    formatDistance,
    formatElevation,
    formatPace,
    formatSpeed,
    formatSpeedKmh,
} = useUnits();

const showHalfComparison = ref(false);

watch(
    () => props.series,
    () => {
        showHalfComparison.value = false;
    },
);

const maxIndex = computed<number>(() => Math.max(0, props.series.length - 1));

const hasDistance = computed<boolean>(() =>
    props.series.some((p) => p.distance !== null && p.distance > 0),
);

const hasSelection = computed<boolean>(
    () =>
        props.startIndex !== null &&
        props.endIndex !== null &&
        props.endIndex > props.startIndex &&
        props.series.length >= 2,
);

const effectiveStartIndex = computed<number>(() =>
    props.startIndex !== null
        ? Math.max(0, Math.min(maxIndex.value, props.startIndex))
        : 0,
);

const effectiveEndIndex = computed<number>(() =>
    props.endIndex !== null
        ? Math.max(0, Math.min(maxIndex.value, props.endIndex))
        : maxIndex.value,
);

const selectionStats = computed<StreamSelectionStats | null>(() => {
    if (!hasSelection.value) {
        return null;
    }

    return computeStreamSelectionStats(
        props.series,
        effectiveStartIndex.value,
        effectiveEndIndex.value,
        props.weather ?? null,
    );
});

const midpointIndex = computed<number>(() => findMidpointIndex(props.series));

const isFirstHalfActive = computed<boolean>(
    () =>
        hasSelection.value &&
        effectiveStartIndex.value === 0 &&
        effectiveEndIndex.value === midpointIndex.value &&
        props.activeIntervalId === null,
);

const isSecondHalfActive = computed<boolean>(
    () =>
        hasSelection.value &&
        effectiveStartIndex.value === midpointIndex.value &&
        effectiveEndIndex.value === maxIndex.value &&
        props.activeIntervalId === null,
);

const halfComparison = computed<{
    first: StreamSelectionStats;
    second: StreamSelectionStats;
} | null>(() => {
    if (
        !showHalfComparison.value ||
        props.series.length < 4 ||
        midpointIndex.value <= 0 ||
        midpointIndex.value >= maxIndex.value
    ) {
        return null;
    }

    const first = computeStreamSelectionStats(
        props.series,
        0,
        midpointIndex.value,
        props.weather ?? null,
    );
    const second = computeStreamSelectionStats(
        props.series,
        midpointIndex.value,
        maxIndex.value,
        props.weather ?? null,
    );

    if (!first || !second) {
        return null;
    }

    return { first, second };
});

const intervalMetricLabel = computed<string>(() => {
    const first = props.detectedIntervals[0];

    if (!first) {
        return '';
    }

    if (first.metric === 'power') {
        return 'Power';
    }

    if (first.metric === 'gap_speed') {
        return 'GAP';
    }

    return props.usesPace ? 'Pace' : 'Speed';
});

const shouldRenderPanel = computed<boolean>(
    () =>
        props.series.length >= 2 &&
        (props.inspectMode ||
            hasSelection.value ||
            props.detectedIntervals.length >= 2),
);

const isExpanded = computed<boolean>(
    () => props.inspectMode || hasSelection.value || showHalfComparison.value,
);

const selectionStartPct = computed<number>(() => {
    if (!hasSelection.value || maxIndex.value === 0) {
        return 0;
    }

    return (effectiveStartIndex.value / maxIndex.value) * 100;
});

const selectionWidthPct = computed<number>(() => {
    if (!hasSelection.value || maxIndex.value === 0) {
        return 0;
    }

    return Math.max(
        1,
        ((effectiveEndIndex.value - effectiveStartIndex.value) /
            maxIndex.value) *
            100,
    );
});

const pendingAnchorPct = computed<number | null>(() => {
    if (props.pendingStartIndex === null || maxIndex.value === 0) {
        return null;
    }

    return (props.pendingStartIndex / maxIndex.value) * 100;
});

function formatPointMarker(idx: number): string {
    const pt = props.series[idx];

    if (!pt) {
        return '—';
    }

    if (hasDistance.value && pt.distance !== null) {
        return formatDistance(pt.distance, 2);
    }

    return formatDurationClock(pt.time);
}

function formatPointFull(idx: number): string {
    const pt = props.series[idx];

    if (!pt) {
        return '—';
    }

    if (hasDistance.value && pt.distance !== null) {
        return `${formatDistance(pt.distance, 2)} · ${formatDurationClock(pt.time)}`;
    }

    return formatDurationClock(pt.time);
}

function formatPrimaryOutput(speedMps: number | null): string {
    if (speedMps === null || speedMps <= 0) {
        return '—';
    }

    return props.usesPace ? formatPace(speedMps) : formatSpeed(speedMps);
}

function formatVam(vamMetersPerHour: number | null): string | null {
    if (vamMetersPerHour === null) {
        return null;
    }

    const converted = Math.round(elevationFromMeters(vamMetersPerHour));

    return `${formatNumber(converted, 0)} ${elevationUnit.value}/h`;
}

function formatGrade(gradePct: number | null): string {
    if (gradePct === null) {
        return '—';
    }

    const sign = gradePct > 0 ? '+' : '';

    return `${sign}${gradePct.toFixed(1)}%`;
}

function formatWindSummary(effectiveKmh: number | null): {
    label: string;
    toneClass: string;
} | null {
    if (effectiveKmh === null) {
        return null;
    }

    if (Math.abs(effectiveKmh) < 2) {
        return {
            label: 'Calm / Cross',
            toneClass: 'text-zinc-600 dark:text-zinc-300',
        };
    }

    const speedText = formatSpeedKmh(Math.abs(effectiveKmh), 0);

    if (effectiveKmh > 0) {
        return {
            label: `${speedText} Tail`,
            toneClass: 'text-emerald-600 dark:text-emerald-400',
        };
    }

    return {
        label: `${speedText} Head`,
        toneClass: 'text-rose-600 dark:text-rose-400',
    };
}

function formatIntervalHint(interval: DetectedInterval): string {
    const duration = formatDurationClock(interval.stats.durationSeconds);

    if (interval.metric === 'power' && interval.stats.avgPower !== null) {
        return `${duration} · ${interval.stats.avgPower} W`;
    }

    if (interval.stats.avgSpeed !== null && interval.stats.avgSpeed > 0) {
        const paceOrSpeed = props.usesPace
            ? formatPace(interval.stats.avgSpeed)
            : formatSpeed(interval.stats.avgSpeed);

        return `${duration} · ${paceOrSpeed}`;
    }

    return duration;
}

function formatPaceOrSpeedDelta(
    firstSpeed: number | null,
    secondSpeed: number | null,
): { text: string; toneClass: string } | null {
    if (
        firstSpeed === null ||
        secondSpeed === null ||
        firstSpeed <= 0 ||
        secondSpeed <= 0
    ) {
        return null;
    }

    if (props.usesPace) {
        const p1 = paceSecondsFromMps(firstSpeed);
        const p2 = paceSecondsFromMps(secondSpeed);

        if (p1 === null || p2 === null) {
            return null;
        }

        const deltaSec = Math.round(p2 - p1);

        if (deltaSec === 0) {
            return {
                text: 'Even split',
                toneClass: 'text-zinc-500 dark:text-zinc-400',
            };
        }

        const absSec = Math.abs(deltaSec);
        const mins = Math.floor(absSec / 60);
        const secs = absSec % 60;
        const formatted = `${deltaSec < 0 ? '-' : '+'}${mins}:${String(secs).padStart(2, '0')} ${paceUnit.value}`;

        return {
            text: formatted,
            toneClass:
                deltaSec < 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-amber-600 dark:text-amber-400',
        };
    }

    const s1 = speedFromMps(firstSpeed);
    const s2 = speedFromMps(secondSpeed);
    const delta = Number((s2 - s1).toFixed(1));

    if (delta === 0) {
        return {
            text: 'Even split',
            toneClass: 'text-zinc-500 dark:text-zinc-400',
        };
    }

    return {
        text: `${delta > 0 ? '+' : ''}${delta.toFixed(1)} ${speedUnit.value}`,
        toneClass:
            delta > 0
                ? 'text-emerald-600 dark:text-emerald-400'
                : 'text-amber-600 dark:text-amber-400',
    };
}

function formatSignedDelta(
    delta: number | null,
    unit: string,
    invertTone = false,
): { text: string; toneClass: string } | null {
    if (delta === null || !Number.isFinite(delta)) {
        return null;
    }

    const rounded = Math.round(delta);

    if (rounded === 0) {
        return {
            text: `0 ${unit}`,
            toneClass: 'text-zinc-500 dark:text-zinc-400',
        };
    }

    const positiveIsGood = !invertTone;
    const isGood = positiveIsGood ? rounded > 0 : rounded < 0;

    return {
        text: `${rounded > 0 ? '+' : ''}${rounded} ${unit}`,
        toneClass: isGood
            ? 'text-emerald-600 dark:text-emerald-400'
            : 'text-amber-600 dark:text-amber-400',
    };
}

function handleStartSliderInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    const nextStart = Math.max(
        0,
        Math.min(maxIndex.value - 1, Number(target.value)),
    );
    const nextEnd = Math.max(nextStart + 1, effectiveEndIndex.value);

    emit('updateRange', {
        startIndex: nextStart,
        endIndex: nextEnd,
        label: 'Custom Slice',
        intervalId: null,
    });
}

function handleEndSliderInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    const nextEnd = Math.max(1, Math.min(maxIndex.value, Number(target.value)));
    const nextStart = Math.min(effectiveStartIndex.value, nextEnd - 1);

    emit('updateRange', {
        startIndex: nextStart,
        endIndex: nextEnd,
        label: 'Custom Slice',
        intervalId: null,
    });
}

function selectFirstHalf(): void {
    if (midpointIndex.value <= 0) {
        return;
    }

    emit('updateRange', {
        startIndex: 0,
        endIndex: midpointIndex.value,
        label: '1st Half',
        intervalId: null,
    });
}

function selectSecondHalf(): void {
    if (midpointIndex.value >= maxIndex.value) {
        return;
    }

    emit('updateRange', {
        startIndex: midpointIndex.value,
        endIndex: maxIndex.value,
        label: '2nd Half',
        intervalId: null,
    });
}

function selectInterval(interval: DetectedInterval): void {
    if (props.activeIntervalId === interval.id) {
        emit('clear');

        return;
    }

    emit('updateRange', {
        startIndex: interval.startIndex,
        endIndex: interval.endIndex,
        label: interval.label,
        intervalId: interval.id,
    });
}

function toggleHalfComparison(): void {
    showHalfComparison.value = !showHalfComparison.value;
}
</script>

<template>
    <div v-if="shouldRenderPanel" class="mb-4">
        <!-- Compact Auto-Intervals Discovery Strip (when inspector is closed & no slice active) -->
        <div
            v-if="!isExpanded"
            class="flex flex-wrap items-center justify-between gap-2.5 rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/70 px-3 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/40"
        >
            <div class="flex flex-wrap items-center gap-1.5">
                <span
                    class="inline-flex items-center gap-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    <Zap class="size-3 text-indigo-500 dark:text-indigo-400" />
                    Auto Intervals ({{ detectedIntervals.length }})
                </span>

                <button
                    v-for="interval in detectedIntervals"
                    :key="interval.id"
                    type="button"
                    :title="formatIntervalHint(interval)"
                    class="cursor-pointer rounded-md border border-zinc-200/90 bg-white px-2 py-0.5 font-mono text-[11px] text-zinc-700 tabular-nums transition hover:border-indigo-400/70 hover:text-indigo-600 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-300 dark:hover:border-indigo-500/60 dark:hover:text-indigo-300"
                    @click="selectInterval(interval)"
                >
                    {{ interval.shortLabel }} ·
                    {{ formatDurationClock(interval.stats.durationSeconds) }}
                </button>
            </div>

            <button
                type="button"
                class="inline-flex cursor-pointer items-center gap-1 font-mono text-[10px] tracking-wider text-indigo-600 uppercase transition hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300"
                @click="emit('toggleInspectMode')"
            >
                <SlidersHorizontal class="size-3" />
                Slice Inspector
            </button>
        </div>

        <!-- Full Interactive Slice & Interval Inspector -->
        <div
            v-else
            class="space-y-3 rounded-lg border border-zinc-200/90 bg-[#f7f7f5]/75 p-3.5 dark:border-zinc-800/90 dark:bg-zinc-900/45"
        >
            <!-- Top Bar: Active Status + Quick Presets -->
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200/70 pb-2.5 dark:border-zinc-800/70"
            >
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 font-mono text-[11px] font-medium tracking-wider uppercase"
                        :class="
                            hasSelection
                                ? 'text-indigo-600 dark:text-indigo-400'
                                : 'text-zinc-600 dark:text-zinc-300'
                        "
                    >
                        <Crosshair class="size-3.5 shrink-0" />
                        {{ hasSelection ? selectionLabel : 'Slice Inspector' }}
                    </span>

                    <span
                        v-if="hasSelection"
                        class="font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ formatPointMarker(effectiveStartIndex) }} –
                        {{ formatPointMarker(effectiveEndIndex) }}
                    </span>
                    <span
                        v-else-if="pendingStartIndex !== null"
                        class="rounded bg-indigo-500/10 px-1.5 py-0.5 font-mono text-[11px] text-indigo-700 tabular-nums dark:bg-indigo-500/15 dark:text-indigo-300"
                    >
                        Start anchored at
                        {{ formatPointMarker(pendingStartIndex) }} — click a
                        second point on any chart to set End
                    </span>
                    <span
                        v-else
                        class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                    >
                        Click two points or drag across any chart below, or use
                        sliders
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        class="cursor-pointer rounded-md border px-2 py-1 font-mono text-[10px] tracking-wider uppercase transition"
                        :class="
                            isFirstHalfActive
                                ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="selectFirstHalf"
                    >
                        1st Half
                    </button>

                    <button
                        type="button"
                        class="cursor-pointer rounded-md border px-2 py-1 font-mono text-[10px] tracking-wider uppercase transition"
                        :class="
                            isSecondHalfActive
                                ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="selectSecondHalf"
                    >
                        2nd Half
                    </button>

                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1 rounded-md border px-2 py-1 font-mono text-[10px] tracking-wider uppercase transition"
                        :class="
                            showHalfComparison
                                ? 'border-indigo-500/60 bg-indigo-500/10 text-indigo-700 dark:border-indigo-400/50 dark:bg-indigo-500/15 dark:text-indigo-300'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="toggleHalfComparison"
                    >
                        <ArrowLeftRight class="size-3" />
                        Compare 1st vs 2nd
                    </button>

                    <button
                        v-if="hasSelection || pendingStartIndex !== null"
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-zinc-200/90 bg-white px-2 py-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase transition hover:border-rose-300 hover:text-rose-600 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-rose-500/50 dark:hover:text-rose-400"
                        @click="emit('clear')"
                    >
                        <X class="size-3" />
                        Clear
                    </button>
                </div>
            </div>

            <!-- Auto-Detected Intervals Quick-Pick Pills -->
            <div
                v-if="detectedIntervals.length >= 2"
                class="flex flex-wrap items-center gap-1.5"
            >
                <span
                    class="inline-flex items-center gap-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    <Zap class="size-3 text-indigo-500 dark:text-indigo-400" />
                    Auto Intervals
                    <span
                        v-if="intervalMetricLabel"
                        class="text-zinc-400 dark:text-zinc-500"
                    >
                        ({{ intervalMetricLabel }})
                    </span>
                </span>

                <button
                    v-for="interval in detectedIntervals"
                    :key="interval.id"
                    type="button"
                    :title="formatIntervalHint(interval)"
                    class="cursor-pointer rounded-md border px-2 py-0.5 font-mono text-[11px] tabular-nums transition"
                    :class="
                        activeIntervalId === interval.id
                            ? 'border-indigo-600 bg-indigo-600 text-white dark:border-indigo-500 dark:bg-indigo-500 dark:text-white'
                            : 'border-zinc-200/90 bg-white text-zinc-700 hover:border-indigo-400/70 hover:text-indigo-600 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-300 dark:hover:border-indigo-500/60 dark:hover:text-indigo-300'
                    "
                    @click="selectInterval(interval)"
                >
                    {{ interval.shortLabel }} ·
                    {{ formatDurationClock(interval.stats.durationSeconds) }}
                </button>
            </div>

            <!-- Visual Span Track + Dual Fine-Tuning Sliders -->
            <div class="space-y-2">
                <!-- Timeline Span Bar (with subtle auto-interval markers) -->
                <div
                    class="relative h-2 w-full overflow-hidden rounded-full bg-zinc-200/90 dark:bg-zinc-800"
                >
                    <div
                        v-for="interval in detectedIntervals"
                        :key="`bar-${interval.id}`"
                        class="absolute top-0 bottom-0 bg-indigo-400/35 dark:bg-indigo-400/30"
                        :style="{
                            left: `${maxIndex > 0 ? (interval.startIndex / maxIndex) * 100 : 0}%`,
                            width: `${maxIndex > 0 ? Math.max(0.8, ((interval.endIndex - interval.startIndex) / maxIndex) * 100) : 0}%`,
                        }"
                    />

                    <div
                        v-if="hasSelection"
                        class="absolute top-0 bottom-0 rounded-full bg-indigo-600 dark:bg-indigo-400"
                        :style="{
                            left: `${selectionStartPct}%`,
                            width: `${selectionWidthPct}%`,
                        }"
                    />

                    <div
                        v-else-if="pendingAnchorPct !== null"
                        class="absolute top-0 bottom-0 w-1 -translate-x-0.5 rounded-full bg-indigo-600 dark:bg-indigo-400"
                        :style="{ left: `${pendingAnchorPct}%` }"
                    />
                </div>

                <!-- Start / End Sliders -->
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1">
                        <span
                            class="flex items-center justify-between font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <span>Start</span>
                            <span
                                class="text-zinc-700 normal-case tabular-nums dark:text-zinc-200"
                            >
                                {{ formatPointFull(effectiveStartIndex) }}
                            </span>
                        </span>
                        <input
                            type="range"
                            :min="0"
                            :max="Math.max(0, maxIndex - 1)"
                            :value="effectiveStartIndex"
                            class="h-1.5 w-full cursor-pointer appearance-none rounded-lg bg-zinc-200 accent-indigo-600 dark:bg-zinc-800 dark:accent-indigo-400"
                            @input="handleStartSliderInput"
                        />
                    </label>

                    <label class="flex flex-col gap-1">
                        <span
                            class="flex items-center justify-between font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <span>End</span>
                            <span
                                class="text-zinc-700 normal-case tabular-nums dark:text-zinc-200"
                            >
                                {{ formatPointFull(effectiveEndIndex) }}
                            </span>
                        </span>
                        <input
                            type="range"
                            :min="1"
                            :max="maxIndex"
                            :value="effectiveEndIndex"
                            class="h-1.5 w-full cursor-pointer appearance-none rounded-lg bg-zinc-200 accent-indigo-600 dark:bg-zinc-800 dark:accent-indigo-400"
                            @input="handleEndSliderInput"
                        />
                    </label>
                </div>
            </div>

            <!-- Live Slice Metrics Strip -->
            <div
                v-if="selectionStats"
                class="grid grid-cols-2 gap-2 pt-1 sm:grid-cols-3 lg:grid-cols-6"
            >
                <!-- Duration -->
                <div
                    class="rounded-md border border-zinc-200/80 bg-white px-2.5 py-2 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Duration
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{
                            formatDurationClock(selectionStats.durationSeconds)
                        }}
                    </div>
                    <div
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ formatDurationClock(selectionStats.startTime) }} –
                        {{ formatDurationClock(selectionStats.endTime) }}
                    </div>
                </div>

                <!-- Distance -->
                <div
                    v-if="selectionStats.distanceMeters !== null"
                    class="rounded-md border border-zinc-200/80 bg-white px-2.5 py-2 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Distance
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatDistance(selectionStats.distanceMeters, 2) }}
                    </div>
                    <div
                        v-if="
                            selectionStats.startDistanceMeters !== null &&
                            selectionStats.endDistanceMeters !== null
                        "
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{
                            formatDistance(
                                selectionStats.startDistanceMeters,
                                1,
                            )
                        }}
                        →
                        {{
                            formatDistance(selectionStats.endDistanceMeters, 1)
                        }}
                    </div>
                </div>

                <!-- Pace / Speed (+ GAP) -->
                <div
                    v-if="selectionStats.avgSpeed !== null"
                    class="rounded-md border border-zinc-200/80 bg-white px-2.5 py-2 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{ usesPace ? 'Avg Pace' : 'Avg Speed' }}
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatPrimaryOutput(selectionStats.avgSpeed) }}
                    </div>
                    <div
                        v-if="isRunning && selectionStats.avgGapSpeed !== null"
                        class="font-mono text-[10px] text-amber-600 tabular-nums dark:text-amber-400"
                    >
                        GAP {{ formatPace(selectionStats.avgGapSpeed) }}
                    </div>
                    <div
                        v-else-if="
                            selectionStats.maxSpeed !== null &&
                            selectionStats.maxSpeed > 0
                        "
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        Max {{ formatPrimaryOutput(selectionStats.maxSpeed) }}
                    </div>
                </div>

                <!-- Heart Rate -->
                <div
                    v-if="selectionStats.avgHr !== null"
                    class="rounded-md border border-zinc-200/80 bg-white px-2.5 py-2 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Heart Rate
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold text-rose-600 tabular-nums dark:text-rose-400"
                    >
                        {{ selectionStats.avgHr }} bpm
                    </div>
                    <div
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        Min {{ selectionStats.minHr ?? '—' }} · Max
                        {{ selectionStats.maxHr ?? '—' }}
                    </div>
                </div>

                <!-- Power & NP -->
                <div
                    v-if="selectionStats.avgPower !== null"
                    class="rounded-md border border-zinc-200/80 bg-white px-2.5 py-2 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Power
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold text-sky-600 tabular-nums dark:text-sky-400"
                    >
                        {{ selectionStats.avgPower }} W
                        <span
                            v-if="thresholdPower && thresholdPower > 0"
                            class="text-[11px] font-normal text-zinc-400 dark:text-zinc-500"
                        >
                            ({{
                                Math.round(
                                    (selectionStats.avgPower / thresholdPower) *
                                        100,
                                )
                            }}%)
                        </span>
                    </div>
                    <div
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        <template
                            v-if="selectionStats.normalizedPower !== null"
                        >
                            NP {{ selectionStats.normalizedPower }} W ·
                        </template>
                        Max {{ selectionStats.maxPower ?? '—' }} W
                    </div>
                </div>

                <!-- Elevation, Grade & VAM -->
                <div
                    v-if="selectionStats.elevationGain !== null"
                    class="rounded-md border border-zinc-200/80 bg-white px-2.5 py-2 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Elevation
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        +{{ formatElevation(selectionStats.elevationGain) }}
                    </div>
                    <div
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ formatGrade(selectionStats.avgGradePct) }}
                        <template v-if="formatVam(selectionStats.vam)">
                            · {{ formatVam(selectionStats.vam) }}
                        </template>
                    </div>
                </div>

                <!-- Cadence -->
                <div
                    v-if="selectionStats.avgCadence !== null"
                    class="rounded-md border border-zinc-200/80 bg-white px-2.5 py-2 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Cadence
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ selectionStats.avgCadence }}
                        {{ isRunning ? 'spm' : 'rpm' }}
                    </div>
                    <div
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ formatNumber(selectionStats.pointCount, 0) }} pts
                    </div>
                </div>

                <!-- Effective Wind (when route weather samples available) -->
                <div
                    v-if="formatWindSummary(selectionStats.avgEffectiveWindKmh)"
                    class="rounded-md border border-zinc-200/80 bg-white px-2.5 py-2 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="flex items-center gap-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        <Wind class="size-3" />
                        Effective Wind
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold tabular-nums"
                        :class="
                            formatWindSummary(
                                selectionStats.avgEffectiveWindKmh,
                            )?.toneClass
                        "
                    >
                        {{
                            formatWindSummary(
                                selectionStats.avgEffectiveWindKmh,
                            )?.label
                        }}
                    </div>
                    <div
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{
                            isImperial
                                ? 'Route-aligned mph'
                                : 'Route-aligned km/h'
                        }}
                    </div>
                </div>
            </div>

            <!-- Side-by-Side 1st Half vs 2nd Half Comparison -->
            <div
                v-if="halfComparison"
                class="overflow-hidden rounded-md border border-zinc-200/80 bg-white dark:border-zinc-800/80 dark:bg-[#141417]"
            >
                <div
                    class="flex items-center justify-between border-b border-zinc-200/70 bg-[#f7f7f5]/80 px-3 py-1.5 dark:border-zinc-800/70 dark:bg-zinc-900/50"
                >
                    <span
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        1st Half vs 2nd Half Split
                    </span>
                    <button
                        type="button"
                        class="cursor-pointer font-mono text-[10px] text-zinc-400 uppercase hover:text-zinc-700 dark:text-zinc-500 dark:hover:text-zinc-200"
                        @click="showHalfComparison = false"
                    >
                        Hide
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table
                        class="w-full text-left font-mono text-xs tabular-nums"
                    >
                        <thead
                            class="border-b border-zinc-100 text-[10px] text-zinc-400 uppercase dark:border-zinc-800/80 dark:text-zinc-500"
                        >
                            <tr>
                                <th class="px-3 py-1.5 font-medium">Metric</th>
                                <th class="px-3 py-1.5 text-right font-medium">
                                    <button
                                        type="button"
                                        class="cursor-pointer underline decoration-zinc-300 underline-offset-2 hover:text-indigo-600 dark:decoration-zinc-700 dark:hover:text-indigo-400"
                                        @click="selectFirstHalf"
                                    >
                                        1st Half
                                    </button>
                                </th>
                                <th class="px-3 py-1.5 text-right font-medium">
                                    <button
                                        type="button"
                                        class="cursor-pointer underline decoration-zinc-300 underline-offset-2 hover:text-indigo-600 dark:decoration-zinc-700 dark:hover:text-indigo-400"
                                        @click="selectSecondHalf"
                                    >
                                        2nd Half
                                    </button>
                                </th>
                                <th class="px-3 py-1.5 text-right font-medium">
                                    Delta
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-zinc-100 dark:divide-zinc-800/70"
                        >
                            <tr>
                                <td
                                    class="px-3 py-1.5 text-zinc-500 dark:text-zinc-400"
                                >
                                    Duration
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    {{
                                        formatDurationClock(
                                            halfComparison.first
                                                .durationSeconds,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    {{
                                        formatDurationClock(
                                            halfComparison.second
                                                .durationSeconds,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        formatSignedDelta(
                                            halfComparison.second
                                                .durationSeconds -
                                                halfComparison.first
                                                    .durationSeconds,
                                            's',
                                            true,
                                        )?.text ?? '—'
                                    }}
                                </td>
                            </tr>

                            <tr
                                v-if="
                                    halfComparison.first.avgSpeed !== null &&
                                    halfComparison.second.avgSpeed !== null
                                "
                            >
                                <td
                                    class="px-3 py-1.5 text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ usesPace ? 'Avg Pace' : 'Avg Speed' }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        formatPrimaryOutput(
                                            halfComparison.first.avgSpeed,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        formatPrimaryOutput(
                                            halfComparison.second.avgSpeed,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right font-medium"
                                    :class="
                                        formatPaceOrSpeedDelta(
                                            halfComparison.first.avgSpeed,
                                            halfComparison.second.avgSpeed,
                                        )?.toneClass
                                    "
                                >
                                    {{
                                        formatPaceOrSpeedDelta(
                                            halfComparison.first.avgSpeed,
                                            halfComparison.second.avgSpeed,
                                        )?.text ?? '—'
                                    }}
                                </td>
                            </tr>

                            <tr
                                v-if="
                                    isRunning &&
                                    halfComparison.first.avgGapSpeed !== null &&
                                    halfComparison.second.avgGapSpeed !== null
                                "
                            >
                                <td
                                    class="px-3 py-1.5 text-zinc-500 dark:text-zinc-400"
                                >
                                    GAP
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    {{
                                        formatPace(
                                            halfComparison.first.avgGapSpeed,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    {{
                                        formatPace(
                                            halfComparison.second.avgGapSpeed,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right font-medium"
                                    :class="
                                        formatPaceOrSpeedDelta(
                                            halfComparison.first.avgGapSpeed,
                                            halfComparison.second.avgGapSpeed,
                                        )?.toneClass
                                    "
                                >
                                    {{
                                        formatPaceOrSpeedDelta(
                                            halfComparison.first.avgGapSpeed,
                                            halfComparison.second.avgGapSpeed,
                                        )?.text ?? '—'
                                    }}
                                </td>
                            </tr>

                            <tr
                                v-if="
                                    halfComparison.first.avgHr !== null &&
                                    halfComparison.second.avgHr !== null
                                "
                            >
                                <td
                                    class="px-3 py-1.5 text-zinc-500 dark:text-zinc-400"
                                >
                                    Avg HR
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ halfComparison.first.avgHr }} bpm
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ halfComparison.second.avgHr }} bpm
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right font-medium"
                                    :class="
                                        formatSignedDelta(
                                            halfComparison.second.avgHr -
                                                halfComparison.first.avgHr,
                                            'bpm',
                                            true,
                                        )?.toneClass
                                    "
                                >
                                    {{
                                        formatSignedDelta(
                                            halfComparison.second.avgHr -
                                                halfComparison.first.avgHr,
                                            'bpm',
                                            true,
                                        )?.text ?? '—'
                                    }}
                                </td>
                            </tr>

                            <tr
                                v-if="
                                    halfComparison.first.avgPower !== null &&
                                    halfComparison.second.avgPower !== null
                                "
                            >
                                <td
                                    class="px-3 py-1.5 text-zinc-500 dark:text-zinc-400"
                                >
                                    Avg Power
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ halfComparison.first.avgPower }} W
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ halfComparison.second.avgPower }} W
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right font-medium"
                                    :class="
                                        formatSignedDelta(
                                            halfComparison.second.avgPower -
                                                halfComparison.first.avgPower,
                                            'W',
                                        )?.toneClass
                                    "
                                >
                                    {{
                                        formatSignedDelta(
                                            halfComparison.second.avgPower -
                                                halfComparison.first.avgPower,
                                            'W',
                                        )?.text ?? '—'
                                    }}
                                </td>
                            </tr>

                            <tr
                                v-if="
                                    halfComparison.first.elevationGain !==
                                        null &&
                                    halfComparison.second.elevationGain !== null
                                "
                            >
                                <td
                                    class="px-3 py-1.5 text-zinc-500 dark:text-zinc-400"
                                >
                                    Elev Gain
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    +{{
                                        formatElevation(
                                            halfComparison.first.elevationGain,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-800 dark:text-zinc-200"
                                >
                                    +{{
                                        formatElevation(
                                            halfComparison.second.elevationGain,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-3 py-1.5 text-right text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        formatSignedDelta(
                                            elevationFromMeters(
                                                halfComparison.second
                                                    .elevationGain -
                                                    halfComparison.first
                                                        .elevationGain,
                                            ),
                                            elevationUnit,
                                        )?.text ?? '—'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
