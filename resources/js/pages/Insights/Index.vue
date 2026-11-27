<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Activity,
    Calendar,
    Clock,
    HeartPulse,
    Moon,
    RotateCcw,
    Sparkles,
    Thermometer,
    Wind,
} from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed, ref } from 'vue';
import InfoPopover from '@/components/InfoPopover.vue';
import BaseChart, {
    type ChartPointClickPayload,
} from '@/components/charts/BaseChart.vue';
import DateRangeControl, {
    type DateRangePreset,
} from '@/components/DateRangeControl.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatNumber, formatShortDate } from '@/lib/format';
import { escapeHtml } from '@/lib/html';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import { show as showActivity } from '@/routes/activities';
import { index as insightsIndex } from '@/routes/insights';
import type {
    CorrelationStat,
    CorrelationStrength,
    HrvScatterPoint,
    InsightsPageProps,
    RecoveryMatrixCell,
    SleepScatterPoint,
    WeatherScatterPoint,
} from '@/types/insights';

const props = defineProps<InsightsPageProps>();

const theme = useChartTheme();
const { getSportMeta } = useSports();
const {
    isImperial,
    tempUnit,
    distanceUnit,
    tempFromC,
    distanceFromMeters,
    formatTemp,
    formatDistanceCompact,
    formatPace,
    formatSpeed,
    formatSpeedKmh,
} = useUnits();

const RANGE_PRESETS: DateRangePreset[] = [
    { value: '90d', label: '90d' },
    { value: '180d', label: '180d' },
    { value: '1y', label: '1y' },
    { value: 'all', label: 'All' },
];

// Interactive panel view toggles
const sleepView = ref<'scatter' | 'duration' | 'score'>('scatter');
const sleepScatterX = ref<'sleep_score' | 'sleep_hours'>('sleep_score');
const sleepScatterY = ref<'efficiency_factor' | 'decoupling_pct'>(
    'efficiency_factor',
);

const hrvView = ref<'baseline' | 'status' | 'scatter'>('baseline');
const hrvScatterY = ref<'efficiency_factor' | 'decoupling_pct'>(
    'efficiency_factor',
);

const weatherView = ref<'scatter' | 'buckets'>('scatter');
const weatherScatterY = ref<'efficiency_factor' | 'decoupling_pct'>(
    'efficiency_factor',
);

const recoveryView = ref<'time' | 'intensity'>('time');

interface SportPill {
    value: string;
    label: string;
    accentHex: string | null;
}

const sportPills = computed<SportPill[]>(() => [
    { value: 'all', label: 'All Sports', accentHex: null },
    ...props.available_sports.map((sport) => ({
        value: sport.key,
        label: sport.short_label || sport.label,
        accentHex: sport.accent_hex,
    })),
]);

const activeSportMeta = computed(() =>
    props.filters.sport === 'all' ? null : getSportMeta(props.filters.sport),
);

// Speed averages mix running and cycling under "All Sports", so they are only shown per sport.
const hasComparableSpeed = computed<boolean>(
    () => activeSportMeta.value !== null,
);

const usePaceFormat = computed<boolean>(
    () =>
        activeSportMeta.value !== null &&
        activeSportMeta.value.metric_mode === 'pace',
);

function isSportActive(value: string): boolean {
    if (value === 'all') {
        return props.filters.sport === 'all';
    }

    return (
        props.filters.sport === value ||
        (props.filters.sport !== 'all' &&
            getSportMeta(props.filters.sport).key === value)
    );
}

interface QueryOverrides {
    sport?: string;
    range?: string;
    end?: string | null;
    from?: string | null;
    to?: string | null;
}

function buildQuery(overrides: QueryOverrides = {}): Record<string, string> {
    const nextSport = overrides.sport ?? props.filters.sport;
    const nextRange = overrides.range ?? props.filters.range;
    const nextEnd =
        overrides.end !== undefined
            ? overrides.end
            : props.dateWindow.isLatest
              ? null
              : props.dateWindow.end;
    const nextFrom =
        overrides.from !== undefined ? overrides.from : props.dateWindow.from;
    const nextTo =
        overrides.to !== undefined ? overrides.to : props.dateWindow.to;

    const query: Record<string, string> = {};

    if (nextSport && nextSport !== 'running') {
        query.sport = nextSport;
    }

    if (nextRange && nextRange !== '1y') {
        query.range = nextRange;
    }

    if (nextRange === 'custom') {
        if (nextFrom) {
            query.from = nextFrom;
        }

        if (nextTo) {
            query.to = nextTo;
        }
    } else if (nextRange !== 'all' && nextEnd) {
        query.end = nextEnd;
    }

    return query;
}

function navigate(overrides: QueryOverrides): void {
    router.get(insightsIndex.url(), buildQuery(overrides), {
        preserveState: true,
        preserveScroll: true,
    });
}

function selectSport(sport: string): void {
    navigate({ sport });
}

function selectPreset(range: string): void {
    navigate({ range, end: null, from: null, to: null });
}

function stepWindow(end: string | null): void {
    navigate({ end });
}

function applyCustomRange(payload: {
    from: string | null;
    to: string | null;
}): void {
    navigate({
        range: 'custom',
        end: null,
        from: payload.from,
        to: payload.to,
    });
}

function resetFilters(): void {
    router.get(
        insightsIndex.url(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

const hasActiveFilter = computed<boolean>(
    () =>
        props.filters.sport !== 'running' ||
        props.filters.range !== '1y' ||
        !props.dateWindow.isLatest,
);

function openActivity(activityId: number): void {
    router.visit(showActivity.url(activityId));
}

function formatTempBand(
    minC: number | null,
    maxC: number | null,
    fallback: string,
): string {
    if (!isImperial.value) {
        return fallback;
    }

    if (minC === null && maxC !== null) {
        return `< ${Math.round(tempFromC(maxC))}°F`;
    }

    if (minC !== null && maxC === null) {
        return `≥ ${Math.round(tempFromC(minC))}°F`;
    }

    if (minC !== null && maxC !== null) {
        return `${Math.round(tempFromC(minC))}–${Math.round(tempFromC(maxC))}°F`;
    }

    return fallback;
}

function formatSpeedOrPace(speedMps: number | null): string {
    if (speedMps === null || !hasComparableSpeed.value) {
        return '—';
    }

    return usePaceFormat.value ? formatPace(speedMps) : formatSpeed(speedMps);
}

function formatSigned(value: number | null, decimals = 1): string {
    if (value === null || !Number.isFinite(value)) {
        return '—';
    }

    const prefix = value > 0 ? '+' : '';

    return `${prefix}${formatNumber(value, decimals)}`;
}

const MIN_CORRELATION_SAMPLES = 10;

const CORRELATION_STRENGTH_LABELS: Record<CorrelationStrength, string> = {
    strong: 'Strong',
    moderate: 'Moderate',
    weak: 'Weak',
    negligible: 'Negligible',
    inconclusive: 'Not significant',
};

const efBasisLabel = computed<string>(() =>
    props.summary.ef_metric === 'power'
        ? 'power-based (W/bpm)'
        : 'speed-based (m/min/bpm)',
);

function formatPValue(pValue: number): string {
    return pValue < 0.001 ? 'p < 0.001' : `p = ${pValue.toFixed(3)}`;
}

function formatCorrelationLabel(stat: CorrelationStat): string {
    if (stat.r === null || stat.strength === null) {
        return `n = ${stat.n}`;
    }

    const prefix = stat.r > 0 ? '+' : '';

    return `${CORRELATION_STRENGTH_LABELS[stat.strength]} (${prefix}${stat.r.toFixed(2)})`;
}

function correlationTooltip(
    pairLabel: string,
    stat: CorrelationStat,
    unit = 'days',
): string {
    if (stat.r === null) {
        return `${pairLabel}: Needs at least ${MIN_CORRELATION_SAMPLES} paired ${unit} (currently ${stat.n}).`;
    }

    const prefix = stat.r > 0 ? '+' : '';
    const pText =
        stat.p_value !== null ? `, ${formatPValue(stat.p_value)}` : '';
    const verdict = stat.significant
        ? 'Statistically significant at p < 0.05.'
        : 'Not statistically significant (p ≥ 0.05) — this could be noise.';

    return `${pairLabel}: Pearson r = ${prefix}${stat.r.toFixed(2)}${pText} across ${stat.n} ${unit}. ${verdict}`;
}

function correlationTone(stat: CorrelationStat, higherIsBetter = true): string {
    if (stat.r === null) {
        return 'border-zinc-200/90 bg-zinc-50 text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400';
    }

    if (
        !stat.significant ||
        stat.strength === 'negligible' ||
        stat.strength === 'weak'
    ) {
        return 'border-zinc-200/90 bg-zinc-100/80 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-300';
    }

    const favorable = higherIsBetter ? stat.r > 0 : stat.r < 0;
    const isStrong = stat.strength === 'strong';

    if (favorable) {
        return isStrong
            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
            : 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    return isStrong
        ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
        : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';
}

const formattedWeatherTakeaway = computed<string | null>(() => {
    const raw = props.weather_impact.takeaway;

    if (!raw || !isImperial.value) {
        return raw;
    }

    let converted = raw;

    for (const bucket of props.weather_impact.temperature_buckets) {
        const imperialLabel = formatTempBand(
            bucket.min_c,
            bucket.max_c,
            bucket.label,
        );
        converted = converted.replaceAll(bucket.label, imperialLabel);
    }

    return converted;
});

function coveragePct(part: number, total: number): number {
    if (total <= 0) {
        return 0;
    }

    return Math.round((part / total) * 100);
}

// =========================================================================
// Panel 1: Sleep vs. Performance
// =========================================================================
const sleepScatterPoints = computed<SleepScatterPoint[]>(() =>
    props.sleep_vs_performance.scatter.filter((point) => {
        const xVal =
            sleepScatterX.value === 'sleep_score'
                ? point.sleep_score
                : point.sleep_hours;
        const yVal =
            sleepScatterY.value === 'efficiency_factor'
                ? point.efficiency_factor
                : point.decoupling_pct;

        return xVal !== null && yVal !== null;
    }),
);

function handleSleepChartClick(payload: ChartPointClickPayload): void {
    if (sleepView.value !== 'scatter') {
        return;
    }

    const point = sleepScatterPoints.value[payload.dataIndex];

    if (point) {
        openActivity(point.activity_id);
    }
}

const sleepChartOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    if (sleepView.value === 'scatter') {
        const points = sleepScatterPoints.value;
        const isScoreX = sleepScatterX.value === 'sleep_score';
        const isEfY = sleepScatterY.value === 'efficiency_factor';

        return {
            animation: false,
            grid: {
                left: 16,
                right: 20,
                top: 34,
                bottom: 34,
                containLabel: true,
            },
            tooltip: {
                trigger: 'item',
                formatter: (params: unknown) => {
                    const p = params as { dataIndex?: number };
                    const item =
                        p.dataIndex !== undefined
                            ? points[p.dataIndex]
                            : undefined;

                    if (!item) {
                        return '';
                    }

                    return [
                        `<strong>${escapeHtml(item.activity_name)}</strong>`,
                        `<span style="opacity:0.75">${formatShortDate(item.date)} · Click to open</span>`,
                        `Sleep: ${item.sleep_hours !== null ? `${formatNumber(item.sleep_hours, 1)}h` : '—'}${item.sleep_score !== null ? ` (score ${item.sleep_score})` : ''}`,
                        `Efficiency Factor: ${item.efficiency_factor !== null ? formatNumber(item.efficiency_factor, 2) : '—'}`,
                        `Decoupling: ${item.decoupling_pct !== null ? `${formatSigned(item.decoupling_pct, 1)}%` : '—'}`,
                        item.avg_hr !== null
                            ? `Avg HR: ${item.avg_hr} bpm`
                            : '',
                    ]
                        .filter(Boolean)
                        .join('<br/>');
                },
            },
            xAxis: {
                type: 'value',
                scale: true,
                name: isScoreX ? 'Sleep Score' : 'Sleep Duration (h)',
                nameLocation: 'middle',
                nameGap: 24,
                nameTextStyle: axisLabelStyle,
                axisLine: { lineStyle: { color: theme.gridLineColor.value } },
                axisLabel: axisLabelStyle,
                splitLine: {
                    lineStyle: {
                        color: theme.gridLineColor.value,
                        type: 'dashed',
                    },
                },
            },
            yAxis: {
                type: 'value',
                scale: true,
                name: isEfY ? 'Efficiency Factor' : 'Decoupling (%)',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            series: [
                {
                    name: 'Session',
                    type: 'scatter',
                    symbolSize: 10,
                    cursor: 'pointer',
                    itemStyle: {
                        color: isEfY
                            ? 'rgba(99, 102, 241, 0.8)'
                            : 'rgba(245, 158, 11, 0.8)',
                        borderColor: theme.isDark.value ? '#141417' : '#ffffff',
                        borderWidth: 1,
                    },
                    data: points.map((pt) => [
                        isScoreX ? pt.sleep_score : pt.sleep_hours,
                        isEfY ? pt.efficiency_factor : pt.decoupling_pct,
                    ]),
                },
            ],
        };
    }

    const buckets =
        sleepView.value === 'score'
            ? props.sleep_vs_performance.score_buckets
            : props.sleep_vs_performance.duration_buckets;

    return {
        animation: false,
        grid: {
            left: 14,
            right: 16,
            top: 58,
            bottom: 14,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: axisLabelStyle,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const bucket =
                    first?.dataIndex !== undefined
                        ? buckets[first.dataIndex]
                        : undefined;

                if (!bucket) {
                    return '';
                }

                return [
                    `<strong>${escapeHtml(bucket.label)}</strong> (${bucket.count} ${bucket.count === 1 ? 'day' : 'days'})`,
                    `Avg EF: ${bucket.avg_ef !== null ? formatNumber(bucket.avg_ef, 2) : '—'}`,
                    `Avg Decoupling: ${bucket.avg_decoupling !== null ? `${formatSigned(bucket.avg_decoupling, 1)}%` : '—'}`,
                    `Avg HR: ${bucket.avg_hr !== null ? `${formatNumber(bucket.avg_hr, 0)} bpm` : '—'}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: buckets.map((b) => `${b.label}\n(n=${b.count})`),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: { ...axisLabelStyle, lineHeight: 15 },
        },
        yAxis: [
            {
                type: 'value',
                scale: true,
                name: 'Avg EF',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                name: 'Decoupling %',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: 'Avg Efficiency Factor',
                type: 'bar',
                yAxisIndex: 0,
                barMaxWidth: 34,
                itemStyle: { color: '#6366f1', borderRadius: [4, 4, 0, 0] },
                data: buckets.map((b) => b.avg_ef),
            },
            {
                name: 'Avg Decoupling %',
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.25,
                symbol: 'circle',
                symbolSize: 7,
                lineStyle: { color: '#f59e0b', width: 2 },
                itemStyle: { color: '#f59e0b' },
                data: buckets.map((b) => b.avg_decoupling),
            },
        ],
    };
});

// =========================================================================
// Panel 2: HRV & Recovery vs. Output
// =========================================================================
const hrvScatterPoints = computed<HrvScatterPoint[]>(() =>
    props.hrv_vs_performance.scatter.filter((point) => {
        const yVal =
            hrvScatterY.value === 'efficiency_factor'
                ? point.efficiency_factor
                : point.decoupling_pct;

        return point.hrv !== null && yVal !== null;
    }),
);

function handleHrvChartClick(payload: ChartPointClickPayload): void {
    if (hrvView.value !== 'scatter') {
        return;
    }

    const point = hrvScatterPoints.value[payload.dataIndex];

    if (point) {
        openActivity(point.activity_id);
    }
}

const hrvChartOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    if (hrvView.value === 'scatter') {
        const points = hrvScatterPoints.value;
        const isEfY = hrvScatterY.value === 'efficiency_factor';

        return {
            animation: false,
            grid: {
                left: 16,
                right: 20,
                top: 34,
                bottom: 34,
                containLabel: true,
            },
            tooltip: {
                trigger: 'item',
                formatter: (params: unknown) => {
                    const p = params as { dataIndex?: number };
                    const item =
                        p.dataIndex !== undefined
                            ? points[p.dataIndex]
                            : undefined;

                    if (!item) {
                        return '';
                    }

                    return [
                        `<strong>${escapeHtml(item.activity_name)}</strong>`,
                        `<span style="opacity:0.75">${formatShortDate(item.date)} · Click to open</span>`,
                        `Overnight HRV: ${item.hrv !== null ? `${item.hrv} ms` : '—'}${item.status ? ` (${escapeHtml(item.status)})` : ''}`,
                        item.rhr !== null ? `Resting HR: ${item.rhr} bpm` : '',
                        `Efficiency Factor: ${item.efficiency_factor !== null ? formatNumber(item.efficiency_factor, 2) : '—'}`,
                        `Decoupling: ${item.decoupling_pct !== null ? `${formatSigned(item.decoupling_pct, 1)}%` : '—'}`,
                    ]
                        .filter(Boolean)
                        .join('<br/>');
                },
            },
            xAxis: {
                type: 'value',
                scale: true,
                name: 'Overnight HRV (ms)',
                nameLocation: 'middle',
                nameGap: 24,
                nameTextStyle: axisLabelStyle,
                axisLine: { lineStyle: { color: theme.gridLineColor.value } },
                axisLabel: axisLabelStyle,
                splitLine: {
                    lineStyle: {
                        color: theme.gridLineColor.value,
                        type: 'dashed',
                    },
                },
            },
            yAxis: {
                type: 'value',
                scale: true,
                name: isEfY ? 'Efficiency Factor' : 'Decoupling (%)',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            series: [
                {
                    name: 'Session',
                    type: 'scatter',
                    symbolSize: 10,
                    cursor: 'pointer',
                    itemStyle: {
                        color: 'rgba(139, 92, 246, 0.82)',
                        borderColor: theme.isDark.value ? '#141417' : '#ffffff',
                        borderWidth: 1,
                    },
                    data: points.map((pt) => [
                        pt.hrv,
                        isEfY ? pt.efficiency_factor : pt.decoupling_pct,
                    ]),
                },
            ],
        };
    }

    const buckets =
        hrvView.value === 'status'
            ? props.hrv_vs_performance.status_buckets
            : props.hrv_vs_performance.baseline_buckets;

    return {
        animation: false,
        grid: {
            left: 14,
            right: 16,
            top: 58,
            bottom: 14,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: axisLabelStyle,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const bucket =
                    first?.dataIndex !== undefined
                        ? buckets[first.dataIndex]
                        : undefined;

                if (!bucket) {
                    return '';
                }

                return [
                    `<strong>${escapeHtml(bucket.label)}</strong> (${bucket.count} ${bucket.count === 1 ? 'day' : 'days'})`,
                    `Avg EF: ${bucket.avg_ef !== null ? formatNumber(bucket.avg_ef, 2) : '—'}`,
                    `Avg Decoupling: ${bucket.avg_decoupling !== null ? `${formatSigned(bucket.avg_decoupling, 1)}%` : '—'}`,
                    `Avg HRV: ${bucket.avg_hrv !== null ? `${formatNumber(bucket.avg_hrv, 0)} ms` : '—'}`,
                    `Avg RHR: ${bucket.avg_rhr !== null ? `${formatNumber(bucket.avg_rhr, 0)} bpm` : '—'}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: buckets.map((b) => `${b.label}\n(n=${b.count})`),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: { ...axisLabelStyle, lineHeight: 15 },
        },
        yAxis: [
            {
                type: 'value',
                scale: true,
                name: 'Avg EF',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                name: 'Decoupling %',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: 'Avg Efficiency Factor',
                type: 'bar',
                yAxisIndex: 0,
                barMaxWidth: 36,
                itemStyle: { color: '#8b5cf6', borderRadius: [4, 4, 0, 0] },
                data: buckets.map((b) => b.avg_ef),
            },
            {
                name: 'Avg Decoupling %',
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.25,
                symbol: 'circle',
                symbolSize: 7,
                lineStyle: { color: '#10b981', width: 2 },
                itemStyle: { color: '#10b981' },
                data: buckets.map((b) => b.avg_decoupling),
            },
        ],
    };
});

// =========================================================================
// Panel 3: Weather & Wind Impact (Full °C ↔ °F conversion via useUnits)
// =========================================================================
const weatherScatterPoints = computed<WeatherScatterPoint[]>(() =>
    props.weather_impact.scatter.filter((point) => {
        const yVal =
            weatherScatterY.value === 'efficiency_factor'
                ? point.efficiency_factor
                : point.decoupling_pct;

        return yVal !== null;
    }),
);

function handleWeatherChartClick(payload: ChartPointClickPayload): void {
    if (weatherView.value !== 'scatter') {
        return;
    }

    const point = weatherScatterPoints.value[payload.dataIndex];

    if (point) {
        openActivity(point.activity_id);
    }
}

function tempPointColor(tempC: number): string {
    if (tempC < 10) {
        return '#0ea5e9';
    }

    if (tempC < 15) {
        return '#10b981';
    }

    if (tempC < 20) {
        return '#84cc16';
    }

    if (tempC < 25) {
        return '#f59e0b';
    }

    return '#f43f5e';
}

const weatherChartOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    if (weatherView.value === 'scatter') {
        const points = weatherScatterPoints.value;
        const isEfY = weatherScatterY.value === 'efficiency_factor';

        return {
            animation: false,
            grid: {
                left: 16,
                right: 20,
                top: 34,
                bottom: 34,
                containLabel: true,
            },
            tooltip: {
                trigger: 'item',
                formatter: (params: unknown) => {
                    const p = params as { dataIndex?: number };
                    const item =
                        p.dataIndex !== undefined
                            ? points[p.dataIndex]
                            : undefined;

                    if (!item) {
                        return '';
                    }

                    return [
                        `<strong>${escapeHtml(item.activity_name)}</strong>`,
                        `<span style="opacity:0.75">${formatShortDate(item.date)} · Click to open</span>`,
                        `Temp: ${formatTemp(item.temperature_c, 1)}${item.dew_point_c !== null ? ` · Dew point ${formatTemp(item.dew_point_c, 1)}` : ''}`,
                        `Efficiency Factor: ${item.efficiency_factor !== null ? formatNumber(item.efficiency_factor, 2) : '—'}`,
                        `Decoupling: ${item.decoupling_pct !== null ? `${formatSigned(item.decoupling_pct, 1)}%` : '—'}`,
                        item.avg_speed !== null && hasComparableSpeed.value
                            ? `${usePaceFormat.value ? 'Avg Pace' : 'Avg Speed'}: ${formatSpeedOrPace(item.avg_speed)}`
                            : '',
                    ]
                        .filter(Boolean)
                        .join('<br/>');
                },
            },
            xAxis: {
                type: 'value',
                scale: true,
                name: `Temperature (${tempUnit.value})`,
                nameLocation: 'middle',
                nameGap: 24,
                nameTextStyle: axisLabelStyle,
                axisLine: { lineStyle: { color: theme.gridLineColor.value } },
                axisLabel: axisLabelStyle,
                splitLine: {
                    lineStyle: {
                        color: theme.gridLineColor.value,
                        type: 'dashed',
                    },
                },
            },
            yAxis: {
                type: 'value',
                scale: true,
                name: isEfY ? 'Efficiency Factor' : 'Decoupling (%)',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            series: [
                {
                    name: 'Session',
                    type: 'scatter',
                    symbolSize: 10,
                    cursor: 'pointer',
                    data: points.map((pt) => ({
                        value: [
                            Number(tempFromC(pt.temperature_c).toFixed(1)),
                            isEfY ? pt.efficiency_factor : pt.decoupling_pct,
                        ],
                        itemStyle: {
                            color: tempPointColor(pt.temperature_c),
                            opacity: 0.85,
                            borderColor: theme.isDark.value
                                ? '#141417'
                                : '#ffffff',
                            borderWidth: 1,
                        },
                    })),
                },
            ],
        };
    }

    const buckets = props.weather_impact.temperature_buckets;

    return {
        animation: false,
        grid: {
            left: 14,
            right: 16,
            top: 58,
            bottom: 14,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: axisLabelStyle,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const bucket =
                    first?.dataIndex !== undefined
                        ? buckets[first.dataIndex]
                        : undefined;

                if (!bucket) {
                    return '';
                }

                const bandLabel = formatTempBand(
                    bucket.min_c,
                    bucket.max_c,
                    bucket.label,
                );

                return [
                    `<strong>${escapeHtml(bandLabel)}</strong> (${bucket.count} ${bucket.count === 1 ? 'session' : 'sessions'})`,
                    `Avg EF: ${bucket.avg_ef !== null ? formatNumber(bucket.avg_ef, 2) : '—'}`,
                    `Avg Decoupling: ${bucket.avg_decoupling !== null ? `${formatSigned(bucket.avg_decoupling, 1)}%` : '—'}`,
                    hasComparableSpeed.value
                        ? `${usePaceFormat.value ? 'Avg Pace' : 'Avg Speed'}: ${formatSpeedOrPace(bucket.avg_speed)}`
                        : '',
                    `Avg HR: ${bucket.avg_hr !== null ? `${formatNumber(bucket.avg_hr, 0)} bpm` : '—'}`,
                ]
                    .filter(Boolean)
                    .join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: buckets.map(
                (b) =>
                    `${formatTempBand(b.min_c, b.max_c, b.label)}\n(n=${b.count})`,
            ),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: { ...axisLabelStyle, lineHeight: 15 },
        },
        yAxis: [
            {
                type: 'value',
                scale: true,
                name: 'Avg EF',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                name: 'Decoupling %',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: 'Avg Efficiency Factor',
                type: 'bar',
                yAxisIndex: 0,
                barMaxWidth: 34,
                data: buckets.map((b, idx) => ({
                    value: b.avg_ef,
                    itemStyle: {
                        color: [
                            '#0ea5e9',
                            '#10b981',
                            '#84cc16',
                            '#f59e0b',
                            '#f43f5e',
                        ][idx],
                        borderRadius: [4, 4, 0, 0],
                    },
                })),
            },
            {
                name: 'Avg Decoupling %',
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.25,
                symbol: 'circle',
                symbolSize: 7,
                lineStyle: {
                    color: theme.isDark.value ? '#e4e4e7' : '#27272a',
                    width: 2,
                },
                itemStyle: {
                    color: theme.isDark.value ? '#e4e4e7' : '#27272a',
                },
                data: buckets.map((b) => b.avg_decoupling),
            },
        ],
    };
});

// =========================================================================
// Panel 4: Evening Workouts -> Overnight Recovery
// =========================================================================
const recoveryChartOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    const buckets =
        recoveryView.value === 'intensity'
            ? props.workout_to_recovery.by_intensity
            : props.workout_to_recovery.by_time_of_day;

    return {
        animation: false,
        grid: {
            left: 14,
            right: 16,
            top: 58,
            bottom: 14,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: axisLabelStyle,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const bucket =
                    first?.dataIndex !== undefined
                        ? buckets[first.dataIndex]
                        : undefined;

                if (!bucket) {
                    return '';
                }

                return [
                    `<strong>${escapeHtml(bucket.label)}</strong> (${bucket.count} ${bucket.count === 1 ? 'day' : 'days'})`,
                    `Next-Night HRV Δ: ${bucket.avg_hrv_delta !== null ? `${formatSigned(bucket.avg_hrv_delta, 1)} ms` : '—'}`,
                    `Next-Night HRV: ${bucket.avg_hrv !== null ? `${formatNumber(bucket.avg_hrv, 0)} ms` : '—'}`,
                    `Next-Night Sleep Score: ${bucket.avg_sleep_score !== null ? formatNumber(bucket.avg_sleep_score, 0) : '—'}`,
                    `Next-Night Deep Sleep: ${bucket.avg_deep_minutes !== null ? `${formatNumber(bucket.avg_deep_minutes, 0)} min` : '—'}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: buckets.map((b) => `${b.label}\n(n=${b.count})`),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: { ...axisLabelStyle, lineHeight: 15 },
        },
        yAxis: [
            {
                type: 'value',
                name: 'HRV Δ vs prior 7 nights (ms)',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                scale: true,
                name: 'Sleep Score',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: 'Next-Night HRV Δ (ms)',
                type: 'bar',
                yAxisIndex: 0,
                barMaxWidth: 38,
                markLine: {
                    silent: true,
                    symbol: ['none', 'none'],
                    label: { show: false },
                    lineStyle: {
                        color: theme.mutedColor.value,
                        type: 'dashed',
                        width: 1,
                    },
                    data: [{ yAxis: 0 }],
                },
                data: buckets.map((b) => ({
                    value: b.avg_hrv_delta,
                    itemStyle: {
                        color:
                            b.avg_hrv_delta === null
                                ? '#71717a'
                                : b.avg_hrv_delta >= 0
                                  ? '#10b981'
                                  : '#f43f5e',
                        borderRadius:
                            (b.avg_hrv_delta ?? 0) >= 0
                                ? [4, 4, 0, 0]
                                : [0, 0, 4, 4],
                    },
                })),
            },
            {
                name: 'Next-Night Sleep Score',
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.25,
                symbol: 'circle',
                symbolSize: 8,
                lineStyle: { color: '#6366f1', width: 2 },
                itemStyle: { color: '#6366f1' },
                data: buckets.map((b) => b.avg_sleep_score),
            },
        ],
    };
});

const recoveryMatrixRows = computed(() => {
    const timeOrder = props.workout_to_recovery.by_time_of_day.map(
        (bucket) => ({ key: bucket.key, label: bucket.label }),
    );
    const intensityOrder = ['easy', 'moderate', 'hard'];
    const byKey = new Map<string, RecoveryMatrixCell>();

    for (const cell of props.workout_to_recovery.matrix) {
        byKey.set(`${cell.time_key}:${cell.intensity_key}`, cell);
    }

    return timeOrder.map((t) => ({
        timeKey: t.key,
        timeLabel: t.label,
        cells: intensityOrder.map(
            (iKey) => byKey.get(`${t.key}:${iKey}`) ?? null,
        ),
    }));
});

// =========================================================================
// Panel 5: Day-of-Week & Time-of-Day Patterns
// =========================================================================
const dayOfWeekChartOption = computed<EChartsOption>(() => {
    const buckets = props.schedule_patterns.by_day_of_week;
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 58,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: axisLabelStyle,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const bucket =
                    first?.dataIndex !== undefined
                        ? buckets[first.dataIndex]
                        : undefined;

                if (!bucket) {
                    return '';
                }

                return [
                    `<strong>${escapeHtml(bucket.label)}</strong> (${bucket.count} ${bucket.count === 1 ? 'session' : 'sessions'})`,
                    `Total Distance: ${formatDistanceCompact(bucket.total_distance_meters, 1)}`,
                    `Avg EF: ${bucket.avg_ef !== null ? formatNumber(bucket.avg_ef, 2) : '—'}`,
                    `Avg Decoupling: ${bucket.avg_decoupling !== null ? `${formatSigned(bucket.avg_decoupling, 1)}%` : '—'}`,
                    `Avg HR: ${bucket.avg_hr !== null ? `${formatNumber(bucket.avg_hr, 0)} bpm` : '—'}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: buckets.map((b) => b.label),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: [
            {
                type: 'value',
                name: `Volume (${distanceUnit.value})`,
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                scale: true,
                name: 'Avg EF',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: `Total Distance (${distanceUnit.value})`,
                type: 'bar',
                yAxisIndex: 0,
                barMaxWidth: 28,
                itemStyle: { color: '#0ea5e9', borderRadius: [4, 4, 0, 0] },
                data: buckets.map((b) =>
                    Number(
                        distanceFromMeters(b.total_distance_meters).toFixed(1),
                    ),
                ),
            },
            {
                name: 'Avg EF',
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.25,
                symbol: 'circle',
                symbolSize: 7,
                lineStyle: { color: '#10b981', width: 2 },
                itemStyle: { color: '#10b981' },
                data: buckets.map((b) => b.avg_ef),
            },
        ],
    };
});

const timeOfDayChartOption = computed<EChartsOption>(() => {
    const buckets = props.schedule_patterns.by_time_of_day;
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 58,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: axisLabelStyle,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const bucket =
                    first?.dataIndex !== undefined
                        ? buckets[first.dataIndex]
                        : undefined;

                if (!bucket) {
                    return '';
                }

                return [
                    `<strong>${escapeHtml(bucket.label)}</strong> (${bucket.count} ${bucket.count === 1 ? 'session' : 'sessions'})`,
                    `Total Distance: ${formatDistanceCompact(bucket.total_distance_meters, 1)}`,
                    `Avg EF: ${bucket.avg_ef !== null ? formatNumber(bucket.avg_ef, 2) : '—'}`,
                    `Avg Decoupling: ${bucket.avg_decoupling !== null ? `${formatSigned(bucket.avg_decoupling, 1)}%` : '—'}`,
                    hasComparableSpeed.value
                        ? `${usePaceFormat.value ? 'Avg Pace' : 'Avg Speed'}: ${formatSpeedOrPace(bucket.avg_speed)}`
                        : '',
                ]
                    .filter(Boolean)
                    .join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: buckets.map((b) => b.label.replace(' (', '\n(')),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: { ...axisLabelStyle, lineHeight: 14 },
        },
        yAxis: [
            {
                type: 'value',
                name: 'Sessions',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                scale: true,
                name: 'Avg EF',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: 'Sessions',
                type: 'bar',
                yAxisIndex: 0,
                barMaxWidth: 28,
                itemStyle: { color: '#f59e0b', borderRadius: [4, 4, 0, 0] },
                data: buckets.map((b) => b.count),
            },
            {
                name: 'Avg EF',
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.25,
                symbol: 'circle',
                symbolSize: 7,
                lineStyle: { color: '#6366f1', width: 2 },
                itemStyle: { color: '#6366f1' },
                data: buckets.map((b) => b.avg_ef),
            },
        ],
    };
});

function windBadgeTone(key: string): string {
    if (key === 'tailwind') {
        return 'border-emerald-500/30 bg-emerald-500/8 text-emerald-700 dark:text-emerald-300';
    }

    if (key === 'headwind') {
        return 'border-rose-500/30 bg-rose-500/8 text-rose-700 dark:text-rose-300';
    }

    return 'border-zinc-200/90 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-300';
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Health & Performance Insights" />

        <!-- Page Header -->
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div class="space-y-1">
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-950 sm:text-3xl dark:text-zinc-50"
                >
                    Health &amp; Performance Insights
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Discover how sleep, HRV, weather, wind, and workout timing
                    relate to aerobic efficiency, cardiac drift, and overnight
                    recovery.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <DateRangeControl
                    :presets="RANGE_PRESETS"
                    :window="dateWindow"
                    surface="page"
                    popover-align="right"
                    :max-date="today"
                    aria-label="Insights date range"
                    @select-preset="selectPreset"
                    @step="stepWindow"
                    @apply-custom="applyCustomRange"
                />
            </div>
        </header>

        <!-- Sport Filter Bar -->
        <section
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200/90 bg-white p-3.5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex flex-wrap items-center gap-1.5"
                role="group"
                aria-label="Filter insights by sport"
            >
                <button
                    v-for="pill in sportPills"
                    :key="pill.value"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] font-medium transition-colors"
                    :class="
                        isSportActive(pill.value)
                            ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                            : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-zinc-900/50 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                    "
                    @click="selectSport(pill.value)"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="
                            pill.accentHex
                                ? undefined
                                : 'bg-zinc-400 dark:bg-zinc-500'
                        "
                        :style="
                            pill.accentHex
                                ? { backgroundColor: pill.accentHex }
                                : undefined
                        "
                    />
                    <span>{{ pill.label }}</span>
                </button>
            </div>

            <button
                v-if="hasActiveFilter"
                type="button"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200/90 px-2.5 py-1.5 font-mono text-[11px] text-zinc-600 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                @click="resetFilters"
            >
                <RotateCcw class="size-3" />
                <span>Reset</span>
            </button>
        </section>

        <!-- Global Empty State -->
        <section
            v-if="!has_data"
            class="rounded-xl border border-dashed border-zinc-300/90 bg-white p-10 text-center dark:border-zinc-800 dark:bg-[#141417]"
        >
            <div
                class="mx-auto flex size-11 items-center justify-center rounded-xl border border-zinc-200/90 bg-zinc-50 text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400"
            >
                <Sparkles class="size-5" />
            </div>
            <h2
                class="mt-4 text-base font-semibold text-zinc-900 dark:text-zinc-100"
            >
                No activities found in this window
            </h2>
            <p
                class="mx-auto mt-1.5 max-w-md text-sm text-zinc-500 dark:text-zinc-400"
            >
                Sync activities with derived aerobic metrics, sleep, HRV, or
                weather data — or widen your sport and date range filters — to
                surface personal performance correlations.
            </p>
            <button
                v-if="hasActiveFilter"
                type="button"
                class="mt-5 inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 font-mono text-xs font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                @click="resetFilters"
            >
                <RotateCcw class="size-3.5" />
                <span>Reset filters</span>
            </button>
        </section>

        <!-- Main Insights Content -->
        <template v-else>
            <!-- Headline Findings Strip -->
            <section
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4"
            >
                <!-- Best Sleep Band -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-indigo-500" />
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Best Sleep Band
                            </p>
                        </div>
                        <Moon
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <template v-if="summary.best_sleep_bucket">
                        <p
                            class="mt-2 font-mono text-xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                        >
                            {{ summary.best_sleep_bucket.label }}
                        </p>
                        <p
                            class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{
                                summary.best_sleep_bucket.avg_ef !== null
                                    ? `${formatNumber(summary.best_sleep_bucket.avg_ef, 2)} EF`
                                    : '—'
                            }}
                            · {{ summary.best_sleep_bucket.count }} days
                        </p>
                    </template>
                    <template v-else>
                        <p
                            class="mt-2 font-mono text-base font-medium text-zinc-400 dark:text-zinc-500"
                        >
                            Collecting data
                        </p>
                        <p
                            class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            Need ≥5 sleep-matched days and ≥3 per band ({{
                                summary.with_sleep
                            }}
                            matched)
                        </p>
                    </template>
                </div>

                <!-- Optimal Temperature Band -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span
                                class="size-1.5 rounded-full bg-emerald-500"
                            />
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Optimal Temp Band
                            </p>
                        </div>
                        <Thermometer
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <template v-if="summary.optimal_temp_bucket">
                        <p
                            class="mt-2 font-mono text-xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                        >
                            {{
                                formatTempBand(
                                    summary.optimal_temp_bucket.min_c,
                                    summary.optimal_temp_bucket.max_c,
                                    summary.optimal_temp_bucket.label,
                                )
                            }}
                        </p>
                        <p
                            class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{
                                summary.optimal_temp_bucket.avg_ef !== null
                                    ? `${formatNumber(summary.optimal_temp_bucket.avg_ef, 2)} EF`
                                    : '—'
                            }}
                            · {{ summary.optimal_temp_bucket.count }} sessions
                        </p>
                    </template>
                    <template v-else>
                        <p
                            class="mt-2 font-mono text-base font-medium text-zinc-400 dark:text-zinc-500"
                        >
                            Collecting data
                        </p>
                        <p
                            class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            Need ≥5 weather-matched sessions and ≥3 per band ({{
                                summary.with_weather
                            }}
                            matched)
                        </p>
                    </template>
                </div>

                <!-- Peak Time of Day -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-amber-500" />
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Peak Time of Day
                            </p>
                        </div>
                        <Clock
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <template v-if="summary.peak_time_of_day">
                        <p
                            class="mt-2 font-mono text-xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                        >
                            {{ summary.peak_time_of_day.label }}
                        </p>
                        <p
                            class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{
                                summary.peak_time_of_day.avg_ef !== null
                                    ? `${formatNumber(summary.peak_time_of_day.avg_ef, 2)} EF`
                                    : '—'
                            }}
                            · {{ summary.peak_time_of_day.count }} sessions
                        </p>
                    </template>
                    <template v-else>
                        <p
                            class="mt-2 font-mono text-base font-medium text-zinc-400 dark:text-zinc-500"
                        >
                            Collecting data
                        </p>
                        <p
                            class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            Need ≥5 sessions and ≥3 with EF per time slot ({{
                                summary.with_derived_metrics
                            }}
                            computed)
                        </p>
                    </template>
                </div>

                <!-- Sample Coverage -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-sky-500" />
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Sample Coverage
                            </p>
                        </div>
                        <Activity
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <p
                        class="mt-2 font-mono text-xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                    >
                        {{ formatNumber(summary.total_activities, 0) }}
                        <span
                            class="text-xs font-normal text-zinc-500 dark:text-zinc-400"
                        >
                            sessions
                        </span>
                    </p>
                    <div
                        class="mt-1.5 flex flex-wrap gap-1.5 font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        <span
                            class="inline-flex items-center gap-1 rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800/80"
                        >
                            <span>
                                EF
                                {{
                                    coveragePct(
                                        summary.with_derived_metrics,
                                        summary.total_activities,
                                    )
                                }}%
                            </span>
                            <InfoPopover topic="efficiency_factor" />
                        </span>
                        <span
                            class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800/80"
                        >
                            Days {{ summary.training_days }}
                        </span>
                        <span
                            class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800/80"
                        >
                            Sleep {{ summary.with_sleep }}
                        </span>
                        <span
                            class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800/80"
                        >
                            HRV {{ summary.with_hrv }}
                        </span>
                        <span
                            class="rounded bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-800/80"
                        >
                            Weather {{ summary.with_weather }}
                        </span>
                    </div>
                    <p
                        v-if="summary.ef_excluded_sessions > 0"
                        class="mt-2 text-[11px] leading-snug text-zinc-500 dark:text-zinc-400"
                    >
                        EF uses {{ efBasisLabel }} sessions only;
                        {{ summary.ef_excluded_sessions }}
                        {{
                            summary.ef_excluded_sessions === 1
                                ? 'session uses'
                                : 'sessions use'
                        }}
                        a different output basis and can’t be compared.
                    </p>
                </div>
            </section>

            <!-- Panel 1: Sleep vs. Efficiency & Drift -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-col gap-3 border-b border-zinc-100 pb-4 xl:flex-row xl:items-center xl:justify-between dark:border-zinc-800/80"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Moon class="size-4 text-indigo-500" />
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                            >
                                Sleep vs. Efficiency &amp; Drift
                            </h2>
                            <InfoPopover topic="insights_sleep_performance" />
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Morning sleep duration and quality paired with
                            same-day aerobic efficiency, using each day’s
                            longest session. Click any scatter dot to open the
                            session.
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        sleep_vs_performance.correlations
                                            .score_vs_ef,
                                        true,
                                    )
                                "
                            >
                                <span>
                                    Score vs EF ·
                                    {{
                                        formatCorrelationLabel(
                                            sleep_vs_performance.correlations
                                                .score_vs_ef,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Sleep Score vs Efficiency Factor correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Sleep Score vs Efficiency Factor',
                                                sleep_vs_performance
                                                    .correlations.score_vs_ef,
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        sleep_vs_performance.correlations
                                            .hours_vs_decoupling,
                                        false,
                                    )
                                "
                            >
                                <span>
                                    Hours vs Drift ·
                                    {{
                                        formatCorrelationLabel(
                                            sleep_vs_performance.correlations
                                                .hours_vs_decoupling,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Sleep Duration vs Cardiac Drift correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Sleep Duration vs Cardiac Drift',
                                                sleep_vs_performance
                                                    .correlations
                                                    .hours_vs_decoupling,
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                        >
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    sleepView === 'scatter'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="sleepView = 'scatter'"
                            >
                                Scatter
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    sleepView === 'duration'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="sleepView = 'duration'"
                            >
                                Duration Bands
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    sleepView === 'score'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="sleepView = 'score'"
                            >
                                Score Bands
                            </button>
                        </div>

                        <div
                            v-if="sleepView === 'scatter'"
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                        >
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] transition-colors"
                                :class="
                                    sleepScatterX === 'sleep_score'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="sleepScatterX = 'sleep_score'"
                            >
                                Score
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] transition-colors"
                                :class="
                                    sleepScatterX === 'sleep_hours'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="sleepScatterX = 'sleep_hours'"
                            >
                                Hours
                            </button>
                        </div>

                        <div
                            v-if="sleepView === 'scatter'"
                            class="inline-flex items-center gap-1"
                        >
                            <div
                                class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            >
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] transition-colors"
                                    :class="
                                        sleepScatterY === 'efficiency_factor'
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="sleepScatterY = 'efficiency_factor'"
                                >
                                    EF
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] transition-colors"
                                    :class="
                                        sleepScatterY === 'decoupling_pct'
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="sleepScatterY = 'decoupling_pct'"
                                >
                                    Drift %
                                </button>
                            </div>
                            <InfoPopover
                                :topic="
                                    sleepScatterY === 'efficiency_factor'
                                        ? 'efficiency_factor'
                                        : 'decoupling'
                                "
                            />
                        </div>
                    </div>
                </div>

                <!-- Takeaway Banner -->
                <div
                    v-if="sleep_vs_performance.takeaway"
                    class="mt-4 flex items-start gap-2.5 rounded-lg border border-indigo-500/20 bg-indigo-500/6 px-3.5 py-2.5 text-xs text-zinc-700 dark:text-zinc-300"
                >
                    <Sparkles
                        class="mt-0.5 size-3.5 shrink-0 text-indigo-500"
                    />
                    <span>{{ sleep_vs_performance.takeaway }}</span>
                </div>

                <div class="mt-4">
                    <div
                        v-if="sleep_vs_performance.n === 0"
                        class="rounded-lg border border-dashed border-zinc-200 px-6 py-10 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                    >
                        No matched sleep records for activities in this window.
                    </div>
                    <div
                        v-else-if="
                            sleepView === 'scatter' &&
                            sleepScatterPoints.length === 0
                        "
                        class="rounded-lg border border-dashed border-zinc-200 px-6 py-10 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                    >
                        No scatter pairs available for this axis combination yet
                        — try switching to Duration Bands or Score Bands.
                    </div>
                    <BaseChart
                        v-else
                        :option="sleepChartOption"
                        :height="280"
                        @point-click="handleSleepChartClick"
                    />
                </div>
            </section>

            <!-- Panel 2: HRV & Recovery vs. Output -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-col gap-3 border-b border-zinc-100 pb-4 xl:flex-row xl:items-center xl:justify-between dark:border-zinc-800/80"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <HeartPulse class="size-4 text-violet-500" />
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                            >
                                HRV &amp; Recovery vs. Output
                            </h2>
                            <InfoPopover topic="insights_hrv_performance" />
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Compare workout output across overnight HRV baseline
                            zones, Garmin HRV status bands, and resting heart
                            rate, using each day’s longest session.
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        hrv_vs_performance.correlations
                                            .hrv_vs_ef,
                                        true,
                                    )
                                "
                            >
                                <span>
                                    HRV vs EF ·
                                    {{
                                        formatCorrelationLabel(
                                            hrv_vs_performance.correlations
                                                .hrv_vs_ef,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Overnight HRV vs Efficiency Factor correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Overnight HRV vs Efficiency Factor',
                                                hrv_vs_performance.correlations
                                                    .hrv_vs_ef,
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        hrv_vs_performance.correlations
                                            .hrv_vs_decoupling,
                                        false,
                                    )
                                "
                            >
                                <span>
                                    HRV vs Drift ·
                                    {{
                                        formatCorrelationLabel(
                                            hrv_vs_performance.correlations
                                                .hrv_vs_decoupling,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Overnight HRV vs Cardiac Drift correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Overnight HRV vs Cardiac Drift',
                                                hrv_vs_performance.correlations
                                                    .hrv_vs_decoupling,
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        hrv_vs_performance.correlations
                                            .rhr_vs_ef,
                                        false,
                                    )
                                "
                            >
                                <span>
                                    RHR vs EF ·
                                    {{
                                        formatCorrelationLabel(
                                            hrv_vs_performance.correlations
                                                .rhr_vs_ef,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Resting HR vs Efficiency Factor correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Resting HR vs Efficiency Factor',
                                                hrv_vs_performance.correlations
                                                    .rhr_vs_ef,
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                        >
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    hrvView === 'baseline'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="hrvView = 'baseline'"
                            >
                                28d Baseline
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    hrvView === 'status'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="hrvView = 'status'"
                            >
                                Status Bands
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    hrvView === 'scatter'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="hrvView = 'scatter'"
                            >
                                HRV Scatter
                            </button>
                        </div>

                        <div
                            v-if="hrvView === 'scatter'"
                            class="inline-flex items-center gap-1"
                        >
                            <div
                                class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            >
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] transition-colors"
                                    :class="
                                        hrvScatterY === 'efficiency_factor'
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="hrvScatterY = 'efficiency_factor'"
                                >
                                    EF
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] transition-colors"
                                    :class="
                                        hrvScatterY === 'decoupling_pct'
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="hrvScatterY = 'decoupling_pct'"
                                >
                                    Drift %
                                </button>
                            </div>
                            <InfoPopover
                                :topic="
                                    hrvScatterY === 'efficiency_factor'
                                        ? 'efficiency_factor'
                                        : 'decoupling'
                                "
                            />
                        </div>
                    </div>
                </div>

                <div
                    v-if="hrv_vs_performance.takeaway"
                    class="mt-4 flex items-start gap-2.5 rounded-lg border border-violet-500/20 bg-violet-500/6 px-3.5 py-2.5 text-xs text-zinc-700 dark:text-zinc-300"
                >
                    <Sparkles
                        class="mt-0.5 size-3.5 shrink-0 text-violet-500"
                    />
                    <span>{{ hrv_vs_performance.takeaway }}</span>
                </div>

                <div class="mt-4">
                    <div
                        v-if="hrv_vs_performance.n === 0"
                        class="rounded-lg border border-dashed border-zinc-200 px-6 py-10 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                    >
                        No matched HRV records for activities in this window.
                    </div>
                    <div
                        v-else-if="
                            hrvView === 'scatter' &&
                            hrvScatterPoints.length === 0
                        "
                        class="rounded-lg border border-dashed border-zinc-200 px-6 py-10 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                    >
                        No HRV scatter pairs with derived metrics yet — switch
                        to 28d Baseline or Status Bands.
                    </div>
                    <BaseChart
                        v-else
                        :option="hrvChartOption"
                        :height="280"
                        @point-click="handleHrvChartClick"
                    />
                </div>
            </section>

            <!-- Panel 3: Temperature & Wind Impact -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-col gap-3 border-b border-zinc-100 pb-4 xl:flex-row xl:items-center xl:justify-between dark:border-zinc-800/80"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Thermometer class="size-4 text-emerald-500" />
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                            >
                                Temperature &amp; Wind Impact
                            </h2>
                            <InfoPopover topic="insights_weather_impact" />
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Heat penalty and wind exposure across outdoor
                            activities ({{ tempUnit }}). Click any scatter dot
                            to inspect the session.
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        weather_impact.correlations.temp_vs_ef,
                                        true,
                                    )
                                "
                            >
                                <span>
                                    Temp vs EF ·
                                    {{
                                        formatCorrelationLabel(
                                            weather_impact.correlations
                                                .temp_vs_ef,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Temperature vs Efficiency Factor correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Temperature vs Efficiency Factor',
                                                weather_impact.correlations
                                                    .temp_vs_ef,
                                                'sessions',
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        weather_impact.correlations
                                            .temp_vs_decoupling,
                                        false,
                                    )
                                "
                            >
                                <span>
                                    Temp vs Drift ·
                                    {{
                                        formatCorrelationLabel(
                                            weather_impact.correlations
                                                .temp_vs_decoupling,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Temperature vs Cardiac Drift correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Temperature vs Cardiac Drift',
                                                weather_impact.correlations
                                                    .temp_vs_decoupling,
                                                'sessions',
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                        >
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    weatherView === 'scatter'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="weatherView = 'scatter'"
                            >
                                Temp Scatter
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    weatherView === 'buckets'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="weatherView = 'buckets'"
                            >
                                Temp Bands
                            </button>
                        </div>

                        <div
                            v-if="weatherView === 'scatter'"
                            class="inline-flex items-center gap-1"
                        >
                            <div
                                class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            >
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] transition-colors"
                                    :class="
                                        weatherScatterY === 'efficiency_factor'
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="
                                        weatherScatterY = 'efficiency_factor'
                                    "
                                >
                                    EF
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] transition-colors"
                                    :class="
                                        weatherScatterY === 'decoupling_pct'
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="weatherScatterY = 'decoupling_pct'"
                                >
                                    Drift %
                                </button>
                            </div>
                            <InfoPopover
                                :topic="
                                    weatherScatterY === 'efficiency_factor'
                                        ? 'efficiency_factor'
                                        : 'decoupling'
                                "
                            />
                        </div>
                    </div>
                </div>

                <div
                    v-if="formattedWeatherTakeaway"
                    class="mt-4 flex items-start gap-2.5 rounded-lg border border-emerald-500/20 bg-emerald-500/6 px-3.5 py-2.5 text-xs text-zinc-700 dark:text-zinc-300"
                >
                    <Sparkles
                        class="mt-0.5 size-3.5 shrink-0 text-emerald-500"
                    />
                    <span>{{ formattedWeatherTakeaway }}</span>
                </div>

                <div class="mt-4">
                    <div
                        v-if="weather_impact.n === 0"
                        class="rounded-lg border border-dashed border-zinc-200 px-6 py-10 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                    >
                        No activity weather records found in this window.
                    </div>
                    <div
                        v-else-if="
                            weatherView === 'scatter' &&
                            weatherScatterPoints.length === 0
                        "
                        class="rounded-lg border border-dashed border-zinc-200 px-6 py-10 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                    >
                        Weather data exists, but derived EF/decoupling is not
                        yet available — switch to Temp Bands to inspect speed
                        and HR.
                    </div>
                    <BaseChart
                        v-else
                        :option="weatherChartOption"
                        :height="280"
                        @point-click="handleWeatherChartClick"
                    />
                </div>

                <!-- Wind Exposure Comparison Pills -->
                <div
                    v-if="weather_impact.wind_n > 0"
                    class="mt-5 border-t border-zinc-100 pt-4 dark:border-zinc-800/80"
                >
                    <div class="mb-3 flex items-center gap-2">
                        <Wind class="size-3.5 text-zinc-400" />
                        <h3
                            class="font-mono text-[10px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Wind Exposure Comparison ({{
                                weather_impact.wind_n
                            }}
                            sessions)
                        </h3>
                        <InfoPopover topic="wind_impact" />
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div
                            v-for="wind in weather_impact.wind_buckets"
                            :key="wind.key"
                            class="rounded-lg border p-3"
                            :class="windBadgeTone(wind.key)"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold">
                                    {{ wind.label }}
                                </span>
                                <span
                                    class="font-mono text-[10px] tabular-nums"
                                >
                                    n={{ wind.count }}
                                </span>
                            </div>
                            <div
                                class="mt-2 grid grid-cols-3 gap-2 font-mono text-[11px] tabular-nums"
                            >
                                <div>
                                    <span class="block text-[9px] opacity-70">
                                        Avg EF
                                    </span>
                                    <span class="font-semibold">
                                        {{
                                            wind.avg_ef !== null
                                                ? formatNumber(wind.avg_ef, 2)
                                                : '—'
                                        }}
                                    </span>
                                </div>
                                <div>
                                    <span class="block text-[9px] opacity-70">
                                        {{ usePaceFormat ? 'Pace' : 'Speed' }}
                                    </span>
                                    <span class="font-semibold">
                                        {{ formatSpeedOrPace(wind.avg_speed) }}
                                    </span>
                                </div>
                                <div>
                                    <span class="block text-[9px] opacity-70">
                                        Avg HR
                                    </span>
                                    <span class="font-semibold">
                                        {{
                                            wind.avg_hr !== null
                                                ? `${formatNumber(wind.avg_hr, 0)}`
                                                : '—'
                                        }}
                                    </span>
                                </div>
                            </div>
                            <p
                                v-if="wind.avg_effective_wind_kmh !== null"
                                class="mt-1.5 font-mono text-[10px] tabular-nums opacity-75"
                            >
                                Net wind:
                                {{ wind.avg_effective_wind_kmh >= 0 ? '+' : ''
                                }}{{
                                    formatSpeedKmh(
                                        wind.avg_effective_wind_kmh,
                                        1,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Panel 4: Evening Workouts -> Overnight Recovery -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-col gap-3 border-b border-zinc-100 pb-4 xl:flex-row xl:items-center xl:justify-between dark:border-zinc-800/80"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Clock class="size-4 text-rose-500" />
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                            >
                                Workout Timing &amp; Intensity → Overnight
                                Recovery
                            </h2>
                            <InfoPopover topic="insights_workout_recovery" />
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Matches each training day against the following
                            night’s Sleep Score, deep sleep, and HRV delta vs
                            the prior 7 nights. Multi-session days count once,
                            using the day’s total load and its hardest session’s
                            start time.
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        workout_to_recovery.correlations
                                            .start_hour_vs_hrv_delta,
                                        true,
                                    )
                                "
                            >
                                <span>
                                    Start Hour vs HRV Δ ·
                                    {{
                                        formatCorrelationLabel(
                                            workout_to_recovery.correlations
                                                .start_hour_vs_hrv_delta,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Start Hour vs Next-Night HRV Delta correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Start Hour vs Next-Night HRV Delta',
                                                workout_to_recovery.correlations
                                                    .start_hour_vs_hrv_delta,
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    correlationTone(
                                        workout_to_recovery.correlations
                                            .start_hour_vs_sleep_score,
                                        true,
                                    )
                                "
                            >
                                <span>
                                    Start Hour vs Sleep ·
                                    {{
                                        formatCorrelationLabel(
                                            workout_to_recovery.correlations
                                                .start_hour_vs_sleep_score,
                                        )
                                    }}
                                </span>
                                <InfoPopover
                                    topic="correlation"
                                    label="More info about Start Hour vs Next-Night Sleep Score correlation"
                                >
                                    <p
                                        class="rounded-md border border-zinc-200/70 bg-zinc-50 px-2 py-1.5 text-[11px] text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-200"
                                    >
                                        {{
                                            correlationTooltip(
                                                'Start Hour vs Next-Night Sleep Score',
                                                workout_to_recovery.correlations
                                                    .start_hour_vs_sleep_score,
                                            )
                                        }}
                                    </p>
                                </InfoPopover>
                            </span>
                        </div>
                    </div>

                    <div
                        class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                    >
                        <button
                            type="button"
                            class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                            :class="
                                recoveryView === 'time'
                                    ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                    : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="recoveryView = 'time'"
                        >
                            By Start Time
                        </button>
                        <button
                            type="button"
                            class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                            :class="
                                recoveryView === 'intensity'
                                    ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                    : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="recoveryView = 'intensity'"
                        >
                            By Intensity
                        </button>
                    </div>
                </div>

                <div
                    v-if="workout_to_recovery.takeaway"
                    class="mt-4 flex items-start gap-2.5 rounded-lg border border-rose-500/20 bg-rose-500/6 px-3.5 py-2.5 text-xs text-zinc-700 dark:text-zinc-300"
                >
                    <Sparkles class="mt-0.5 size-3.5 shrink-0 text-rose-500" />
                    <span>{{ workout_to_recovery.takeaway }}</span>
                </div>

                <div class="mt-4">
                    <div
                        v-if="workout_to_recovery.n === 0"
                        class="rounded-lg border border-dashed border-zinc-200 px-6 py-10 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                    >
                        No next-night sleep or HRV records matched to workouts
                        in this window.
                    </div>
                    <template v-else>
                        <BaseChart
                            :option="recoveryChartOption"
                            :height="270"
                        />

                        <!-- Time × Intensity Recovery Matrix -->
                        <div
                            class="mt-5 overflow-x-auto border-t border-zinc-100 pt-4 dark:border-zinc-800/80"
                        >
                            <p
                                class="mb-2.5 font-mono text-[10px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Timing × Intensity Next-Night Impact (HRV Δ /
                                Sleep Score)
                            </p>
                            <table class="w-full text-left font-mono text-xs">
                                <thead>
                                    <tr
                                        class="border-b border-zinc-200/80 text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800 dark:text-zinc-400"
                                    >
                                        <th class="py-2 pr-3 font-medium">
                                            Start Window
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Easy
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Moderate
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Hard
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-zinc-100 dark:divide-zinc-800/70"
                                >
                                    <tr
                                        v-for="row in recoveryMatrixRows"
                                        :key="row.timeKey"
                                    >
                                        <td
                                            class="py-2.5 pr-3 font-medium text-zinc-700 dark:text-zinc-300"
                                        >
                                            {{ row.timeLabel }}
                                        </td>
                                        <td
                                            v-for="(cell, idx) in row.cells"
                                            :key="idx"
                                            class="px-3 py-2.5 tabular-nums"
                                        >
                                            <template
                                                v-if="cell && cell.count > 0"
                                            >
                                                <span
                                                    :class="
                                                        cell.avg_hrv_delta !==
                                                        null
                                                            ? cell.avg_hrv_delta >=
                                                              0
                                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                                : 'text-rose-600 dark:text-rose-400'
                                                            : 'text-zinc-500'
                                                    "
                                                >
                                                    {{
                                                        cell.avg_hrv_delta !==
                                                        null
                                                            ? `${formatSigned(cell.avg_hrv_delta, 1)} ms`
                                                            : '—'
                                                    }}
                                                </span>
                                                <span
                                                    class="mx-1 text-zinc-300 dark:text-zinc-700"
                                                >
                                                    /
                                                </span>
                                                <span
                                                    class="text-zinc-700 dark:text-zinc-300"
                                                >
                                                    {{
                                                        cell.avg_sleep_score !==
                                                        null
                                                            ? `${formatNumber(cell.avg_sleep_score, 0)}`
                                                            : '—'
                                                    }}
                                                </span>
                                                <span
                                                    class="ml-1.5 text-[10px] text-zinc-400"
                                                >
                                                    (n={{ cell.count }})
                                                </span>
                                            </template>
                                            <span
                                                v-else
                                                class="text-zinc-400 dark:text-zinc-600"
                                            >
                                                —
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </section>

            <!-- Panel 5: Day-of-Week & Time-of-Day Patterns -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-col gap-2 border-b border-zinc-100 pb-4 dark:border-zinc-800/80"
                >
                    <div class="flex items-center gap-2">
                        <Calendar class="size-4 text-sky-500" />
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                        >
                            Day-of-Week &amp; Time-of-Day Patterns
                        </h2>
                        <span
                            class="rounded-md border border-zinc-200/90 bg-zinc-50 px-2 py-0.5 font-mono text-[10px] text-zinc-600 tabular-nums dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400"
                        >
                            n = {{ schedule_patterns.n }}
                        </span>
                    </div>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Weekly volume distribution and circadian efficiency
                        trends across all {{ schedule_patterns.n }} sessions in
                        this window.
                    </p>
                </div>

                <div
                    v-if="schedule_patterns.takeaway"
                    class="mt-4 flex items-start gap-2.5 rounded-lg border border-sky-500/20 bg-sky-500/6 px-3.5 py-2.5 text-xs text-zinc-700 dark:text-zinc-300"
                >
                    <Sparkles class="mt-0.5 size-3.5 shrink-0 text-sky-500" />
                    <span>{{ schedule_patterns.takeaway }}</span>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <div>
                        <h3
                            class="mb-2 font-mono text-[10px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            By Day of Week (Mon–Sun)
                        </h3>
                        <BaseChart
                            :option="dayOfWeekChartOption"
                            :height="260"
                        />
                    </div>
                    <div>
                        <h3
                            class="mb-2 font-mono text-[10px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            By Time of Day
                        </h3>
                        <BaseChart
                            :option="timeOfDayChartOption"
                            :height="260"
                        />
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
