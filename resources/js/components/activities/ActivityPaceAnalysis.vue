<script setup lang="ts">
import type { EChartsOption } from 'echarts';
import { computed, ref, watch } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatDurationClock } from '@/lib/format';
import {
    KM_TO_MI,
    computeSplitsFromSeries,
    distanceFromMeters,
    elevationFromMeters,
    formatPaceSeconds,
    paceSecondsFromMps,
    speedFromMps,
    useUnits,
} from '@/lib/units';
import type { KmSplitItem, SplitItem, StreamPoint } from '@/types/activity';

const props = defineProps<{
    series: StreamPoint[];
    kmSplits: KmSplitItem[];
    laps: Array<Record<string, number | null>>;
    usesPace: boolean;
    isRunning: boolean;
    accentHex: string;
}>();

type Granularity = 'splits' | 'laps' | 'smoothed';
type PaceMode = 'pace' | 'gap';

const theme = useChartTheme();
const {
    units,
    isImperial,
    distanceUnit,
    elevationUnit,
    paceUnit,
    speedUnit,
    formatDistance,
    formatElevation,
} = useUnits();

const computedSplits = computed<SplitItem[]>(() =>
    computeSplitsFromSeries(props.series, props.kmSplits, units.value),
);

const granularity = ref<Granularity>(
    computedSplits.value.length > 0
        ? 'splits'
        : props.laps.length > 0
          ? 'laps'
          : 'smoothed',
);
const paceMode = ref<PaceMode>('pace');

watch(
    () => [computedSplits.value.length, props.laps.length],
    () => {
        if (
            granularity.value === 'splits' &&
            computedSplits.value.length === 0
        ) {
            granularity.value = props.laps.length > 0 ? 'laps' : 'smoothed';
        } else if (granularity.value === 'laps' && props.laps.length === 0) {
            granularity.value =
                computedSplits.value.length > 0 ? 'splits' : 'smoothed';
        }
    },
);

function pickLapNumber(
    lap: Record<string, number | null>,
    keys: string[],
): number | null {
    for (const key of keys) {
        const val = lap[key];

        if (typeof val === 'number' && Number.isFinite(val)) {
            return val;
        }
    }

    return null;
}

const hasGap = computed<boolean>(() => {
    if (!props.isRunning) {
        return false;
    }

    return (
        computedSplits.value.some((s) => s.gap_seconds !== null) ||
        props.laps.some(
            (l) => pickLapNumber(l, ['gap_seconds_per_km']) !== null,
        ) ||
        props.series.some(
            (p) =>
                p.gap_speed !== null &&
                p.gap_speed !== undefined &&
                p.gap_speed > 0,
        )
    );
});

interface BarSegment {
    label: string;
    startDist: number;
    endDist: number;
    distanceMeters: number;
    durationSeconds: number;
    paceSec: number | null;
    gapSec: number | null;
    speedVal: number | null;
    hr: number | null;
    elevGain: number | null;
}

const barSegments = computed<BarSegment[]>(() => {
    const segments: BarSegment[] = [];
    let cursorMeters = 0;

    if (granularity.value === 'splits') {
        for (const split of computedSplits.value) {
            const startDist = distanceFromMeters(cursorMeters, units.value);
            cursorMeters += split.distance_meters;
            const endDist = distanceFromMeters(cursorMeters, units.value);

            segments.push({
                label: split.label,
                startDist,
                endDist,
                distanceMeters: split.distance_meters,
                durationSeconds: split.duration_seconds,
                paceSec: split.pace_seconds > 0 ? split.pace_seconds : null,
                gapSec: split.gap_seconds,
                speedVal:
                    split.avg_speed > 0
                        ? Number(
                              speedFromMps(
                                  split.avg_speed,
                                  units.value,
                              ).toFixed(1),
                          )
                        : null,
                hr: split.avg_hr,
                elevGain: split.elevation_gain,
            });
        }
    } else if (granularity.value === 'laps') {
        for (let i = 0; i < props.laps.length; i += 1) {
            const lap = props.laps[i];
            const dist =
                pickLapNumber(lap, [
                    'distance_meters',
                    'total_distance',
                    'distance',
                ]) ?? 0;
            const dur =
                pickLapNumber(lap, [
                    'duration_seconds',
                    'total_timer_time',
                    'total_elapsed_time',
                    'duration',
                ]) ?? 0;
            const avgSpeed =
                pickLapNumber(lap, [
                    'avg_speed',
                    'enhanced_avg_speed',
                    'speed',
                ]) ?? (dur > 0 && dist > 0 ? dist / dur : null);
            const rawGapSecPerKm = pickLapNumber(lap, ['gap_seconds_per_km']);
            const gapSec =
                rawGapSecPerKm !== null
                    ? isImperial.value
                        ? rawGapSecPerKm / KM_TO_MI
                        : rawGapSecPerKm
                    : null;

            if (dist <= 0) {
                continue;
            }

            const startDist = distanceFromMeters(cursorMeters, units.value);
            cursorMeters += dist;
            const endDist = distanceFromMeters(cursorMeters, units.value);

            segments.push({
                label: `Lap ${i + 1}`,
                startDist,
                endDist,
                distanceMeters: dist,
                durationSeconds: dur,
                paceSec:
                    avgSpeed !== null && avgSpeed > 0
                        ? paceSecondsFromMps(avgSpeed, units.value)
                        : null,
                gapSec,
                speedVal:
                    avgSpeed !== null && avgSpeed > 0
                        ? Number(speedFromMps(avgSpeed, units.value).toFixed(1))
                        : null,
                hr: pickLapNumber(lap, ['avg_hr', 'avg_heart_rate', 'hr']),
                elevGain: pickLapNumber(lap, [
                    'elevation_gain',
                    'total_ascent',
                    'ascent',
                ]),
            });
        }
    }

    return segments;
});

const elevationPoints = computed<Array<[number, number]>>(() => {
    const pts: Array<[number, number]> = [];

    for (const p of props.series) {
        if (p.distance !== null && p.altitude !== null) {
            pts.push([
                Number(distanceFromMeters(p.distance, units.value).toFixed(3)),
                Number(elevationFromMeters(p.altitude, units.value).toFixed(1)),
            ]);
        }
    }

    return pts;
});

const smoothedPoints = computed<Array<[number, number]>>(() => {
    const raw: Array<[number, number]> = [];

    for (const p of props.series) {
        if (p.distance === null) {
            continue;
        }

        const speed =
            props.isRunning &&
            paceMode.value === 'gap' &&
            p.gap_speed !== null &&
            p.gap_speed !== undefined &&
            p.gap_speed > 0
                ? p.gap_speed
                : p.speed;

        if (speed === null || speed <= 0.6) {
            continue;
        }

        const distCoord = Number(
            distanceFromMeters(p.distance, units.value).toFixed(3),
        );

        if (props.usesPace) {
            const metricPaceSec = 1000 / speed;
            const displayPaceSec = paceSecondsFromMps(speed, units.value);

            if (
                metricPaceSec >= 140 &&
                metricPaceSec <= 900 &&
                displayPaceSec !== null
            ) {
                raw.push([distCoord, Math.round(displayPaceSec)]);
            }
        } else {
            raw.push([
                distCoord,
                Number(speedFromMps(speed, units.value).toFixed(1)),
            ]);
        }
    }

    if (raw.length <= 5) {
        return raw;
    }

    // Light 5-point moving average for clean smoothed pace line
    const smoothed: Array<[number, number]> = [];

    for (let i = 0; i < raw.length; i += 1) {
        const start = Math.max(0, i - 2);
        const end = Math.min(raw.length - 1, i + 2);
        let sum = 0;
        let cnt = 0;

        for (let j = start; j <= end; j += 1) {
            sum += raw[j][1];
            cnt += 1;
        }

        smoothed.push([raw[i][0], Number((sum / cnt).toFixed(1))]);
    }

    return smoothed;
});

const totalDistance = computed<number>(() => {
    const fromSegments =
        barSegments.value.length > 0
            ? barSegments.value[barSegments.value.length - 1].endDist
            : 0;
    const fromSeries =
        elevationPoints.value.length > 0
            ? elevationPoints.value[elevationPoints.value.length - 1][0]
            : 0;
    const fromSmoothed =
        smoothedPoints.value.length > 0
            ? smoothedPoints.value[smoothedPoints.value.length - 1][0]
            : 0;

    return Math.max(fromSegments, fromSeries, fromSmoothed, 0.1);
});

function intensityColor(ratio: number, isDark: boolean): string {
    const clamped = Math.max(0, Math.min(1, ratio));

    if (props.usesPace) {
        // Cool sky-blue for easier paces -> deep ocean teal for faster efforts
        const stops: Array<[number, number, number]> = isDark
            ? [
                  [56, 189, 248],
                  [14, 165, 233],
                  [2, 132, 199],
                  [3, 105, 161],
              ]
            : [
                  [56, 189, 248],
                  [14, 132, 185],
                  [7, 103, 146],
                  [6, 78, 117],
              ];
        const scaled = clamped * (stops.length - 1);
        const idx = Math.min(Math.floor(scaled), stops.length - 2);
        const t = scaled - idx;
        const a = stops[idx];
        const b = stops[idx + 1];

        return `rgb(${Math.round(a[0] + (b[0] - a[0]) * t)}, ${Math.round(a[1] + (b[1] - a[1]) * t)}, ${Math.round(a[2] + (b[2] - a[2]) * t)})`;
    }

    return props.accentHex;
}

const chartOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const isDark = theme.isDark.value;
    const activeUnits = units.value;
    const distUnit = distanceUnit.value;
    const elevUnit = elevationUnit.value;
    const activePaceUnit = paceUnit.value;
    const activeSpeedUnit = speedUnit.value;
    const paceMinFloor = isImperial.value ? 180 : 120;
    const pacePadTop = isImperial.value ? 45 : 35;
    const pacePadBottom = isImperial.value ? 60 : 45;
    const minElevSpan = isImperial.value ? 60 : 20;

    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    const useGap = props.isRunning && paceMode.value === 'gap' && hasGap.value;

    const elevMin =
        elevationPoints.value.length > 0
            ? Math.min(...elevationPoints.value.map((p) => p[1]))
            : 0;
    const elevMax =
        elevationPoints.value.length > 0
            ? Math.max(...elevationPoints.value.map((p) => p[1]))
            : 100;
    const elevSpan = Math.max(minElevSpan, elevMax - elevMin);

    const series: EChartsOption['series'] = [];

    // Primary Pace / Speed visual
    let yMin: number | undefined;
    let yMax: number | undefined;

    if (granularity.value !== 'smoothed' && barSegments.value.length > 0) {
        const values = barSegments.value
            .map((seg) => {
                if (props.usesPace) {
                    return useGap ? (seg.gapSec ?? seg.paceSec) : seg.paceSec;
                }

                return seg.speedVal;
            })
            .filter((v): v is number => v !== null && Number.isFinite(v));

        const minVal = values.length > 0 ? Math.min(...values) : 0;
        const maxVal = values.length > 0 ? Math.max(...values) : 1;

        if (props.usesPace) {
            // Inverted pace axis: faster (smaller sec/unit) at top, slower at bottom
            yMin = Math.max(
                paceMinFloor,
                Math.floor((minVal - pacePadTop) / 15) * 15,
            );
            yMax = Math.ceil((maxVal + pacePadBottom) / 15) * 15;
        } else {
            yMin = 0;
            yMax = Math.ceil((maxVal + 5) / 5) * 5;
        }

        const customData = barSegments.value.map((seg, index) => {
            const val = props.usesPace
                ? useGap
                    ? (seg.gapSec ?? seg.paceSec)
                    : seg.paceSec
                : seg.speedVal;

            const ratio =
                val === null || maxVal === minVal
                    ? 0.5
                    : props.usesPace
                      ? (maxVal - val) / (maxVal - minVal)
                      : (val - minVal) / (maxVal - minVal);

            return {
                value: [seg.startDist, seg.endDist, val ?? yMax ?? 0, index],
                itemStyle: {
                    color: intensityColor(ratio, isDark),
                },
            };
        });

        series.push({
            name: props.usesPace ? (useGap ? 'GAP' : 'Pace') : 'Speed',
            type: 'custom',
            xAxisIndex: 0,
            yAxisIndex: 0,
            z: 2,
            renderItem: (params: unknown, api: unknown) => {
                const a = api as {
                    value: (idx: number) => number;
                    coord: (val: [number, number]) => [number, number];
                    style: (extra?: Record<string, unknown>) => unknown;
                };
                const startDist = a.value(0);
                const endDist = a.value(1);
                const yVal = a.value(2);
                const baseline = props.usesPace
                    ? (yMax ?? (isImperial.value ? 960 : 600))
                    : 0;

                const topLeft = a.coord([startDist, yVal]);
                const bottomRight = a.coord([endDist, baseline]);

                const x = topLeft[0] + 1;
                const width = Math.max(2, bottomRight[0] - topLeft[0] - 2);
                const y = Math.min(topLeft[1], bottomRight[1]);
                const height = Math.max(
                    1,
                    Math.abs(bottomRight[1] - topLeft[1]),
                );

                return {
                    type: 'rect',
                    shape: {
                        x,
                        y,
                        width,
                        height,
                        r: [2, 2, 0, 0],
                    },
                    style: a.style({
                        opacity: 0.9,
                    }),
                };
            },
            data: customData,
        } as never);
    } else if (smoothedPoints.value.length > 0) {
        const vals = smoothedPoints.value.map((p) => p[1]);
        const minVal = Math.min(...vals);
        const maxVal = Math.max(...vals);

        if (props.usesPace) {
            yMin = Math.max(
                paceMinFloor,
                Math.floor((minVal - (isImperial.value ? 35 : 25)) / 15) * 15,
            );
            yMax = Math.ceil((maxVal + (isImperial.value ? 45 : 35)) / 15) * 15;
        } else {
            yMin = 0;
            yMax = Math.ceil((maxVal + 5) / 5) * 5;
        }

        series.push({
            name: props.usesPace
                ? useGap
                    ? 'Smoothed GAP'
                    : 'Smoothed Pace'
                : 'Smoothed Speed',
            type: 'line',
            xAxisIndex: 0,
            yAxisIndex: 0,
            z: 3,
            showSymbol: false,
            smooth: 0.25,
            lineStyle: {
                color: props.usesPace ? '#0284c7' : props.accentHex,
                width: 2,
            },
            areaStyle: {
                color: props.usesPace
                    ? isDark
                        ? 'rgba(14, 165, 233, 0.16)'
                        : 'rgba(2, 132, 199, 0.14)'
                    : 'rgba(245, 158, 11, 0.14)',
            },
            data: smoothedPoints.value,
        });
    }

    // Grey elevation silhouette along the bottom
    if (elevationPoints.value.length > 1) {
        series.push({
            name: 'Elevation',
            type: 'line',
            xAxisIndex: 0,
            yAxisIndex: 1,
            z: 4,
            silent: true,
            showSymbol: false,
            smooth: 0.2,
            lineStyle: {
                color: isDark
                    ? 'rgba(161, 161, 170, 0.55)'
                    : 'rgba(113, 113, 122, 0.45)',
                width: 1.25,
            },
            areaStyle: {
                color: isDark
                    ? 'rgba(212, 212, 216, 0.22)'
                    : 'rgba(212, 212, 216, 0.72)',
            },
            data: elevationPoints.value,
        });
    }

    return {
        animation: false,
        grid: {
            left: 68,
            right: elevationPoints.value.length > 1 ? 56 : 16,
            top: 16,
            bottom: 28,
        },
        tooltip: {
            trigger: granularity.value === 'smoothed' ? 'axis' : 'item',
            formatter: (params: unknown) => {
                if (granularity.value === 'smoothed') {
                    const list = Array.isArray(params) ? params : [params];
                    const first = list[0] as
                        | { value?: [number, number]; seriesName?: string }
                        | undefined;

                    if (!first?.value) {
                        return '';
                    }

                    const dist = first.value[0];
                    const val = first.value[1];
                    const display = props.usesPace
                        ? formatPaceSeconds(val, activeUnits)
                        : `${val.toFixed(1)} ${activeSpeedUnit}`;

                    return `<div><div style="opacity:0.65;margin-bottom:2px">${dist.toFixed(2)} ${distUnit}</div><strong>${first.seriesName}:</strong> ${display}</div>`;
                }

                const item = params as {
                    value?: [number, number, number, number];
                };
                const idx = item?.value?.[3];

                if (idx === undefined || !barSegments.value[idx]) {
                    return '';
                }

                const seg = barSegments.value[idx];
                const distLabel = formatDistance(seg.distanceMeters, 2);
                const paceLine = props.usesPace
                    ? `Pace: <strong>${formatPaceSeconds(seg.paceSec, activeUnits)}</strong>`
                    : `Speed: <strong>${seg.speedVal !== null ? `${seg.speedVal.toFixed(1)} ${activeSpeedUnit}` : '—'}</strong>`;
                const gapLine =
                    props.isRunning && seg.gapSec !== null
                        ? `<br/>GAP: <strong>${formatPaceSeconds(seg.gapSec, activeUnits)}</strong>`
                        : '';
                const elevLine =
                    seg.elevGain !== null && seg.elevGain > 0
                        ? `<br/>Elev Gain: +${formatElevation(seg.elevGain)}`
                        : '';
                const hrLine =
                    seg.hr !== null
                        ? `<br/>Avg HR: ${Math.round(seg.hr)} bpm`
                        : '';

                return `<div><div style="font-weight:600;margin-bottom:2px">${seg.label} · ${distLabel} (${formatDurationClock(seg.durationSeconds)})</div>${paceLine}${gapLine}${elevLine}${hrLine}</div>`;
            },
        },
        xAxis: {
            type: 'value',
            min: 0,
            max: Number(totalDistance.value.toFixed(2)),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            splitLine: { show: false },
            axisLabel: {
                ...axisLabelStyle,
                formatter: (v: number) =>
                    `${v.toFixed(v % 1 === 0 ? 0 : 1)} ${distUnit}`,
            },
        },
        yAxis: [
            {
                type: 'value',
                inverse: props.usesPace,
                min: yMin,
                max: yMax,
                splitNumber: 4,
                axisLabel: {
                    ...axisLabelStyle,
                    formatter: (val: number) => {
                        if (props.usesPace) {
                            const total = Math.round(val);
                            const mins = Math.floor(total / 60);
                            const secs = total % 60;

                            return `${mins}:${String(secs).padStart(2, '0')} ${activePaceUnit}`;
                        }

                        return `${Math.round(val)} ${activeSpeedUnit}`;
                    },
                },
                splitLine: {
                    lineStyle: { color: theme.gridLineColor.value },
                },
            },
            {
                type: 'value',
                position: 'right',
                show: elevationPoints.value.length > 1,
                min: Math.floor(elevMin - elevSpan * 0.05),
                // Push elevation silhouette into the bottom ~40% of the chart
                max: Math.ceil(elevMax + elevSpan * 1.4),
                splitNumber: 3,
                axisLabel: {
                    ...axisLabelStyle,
                    formatter: (val: number) =>
                        val <= elevMax + elevSpan * 0.15
                            ? `${Math.round(val)} ${elevUnit}`
                            : '',
                },
                splitLine: { show: false },
            },
        ],
        series,
    };
});

const canRender = computed<boolean>(
    () =>
        computedSplits.value.length > 0 ||
        props.laps.length > 0 ||
        smoothedPoints.value.length > 1,
);
</script>

<template>
    <section
        v-if="canRender"
        class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <div
            class="mb-3.5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <h2
                class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
            >
                {{ usesPace ? 'Pace Analysis' : 'Speed Analysis' }}
            </h2>

            <div class="flex flex-wrap items-center gap-2">
                <!-- Granularity Toggle -->
                <div
                    class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                >
                    <button
                        v-if="computedSplits.length > 0"
                        type="button"
                        class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            granularity === 'splits'
                                ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="granularity = 'splits'"
                    >
                        Splits
                    </button>
                    <button
                        v-if="laps.length > 0"
                        type="button"
                        class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            granularity === 'laps'
                                ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="granularity = 'laps'"
                    >
                        Laps
                    </button>
                    <button
                        v-if="smoothedPoints.length > 1"
                        type="button"
                        class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            granularity === 'smoothed'
                                ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="granularity = 'smoothed'"
                    >
                        Smoothed
                    </button>
                </div>

                <!-- Pace vs GAP Toggle (Running) -->
                <div
                    v-if="hasGap"
                    class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                >
                    <button
                        type="button"
                        class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            paceMode === 'pace'
                                ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="paceMode = 'pace'"
                    >
                        Pace
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            paceMode === 'gap'
                                ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="paceMode = 'gap'"
                    >
                        GAP
                    </button>
                </div>
            </div>
        </div>

        <BaseChart :option="chartOption" :height="270" />
    </section>
</template>
