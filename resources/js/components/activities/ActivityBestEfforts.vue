<script setup lang="ts">
import type { EChartsOption } from 'echarts';
import { Trophy, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatDurationClock, formatNumber } from '@/lib/format';
import { distanceFromMeters, elevationFromMeters, useUnits } from '@/lib/units';
import type {
    ActivityBestEffortItem,
    HighlightRange,
    StreamPoint,
} from '@/types/activity';

const props = defineProps<{
    bestEfforts: ActivityBestEffortItem[];
    series: StreamPoint[];
    usesPace: boolean;
}>();

const emit = defineEmits<{
    selectRange: [range: HighlightRange | null];
}>();

const theme = useChartTheme();
const { units, distanceUnit, elevationUnit, formatPace, formatSpeed } =
    useUnits();
const selectedEffortId = ref<number | null>(null);

watch(
    () => props.bestEfforts,
    () => {
        selectedEffortId.value = null;
        emit('selectRange', null);
    },
);

const selectedEffort = computed<ActivityBestEffortItem | null>(() => {
    if (selectedEffortId.value === null) {
        return null;
    }

    return (
        props.bestEfforts.find((e) => e.id === selectedEffortId.value) ?? null
    );
});

function resolveEffortDistanceWindow(
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

function toggleSelect(effort: ActivityBestEffortItem): void {
    if (selectedEffortId.value === effort.id) {
        selectedEffortId.value = null;
        emit('selectRange', null);

        return;
    }

    selectedEffortId.value = effort.id;
    emit('selectRange', resolveEffortDistanceWindow(effort));
}

function clearSelection(): void {
    selectedEffortId.value = null;
    emit('selectRange', null);
}

function formatEffortPaceOrSpeed(effort: ActivityBestEffortItem): string {
    if (effort.effective_seconds <= 0 || effort.distance_meters <= 0) {
        return '—';
    }

    const speed = effort.distance_meters / effort.effective_seconds;

    return effort.sport === 'running' || effort.sport === 'trail_running'
        ? formatPace(speed)
        : formatSpeed(speed);
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

const elevationPoints = computed<Array<[number, number]>>(() => {
    const pts: Array<[number, number]> = [];
    const currentUnits = units.value;

    for (const p of props.series) {
        if (p.distance !== null && p.altitude !== null) {
            pts.push([
                Number(distanceFromMeters(p.distance, currentUnits).toFixed(3)),
                elevationFromMeters(p.altitude, currentUnits),
            ]);
        }
    }

    return pts;
});

const selectedWindow = computed<HighlightRange | null>(() =>
    selectedEffort.value
        ? resolveEffortDistanceWindow(selectedEffort.value)
        : null,
);

const elevationOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const isDark = theme.isDark.value;
    const pts = elevationPoints.value;
    const win = selectedWindow.value;

    const currentUnits = units.value;
    const distUnit = distanceUnit.value;
    const elevUnit = elevationUnit.value;

    const highlightedPts = win
        ? pts.filter(
              (p) =>
                  p[0] >= distanceFromMeters(win.startDistance, currentUnits) &&
                  p[0] <= distanceFromMeters(win.endDistance, currentUnits),
          )
        : [];

    const maxDist = pts.length > 0 ? pts[pts.length - 1][0] : 1;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    return {
        animation: false,
        grid: {
            left: 48,
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

                return `${first.value[0].toFixed(2)} ${distUnit} · <strong>${Math.round(first.value[1])} ${elevUnit}</strong>`;
            },
        },
        xAxis: {
            type: 'value',
            min: 0,
            max: Number(maxDist.toFixed(2)),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            splitLine: { show: false },
            axisLabel: {
                ...axisLabelStyle,
                formatter: (v: number) => `${v.toFixed(1)} ${distUnit}`,
            },
        },
        yAxis: {
            type: 'value',
            scale: true,
            splitNumber: 3,
            axisLabel: {
                ...axisLabelStyle,
                formatter: (v: number) => `${Math.round(v)} ${elevUnit}`,
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'Elevation',
                type: 'line',
                showSymbol: false,
                smooth: 0.15,
                lineStyle: {
                    color: isDark ? '#71717a' : '#a1a1aa',
                    width: 1.5,
                },
                areaStyle: {
                    color: isDark
                        ? 'rgba(113, 113, 122, 0.25)'
                        : 'rgba(161, 161, 170, 0.35)',
                },
                data: pts,
            },
            ...(highlightedPts.length > 1
                ? [
                      {
                          name: win?.label ?? 'Selected Effort',
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
                                  : 'rgba(249, 115, 22, 0.55)',
                          },
                          data: highlightedPts,
                      },
                  ]
                : []),
        ],
    };
});
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <!-- Header -->
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200/80 px-4 py-3 sm:px-5 dark:border-zinc-800/80"
        >
            <div class="flex items-center gap-2">
                <Trophy class="size-3.5 text-amber-500" />
                <h2
                    class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Best Efforts in This Activity
                </h2>
            </div>

            <div class="flex items-center gap-2">
                <span
                    v-if="selectedWindow"
                    class="inline-flex items-center gap-1.5 rounded-md border border-orange-500/40 bg-orange-50 px-2 py-0.5 font-mono text-[11px] font-medium text-orange-700 dark:bg-orange-500/15 dark:text-orange-300"
                >
                    Highlighted: {{ selectedWindow.label }}
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

        <!-- Linked Elevation Highlight Preview -->
        <div
            v-if="elevationPoints.length > 1"
            class="border-b border-zinc-200/80 bg-[#f7f7f5]/40 px-4 py-3 sm:px-5 dark:border-zinc-800/80 dark:bg-zinc-900/30"
        >
            <BaseChart :option="elevationOption" :height="145" />
        </div>

        <!-- Best Efforts Table -->
        <div class="overflow-x-auto">
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
                        class="cursor-pointer transition-colors"
                        :class="[
                            selectedEffortId === effort.id
                                ? 'bg-orange-50/80 dark:bg-orange-500/10'
                                : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40',
                            effort.is_excluded && 'opacity-50',
                        ]"
                        @click="toggleSelect(effort)"
                    >
                        <td
                            class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full transition-colors"
                                    :class="
                                        selectedEffortId === effort.id
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
                            {{ formatEffortPaceOrSpeed(effort) }}
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
                                {{ effort.elevation_delta > 0 ? '+' : ''
                                }}{{
                                    formatNumber(
                                        Math.round(
                                            elevationFromMeters(
                                                effort.elevation_delta,
                                                units,
                                            ),
                                        ),
                                        0,
                                    )
                                }}
                                {{ elevationUnit }}
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
    </section>
</template>
