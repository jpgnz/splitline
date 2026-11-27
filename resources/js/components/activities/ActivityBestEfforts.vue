<script setup lang="ts">
import type { EChartsOption } from 'echarts';
import { Trophy, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatDurationClock, formatNumber } from '@/lib/format';
import {
    computeAllDurationPeakEfforts,
    type BestEffortMode,
    type DurationPeakEffort,
} from '@/lib/peakEfforts';
import { formatGrade } from '@/lib/segments';
import {
    distanceFromMeters,
    elevationFromMeters,
    paceSecondsFromMps,
    useUnits,
} from '@/lib/units';
import type {
    ActivityBestEffortItem,
    HighlightRange,
    StreamPoint,
} from '@/types/activity';

const props = withDefaults(
    defineProps<{
        bestEfforts: ActivityBestEffortItem[];
        series: StreamPoint[];
        usesPace: boolean;
        isRunning?: boolean;
        isCycling?: boolean;
        isIndoor?: boolean;
        athleteWeightKg?: number | null;
        powerEffortRanks?: Record<string, number> | null;
        highlightedRange?: HighlightRange | null;
    }>(),
    {
        isRunning: false,
        isCycling: false,
        isIndoor: false,
        athleteWeightKg: null,
        powerEffortRanks: null,
        highlightedRange: undefined,
    },
);

const emit = defineEmits<{
    selectRange: [range: HighlightRange | null];
}>();

const theme = useChartTheme();
const {
    units,
    distanceUnit,
    elevationUnit,
    paceUnit,
    formatPace,
    formatPaceSeconds,
    formatSpeed,
    formatVam,
} = useUnits();

const durationEfforts = computed(() =>
    computeAllDurationPeakEfforts(props.series, {
        isRunning: props.isRunning,
        isCycling: props.isCycling,
        isIndoor: props.isIndoor,
        athleteWeightKg: props.athleteWeightKg,
        powerEffortRanks: props.powerEffortRanks,
    }),
);

const modeOptions = computed<Array<{ value: BestEffortMode; label: string }>>(
    () => {
        const list: Array<{ value: BestEffortMode; label: string }> = [];

        if (props.bestEfforts.length > 0) {
            list.push({ value: 'distance', label: 'Distance' });
        }

        if (props.isRunning && durationEfforts.value.gap.length > 0) {
            list.push({ value: 'gap', label: 'GAP' });
        }

        if (durationEfforts.value.power.length > 0) {
            list.push({ value: 'power', label: 'Power' });
        }

        if (durationEfforts.value.hr.length > 0) {
            list.push({ value: 'hr', label: 'Heart Rate' });
        }

        if (durationEfforts.value.climbing.length > 0) {
            list.push({ value: 'climbing', label: 'Climbing' });
        }

        return list;
    },
);

const selectedMode = ref<BestEffortMode>(
    modeOptions.value[0]?.value ?? 'distance',
);
const selectedRowKey = ref<string | null>(null);

watch(
    modeOptions,
    (options) => {
        if (options.length === 0) {
            return;
        }

        if (!options.some((opt) => opt.value === selectedMode.value)) {
            selectedMode.value = options[0].value;
            selectedRowKey.value = null;
            emit('selectRange', null);
        }
    },
    { immediate: true },
);

watch(
    () => [props.bestEfforts, props.series],
    () => {
        selectedRowKey.value = null;
        emit('selectRange', null);

        if (
            modeOptions.value.length > 0 &&
            !modeOptions.value.some((opt) => opt.value === selectedMode.value)
        ) {
            selectedMode.value = modeOptions.value[0].value;
        }
    },
);

watch(selectedMode, () => {
    selectedRowKey.value = null;
    emit('selectRange', null);
});

watch(
    () => props.highlightedRange,
    (nextRange) => {
        if (nextRange === null) {
            selectedRowKey.value = null;
        }
    },
);

const activeDurationRows = computed<DurationPeakEffort[]>(() => {
    if (selectedMode.value === 'distance') {
        return [];
    }

    return durationEfforts.value[selectedMode.value];
});

const hasPowerColumn = computed<boolean>(
    () =>
        durationEfforts.value.power.length > 0 ||
        props.series.some((p) => p.power !== null && p.power > 0),
);

const selectedDistanceEffort = computed<ActivityBestEffortItem | null>(() => {
    if (selectedMode.value !== 'distance' || selectedRowKey.value === null) {
        return null;
    }

    return (
        props.bestEfforts.find(
            (e) => `distance-${e.id}` === selectedRowKey.value,
        ) ?? null
    );
});

const selectedDurationEffort = computed<DurationPeakEffort | null>(() => {
    if (selectedMode.value === 'distance' || selectedRowKey.value === null) {
        return null;
    }

    return (
        activeDurationRows.value.find((e) => e.id === selectedRowKey.value) ??
        null
    );
});

function resolveDistanceEffortRange(
    effort: ActivityBestEffortItem,
): HighlightRange | null {
    if (
        effort.start_distance_meters !== null &&
        effort.end_distance_meters !== null &&
        effort.end_distance_meters > effort.start_distance_meters
    ) {
        return {
            startDistance: effort.start_distance_meters,
            endDistance: effort.end_distance_meters,
            label: `${effort.label} (${formatDurationClock(effort.effective_seconds)})`,
        };
    }

    if (effort.start_offset_seconds !== null && props.series.length > 1) {
        const startSec = effort.start_offset_seconds;
        const endSec = startSec + effort.elapsed_seconds;
        const startPt = props.series.find(
            (p) => p.time >= startSec && p.distance !== null,
        );
        const endPt = [...props.series]
            .reverse()
            .find((p) => p.time <= endSec && p.distance !== null);

        if (
            startPt?.distance !== null &&
            startPt?.distance !== undefined &&
            endPt?.distance !== null &&
            endPt?.distance !== undefined &&
            endPt.distance > startPt.distance
        ) {
            return {
                startDistance: startPt.distance,
                endDistance: endPt.distance,
                label: `${effort.label} (${formatDurationClock(effort.effective_seconds)})`,
            };
        }
    }

    return null;
}

function formatDurationEffortHighlightLabel(
    effort: DurationPeakEffort,
): string {
    if (effort.mode === 'power') {
        return `${effort.label} Power (${Math.round(effort.value)} W)`;
    }

    if (effort.mode === 'gap') {
        return `${effort.label} GAP (${formatPace(effort.value)})`;
    }

    if (effort.mode === 'hr') {
        return `${effort.label} HR (${Math.round(effort.value)} bpm)`;
    }

    return `${effort.label} Climb (+${Math.round(elevationFromMeters(effort.value, units.value))} ${elevationUnit.value})`;
}

function resolveDurationEffortRange(
    effort: DurationPeakEffort,
): HighlightRange | null {
    if (
        effort.startDistanceMeters !== null &&
        effort.endDistanceMeters !== null &&
        effort.endDistanceMeters > effort.startDistanceMeters
    ) {
        return {
            startDistance: effort.startDistanceMeters,
            endDistance: effort.endDistanceMeters,
            label: formatDurationEffortHighlightLabel(effort),
        };
    }

    return null;
}

const activeHighlightLabel = computed<string | null>(() => {
    if (selectedDistanceEffort.value) {
        return `${selectedDistanceEffort.value.label} (${formatDurationClock(selectedDistanceEffort.value.effective_seconds)})`;
    }

    if (selectedDurationEffort.value) {
        return formatDurationEffortHighlightLabel(selectedDurationEffort.value);
    }

    return null;
});

function toggleDistanceSelect(effort: ActivityBestEffortItem): void {
    const key = `distance-${effort.id}`;

    if (selectedRowKey.value === key) {
        selectedRowKey.value = null;
        emit('selectRange', null);

        return;
    }

    selectedRowKey.value = key;
    emit('selectRange', resolveDistanceEffortRange(effort));
}

function toggleDurationSelect(effort: DurationPeakEffort): void {
    if (selectedRowKey.value === effort.id) {
        selectedRowKey.value = null;
        emit('selectRange', null);

        return;
    }

    selectedRowKey.value = effort.id;
    emit('selectRange', resolveDurationEffortRange(effort));
}

function clearSelection(): void {
    selectedRowKey.value = null;
    emit('selectRange', null);
}

function formatDistanceEffortPaceOrSpeed(
    effort: ActivityBestEffortItem,
): string {
    if (effort.effective_seconds <= 0 || effort.distance_meters <= 0) {
        return '—';
    }

    const speed = effort.distance_meters / effort.effective_seconds;

    return effort.sport === 'running' || effort.sport === 'trail_running'
        ? formatPace(speed)
        : formatSpeed(speed);
}

function formatEffortPaceOrSpeed(speedMps: number | null): string {
    if (speedMps === null || !Number.isFinite(speedMps) || speedMps <= 0) {
        return '—';
    }

    return props.usesPace ? formatPace(speedMps) : formatSpeed(speedMps);
}

function formatElevationDeltaValue(deltaMeters: number | null): string {
    if (deltaMeters === null || !Number.isFinite(deltaMeters)) {
        return '—';
    }

    const converted = Math.round(elevationFromMeters(deltaMeters, units.value));

    return `${converted > 0 ? '+' : ''}${formatNumber(converted, 0)} ${elevationUnit.value}`;
}

function rankBadgeClass(rank: number): string {
    if (rank === 1) {
        return 'border-amber-500/40 bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300';
    }

    if (rank === 2) {
        return 'border-zinc-300 bg-zinc-100 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
    }

    return 'border-orange-500/35 bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300';
}

interface PreviewPoint {
    x: number;
    y: number;
    time: number;
    distance: number | null;
}

const useDistanceAxis = computed<boolean>(() => {
    let firstDist: number | null = null;
    let lastDist: number | null = null;

    for (const pt of props.series) {
        if (pt.distance !== null && Number.isFinite(pt.distance)) {
            if (firstDist === null) {
                firstDist = pt.distance;
            }

            lastDist = pt.distance;
        }
    }

    return firstDist !== null && lastDist !== null && lastDist > firstDist;
});

function extractPreviewY(pt: StreamPoint): number | null {
    const mode = selectedMode.value;
    const currentUnits = units.value;

    if (mode === 'distance' || mode === 'climbing') {
        return pt.altitude !== null && Number.isFinite(pt.altitude)
            ? elevationFromMeters(pt.altitude, currentUnits)
            : null;
    }

    if (mode === 'power') {
        return pt.power !== null && Number.isFinite(pt.power) && pt.power >= 0
            ? pt.power
            : null;
    }

    if (mode === 'gap') {
        if (
            pt.gap_speed === null ||
            pt.gap_speed === undefined ||
            !Number.isFinite(pt.gap_speed) ||
            pt.gap_speed <= 0.5
        ) {
            return null;
        }

        const paceSec = paceSecondsFromMps(pt.gap_speed, currentUnits);

        if (paceSec === null || paceSec < 120 || paceSec > 900) {
            return null;
        }

        return paceSec;
    }

    return pt.hr !== null && Number.isFinite(pt.hr) && pt.hr >= 40
        ? pt.hr
        : null;
}

const previewPoints = computed<PreviewPoint[]>(() => {
    const pts: PreviewPoint[] = [];
    const currentUnits = units.value;
    const byDistance = useDistanceAxis.value;

    for (const pt of props.series) {
        const y = extractPreviewY(pt);

        if (y === null) {
            continue;
        }

        if (byDistance) {
            if (pt.distance === null || !Number.isFinite(pt.distance)) {
                continue;
            }

            pts.push({
                x: Number(
                    distanceFromMeters(pt.distance, currentUnits).toFixed(3),
                ),
                y,
                time: pt.time,
                distance: pt.distance,
            });
        } else {
            pts.push({
                x: pt.time,
                y,
                time: pt.time,
                distance: pt.distance,
            });
        }
    }

    if (pts.length <= 700) {
        return pts;
    }

    const stride = Math.ceil(pts.length / 700);
    const sampled: PreviewPoint[] = [];

    for (let i = 0; i < pts.length; i += stride) {
        sampled.push(pts[i]);
    }

    if (sampled[sampled.length - 1] !== pts[pts.length - 1]) {
        sampled.push(pts[pts.length - 1]);
    }

    return sampled;
});

const highlightedPreviewCoords = computed<Array<[number, number]>>(() => {
    const currentUnits = units.value;
    const byDistance = useDistanceAxis.value;

    if (selectedDistanceEffort.value) {
        const win = resolveDistanceEffortRange(selectedDistanceEffort.value);

        if (!win) {
            return [];
        }

        const startX = distanceFromMeters(win.startDistance, currentUnits);
        const endX = distanceFromMeters(win.endDistance, currentUnits);

        return previewPoints.value
            .filter((p) => p.x >= startX && p.x <= endX)
            .map((p) => [p.x, p.y]);
    }

    if (selectedDurationEffort.value) {
        const effort = selectedDurationEffort.value;
        const pts: Array<[number, number]> = [];

        for (const pt of props.series) {
            if (pt.time < effort.startTime || pt.time > effort.endTime) {
                continue;
            }

            const y = extractPreviewY(pt);

            if (y === null) {
                continue;
            }

            if (byDistance) {
                if (pt.distance === null || !Number.isFinite(pt.distance)) {
                    continue;
                }

                pts.push([
                    Number(
                        distanceFromMeters(pt.distance, currentUnits).toFixed(
                            3,
                        ),
                    ),
                    y,
                ]);
            } else {
                pts.push([pt.time, y]);
            }
        }

        return pts;
    }

    return [];
});

const previewOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const isDark = theme.isDark.value;
    const pts = previewPoints.value;
    const highlighted = highlightedPreviewCoords.value;
    const mode = selectedMode.value;
    const byDistance = useDistanceAxis.value;
    const distUnit = distanceUnit.value;
    const elevUnit = elevationUnit.value;
    const pUnit = paceUnit.value;

    const maxX = pts.length > 0 ? pts[pts.length - 1].x : 1;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    const palette = (() => {
        if (mode === 'power') {
            return {
                name: 'Power',
                line: props.isRunning ? '#8b5cf6' : '#0ea5e9',
                area: props.isRunning
                    ? isDark
                        ? 'rgba(139, 92, 246, 0.2)'
                        : 'rgba(139, 92, 246, 0.18)'
                    : isDark
                      ? 'rgba(14, 165, 233, 0.2)'
                      : 'rgba(14, 165, 233, 0.18)',
            };
        }

        if (mode === 'gap') {
            return {
                name: 'GAP',
                line: '#f59e0b',
                area: isDark
                    ? 'rgba(245, 158, 11, 0.18)'
                    : 'rgba(245, 158, 11, 0.16)',
            };
        }

        if (mode === 'hr') {
            return {
                name: 'Heart Rate',
                line: '#f43f5e',
                area: isDark
                    ? 'rgba(244, 63, 94, 0.18)'
                    : 'rgba(244, 63, 94, 0.15)',
            };
        }

        return {
            name: 'Elevation',
            line: isDark ? '#71717a' : '#a1a1aa',
            area: isDark
                ? 'rgba(113, 113, 122, 0.25)'
                : 'rgba(161, 161, 170, 0.35)',
        };
    })();

    const isInversePace = mode === 'gap';

    const formatYAxis = (v: number): string => {
        if (mode === 'power') {
            return `${Math.round(v)} W`;
        }

        if (mode === 'hr') {
            return `${Math.round(v)} bpm`;
        }

        if (mode === 'gap') {
            const total = Math.round(v);
            const mins = Math.floor(total / 60);
            const secs = total % 60;

            return `${mins}:${String(secs).padStart(2, '0')}`;
        }

        return `${Math.round(v)} ${elevUnit}`;
    };

    const formatTooltipY = (v: number): string => {
        if (mode === 'power') {
            return `${Math.round(v)} W`;
        }

        if (mode === 'hr') {
            return `${Math.round(v)} bpm`;
        }

        if (mode === 'gap') {
            return `${formatPaceSeconds(v).replace(` ${pUnit}`, '')} ${pUnit}`;
        }

        return `${Math.round(v)} ${elevUnit}`;
    };

    const formatXLabel = (v: number): string =>
        byDistance ? `${v.toFixed(1)} ${distUnit}` : formatDurationClock(v);

    const formatTooltipX = (v: number): string =>
        byDistance ? `${v.toFixed(2)} ${distUnit}` : formatDurationClock(v);

    return {
        animation: false,
        grid: {
            left: 52,
            right: 16,
            top: 12,
            bottom: 24,
        },
        tooltip: {
            trigger: 'axis',
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as
                    | { value?: [number, number] }
                    | undefined;

                if (!first?.value) {
                    return '';
                }

                return `${formatTooltipX(first.value[0])} · <strong>${formatTooltipY(first.value[1])}</strong>`;
            },
        },
        xAxis: {
            type: 'value',
            min: 0,
            max: byDistance ? Number(maxX.toFixed(2)) : Math.ceil(maxX),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            splitLine: { show: false },
            axisLabel: {
                ...axisLabelStyle,
                formatter: formatXLabel,
            },
        },
        yAxis: {
            type: 'value',
            scale: true,
            inverse: isInversePace,
            splitNumber: 3,
            axisLabel: {
                ...axisLabelStyle,
                formatter: formatYAxis,
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: palette.name,
                type: 'line',
                showSymbol: false,
                smooth: 0.15,
                lineStyle: {
                    color: palette.line,
                    width: 1.5,
                },
                areaStyle: {
                    color: palette.area,
                    origin: isInversePace ? 'end' : 'auto',
                },
                data: pts.map((p) => [p.x, p.y]),
            },
            ...(highlighted.length > 1
                ? [
                      {
                          name: activeHighlightLabel.value ?? 'Selected Effort',
                          type: 'line' as const,
                          showSymbol: false,
                          smooth: 0.15,
                          z: 4,
                          lineStyle: {
                              color: '#f97316',
                              width: 2.25,
                          },
                          areaStyle: {
                              color: isDark
                                  ? 'rgba(249, 115, 22, 0.42)'
                                  : 'rgba(249, 115, 22, 0.5)',
                              origin: isInversePace
                                  ? ('end' as const)
                                  : ('auto' as const),
                          },
                          data: highlighted,
                      },
                  ]
                : []),
        ],
    };
});
</script>

<template>
    <section
        v-if="modeOptions.length > 0"
        class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <!-- Header -->
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200/80 px-4 py-3 sm:px-5 dark:border-zinc-800/80"
        >
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="flex items-center gap-2">
                    <Trophy class="size-3.5 text-amber-500" />
                    <h2
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Best Efforts in This Activity
                    </h2>
                </div>

                <div
                    v-if="modeOptions.length > 1"
                    class="flex items-center gap-1.5"
                >
                    <label for="best-efforts-metric" class="sr-only">
                        Effort metric
                    </label>
                    <select
                        id="best-efforts-metric"
                        v-model="selectedMode"
                        class="h-7.5 cursor-pointer rounded-lg border border-zinc-200/90 bg-[#f7f7f5] px-2.5 pr-7 font-mono text-xs font-medium text-zinc-900 transition-colors hover:border-zinc-300 focus:border-zinc-400 focus:outline-none dark:border-zinc-700/90 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:border-zinc-600"
                    >
                        <option
                            v-for="opt in modeOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span
                    v-if="activeHighlightLabel"
                    class="inline-flex items-center gap-1.5 rounded-md border border-orange-500/40 bg-orange-50 px-2 py-0.5 font-mono text-[11px] font-medium text-orange-700 dark:bg-orange-500/15 dark:text-orange-300"
                >
                    Highlighted: {{ activeHighlightLabel }}
                    <button
                        type="button"
                        class="cursor-pointer rounded hover:text-orange-950 dark:hover:text-orange-100"
                        aria-label="Clear highlight"
                        @click="clearSelection"
                    >
                        <X class="size-3" />
                    </button>
                </span>
                <span
                    v-else
                    class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                >
                    Select a row to highlight
                </span>
            </div>
        </div>

        <!-- Linked Telemetry Highlight Preview -->
        <div
            v-if="previewPoints.length > 1"
            class="border-b border-zinc-200/80 bg-[#f7f7f5]/40 px-4 py-3 sm:px-5 dark:border-zinc-800/80 dark:bg-zinc-900/30"
        >
            <BaseChart :option="previewOption" :height="145" />
        </div>

        <!-- DISTANCE MODE TABLE -->
        <div v-if="selectedMode === 'distance'" class="overflow-x-auto">
            <table class="w-full text-left text-sm tabular-nums">
                <thead
                    class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Distance</th>
                        <th class="px-4 py-2.5 text-right font-medium">Time</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{ usesPace ? 'Pace' : 'Speed' }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Heart Rate
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">Elev</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Status
                        </th>
                    </tr>
                </thead>

                <tbody
                    class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/80"
                >
                    <tr
                        v-for="effort in bestEfforts"
                        :key="effort.id"
                        tabindex="0"
                        :aria-selected="
                            selectedRowKey === `distance-${effort.id}`
                        "
                        class="cursor-pointer transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500"
                        :class="[
                            selectedRowKey === `distance-${effort.id}`
                                ? 'bg-orange-50/80 dark:bg-orange-500/10'
                                : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40',
                            effort.is_excluded && 'opacity-50',
                        ]"
                        @click="toggleDistanceSelect(effort)"
                        @keydown.enter.prevent="toggleDistanceSelect(effort)"
                        @keydown.space.prevent="toggleDistanceSelect(effort)"
                    >
                        <td
                            class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full transition-colors"
                                    :class="
                                        selectedRowKey ===
                                        `distance-${effort.id}`
                                            ? 'bg-orange-500'
                                            : 'bg-zinc-300 dark:bg-zinc-700'
                                    "
                                />
                                <span>{{ effort.label }}</span>
                                <span
                                    v-if="effort.all_time_rank !== null"
                                    class="rounded border px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="
                                        rankBadgeClass(effort.all_time_rank)
                                    "
                                >
                                    #{{ effort.all_time_rank }} All-Time
                                </span>
                            </div>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            {{ formatDurationClock(effort.effective_seconds) }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
                        >
                            {{ formatDistanceEffortPaceOrSpeed(effort) }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <span
                                v-if="effort.avg_hr !== null"
                                class="text-rose-600 dark:text-rose-400"
                            >
                                {{ Math.round(effort.avg_hr) }} bpm
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <span
                                v-if="effort.elevation_delta !== null"
                                :class="
                                    effort.elevation_delta > 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : effort.elevation_delta < 0
                                          ? 'text-sky-600 dark:text-sky-400'
                                          : 'text-zinc-500 dark:text-zinc-400'
                                "
                            >
                                {{
                                    formatElevationDeltaValue(
                                        effort.elevation_delta,
                                    )
                                }}
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <span
                                v-if="effort.override_seconds !== null"
                                class="text-[10px] text-amber-600 dark:text-amber-400"
                            >
                                Override
                            </span>
                            <span
                                v-else-if="effort.is_excluded"
                                class="text-[10px] text-rose-500"
                            >
                                Excluded
                            </span>
                            <span
                                v-else
                                class="rounded border border-zinc-200/90 bg-white px-1.5 py-0.5 text-[9px] tracking-wider text-zinc-500 uppercase dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                            >
                                {{
                                    effort.source === 'fit_stream'
                                        ? 'FIT'
                                        : 'Garmin'
                                }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- POWER MODE TABLE -->
        <div v-else-if="selectedMode === 'power'" class="overflow-x-auto">
            <table class="w-full text-left text-sm tabular-nums">
                <thead
                    class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Duration</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Power{{ athleteWeightKg ? ' / W/kg' : '' }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{ usesPace ? 'Pace' : 'Speed' }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Heart Rate
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">Elev</th>
                        <th class="px-4 py-2.5 text-right font-medium">At</th>
                    </tr>
                </thead>

                <tbody
                    class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/80"
                >
                    <tr
                        v-for="effort in activeDurationRows"
                        :key="effort.id"
                        tabindex="0"
                        :aria-selected="selectedRowKey === effort.id"
                        class="cursor-pointer transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500"
                        :class="
                            selectedRowKey === effort.id
                                ? 'bg-orange-50/80 dark:bg-orange-500/10'
                                : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40'
                        "
                        @click="toggleDurationSelect(effort)"
                        @keydown.enter.prevent="toggleDurationSelect(effort)"
                        @keydown.space.prevent="toggleDurationSelect(effort)"
                    >
                        <td
                            class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full transition-colors"
                                    :class="
                                        selectedRowKey === effort.id
                                            ? 'bg-orange-500'
                                            : 'bg-zinc-300 dark:bg-zinc-700'
                                    "
                                />
                                <span>{{ effort.label }}</span>
                                <span
                                    v-if="effort.allTimeRank !== null"
                                    class="rounded border px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="rankBadgeClass(effort.allTimeRank)"
                                >
                                    #{{ effort.allTimeRank }} All-Time
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                class="font-semibold"
                                :class="
                                    isRunning
                                        ? 'text-violet-600 dark:text-violet-400'
                                        : 'text-sky-600 dark:text-sky-400'
                                "
                            >
                                {{ Math.round(effort.value) }} W
                            </span>
                            <span
                                v-if="effort.wPerKg !== null"
                                class="text-zinc-500 dark:text-zinc-400"
                            >
                                · {{ effort.wPerKg.toFixed(2) }} W/kg
                            </span>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            {{ formatEffortPaceOrSpeed(effort.avgSpeed) }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                v-if="effort.avgHr !== null"
                                class="text-rose-600 dark:text-rose-400"
                            >
                                {{ effort.avgHr }} bpm
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                v-if="effort.elevationDelta !== null"
                                :class="
                                    effort.elevationDelta > 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : effort.elevationDelta < 0
                                          ? 'text-sky-600 dark:text-sky-400'
                                          : 'text-zinc-500 dark:text-zinc-400'
                                "
                            >
                                {{
                                    formatElevationDeltaValue(
                                        effort.elevationDelta,
                                    )
                                }}
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                        >
                            {{ formatDurationClock(effort.startTime) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- GAP MODE TABLE (Running) -->
        <div v-else-if="selectedMode === 'gap'" class="overflow-x-auto">
            <table class="w-full text-left text-sm tabular-nums">
                <thead
                    class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Duration</th>
                        <th class="px-4 py-2.5 text-right font-medium">GAP</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Actual Pace
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Heart Rate
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">Elev</th>
                        <th class="px-4 py-2.5 text-right font-medium">At</th>
                    </tr>
                </thead>

                <tbody
                    class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/80"
                >
                    <tr
                        v-for="effort in activeDurationRows"
                        :key="effort.id"
                        tabindex="0"
                        :aria-selected="selectedRowKey === effort.id"
                        class="cursor-pointer transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500"
                        :class="
                            selectedRowKey === effort.id
                                ? 'bg-orange-50/80 dark:bg-orange-500/10'
                                : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40'
                        "
                        @click="toggleDurationSelect(effort)"
                        @keydown.enter.prevent="toggleDurationSelect(effort)"
                        @keydown.space.prevent="toggleDurationSelect(effort)"
                    >
                        <td
                            class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full transition-colors"
                                    :class="
                                        selectedRowKey === effort.id
                                            ? 'bg-orange-500'
                                            : 'bg-zinc-300 dark:bg-zinc-700'
                                    "
                                />
                                <span>{{ effort.label }}</span>
                            </div>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right font-semibold whitespace-nowrap text-amber-600 dark:text-amber-400"
                        >
                            {{ formatPace(effort.value) }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            {{ formatPace(effort.avgSpeed) }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                v-if="effort.avgHr !== null"
                                class="text-rose-600 dark:text-rose-400"
                            >
                                {{ effort.avgHr }} bpm
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                v-if="effort.elevationDelta !== null"
                                :class="
                                    effort.elevationDelta > 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : effort.elevationDelta < 0
                                          ? 'text-sky-600 dark:text-sky-400'
                                          : 'text-zinc-500 dark:text-zinc-400'
                                "
                            >
                                {{
                                    formatElevationDeltaValue(
                                        effort.elevationDelta,
                                    )
                                }}
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                        >
                            {{ formatDurationClock(effort.startTime) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- HEART RATE MODE TABLE -->
        <div v-else-if="selectedMode === 'hr'" class="overflow-x-auto">
            <table class="w-full text-left text-sm tabular-nums">
                <thead
                    class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Duration</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Avg HR
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{ usesPace ? 'Pace' : 'Speed' }}
                        </th>
                        <th
                            v-if="hasPowerColumn"
                            class="px-4 py-2.5 text-right font-medium"
                        >
                            Power
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">Elev</th>
                        <th class="px-4 py-2.5 text-right font-medium">At</th>
                    </tr>
                </thead>

                <tbody
                    class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/80"
                >
                    <tr
                        v-for="effort in activeDurationRows"
                        :key="effort.id"
                        tabindex="0"
                        :aria-selected="selectedRowKey === effort.id"
                        class="cursor-pointer transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500"
                        :class="
                            selectedRowKey === effort.id
                                ? 'bg-orange-50/80 dark:bg-orange-500/10'
                                : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40'
                        "
                        @click="toggleDurationSelect(effort)"
                        @keydown.enter.prevent="toggleDurationSelect(effort)"
                        @keydown.space.prevent="toggleDurationSelect(effort)"
                    >
                        <td
                            class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full transition-colors"
                                    :class="
                                        selectedRowKey === effort.id
                                            ? 'bg-orange-500'
                                            : 'bg-zinc-300 dark:bg-zinc-700'
                                    "
                                />
                                <span>{{ effort.label }}</span>
                            </div>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right font-semibold whitespace-nowrap text-rose-600 dark:text-rose-400"
                        >
                            {{ Math.round(effort.value) }} bpm
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            {{ formatEffortPaceOrSpeed(effort.avgSpeed) }}
                        </td>
                        <td
                            v-if="hasPowerColumn"
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                effort.avgPower !== null
                                    ? `${effort.avgPower} W`
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                v-if="effort.elevationDelta !== null"
                                :class="
                                    effort.elevationDelta > 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : effort.elevationDelta < 0
                                          ? 'text-sky-600 dark:text-sky-400'
                                          : 'text-zinc-500 dark:text-zinc-400'
                                "
                            >
                                {{
                                    formatElevationDeltaValue(
                                        effort.elevationDelta,
                                    )
                                }}
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                        >
                            {{ formatDurationClock(effort.startTime) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- CLIMBING MODE TABLE -->
        <div v-else-if="selectedMode === 'climbing'" class="overflow-x-auto">
            <table class="w-full text-left text-sm tabular-nums">
                <thead
                    class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Duration</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Elev Gain
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">VAM</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Avg Grade
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            {{
                                hasPowerColumn
                                    ? athleteWeightKg
                                        ? 'Power / W/kg'
                                        : 'Power'
                                    : usesPace
                                      ? 'Pace'
                                      : 'Speed'
                            }}
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Heart Rate
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">At</th>
                    </tr>
                </thead>

                <tbody
                    class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/80"
                >
                    <tr
                        v-for="effort in activeDurationRows"
                        :key="effort.id"
                        tabindex="0"
                        :aria-selected="selectedRowKey === effort.id"
                        class="cursor-pointer transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500"
                        :class="
                            selectedRowKey === effort.id
                                ? 'bg-orange-50/80 dark:bg-orange-500/10'
                                : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40'
                        "
                        @click="toggleDurationSelect(effort)"
                        @keydown.enter.prevent="toggleDurationSelect(effort)"
                        @keydown.space.prevent="toggleDurationSelect(effort)"
                    >
                        <td
                            class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full transition-colors"
                                    :class="
                                        selectedRowKey === effort.id
                                            ? 'bg-orange-500'
                                            : 'bg-zinc-300 dark:bg-zinc-700'
                                    "
                                />
                                <span>{{ effort.label }}</span>
                            </div>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right font-semibold whitespace-nowrap text-emerald-600 dark:text-emerald-400"
                        >
                            +{{
                                formatNumber(
                                    Math.round(
                                        elevationFromMeters(
                                            effort.value,
                                            units,
                                        ),
                                    ),
                                    0,
                                )
                            }}
                            {{ elevationUnit }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-900 dark:text-zinc-100"
                        >
                            {{ formatVam(effort.vam) }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            {{ formatGrade(effort.avgGradePct) }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            <template v-if="hasPowerColumn">
                                <template v-if="effort.avgPower !== null">
                                    {{ effort.avgPower }} W
                                    <span
                                        v-if="effort.wPerKg !== null"
                                        class="text-zinc-500 dark:text-zinc-400"
                                    >
                                        · {{ effort.wPerKg.toFixed(2) }} W/kg
                                    </span>
                                </template>
                                <template v-else>—</template>
                            </template>
                            <template v-else>
                                {{ formatEffortPaceOrSpeed(effort.avgSpeed) }}
                            </template>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                v-if="effort.avgHr !== null"
                                class="text-rose-600 dark:text-rose-400"
                            >
                                {{ effort.avgHr }} bpm
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                        >
                            {{ formatDurationClock(effort.startTime) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
