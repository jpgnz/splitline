<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity as ActivityIcon,
    ArrowLeft,
    ArrowLeftRight,
    Clock,
    CloudSun,
    Crown,
    Heart,
    Mountain,
    Plus,
    Ruler,
    Search,
    Thermometer,
    Wind,
    X,
    Zap,
} from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    compare as activitiesCompare,
    index as activitiesIndex,
    show as showActivity,
} from '@/routes/activities';
import type {
    ActivityCompareProps,
    AlignedSplitEntry,
    ComparisonActivity,
    ComparisonAlignMode,
    ComparisonCandidate,
} from '@/types/comparison';

const props = defineProps<ActivityCompareProps>();

const theme = useChartTheme();
const { getSportMeta, resolveActivitySportKey, sportBadgeClasses } =
    useSports();
const {
    units,
    distanceUnit,
    elevationUnit,
    paceUnit,
    speedUnit,
    tempUnit,
    distanceFromMeters,
    elevationFromMeters,
    paceSecondsFromMps,
    speedFromMps,
    speedFromKmh,
    formatDistance,
    formatElevation,
    formatPace,
    formatPace100m,
    formatPaceSeconds,
    formatSpeed,
    formatSpeedKmh,
    formatTemp,
} = useUnits();

interface SlotPalette {
    label: string;
    hex: string;
    dotClass: string;
    borderClass: string;
    bgClass: string;
    badgeClass: string;
    textClass: string;
}

const SLOT_PALETTES: readonly SlotPalette[] = [
    {
        label: 'Baseline',
        hex: '#10b981',
        dotClass: 'bg-emerald-500',
        borderClass: 'border-emerald-500/40 dark:border-emerald-500/35',
        bgClass: 'bg-emerald-500/[0.05] dark:bg-emerald-500/[0.07]',
        badgeClass:
            'border-emerald-500/30 bg-emerald-500/12 text-emerald-700 dark:text-emerald-300',
        textClass: 'text-emerald-600 dark:text-emerald-400',
    },
    {
        label: 'Activity 2',
        hex: '#0ea5e9',
        dotClass: 'bg-sky-500',
        borderClass: 'border-sky-500/40 dark:border-sky-500/35',
        bgClass: 'bg-sky-500/[0.05] dark:bg-sky-500/[0.07]',
        badgeClass:
            'border-sky-500/30 bg-sky-500/12 text-sky-700 dark:text-sky-300',
        textClass: 'text-sky-600 dark:text-sky-400',
    },
    {
        label: 'Activity 3',
        hex: '#f59e0b',
        dotClass: 'bg-amber-500',
        borderClass: 'border-amber-500/40 dark:border-amber-500/35',
        bgClass: 'bg-amber-500/[0.05] dark:bg-amber-500/[0.07]',
        badgeClass:
            'border-amber-500/30 bg-amber-500/12 text-amber-700 dark:text-amber-300',
        textClass: 'text-amber-600 dark:text-amber-400',
    },
] as const;

function slotPalette(index: number): SlotPalette {
    return SLOT_PALETTES[index] ?? SLOT_PALETTES[0];
}

const pickerOpen = ref<boolean>(false);
const candidateSearch = ref<string>('');
const pickerContainerRef = ref<HTMLElement | null>(null);
const paceOverlayMode = ref<'raw' | 'gap'>('raw');

function handleDocumentClick(event: MouseEvent): void {
    if (
        pickerOpen.value &&
        pickerContainerRef.value &&
        !pickerContainerRef.value.contains(event.target as Node)
    ) {
        pickerOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleDocumentClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleDocumentClick);
});

const baselineActivity = computed<ComparisonActivity | null>(
    () => props.activities[0] ?? null,
);

const canCompare = computed<boolean>(() => props.activities.length >= 2);

const baselineMetricMode = computed<
    'pace' | 'speed' | 'pace_100m' | 'duration'
>(() => {
    const base = baselineActivity.value;

    if (!base) {
        return 'pace';
    }

    const key = resolveActivitySportKey(
        base.sport_category,
        base.effective_discipline,
        base.is_indoor,
    );

    return getSportMeta(key).metric_mode;
});

const usesPace = computed<boolean>(
    () =>
        baselineMetricMode.value === 'pace' ||
        baselineMetricMode.value === 'pace_100m',
);

const filteredCandidates = computed<ComparisonCandidate[]>(() => {
    const query = candidateSearch.value.trim().toLowerCase();

    if (!query) {
        return props.candidates;
    }

    return props.candidates.filter(
        (c) =>
            c.name.toLowerCase().includes(query) ||
            (c.location_name ?? '').toLowerCase().includes(query) ||
            c.started_at_local.toLowerCase().includes(query),
    );
});

function navigateWithSelection(
    ids: number[],
    align: ComparisonAlignMode = props.requested_align,
): void {
    const query: Record<string, string> = {};

    if (ids.length > 0) {
        query.ids = ids.join(',');
    }

    if (align !== 'distance') {
        query.align = align;
    }

    router.get(activitiesCompare.url(), query, {
        preserveScroll: true,
    });
}

function addActivity(id: number): void {
    if (props.selected_ids.includes(id) || props.selected_ids.length >= 3) {
        return;
    }

    pickerOpen.value = false;
    candidateSearch.value = '';
    navigateWithSelection([...props.selected_ids, id]);
}

function removeActivity(id: number): void {
    navigateWithSelection(props.selected_ids.filter((item) => item !== id));
}

function setAsBaseline(id: number): void {
    const rest = props.selected_ids.filter((item) => item !== id);
    navigateWithSelection([id, ...rest]);
}

function clearSelection(): void {
    navigateWithSelection([]);
}

function setAlignMode(mode: ComparisonAlignMode): void {
    if (mode === props.align && mode === props.requested_align) {
        return;
    }

    navigateWithSelection(props.selected_ids, mode);
}

function sportMetaFor(activity: {
    sport_category: string;
    effective_discipline: string | null;
    is_indoor: boolean;
}) {
    const key = resolveActivitySportKey(
        activity.sport_category,
        activity.effective_discipline,
        activity.is_indoor,
    );

    return getSportMeta(key);
}

function formatActivityDate(dateStr: string): string {
    const short = formatShortDate(dateStr);
    const parsed = new Date(dateStr);

    if (Number.isNaN(parsed.getTime())) {
        return short;
    }

    const year = parsed.getFullYear();

    return year !== new Date().getFullYear() ? `${short} ${year}` : short;
}

function formatSpeedOrPace(
    speedMps: number | null,
    mode: string = baselineMetricMode.value,
): string {
    if (mode === 'pace_100m') {
        return formatPace100m(speedMps);
    }

    if (mode === 'pace') {
        return formatPace(speedMps);
    }

    return formatSpeed(speedMps);
}

function formatSignedClock(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds)) {
        return '—';
    }

    if (Math.abs(seconds) < 0.5) {
        return '0:00';
    }

    const sign = seconds > 0 ? '+' : '-';

    return `${sign}${formatDurationClock(Math.abs(seconds))}`;
}

function formatSignedSecondsCompact(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds)) {
        return '—';
    }

    const abs = Math.abs(seconds);

    if (abs < 0.5) {
        return '0s';
    }

    const sign = seconds > 0 ? '+' : '-';

    if (abs < 60) {
        return `${sign}${Math.round(abs)}s`;
    }

    return `${sign}${formatDurationClock(abs)}`;
}

function formatSignedDistance(meters: number | null): string {
    if (meters === null || !Number.isFinite(meters)) {
        return '—';
    }

    if (Math.abs(meters) < 10) {
        return `0 ${distanceUnit.value}`;
    }

    const sign = meters > 0 ? '+' : '-';

    return `${sign}${formatDistance(Math.abs(meters), 2)}`;
}

function formatSignedElevation(meters: number | null): string {
    if (meters === null || !Number.isFinite(meters)) {
        return '—';
    }

    const rounded = Math.round(elevationFromMeters(meters));

    if (rounded === 0) {
        return `0 ${elevationUnit.value}`;
    }

    const sign = rounded > 0 ? '+' : '-';

    return `${sign}${formatNumber(Math.abs(rounded), 0)} ${elevationUnit.value}`;
}

function formatPaceOrSpeedDelta(
    currentMps: number | null,
    baselineMps: number | null,
): { text: string; tone: 'better' | 'worse' | 'neutral' } {
    if (
        currentMps === null ||
        baselineMps === null ||
        currentMps <= 0 ||
        baselineMps <= 0
    ) {
        return { text: '—', tone: 'neutral' };
    }

    if (baselineMetricMode.value === 'pace') {
        const currPace = paceSecondsFromMps(currentMps);
        const basePace = paceSecondsFromMps(baselineMps);

        if (currPace === null || basePace === null) {
            return { text: '—', tone: 'neutral' };
        }

        const diff = Math.round(currPace - basePace);

        if (diff === 0) {
            return { text: `0s${paceUnit.value}`, tone: 'neutral' };
        }

        const sign = diff > 0 ? '+' : '-';

        return {
            text: `${sign}${Math.abs(diff)}s${paceUnit.value}`,
            tone: diff < 0 ? 'better' : 'worse',
        };
    }

    if (baselineMetricMode.value === 'pace_100m') {
        const diff = Math.round(100 / currentMps - 100 / baselineMps);

        if (diff === 0) {
            return { text: '0s/100m', tone: 'neutral' };
        }

        const sign = diff > 0 ? '+' : '-';

        return {
            text: `${sign}${Math.abs(diff)}s/100m`,
            tone: diff < 0 ? 'better' : 'worse',
        };
    }

    const diffSpeed = speedFromMps(currentMps) - speedFromMps(baselineMps);

    if (Math.abs(diffSpeed) < 0.05) {
        return { text: `0.0 ${speedUnit.value}`, tone: 'neutral' };
    }

    const sign = diffSpeed > 0 ? '+' : '-';

    return {
        text: `${sign}${Math.abs(diffSpeed).toFixed(1)} ${speedUnit.value}`,
        tone: diffSpeed > 0 ? 'better' : 'worse',
    };
}

function formatSignedTempDelta(deltaC: number | null): string | null {
    if (deltaC === null || !Number.isFinite(deltaC)) {
        return null;
    }

    const converted = units.value === 'imperial' ? (deltaC * 9) / 5 : deltaC;
    const rounded = Math.round(converted * 10) / 10;

    if (Math.abs(rounded) < 0.5) {
        return 'Similar temp';
    }

    const sign = rounded > 0 ? '+' : '-';
    const label = rounded > 0 ? 'warmer' : 'cooler';

    return `${sign}${Math.abs(rounded).toFixed(0)}${tempUnit.value} ${label}`;
}

function formatSignedWindDelta(deltaKmh: number | null): string | null {
    if (deltaKmh === null || !Number.isFinite(deltaKmh)) {
        return null;
    }

    const converted = speedFromKmh(deltaKmh);
    const rounded = Math.round(converted);

    if (Math.abs(rounded) < 1) {
        return 'Similar wind';
    }

    const sign = rounded > 0 ? '+' : '-';
    const label = rounded > 0 ? 'windier' : 'calmer';

    return `${sign}${Math.abs(rounded)} ${speedUnit.value} ${label}`;
}

function formatHeadwindSummary(activity: ComparisonActivity): string | null {
    const w = activity.weather;

    if (!w) {
        return null;
    }

    if (w.avg_effective_wind_kmh !== null) {
        if (w.avg_effective_wind_kmh <= -3) {
            return `Headwind (${w.headwind_pct !== null ? `${Math.round(w.headwind_pct)}%` : formatSpeedKmh(Math.abs(w.avg_effective_wind_kmh), 0)})`;
        }

        if (w.avg_effective_wind_kmh >= 3) {
            return `Tailwind (${w.tailwind_pct !== null ? `${Math.round(w.tailwind_pct)}%` : formatSpeedKmh(w.avg_effective_wind_kmh, 0)})`;
        }

        return 'Balanced wind';
    }

    if (w.headwind_pct !== null && w.tailwind_pct !== null) {
        return `${Math.round(w.headwind_pct)}% head · ${Math.round(w.tailwind_pct)}% tail`;
    }

    return null;
}

function deltaToneClass(value: number | null, lowerIsBetter: boolean): string {
    if (value === null || Math.abs(value) < 0.01) {
        return 'text-zinc-400 dark:text-zinc-500';
    }

    const isBetter = lowerIsBetter ? value < 0 : value > 0;

    return isBetter
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-rose-600 dark:text-rose-400';
}

function escapeHtml(raw: string): string {
    return raw
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const hasAnyGapHeadline = computed<boolean>(() =>
    props.activities.some((a) => a.avg_grade_adjusted_speed !== null),
);
const hasAnyPowerHeadline = computed<boolean>(() =>
    props.activities.some(
        (a) => a.avg_power !== null || a.normalized_power !== null,
    ),
);
const hasAnyCadenceHeadline = computed<boolean>(() =>
    props.activities.some((a) => a.avg_cadence !== null),
);
const hasAnyWeatherHeadline = computed<boolean>(() =>
    props.activities.some((a) => a.weather !== null),
);

const hasAlignedStreams = computed<boolean>(
    () =>
        canCompare.value &&
        props.aligned.bins.length >= 2 &&
        props.aligned.series.filter((s) => s.has_stream).length >= 2,
);

const axisLabels = computed<string[]>(() => {
    const mode = props.aligned.mode;

    return props.aligned.bins.map((bin) => {
        if (mode === 'time') {
            return formatDurationClock(bin);
        }

        const converted = distanceFromMeters(bin);

        return converted >= 10 ? converted.toFixed(1) : converted.toFixed(2);
    });
});

function formatBinLabel(binIndex: number): string {
    const value = props.aligned.bins[binIndex];

    if (value === undefined) {
        return '';
    }

    if (props.aligned.mode === 'time') {
        return formatDurationClock(value);
    }

    return formatDistance(value, 2);
}

const challengerSummaries = computed(() =>
    props.aligned.series
        .map((series, index) => ({
            series,
            activity: props.activities[index],
            palette: slotPalette(index),
            index,
        }))
        .filter(
            (item) => item.index > 0 && item.activity && item.series.has_stream,
        ),
);

// Ghost Pacer Chart (Time Gap in distance mode, Distance Gap in time mode)
const ghostPacerOption = computed<EChartsOption>(() => {
    const mode = props.aligned.mode;
    const challengers = challengerSummaries.value;
    const isSingleChallenger = challengers.length === 1;
    const labels = axisLabels.value;

    const allValues: number[] = [];

    for (const item of challengers) {
        const arr =
            mode === 'distance'
                ? item.series.time_delta_seconds
                : item.series.distance_delta_meters;

        for (const v of arr) {
            if (v !== null && Number.isFinite(v)) {
                allValues.push(v);
            }
        }
    }

    const maxAbs = Math.max(
        mode === 'distance' ? 10 : 50,
        ...allValues.map((v) => Math.abs(v)),
    );
    const paddedBound = Math.ceil(maxAbs * 1.15);

    const chartSeries = challengers.map((item) => {
        const rawData =
            mode === 'distance'
                ? item.series.time_delta_seconds
                : item.series.distance_delta_meters;

        const baseSeries: Record<string, unknown> = {
            name: item.activity.name,
            type: 'line',
            data: rawData,
            showSymbol: false,
            smooth: 0.2,
            lineStyle: {
                width: 2.5,
                ...(isSingleChallenger ? {} : { color: item.palette.hex }),
            },
            itemStyle: {
                color: item.palette.hex,
            },
            markLine: {
                silent: true,
                symbol: 'none',
                lineStyle: {
                    color: theme.mutedColor.value,
                    type: 'dashed',
                    width: 1.5,
                },
                label: {
                    formatter: 'Baseline (0)',
                    color: theme.mutedColor.value,
                    fontFamily: theme.fontFamilyMono,
                    fontSize: 10,
                    position: 'insideEndTop',
                },
                data: [{ yAxis: 0 }],
            },
        };

        if (isSingleChallenger) {
            baseSeries.areaStyle = {
                opacity: 0.18,
            };
        } else if (item.index === 1) {
            baseSeries.markArea = {
                silent: true,
                data: [
                    [
                        {
                            yAxis: mode === 'distance' ? -paddedBound : 0,
                            itemStyle: { color: 'rgba(16, 185, 129, 0.045)' },
                        },
                        {
                            yAxis: mode === 'distance' ? 0 : paddedBound,
                        },
                    ],
                    [
                        {
                            yAxis: mode === 'distance' ? 0 : -paddedBound,
                            itemStyle: { color: 'rgba(244, 63, 94, 0.045)' },
                        },
                        {
                            yAxis: mode === 'distance' ? paddedBound : 0,
                        },
                    ],
                ],
            };
        }

        return baseSeries;
    });

    return {
        animation: false,
        grid: {
            left: 58,
            right: 20,
            top: 24,
            bottom: 34,
        },
        ...(isSingleChallenger
            ? {
                  visualMap: {
                      show: false,
                      dimension: 1,
                      pieces:
                          mode === 'distance'
                              ? [
                                    { lte: 0, color: '#10b981' },
                                    { gt: 0, color: '#f43f5e' },
                                ]
                              : [
                                    { gte: 0, color: '#10b981' },
                                    { lt: 0, color: '#f43f5e' },
                                ],
                  },
              }
            : {}),
        tooltip: {
            trigger: 'axis',
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const idx = first?.dataIndex;

                if (idx === undefined) {
                    return '';
                }

                const header = `<div style="font-weight:600;margin-bottom:4px;">${mode === 'distance' ? 'At' : 'Elapsed'} ${escapeHtml(formatBinLabel(idx))}</div>`;
                const lines = [header];

                for (const item of challengers) {
                    const val =
                        mode === 'distance'
                            ? item.series.time_delta_seconds[idx]
                            : item.series.distance_delta_meters[idx];

                    if (val === null || val === undefined) {
                        continue;
                    }

                    const isAhead = mode === 'distance' ? val < 0 : val > 0;
                    const color =
                        Math.abs(val) < 0.5
                            ? theme.mutedColor.value
                            : isAhead
                              ? '#10b981'
                              : '#f43f5e';
                    const formatted =
                        mode === 'distance'
                            ? `${formatSignedClock(val)} (${val < -0.5 ? 'ahead' : val > 0.5 ? 'behind' : 'even'})`
                            : `${formatSignedDistance(val)} (${val > 5 ? 'ahead' : val < -5 ? 'behind' : 'even'})`;

                    lines.push(
                        `<div style="display:flex;align-items:center;gap:6px;margin-top:2px;">` +
                            `<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;background:${item.palette.hex};"></span>` +
                            `<span>${escapeHtml(item.activity.name)}:</span>` +
                            `<strong style="color:${color};margin-left:auto;">${escapeHtml(formatted)}</strong>` +
                            `</div>`,
                    );
                }

                return lines.join('');
            },
        },
        xAxis: {
            type: 'category',
            data: labels,
            boundaryGap: false,
            name: mode === 'distance' ? distanceUnit.value : 'Elapsed',
            nameLocation: 'middle',
            nameGap: 20,
            nameTextStyle: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
            },
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
                interval: Math.max(1, Math.floor(labels.length / 8)),
            },
        },
        yAxis: {
            type: 'value',
            min: -paddedBound,
            max: paddedBound,
            name:
                mode === 'distance'
                    ? 'Behind (+) / Ahead (−)'
                    : 'Ahead (+) / Behind (−)',
            nameTextStyle: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
                formatter: (val: number) =>
                    mode === 'distance'
                        ? formatSignedSecondsCompact(val)
                        : `${val > 0 ? '+' : ''}${Math.round(val)}m`,
            },
        },
        series: chartSeries as EChartsOption['series'],
    };
});

function buildOverlayOption(params: {
    yAxisName: string;
    inverse?: boolean;
    yFormatter: (val: number) => string;
    tooltipValueFormatter: (val: number) => string;
    seriesData: Array<{
        name: string;
        color: string;
        isBaseline: boolean;
        data: Array<number | null>;
        areaOpacity?: number;
    }>;
}): EChartsOption {
    const labels = axisLabels.value;
    const mode = props.aligned.mode;

    return {
        animation: false,
        grid: {
            left: 56,
            right: 20,
            top: 24,
            bottom: 30,
        },
        tooltip: {
            trigger: 'axis',
            formatter: (rawParams: unknown) => {
                const list = Array.isArray(rawParams) ? rawParams : [rawParams];
                const first = list[0] as { dataIndex?: number } | undefined;
                const idx = first?.dataIndex;

                if (idx === undefined) {
                    return '';
                }

                const lines = [
                    `<div style="font-weight:600;margin-bottom:4px;">${mode === 'distance' ? 'At' : 'Elapsed'} ${escapeHtml(formatBinLabel(idx))}</div>`,
                ];

                for (const s of params.seriesData) {
                    const v = s.data[idx];

                    if (v === null || v === undefined || !Number.isFinite(v)) {
                        continue;
                    }

                    lines.push(
                        `<div style="display:flex;align-items:center;gap:6px;margin-top:2px;">` +
                            `<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;background:${s.color};"></span>` +
                            `<span>${escapeHtml(s.name)}:</span>` +
                            `<strong style="margin-left:auto;">${escapeHtml(params.tooltipValueFormatter(v))}</strong>` +
                            `</div>`,
                    );
                }

                return lines.join('');
            },
        },
        xAxis: {
            type: 'category',
            data: labels,
            boundaryGap: false,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
                interval: Math.max(1, Math.floor(labels.length / 8)),
            },
        },
        yAxis: {
            type: 'value',
            inverse: params.inverse ?? false,
            scale: true,
            name: params.yAxisName,
            nameTextStyle: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
                formatter: params.yFormatter,
            },
        },
        series: params.seriesData.map((s) => ({
            name: s.name,
            type: 'line' as const,
            data: s.data,
            showSymbol: false,
            smooth: 0.25,
            lineStyle: {
                color: s.color,
                width: s.isBaseline ? 2.2 : 2,
                type: s.isBaseline ? ('solid' as const) : ('solid' as const),
            },
            itemStyle: { color: s.color },
            ...(s.areaOpacity
                ? {
                      areaStyle: {
                          color: s.color,
                          opacity: s.areaOpacity,
                      },
                  }
                : {}),
        })),
    };
}

const speedOverlayOption = computed<EChartsOption>(() => {
    const isPace = usesPace.value;
    const isSwim = baselineMetricMode.value === 'pace_100m';
    const useGap = paceOverlayMode.value === 'gap' && props.aligned.has_gap;

    const seriesData = props.aligned.series
        .map((series, index) => {
            const activity = props.activities[index];

            if (!activity || !series.has_stream) {
                return null;
            }

            const raw = useGap ? series.gap_speed : series.speed;
            const converted = raw.map((mps) => {
                if (mps === null || !Number.isFinite(mps) || mps <= 0.3) {
                    return null;
                }

                if (isSwim) {
                    return Math.min(600, Math.round(100 / mps));
                }

                if (isPace) {
                    const sec = paceSecondsFromMps(mps);

                    return sec !== null && sec <= 1200 ? Math.round(sec) : null;
                }

                return Number(speedFromMps(mps).toFixed(2));
            });

            return {
                name: activity.name,
                color: slotPalette(index).hex,
                isBaseline: series.is_baseline,
                data: converted,
            };
        })
        .filter((item): item is NonNullable<typeof item> => item !== null);

    return buildOverlayOption({
        yAxisName: isSwim
            ? 'Pace (/100m)'
            : isPace
              ? `${useGap ? 'GAP' : 'Pace'} (${paceUnit.value})`
              : `Speed (${speedUnit.value})`,
        inverse: isPace,
        yFormatter: (val: number) =>
            isSwim
                ? formatDurationClock(val)
                : isPace
                  ? formatPaceSeconds(val).replace(/ \/.*$/, '')
                  : val.toFixed(1),
        tooltipValueFormatter: (val: number) =>
            isSwim
                ? `${formatDurationClock(val)} /100m`
                : isPace
                  ? formatPaceSeconds(val)
                  : `${val.toFixed(1)} ${speedUnit.value}`,
        seriesData,
    });
});

const hrOverlayOption = computed<EChartsOption>(() => {
    const seriesData = props.aligned.series
        .map((series, index) => {
            const activity = props.activities[index];

            if (!activity || !series.has_stream) {
                return null;
            }

            return {
                name: activity.name,
                color: slotPalette(index).hex,
                isBaseline: series.is_baseline,
                data: series.hr,
            };
        })
        .filter((item): item is NonNullable<typeof item> => item !== null);

    return buildOverlayOption({
        yAxisName: 'HR (bpm)',
        yFormatter: (val: number) => `${Math.round(val)}`,
        tooltipValueFormatter: (val: number) => `${Math.round(val)} bpm`,
        seriesData,
    });
});

const powerOverlayOption = computed<EChartsOption>(() => {
    const seriesData = props.aligned.series
        .map((series, index) => {
            const activity = props.activities[index];

            if (!activity || !series.has_stream) {
                return null;
            }

            return {
                name: activity.name,
                color: slotPalette(index).hex,
                isBaseline: series.is_baseline,
                data: series.power,
            };
        })
        .filter((item): item is NonNullable<typeof item> => item !== null);

    return buildOverlayOption({
        yAxisName: 'Power (W)',
        yFormatter: (val: number) => `${Math.round(val)}`,
        tooltipValueFormatter: (val: number) => `${Math.round(val)} W`,
        seriesData,
    });
});

const elevationOverlayOption = computed<EChartsOption>(() => {
    const seriesData = props.aligned.series
        .map((series, index) => {
            const activity = props.activities[index];

            if (!activity || !series.has_stream) {
                return null;
            }

            return {
                name: activity.name,
                color: slotPalette(index).hex,
                isBaseline: series.is_baseline,
                data: series.altitude.map((alt) =>
                    alt !== null
                        ? Number(elevationFromMeters(alt).toFixed(1))
                        : null,
                ),
                areaOpacity: series.is_baseline ? 0.1 : 0.04,
            };
        })
        .filter((item): item is NonNullable<typeof item> => item !== null);

    return buildOverlayOption({
        yAxisName: `Elev (${elevationUnit.value})`,
        yFormatter: (val: number) => `${Math.round(val)}`,
        tooltipValueFormatter: (val: number) =>
            `${Math.round(val)} ${elevationUnit.value}`,
        seriesData,
    });
});

function formatSplitPaceOrSpeed(entry: AlignedSplitEntry): string {
    if (entry.avg_speed !== null && entry.avg_speed > 0) {
        return formatSpeedOrPace(entry.avg_speed);
    }

    if (entry.pace_seconds_per_km !== null && entry.pace_seconds_per_km > 0) {
        return formatSpeedOrPace(1000 / entry.pace_seconds_per_km);
    }

    return '—';
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Compare Activities" />

        <!-- Top Navigation & Alignment Controls -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Link
                    :href="activitiesIndex.url()"
                    prefetch
                    class="inline-flex items-center gap-1.5 font-mono text-xs text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                >
                    <ArrowLeft class="size-3.5" />
                    All Activities
                </Link>
                <span class="text-zinc-300 dark:text-zinc-700">/</span>
                <span
                    class="inline-flex items-center gap-1.5 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 font-mono text-[10px] font-medium tracking-wider text-emerald-700 uppercase dark:text-emerald-300"
                >
                    <ArrowLeftRight class="size-3" />
                    Ghost Pacer
                </span>
            </div>

            <!-- Distance / Time Alignment Toggle -->
            <div class="flex flex-wrap items-center gap-2">
                <span
                    v-if="fallback_reason === 'missing_distance_stream'"
                    class="rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-1 font-mono text-[10px] text-amber-700 dark:text-amber-300"
                >
                    Aligned by time (missing distance stream)
                </span>

                <div
                    class="inline-flex rounded-lg border border-zinc-200/90 bg-white p-1 dark:border-zinc-800/90 dark:bg-[#141417]"
                    role="group"
                    aria-label="Alignment axis"
                >
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            align === 'distance'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="setAlignMode('distance')"
                    >
                        <Ruler class="size-3" />
                        Distance
                    </button>
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            align === 'time'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="setAlignMode('time')"
                    >
                        <Clock class="size-3" />
                        Elapsed Time
                    </button>
                </div>
            </div>
        </div>

        <!-- Activity Selector Header -->
        <section
            class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1
                        class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        Side-by-Side Comparison
                    </h1>
                    <p
                        class="mt-0.5 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        Compare up to 3 activities against your Baseline to
                        inspect pacing gaps, physiology, and weather impact.
                    </p>
                </div>

                <button
                    v-if="activities.length > 0"
                    type="button"
                    class="cursor-pointer rounded-lg border border-zinc-200/90 px-2.5 py-1.5 font-mono text-[11px] text-zinc-500 transition-colors hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                    @click="clearSelection"
                >
                    Clear all
                </button>
            </div>

            <!-- Color-coded Selected Activity Chips + Add Dropdown -->
            <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                <div
                    v-for="(activity, index) in activities"
                    :key="activity.id"
                    :class="[
                        'flex flex-col justify-between rounded-xl border p-3.5 transition-colors',
                        slotPalette(index).borderClass,
                        slotPalette(index).bgClass,
                    ]"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span
                                :class="[
                                    'size-2.5 shrink-0 rounded-full',
                                    slotPalette(index).dotClass,
                                ]"
                            />
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase',
                                    slotPalette(index).badgeClass,
                                ]"
                            >
                                <Crown v-if="index === 0" class="size-2.5" />
                                {{ slotPalette(index).label }}
                            </span>
                            <span
                                :class="[
                                    'rounded border px-1.5 py-0.5 font-mono text-[10px]',
                                    sportBadgeClasses(
                                        sportMetaFor(activity).color_token,
                                    ),
                                ]"
                            >
                                {{ sportMetaFor(activity).short_label }}
                            </span>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <button
                                v-if="index > 0"
                                type="button"
                                title="Set as Baseline"
                                class="cursor-pointer rounded-md border border-zinc-200/90 bg-white/80 px-1.5 py-0.5 font-mono text-[10px] text-zinc-600 transition-colors hover:border-emerald-500/40 hover:text-emerald-700 dark:border-zinc-700 dark:bg-zinc-900/80 dark:text-zinc-300 dark:hover:text-emerald-300"
                                @click="setAsBaseline(activity.id)"
                            >
                                Make Baseline
                            </button>
                            <button
                                type="button"
                                title="Remove activity"
                                aria-label="Remove activity"
                                class="inline-flex size-6 cursor-pointer items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-zinc-200/60 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                                @click="removeActivity(activity.id)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </div>
                    </div>

                    <div class="mt-2.5 min-w-0">
                        <Link
                            :href="showActivity.url(activity.id)"
                            class="block truncate text-sm font-semibold text-zinc-900 transition-colors hover:text-emerald-600 dark:text-zinc-100 dark:hover:text-emerald-400"
                        >
                            {{ activity.name }}
                        </Link>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            <span>{{
                                formatActivityDate(activity.started_at_local)
                            }}</span>
                            <span>·</span>
                            <span>{{
                                formatDistance(activity.distance_meters, 2)
                            }}</span>
                            <span>·</span>
                            <span>{{
                                formatDurationClock(activity.duration_seconds)
                            }}</span>
                        </div>
                    </div>
                </div>

                <!-- "+ Add Activity" Dropdown Slot -->
                <div
                    v-if="activities.length < 3"
                    ref="pickerContainerRef"
                    class="relative"
                >
                    <button
                        type="button"
                        class="flex h-full min-h-[104px] w-full cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed border-zinc-300 bg-zinc-50/50 p-4 text-zinc-500 transition-colors hover:border-zinc-400 hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-700/90 dark:bg-zinc-900/30 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:bg-zinc-900/60 dark:hover:text-zinc-100"
                        @click.stop="pickerOpen = !pickerOpen"
                    >
                        <Plus class="size-4" />
                        <span class="font-mono text-xs font-medium">
                            Add Activity
                            {{
                                activities.length === 0
                                    ? '(Baseline)'
                                    : `(#${activities.length + 1})`
                            }}
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{ candidates.length }} recent
                            {{
                                baselineActivity
                                    ? sportMetaFor(baselineActivity).label
                                    : 'activities'
                            }}
                            available
                        </span>
                    </button>

                    <div
                        v-if="pickerOpen"
                        class="absolute top-full right-0 left-0 z-30 mt-2 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-800 dark:bg-[#18181b]"
                    >
                        <div
                            class="border-b border-zinc-100 p-2 dark:border-zinc-800"
                        >
                            <div class="relative">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-zinc-400"
                                />
                                <input
                                    v-model="candidateSearch"
                                    type="search"
                                    placeholder="Filter recent activities..."
                                    class="w-full rounded-lg border border-zinc-200 bg-zinc-50 py-1.5 pr-3 pl-8 font-mono text-xs text-zinc-900 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                                />
                            </div>
                        </div>

                        <div
                            class="max-h-72 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800/80"
                        >
                            <button
                                v-for="candidate in filteredCandidates"
                                :key="candidate.id"
                                type="button"
                                class="flex w-full cursor-pointer items-center justify-between gap-3 px-3 py-2.5 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/60"
                                @click="addActivity(candidate.id)"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="truncate text-xs font-medium text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{ candidate.name }}
                                    </div>
                                    <div
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                    >
                                        {{
                                            formatActivityDate(
                                                candidate.started_at_local,
                                            )
                                        }}
                                        ·
                                        {{
                                            formatDistance(
                                                candidate.distance_meters,
                                                2,
                                            )
                                        }}
                                        ·
                                        {{
                                            formatDurationClock(
                                                candidate.duration_seconds,
                                            )
                                        }}
                                    </div>
                                </div>

                                <span
                                    v-if="candidate.has_streams"
                                    class="shrink-0 rounded border border-emerald-500/25 bg-emerald-500/10 px-1.5 py-0.5 font-mono text-[9px] text-emerald-700 dark:text-emerald-300"
                                >
                                    Streams
                                </span>
                            </button>

                            <p
                                v-if="filteredCandidates.length === 0"
                                class="px-3 py-6 text-center font-mono text-xs text-zinc-400"
                            >
                                No matching activities found.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Empty or Single-Activity Guidance State -->
        <section
            v-if="!canCompare"
            class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="mx-auto max-w-2xl text-center">
                <div
                    class="mx-auto flex size-10 items-center justify-center rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                >
                    <ArrowLeftRight class="size-5" />
                </div>
                <h2
                    class="mt-3 text-lg font-semibold text-zinc-900 dark:text-zinc-100"
                >
                    {{
                        activities.length === 1
                            ? 'Select a second activity to race your Ghost Pacer'
                            : 'Select 2 or 3 activities to compare side by side'
                    }}
                </h2>
                <p
                    class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                >
                    {{
                        activities.length === 1
                            ? `Pick another ${sportMetaFor(activities[0]).label} session below to see time-gap curves, telemetry overlays, weather differences, and kilometer split deltas.`
                            : 'Choose any recent session below as your Baseline, then add 1–2 sessions to compare pacing, heart rate, power, and weather.'
                    }}
                </p>
            </div>

            <div
                v-if="candidates.length > 0"
                class="mt-6 grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3"
            >
                <button
                    v-for="candidate in candidates.slice(0, 9)"
                    :key="candidate.id"
                    type="button"
                    class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-zinc-200/90 bg-zinc-50/50 p-3 text-left transition-colors hover:border-emerald-500/40 hover:bg-emerald-500/[0.04] dark:border-zinc-800/90 dark:bg-zinc-900/40 dark:hover:border-emerald-500/35"
                    @click="addActivity(candidate.id)"
                >
                    <div class="min-w-0">
                        <div
                            class="truncate text-xs font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            {{ candidate.name }}
                        </div>
                        <div
                            class="mt-0.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{ formatActivityDate(candidate.started_at_local) }}
                            ·
                            {{ formatDistance(candidate.distance_meters, 2) }}
                            ·
                            {{
                                formatDurationClock(candidate.duration_seconds)
                            }}
                        </div>
                    </div>
                    <Plus class="size-4 shrink-0 text-zinc-400" />
                </button>
            </div>
        </section>

        <!-- COMPARISON BODY (2–3 Activities) -->
        <template v-else>
            <!-- Headline Side-by-Side KPI & Weather Comparison Grid -->
            <section
                class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex items-center justify-between border-b border-zinc-100 px-5 py-3.5 dark:border-zinc-800/80"
                >
                    <h2
                        class="font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                    >
                        Headline Comparison &amp; Weather Diff
                    </h2>
                    <span
                        class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                    >
                        Deltas vs Baseline ({{ baselineActivity?.name }})
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table
                        class="w-full min-w-[640px] border-collapse text-left text-sm tabular-nums"
                    >
                        <thead>
                            <tr
                                class="border-b border-zinc-200/80 bg-zinc-50/70 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:text-zinc-400"
                            >
                                <th class="w-44 px-5 py-2.5">Metric</th>
                                <th
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-2.5"
                                >
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            :class="[
                                                'size-2 shrink-0 rounded-full',
                                                slotPalette(index).dotClass,
                                            ]"
                                        />
                                        <span
                                            class="truncate font-semibold text-zinc-800 dark:text-zinc-200"
                                        >
                                            {{ activity.name }}
                                        </span>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <tbody
                            class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/70"
                        >
                            <!-- Duration -->
                            <tr>
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Duration
                                </td>
                                <td
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline gap-2"
                                    >
                                        <span
                                            class="font-semibold text-zinc-900 dark:text-zinc-100"
                                        >
                                            {{
                                                formatDurationClock(
                                                    activity.duration_seconds,
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="index === 0"
                                            class="text-[10px] text-zinc-400"
                                        >
                                            Baseline
                                        </span>
                                        <span
                                            v-else-if="
                                                activity.deltas
                                                    ?.duration_delta_s !== null
                                            "
                                            :class="[
                                                'text-[11px] font-medium',
                                                deltaToneClass(
                                                    activity.deltas
                                                        ?.duration_delta_s ??
                                                        null,
                                                    true,
                                                ),
                                            ]"
                                        >
                                            {{
                                                formatSignedClock(
                                                    activity.deltas
                                                        ?.duration_delta_s ??
                                                        null,
                                                )
                                            }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="
                                            activity.moving_duration_seconds !==
                                                null &&
                                            Math.abs(
                                                activity.moving_duration_seconds -
                                                    activity.duration_seconds,
                                            ) >= 15
                                        "
                                        class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500"
                                    >
                                        Moving
                                        {{
                                            formatDurationClock(
                                                activity.moving_duration_seconds,
                                            )
                                        }}
                                    </div>
                                </td>
                            </tr>

                            <!-- Distance -->
                            <tr>
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Distance
                                </td>
                                <td
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline gap-2"
                                    >
                                        <span
                                            class="font-semibold text-zinc-900 dark:text-zinc-100"
                                        >
                                            {{
                                                formatDistance(
                                                    activity.distance_meters,
                                                    2,
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                index > 0 &&
                                                activity.deltas
                                                    ?.distance_delta_m !== null
                                            "
                                            class="text-[11px] text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{
                                                formatSignedDistance(
                                                    activity.deltas
                                                        ?.distance_delta_m ??
                                                        null,
                                                )
                                            }}
                                        </span>
                                    </div>
                                </td>
                            </tr>

                            <!-- Pace / Speed -->
                            <tr>
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Avg {{ usesPace ? 'Pace' : 'Speed' }}
                                </td>
                                <td
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline gap-2"
                                    >
                                        <span
                                            class="font-semibold text-zinc-900 dark:text-zinc-100"
                                        >
                                            {{
                                                formatSpeedOrPace(
                                                    activity.avg_speed,
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="index > 0 && baselineActivity"
                                            :class="[
                                                'text-[11px] font-medium',
                                                formatPaceOrSpeedDelta(
                                                    activity.avg_speed,
                                                    baselineActivity.avg_speed,
                                                ).tone === 'better'
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : formatPaceOrSpeedDelta(
                                                            activity.avg_speed,
                                                            baselineActivity.avg_speed,
                                                        ).tone === 'worse'
                                                      ? 'text-rose-600 dark:text-rose-400'
                                                      : 'text-zinc-400',
                                            ]"
                                        >
                                            {{
                                                formatPaceOrSpeedDelta(
                                                    activity.avg_speed,
                                                    baselineActivity.avg_speed,
                                                ).text
                                            }}
                                        </span>
                                    </div>
                                </td>
                            </tr>

                            <!-- GAP -->
                            <tr v-if="hasAnyGapHeadline">
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Grade-Adjusted
                                </td>
                                <td
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline gap-2"
                                    >
                                        <span
                                            class="text-zinc-900 dark:text-zinc-100"
                                        >
                                            {{
                                                formatSpeedOrPace(
                                                    activity.avg_grade_adjusted_speed,
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                index > 0 &&
                                                baselineActivity &&
                                                activity.avg_grade_adjusted_speed !==
                                                    null &&
                                                baselineActivity.avg_grade_adjusted_speed !==
                                                    null
                                            "
                                            :class="[
                                                'text-[11px] font-medium',
                                                formatPaceOrSpeedDelta(
                                                    activity.avg_grade_adjusted_speed,
                                                    baselineActivity.avg_grade_adjusted_speed,
                                                ).tone === 'better'
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : formatPaceOrSpeedDelta(
                                                            activity.avg_grade_adjusted_speed,
                                                            baselineActivity.avg_grade_adjusted_speed,
                                                        ).tone === 'worse'
                                                      ? 'text-rose-600 dark:text-rose-400'
                                                      : 'text-zinc-400',
                                            ]"
                                        >
                                            {{
                                                formatPaceOrSpeedDelta(
                                                    activity.avg_grade_adjusted_speed,
                                                    baselineActivity.avg_grade_adjusted_speed,
                                                ).text
                                            }}
                                        </span>
                                    </div>
                                </td>
                            </tr>

                            <!-- Elevation -->
                            <tr>
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Elevation Gain
                                </td>
                                <td
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline gap-2"
                                    >
                                        <span
                                            class="text-zinc-900 dark:text-zinc-100"
                                        >
                                            {{
                                                formatElevation(
                                                    activity.elevation_gain,
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                index > 0 &&
                                                activity.deltas
                                                    ?.elevation_gain_delta_m !==
                                                    null
                                            "
                                            class="text-[11px] text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{
                                                formatSignedElevation(
                                                    activity.deltas
                                                        ?.elevation_gain_delta_m ??
                                                        null,
                                                )
                                            }}
                                        </span>
                                    </div>
                                </td>
                            </tr>

                            <!-- Heart Rate -->
                            <tr>
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Avg / Max HR
                                </td>
                                <td
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline gap-2"
                                    >
                                        <span
                                            class="text-rose-600 dark:text-rose-400"
                                        >
                                            {{
                                                activity.avg_hr !== null
                                                    ? `${activity.avg_hr} bpm`
                                                    : '—'
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                index > 0 &&
                                                activity.deltas?.hr_delta !==
                                                    null &&
                                                activity.deltas?.hr_delta !==
                                                    undefined
                                            "
                                            :class="[
                                                'text-[11px] font-medium',
                                                deltaToneClass(
                                                    activity.deltas.hr_delta,
                                                    true,
                                                ),
                                            ]"
                                        >
                                            {{
                                                activity.deltas.hr_delta > 0
                                                    ? `+${activity.deltas.hr_delta}`
                                                    : activity.deltas.hr_delta
                                            }}
                                            bpm
                                        </span>
                                    </div>
                                    <div
                                        v-if="activity.max_hr !== null"
                                        class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500"
                                    >
                                        Max {{ activity.max_hr }} bpm
                                    </div>
                                </td>
                            </tr>

                            <!-- Power -->
                            <tr v-if="hasAnyPowerHeadline">
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Avg / NP Power
                                </td>
                                <td
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline gap-2"
                                    >
                                        <span
                                            class="text-sky-600 dark:text-sky-400"
                                        >
                                            {{
                                                activity.avg_power !== null
                                                    ? `${Math.round(activity.avg_power)} W`
                                                    : '—'
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                index > 0 &&
                                                activity.deltas?.power_delta !==
                                                    null &&
                                                activity.deltas?.power_delta !==
                                                    undefined
                                            "
                                            :class="[
                                                'text-[11px] font-medium',
                                                deltaToneClass(
                                                    activity.deltas.power_delta,
                                                    false,
                                                ),
                                            ]"
                                        >
                                            {{
                                                activity.deltas.power_delta > 0
                                                    ? `+${Math.round(activity.deltas.power_delta)}`
                                                    : Math.round(
                                                          activity.deltas
                                                              .power_delta,
                                                      )
                                            }}
                                            W
                                        </span>
                                    </div>
                                    <div
                                        v-if="
                                            activity.normalized_power !== null
                                        "
                                        class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500"
                                    >
                                        NP
                                        {{
                                            Math.round(
                                                activity.normalized_power,
                                            )
                                        }}
                                        W
                                    </div>
                                </td>
                            </tr>

                            <!-- Cadence -->
                            <tr v-if="hasAnyCadenceHeadline">
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Avg Cadence
                                </td>
                                <td
                                    v-for="activity in activities"
                                    :key="activity.id"
                                    class="px-4 py-3 text-zinc-800 dark:text-zinc-200"
                                >
                                    {{
                                        activity.avg_cadence !== null
                                            ? `${Math.round(activity.avg_cadence)} ${sportMetaFor(activity).cadence_unit ?? ''}`.trim()
                                            : '—'
                                    }}
                                </td>
                            </tr>

                            <!-- Load / TSS / Calories -->
                            <tr>
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    Load / Calories
                                </td>
                                <td
                                    v-for="activity in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <div
                                        class="text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{
                                            activity.training_load !== null
                                                ? `Load ${formatNumber(activity.training_load, 0)}`
                                                : activity.tss !== null
                                                  ? `TSS ${formatNumber(activity.tss, 0)}`
                                                  : '—'
                                        }}
                                        <span
                                            v-if="
                                                activity.training_load !==
                                                    null &&
                                                activity.tss !== null
                                            "
                                            class="text-zinc-400"
                                        >
                                            · TSS
                                            {{ formatNumber(activity.tss, 0) }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="activity.calories !== null"
                                        class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500"
                                    >
                                        {{ formatNumber(activity.calories, 0) }}
                                        kcal
                                    </div>
                                </td>
                            </tr>

                            <!-- Weather & Wind -->
                            <tr v-if="hasAnyWeatherHeadline">
                                <td
                                    class="px-5 py-3 text-zinc-500 dark:text-zinc-400"
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5"
                                    >
                                        <CloudSun
                                            class="size-3.5 text-sky-500"
                                        />
                                        Weather &amp; Wind
                                    </span>
                                </td>
                                <td
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-3"
                                >
                                    <template v-if="activity.weather">
                                        <div
                                            class="flex flex-wrap items-center gap-2 text-zinc-900 dark:text-zinc-100"
                                        >
                                            <span
                                                class="inline-flex items-center gap-1 font-medium"
                                            >
                                                <Thermometer
                                                    class="size-3 text-amber-500"
                                                />
                                                {{
                                                    formatTemp(
                                                        activity.weather
                                                            .temperature_c,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-if="
                                                    activity.weather
                                                        .wind_speed_kmh !== null
                                                "
                                                class="inline-flex items-center gap-1 text-zinc-600 dark:text-zinc-300"
                                            >
                                                <Wind
                                                    class="size-3 text-sky-500"
                                                />
                                                {{
                                                    formatSpeedKmh(
                                                        activity.weather
                                                            .wind_speed_kmh,
                                                        0,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                class="text-[11px] text-zinc-500 dark:text-zinc-400"
                                            >
                                                {{ activity.weather.condition }}
                                            </span>
                                        </div>

                                        <div
                                            class="mt-1 flex flex-wrap items-center gap-1.5 text-[10px]"
                                        >
                                            <span
                                                v-if="
                                                    formatHeadwindSummary(
                                                        activity,
                                                    )
                                                "
                                                class="rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
                                            >
                                                {{
                                                    formatHeadwindSummary(
                                                        activity,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-if="
                                                    index > 0 &&
                                                    formatSignedTempDelta(
                                                        activity.deltas
                                                            ?.temp_delta_c ??
                                                            null,
                                                    )
                                                "
                                                class="rounded border border-amber-500/25 bg-amber-500/10 px-1.5 py-0.5 text-amber-700 dark:text-amber-300"
                                            >
                                                {{
                                                    formatSignedTempDelta(
                                                        activity.deltas
                                                            ?.temp_delta_c ??
                                                            null,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-if="
                                                    index > 0 &&
                                                    formatSignedWindDelta(
                                                        activity.deltas
                                                            ?.wind_delta_kmh ??
                                                            null,
                                                    )
                                                "
                                                class="rounded border border-sky-500/25 bg-sky-500/10 px-1.5 py-0.5 text-sky-700 dark:text-sky-300"
                                            >
                                                {{
                                                    formatSignedWindDelta(
                                                        activity.deltas
                                                            ?.wind_delta_kmh ??
                                                            null,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                    </template>
                                    <span
                                        v-else
                                        class="text-zinc-400 dark:text-zinc-500"
                                    >
                                        —
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Ghost Pacer + Synchronized Telemetry Overlays -->
            <template v-if="hasAlignedStreams">
                <!-- Ghost Pacer Time/Distance Gap Chart -->
                <section
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h2
                                class="flex items-center gap-2 font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                            >
                                <ArrowLeftRight
                                    class="size-3.5 text-emerald-500"
                                />
                                {{
                                    aligned.mode === 'distance'
                                        ? 'Ghost Pacer — Time Gap vs Baseline'
                                        : 'Ghost Pacer — Distance Gap vs Baseline'
                                }}
                            </h2>
                            <p
                                class="mt-0.5 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    aligned.mode === 'distance'
                                        ? `Aligned across ${formatDistance(aligned.axis_max, 2)} · below zero = ahead of Baseline, above zero = behind`
                                        : `Aligned across ${formatDurationClock(aligned.axis_max)} · above zero = ahead of Baseline, below zero = behind`
                                }}
                            </p>
                        </div>

                        <!-- Challenger Gap Summary Pills -->
                        <div class="flex flex-wrap items-center gap-2">
                            <div
                                v-for="item in challengerSummaries"
                                :key="item.activity.id"
                                class="inline-flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-zinc-50/70 px-2.5 py-1 font-mono text-[11px] tabular-nums dark:border-zinc-800 dark:bg-zinc-900/60"
                            >
                                <span
                                    :class="[
                                        'size-2 rounded-full',
                                        item.palette.dotClass,
                                    ]"
                                />
                                <span
                                    class="max-w-[140px] truncate text-zinc-600 dark:text-zinc-300"
                                >
                                    {{ item.activity.name }}
                                </span>
                                <template v-if="aligned.mode === 'distance'">
                                    <strong
                                        :class="
                                            deltaToneClass(
                                                item.series.summary
                                                    .final_time_delta_seconds,
                                                true,
                                            )
                                        "
                                    >
                                        {{
                                            formatSignedClock(
                                                item.series.summary
                                                    .final_time_delta_seconds,
                                            )
                                        }}
                                    </strong>
                                </template>
                                <template v-else>
                                    <strong
                                        :class="
                                            deltaToneClass(
                                                item.series.summary
                                                    .final_distance_delta_meters,
                                                false,
                                            )
                                        "
                                    >
                                        {{
                                            formatSignedDistance(
                                                item.series.summary
                                                    .final_distance_delta_meters,
                                            )
                                        }}
                                    </strong>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <BaseChart
                            :option="ghostPacerOption"
                            :height="260"
                            group="activity-compare"
                        />
                    </div>
                </section>

                <!-- Synchronized Telemetry Overlay Grid -->
                <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <!-- Pace / Speed Overlay -->
                    <div
                        v-if="aligned.has_speed"
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    >
                        <div
                            class="mb-3 flex flex-wrap items-center justify-between gap-2"
                        >
                            <h3
                                class="flex items-center gap-1.5 font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                            >
                                <ActivityIcon
                                    class="size-3.5 text-emerald-500"
                                />
                                {{ usesPace ? 'Pace' : 'Speed' }} Overlay
                            </h3>

                            <div
                                v-if="aligned.has_gap && usesPace"
                                class="inline-flex rounded-md border border-zinc-200/90 p-0.5 font-mono text-[10px] dark:border-zinc-800"
                            >
                                <button
                                    type="button"
                                    class="cursor-pointer rounded px-2 py-0.5 transition-colors"
                                    :class="
                                        paceOverlayMode === 'raw'
                                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="paceOverlayMode = 'raw'"
                                >
                                    Raw
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded px-2 py-0.5 transition-colors"
                                    :class="
                                        paceOverlayMode === 'gap'
                                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="paceOverlayMode = 'gap'"
                                >
                                    GAP
                                </button>
                            </div>
                        </div>

                        <BaseChart
                            :option="speedOverlayOption"
                            :height="230"
                            group="activity-compare"
                        />
                    </div>

                    <!-- Heart Rate Overlay -->
                    <div
                        v-if="aligned.has_hr"
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <h3
                                class="flex items-center gap-1.5 font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                            >
                                <Heart class="size-3.5 text-rose-500" />
                                Heart Rate Overlay
                            </h3>
                        </div>

                        <BaseChart
                            :option="hrOverlayOption"
                            :height="230"
                            group="activity-compare"
                        />
                    </div>

                    <!-- Power Overlay -->
                    <div
                        v-if="aligned.has_power"
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <h3
                                class="flex items-center gap-1.5 font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                            >
                                <Zap class="size-3.5 text-sky-500" />
                                Power Overlay
                            </h3>
                        </div>

                        <BaseChart
                            :option="powerOverlayOption"
                            :height="230"
                            group="activity-compare"
                        />
                    </div>

                    <!-- Elevation Profile Overlay -->
                    <div
                        v-if="aligned.has_altitude"
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <h3
                                class="flex items-center gap-1.5 font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                            >
                                <Mountain class="size-3.5 text-amber-500" />
                                Elevation Profile
                            </h3>
                        </div>

                        <BaseChart
                            :option="elevationOverlayOption"
                            :height="230"
                            group="activity-compare"
                        />
                    </div>
                </section>
            </template>

            <section
                v-else
                class="rounded-xl border border-zinc-200/90 bg-white p-8 text-center dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    Synced telemetry streams are required on at least two
                    activities to render Ghost Pacer time-gap and overlay
                    charts.
                </p>
            </section>

            <!-- Aligned Splits Diff Table -->
            <section
                v-if="splits.length > 0"
                class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-5 py-3.5 dark:border-zinc-800/80"
                >
                    <h2
                        class="font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                    >
                        Kilometer Splits Diff ({{ splits.length }} splits)
                    </h2>
                    <span
                        class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                    >
                        Negative delta = faster split than Baseline
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table
                        class="w-full min-w-[680px] border-collapse text-left font-mono text-xs tabular-nums"
                    >
                        <thead>
                            <tr
                                class="border-b border-zinc-200/80 bg-zinc-50/70 text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:text-zinc-400"
                            >
                                <th class="px-4 py-2.5">Split</th>
                                <th
                                    v-for="(activity, index) in activities"
                                    :key="activity.id"
                                    class="px-4 py-2.5"
                                >
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            :class="[
                                                'size-2 shrink-0 rounded-full',
                                                slotPalette(index).dotClass,
                                            ]"
                                        />
                                        <span class="truncate">
                                            {{ activity.name }}
                                        </span>
                                    </div>
                                </th>
                            </tr>
                        </thead>

                        <tbody
                            class="divide-y divide-zinc-100 dark:divide-zinc-800/70"
                        >
                            <tr
                                v-for="row in splits"
                                :key="row.split_index"
                                class="transition-colors hover:bg-zinc-50/70 dark:hover:bg-zinc-900/40"
                            >
                                <td
                                    class="px-4 py-2.5 font-semibold whitespace-nowrap text-zinc-700 dark:text-zinc-200"
                                >
                                    {{ row.label }}
                                </td>

                                <td
                                    v-for="(entry, index) in row.entries"
                                    :key="entry.activity_id"
                                    class="px-4 py-2.5 whitespace-nowrap"
                                >
                                    <template
                                        v-if="entry.duration_seconds !== null"
                                    >
                                        <div
                                            class="flex flex-wrap items-baseline gap-2"
                                        >
                                            <span
                                                class="font-semibold text-zinc-900 dark:text-zinc-100"
                                            >
                                                {{
                                                    formatSplitPaceOrSpeed(
                                                        entry,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-if="
                                                    index > 0 &&
                                                    entry.time_delta_seconds !==
                                                        null
                                                "
                                                :class="[
                                                    'text-[11px] font-semibold',
                                                    deltaToneClass(
                                                        entry.time_delta_seconds,
                                                        true,
                                                    ),
                                                ]"
                                            >
                                                {{
                                                    formatSignedSecondsCompact(
                                                        entry.time_delta_seconds,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-if="entry.is_partial"
                                                class="py-0.2 rounded border border-zinc-200 px-1 text-[9px] text-zinc-400 dark:border-zinc-700"
                                            >
                                                partial
                                            </span>
                                        </div>

                                        <div
                                            class="mt-0.5 flex items-center gap-2 text-[10px] text-zinc-400 dark:text-zinc-500"
                                        >
                                            <span v-if="entry.avg_hr !== null">
                                                {{ entry.avg_hr }} bpm
                                            </span>
                                            <span
                                                v-if="
                                                    entry.elevation_delta !==
                                                    null
                                                "
                                            >
                                                {{
                                                    formatSignedElevation(
                                                        entry.elevation_delta,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                    </template>
                                    <span
                                        v-else
                                        class="text-zinc-300 dark:text-zinc-700"
                                    >
                                        —
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>
