<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import type { EChartsOption, SeriesOption } from 'echarts';
import { computed } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import DateRangeControl, {
    type DateRangePreset,
    type DateWindowMeta,
} from '@/components/DateRangeControl.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { useUnits } from '@/lib/units';
import { dashboard } from '@/routes';
import { show as healthShow } from '@/routes/health';

interface HealthMetricProps {
    metric: string;
    title: string;
    subtitle: string;
    accent: string;
    unit: string;
    chartType: string;
    range: string;
    startDate: string | null;
    endDate: string;
    dateWindow: DateWindowMeta;
    summary: {
        latest: string | null;
        average: string | null;
        min: string | null;
        max: string | null;
        extraLabel: string | null;
        extraValue: string | null;
    };
    baseline: { low: number | null; high: number | null } | null;
    priorPoint?: Record<string, unknown> | null;
    points: Array<Record<string, number | string | null>>;
    columns: Array<{ key: string; label: string }>;
    rows: Array<Record<string, number | string | null>>;
}

const props = defineProps<HealthMetricProps>();
const theme = useChartTheme();
const units = useUnits();

const RANGES: DateRangePreset[] = [
    { value: '7d', label: '7d' },
    { value: '28d', label: '28d' },
    { value: '90d', label: '90d' },
    { value: '1y', label: '1y' },
    { value: 'all', label: 'All' },
];

const METRIC_SWITCHER: Array<{
    key: string;
    label: string;
    dotClass: string;
}> = [
    { key: 'body-battery', label: 'Body Battery', dotClass: 'bg-sky-500' },
    { key: 'sleep', label: 'Sleep', dotClass: 'bg-indigo-500' },
    { key: 'hrv', label: 'HRV', dotClass: 'bg-emerald-500' },
    { key: 'steps', label: 'Steps', dotClass: 'bg-sky-500' },
    { key: 'heart-rate', label: 'Heart Rate', dotClass: 'bg-rose-500' },
    { key: 'stress', label: 'Stress', dotClass: 'bg-amber-500' },
    {
        key: 'intensity-minutes',
        label: 'Intensity Min',
        dotClass: 'bg-amber-500',
    },
    { key: 'weight', label: 'Weight', dotClass: 'bg-emerald-500' },
    { key: 'vo2-max', label: 'VO2 Max', dotClass: 'bg-indigo-500' },
    { key: 'fitness-age', label: 'Fitness Age', dotClass: 'bg-violet-500' },
    { key: 'calories', label: 'Calories', dotClass: 'bg-orange-500' },
    { key: 'floors', label: 'Floors', dotClass: 'bg-teal-500' },
    {
        key: 'respiration-spo2',
        label: 'Resp & SpO2',
        dotClass: 'bg-teal-500',
    },
    { key: 'hydration', label: 'Hydration', dotClass: 'bg-sky-500' },
];

interface AccentPalette {
    hex: string;
    barClass: string;
    textClass: string;
}

const ACCENT_MAP: Record<string, AccentPalette> = {
    sky: {
        hex: '#0ea5e9',
        barClass: 'bg-sky-500',
        textClass: 'text-sky-600 dark:text-sky-400',
    },
    indigo: {
        hex: '#6366f1',
        barClass: 'bg-indigo-500',
        textClass: 'text-indigo-600 dark:text-indigo-400',
    },
    violet: {
        hex: '#8b5cf6',
        barClass: 'bg-violet-500',
        textClass: 'text-violet-600 dark:text-violet-400',
    },
    emerald: {
        hex: '#10b981',
        barClass: 'bg-emerald-500',
        textClass: 'text-emerald-600 dark:text-emerald-400',
    },
    rose: {
        hex: '#f43f5e',
        barClass: 'bg-rose-500',
        textClass: 'text-rose-600 dark:text-rose-400',
    },
    amber: {
        hex: '#f59e0b',
        barClass: 'bg-amber-500',
        textClass: 'text-amber-600 dark:text-amber-400',
    },
    orange: {
        hex: '#f97316',
        barClass: 'bg-orange-500',
        textClass: 'text-orange-600 dark:text-orange-400',
    },
    teal: {
        hex: '#14b8a6',
        barClass: 'bg-teal-500',
        textClass: 'text-teal-600 dark:text-teal-400',
    },
};

const resolvedAccent = computed<AccentPalette>(() => {
    const raw = (props.accent ?? '').toLowerCase();

    for (const [key, palette] of Object.entries(ACCENT_MAP)) {
        if (raw === key || raw.includes(key)) {
            return palette;
        }
    }

    if (raw.startsWith('#')) {
        return {
            hex: props.accent,
            barClass: 'bg-sky-500',
            textClass: 'text-zinc-600 dark:text-zinc-400',
        };
    }

    return ACCENT_MAP.sky;
});

const activeRangeLabel = computed(() => {
    if (props.range === 'custom' || !props.dateWindow.isLatest) {
        return props.dateWindow.label;
    }

    const match = RANGES.find(
        (item) => item.value.toLowerCase() === props.range.toLowerCase(),
    );

    return match ? match.label : props.range;
});

const currentRangeQuery = computed<Record<string, string>>(() => {
    const query: Record<string, string> = { range: props.range };

    if (props.range === 'custom') {
        if (props.dateWindow.from) {
            query.from = props.dateWindow.from;
        }

        if (props.dateWindow.to) {
            query.to = props.dateWindow.to;
        }
    } else if (!props.dateWindow.isLatest && props.dateWindow.end) {
        query.end = props.dateWindow.end;
    }

    return query;
});

function navigateWindow(query: Record<string, string>): void {
    router.get(
        healthShow.url(props.metric, { query }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function selectRange(nextRange: string): void {
    navigateWindow({ range: nextRange });
}

function stepRange(end: string | null): void {
    const query: Record<string, string> = { range: props.range };

    if (end) {
        query.end = end;
    }

    navigateWindow(query);
}

function applyCustomRange(payload: {
    from: string | null;
    to: string | null;
}): void {
    const query: Record<string, string> = { range: 'custom' };

    if (payload.from) {
        query.from = payload.from;
    }

    if (payload.to) {
        query.to = payload.to;
    }

    navigateWindow(query);
}

function pickNumber(
    point: Record<string, number | string | null>,
    keys: string[],
): number | null {
    for (const key of keys) {
        const raw = point[key];

        if (typeof raw === 'number' && Number.isFinite(raw)) {
            return raw;
        }

        if (typeof raw === 'string' && raw.trim() !== '') {
            const parsed = Number(raw);

            if (Number.isFinite(parsed)) {
                return parsed;
            }
        }
    }

    return null;
}

const DAY_MS = 24 * 60 * 60 * 1000;

const utcDayMonthFormatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

const utcMonthYearFormatter = new Intl.DateTimeFormat('en-GB', {
    month: 'short',
    year: '2-digit',
    timeZone: 'UTC',
});

const utcFullDateFormatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

function parseCalendarDateUtc(
    value: number | string | null | undefined,
): number | null {
    if (typeof value !== 'string') {
        return null;
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());

    if (!match) {
        return null;
    }

    return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
}

function pickPointTimestamp(
    point: Record<string, number | string | null>,
): number | null {
    return parseCalendarDateUtc(point.date ?? point.calendar_date ?? null);
}

function formatTimeAxisTick(value: number, spanDays: number): string {
    const date = new Date(value);

    if (spanDays <= 100) {
        return utcDayMonthFormatter.format(date);
    }

    if (spanDays <= 400) {
        return utcMonthYearFormatter.format(date);
    }

    if (date.getUTCMonth() === 0 && date.getUTCDate() === 1) {
        return String(date.getUTCFullYear());
    }

    return utcMonthYearFormatter.format(date);
}

function formatUtcDateWindow(startMs: number, endMs: number): string {
    const startDate = new Date(startMs);
    const endDate = new Date(endMs);

    if (startDate.getUTCFullYear() === endDate.getUTCFullYear()) {
        return `${utcDayMonthFormatter.format(startDate)} – ${utcFullDateFormatter.format(endDate)}`;
    }

    return `${utcFullDateFormatter.format(startDate)} – ${utcFullDateFormatter.format(endDate)}`;
}

const resolvedTimeWindow = computed<{ startMs: number; endMs: number } | null>(
    () => {
        const endFromProp = parseCalendarDateUtc(props.endDate);
        const startFromProp = parseCalendarDateUtc(props.startDate);

        if (startFromProp !== null && endFromProp !== null) {
            return {
                startMs: Math.min(startFromProp, endFromProp),
                endMs: Math.max(startFromProp, endFromProp),
            };
        }

        const timestamps = props.points
            .map((point) => pickPointTimestamp(point))
            .filter((ts): ts is number => ts !== null);

        if (timestamps.length === 0) {
            return null;
        }

        const firstPointMs = Math.min(...timestamps);
        const lastPointMs = Math.max(...timestamps);
        const endMs =
            endFromProp !== null
                ? Math.max(endFromProp, lastPointMs)
                : lastPointMs;

        return {
            startMs: Math.min(firstPointMs, endMs),
            endMs,
        };
    },
);

const dateWindowLabel = computed<string | null>(() => {
    const window = resolvedTimeWindow.value;

    if (!window) {
        return null;
    }

    return formatUtcDateWindow(window.startMs, window.endMs);
});

function formatCellValue(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value);
}

const isWeeklyIntensity = computed<boolean>(
    () =>
        props.chartType === 'intensity-stacked' &&
        props.points.some((point) => point.granularity === 'weekly'),
);

interface IntensityWeeklyBucket {
    weekStart: string;
    weekEnd: string | null;
    label: string;
    shortLabel: string;
    total: number;
    moderate: number;
    vigorous: number;
    goal: number | null;
    pct: number | null;
    hit: boolean;
}

const intensityWeeklyBuckets = computed<IntensityWeeklyBucket[]>(() => {
    if (
        props.chartType !== 'intensity-stacked' ||
        isWeeklyIntensity.value ||
        props.points.length === 0
    ) {
        return [];
    }

    const bucketMap = new Map<string, IntensityWeeklyBucket>();

    for (const point of props.points) {
        const weekKey =
            typeof point.weekStart === 'string' && point.weekStart !== ''
                ? point.weekStart
                : null;

        if (!weekKey) {
            continue;
        }

        const weekEnd =
            typeof point.weekEnd === 'string' && point.weekEnd !== ''
                ? point.weekEnd
                : typeof point.date === 'string'
                  ? point.date
                  : null;
        const startMs = parseCalendarDateUtc(weekKey);
        const endMs = parseCalendarDateUtc(weekEnd);

        const shortLabel =
            startMs !== null
                ? utcDayMonthFormatter.format(new Date(startMs))
                : weekKey;
        const label =
            startMs !== null && endMs !== null
                ? `${utcDayMonthFormatter.format(new Date(startMs))} – ${utcDayMonthFormatter.format(new Date(endMs))}`
                : shortLabel;

        const total = pickNumber(point, ['weeklyCumulative', 'total']) ?? 0;
        const moderate = pickNumber(point, ['weekModerate', 'moderate']) ?? 0;
        const vigorous = pickNumber(point, ['weekVigorous', 'vigorous']) ?? 0;
        const existing = bucketMap.get(weekKey);
        const goal =
            pickNumber(point, ['goal', 'intensity_minutes_goal']) ??
            existing?.goal ??
            null;
        const pct =
            goal !== null && goal > 0 ? Math.round((total / goal) * 100) : null;
        const hit = goal !== null && goal > 0 && total >= goal;

        bucketMap.set(weekKey, {
            weekStart: weekKey,
            weekEnd,
            label,
            shortLabel,
            total,
            moderate,
            vigorous,
            goal,
            pct,
            hit,
        });
    }

    return Array.from(bucketMap.values());
});

const intensityWeeklySummary = computed<{
    hitCount: number;
    goalCount: number;
}>(() => {
    let hitCount = 0;
    let goalCount = 0;

    for (const bucket of intensityWeeklyBuckets.value) {
        if (bucket.goal !== null && bucket.goal > 0) {
            goalCount++;

            if (bucket.hit) {
                hitCount++;
            }
        }
    }

    return { hitCount, goalCount };
});

const intensityWeeklyGridClass = computed<string>(() => {
    const count = intensityWeeklyBuckets.value.length;

    if (count <= 1) {
        return 'grid gap-3 grid-cols-1';
    }

    if (count === 2) {
        return 'grid gap-3 sm:grid-cols-2';
    }

    if (count === 3 || count === 6) {
        return 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3';
    }

    if (count === 5) {
        return 'grid gap-3 sm:grid-cols-2 lg:grid-cols-5';
    }

    return 'grid gap-3 sm:grid-cols-2 lg:grid-cols-4';
});

const intensityWeeklyStripChartOption = computed<EChartsOption>(() => {
    const buckets = intensityWeeklyBuckets.value;

    if (buckets.length === 0) {
        return {};
    }

    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };
    const hasGoal = buckets.some((b) => b.goal !== null);

    const series: SeriesOption[] = [
        {
            name: 'Weekly Total',
            type: 'bar',
            barMaxWidth: 26,
            data: buckets.map((bucket) => ({
                value: bucket.total,
                itemStyle: {
                    color: bucket.hit ? '#10b981' : '#f59e0b',
                    borderRadius: [3, 3, 0, 0],
                },
            })),
        },
    ];

    if (hasGoal) {
        series.push({
            name: 'Weekly Goal',
            type: 'line',
            step: 'middle',
            connectNulls: true,
            showSymbol: false,
            lineStyle: {
                color: theme.mutedColor.value,
                width: 1.5,
                type: 'dashed',
            },
            itemStyle: { color: theme.mutedColor.value },
            data: buckets.map((bucket) => bucket.goal),
        });
    }

    return {
        useUTC: true,
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 22,
            bottom: 8,
            containLabel: true,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown): string => {
                const list = (
                    Array.isArray(params) ? params : [params]
                ) as AxisTooltipItem[];
                const first = list[0];
                const idx =
                    typeof first?.dataIndex === 'number' ? first.dataIndex : -1;
                const bucket = idx >= 0 ? buckets[idx] : undefined;

                if (!bucket) {
                    return '';
                }

                const lines: string[] = [`<strong>${bucket.label}</strong>`];
                const pctSuffix =
                    bucket.pct !== null
                        ? ` <span style="opacity:0.75">· ${bucket.pct}% of goal</span>`
                        : '';
                lines.push(
                    `Weekly Total: <strong>${bucket.total.toLocaleString()} min</strong>${pctSuffix}`,
                );
                lines.push(
                    `<span style="opacity:0.75">Mod ${bucket.moderate.toLocaleString()} · Vig ${bucket.vigorous.toLocaleString()} (×2 = ${(bucket.vigorous * 2).toLocaleString()})</span>`,
                );

                if (bucket.goal !== null) {
                    lines.push(
                        `Weekly Goal: <strong>${bucket.goal.toLocaleString()} min</strong>`,
                    );
                }

                return lines.join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: buckets.map((bucket) => bucket.shortLabel),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                ...axisLabelStyle,
                hideOverlap: true,
            },
        },
        yAxis: {
            type: 'value',
            splitNumber: 3,
            axisLabel: axisLabelStyle,
            splitLine: {
                lineStyle: { color: theme.gridLineColor.value },
            },
        },
        series,
    };
});

const summaryCards = computed(() => {
    const cards: Array<{ label: string; value: string }> = [
        { label: 'Latest', value: props.summary.latest ?? '—' },
        { label: 'Average', value: props.summary.average ?? '—' },
        { label: 'Min', value: props.summary.min ?? '—' },
        { label: 'Max', value: props.summary.max ?? '—' },
    ];

    if (props.summary.extraLabel && props.summary.extraValue !== null) {
        cards.push({
            label: props.summary.extraLabel,
            value: props.summary.extraValue || '—',
        });
    }

    return cards;
});

const summaryGridClass = computed(() =>
    summaryCards.value.length >= 5
        ? 'grid gap-4 sm:grid-cols-2 lg:grid-cols-5'
        : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-4',
);

interface AxisTooltipItem {
    seriesName?: string;
    marker?: string;
    value?: unknown;
    axisValue?: unknown;
    dataIndex?: number;
}

function extractTooltipTimestamp(item: AxisTooltipItem): number | null {
    if (Array.isArray(item.value) && typeof item.value[0] === 'number') {
        return item.value[0];
    }

    if (typeof item.axisValue === 'number' && Number.isFinite(item.axisValue)) {
        return item.axisValue;
    }

    return null;
}

function extractTooltipNumericValue(item: AxisTooltipItem): number | null {
    const raw = Array.isArray(item.value) ? item.value[1] : item.value;

    if (typeof raw === 'number' && Number.isFinite(raw)) {
        return raw;
    }

    return null;
}

function formatHoursCompact(hours: number | null): string {
    if (hours === null || !Number.isFinite(hours)) {
        return '';
    }

    return `${hours.toFixed(1)}h`;
}

function formatKcal(value: number | null): string {
    if (value === null || !Number.isFinite(value)) {
        return '—';
    }

    return Math.round(value).toLocaleString();
}

const chartOption = computed<EChartsOption>(() => {
    const pts = props.points;
    const timestamps = pts.map((p) => pickPointTimestamp(p));
    const pointByTimestamp = new Map<
        number,
        Record<string, number | string | null>
    >();

    for (let idx = 0; idx < pts.length; idx++) {
        const ts = timestamps[idx];

        if (ts !== null) {
            pointByTimestamp.set(ts, pts[idx]);
        }
    }

    const pickTravelDayLabel = (
        point: Record<string, number | string | null> | undefined,
    ): string | null => {
        if (!point) {
            return null;
        }

        const label = point.travelDayLabel;

        return typeof label === 'string' && label.trim() !== '' ? label : null;
    };

    const formatAxisTooltipWithTravel = (params: unknown): string => {
        const list = (
            Array.isArray(params) ? params : [params]
        ) as AxisTooltipItem[];
        const first = list[0];

        if (!first) {
            return '';
        }

        const timestamp = extractTooltipTimestamp(first);
        const point =
            timestamp !== null ? pointByTimestamp.get(timestamp) : undefined;
        const travelLabel = pickTravelDayLabel(point);
        const dateHeading =
            timestamp !== null
                ? utcFullDateFormatter.format(new Date(timestamp))
                : '';
        const header = dateHeading
            ? `<strong>${dateHeading}</strong>${travelLabel ? ` <span style="opacity:0.75">· ${travelLabel}</span>` : ''}`
            : '';

        const seenSeries = new Set<string>();
        const rows = list
            .filter((item) => {
                if (!item.seriesName || item.seriesName === '_base') {
                    return false;
                }

                if (seenSeries.has(item.seriesName)) {
                    return false;
                }

                seenSeries.add(item.seriesName);

                return true;
            })
            .map((item) => {
                const val = extractTooltipNumericValue(item);

                if (val === null) {
                    return null;
                }

                return `${item.marker ?? ''}${item.seriesName}: <strong>${val}</strong>`;
            })
            .filter((line): line is string => line !== null);

        if (rows.length === 0) {
            return '';
        }

        return [header, ...rows].filter(Boolean).join('<br/>');
    };
    const accentHex = resolvedAccent.value.hex;
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };
    const leftYAxisNameStyle = {
        ...axisLabelStyle,
        align: 'left' as const,
    };
    const rightYAxisNameStyle = {
        ...axisLabelStyle,
        align: 'right' as const,
    };
    const splitLineStyle = {
        lineStyle: { color: theme.gridLineColor.value },
    };

    const baseGrid = {
        left: 12,
        right: 16,
        top: 54,
        bottom: 12,
        containLabel: true,
    };

    const baseLegend = {
        top: 0,
        left: 0,
        padding: [0, 0, 0, 0],
        icon: 'roundRect',
        itemWidth: 10,
        itemHeight: 6,
        textStyle: {
            color: theme.mutedColor.value,
            fontFamily: monoFont,
            fontSize: 11,
        },
    };

    const timeWindow = resolvedTimeWindow.value;
    const spanDays = timeWindow
        ? Math.max(
              1,
              Math.round((timeWindow.endMs - timeWindow.startMs) / DAY_MS),
          )
        : 28;

    const baseXAxis = {
        type: 'time' as const,
        ...(timeWindow
            ? {
                  min: timeWindow.startMs - DAY_MS / 2,
                  max: timeWindow.endMs + DAY_MS / 2,
              }
            : {}),
        minInterval: DAY_MS,
        splitNumber: spanDays <= 7 ? 7 : 6,
        axisLine: { lineStyle: { color: theme.gridLineColor.value } },
        axisTick: { show: false },
        axisLabel: {
            ...axisLabelStyle,
            hideOverlap: true,
            formatter: (value: number) => formatTimeAxisTick(value, spanDays),
        },
    };

    const baseTooltip = {
        trigger: 'axis' as const,
        axisPointer: {
            type: 'line' as const,
            lineStyle: {
                color: theme.mutedColor.value,
                type: 'dashed' as const,
            },
        },
        formatter: formatAxisTooltipWithTravel,
    };

    const toTimeSeries = (
        values: Array<number | null>,
    ): Array<[number, number | null]> => {
        const seriesData: Array<[number, number | null]> = [];

        for (let idx = 0; idx < values.length; idx++) {
            const ts = timestamps[idx];

            if (ts !== null) {
                seriesData.push([ts, values[idx]]);
            }
        }

        return seriesData;
    };

    const pickPriorAnchor = (
        source: Record<string, unknown> | null | undefined,
        valueKey: string,
        transform?: (value: number) => number,
    ): { ts: number; value: number } | null => {
        if (!source) {
            return null;
        }

        const nested = source[valueKey];

        if (nested && typeof nested === 'object' && !Array.isArray(nested)) {
            const record = nested as Record<string, unknown>;
            const ts = parseCalendarDateUtc(
                typeof record.date === 'string' ? record.date : null,
            );
            const raw = record.value;
            const numeric =
                typeof raw === 'number' && Number.isFinite(raw) ? raw : null;

            if (ts === null || numeric === null) {
                return null;
            }

            return {
                ts,
                value: transform ? transform(numeric) : numeric,
            };
        }

        const ts = parseCalendarDateUtc(
            typeof source.date === 'string' ? source.date : null,
        );
        const raw = source[valueKey];
        const numeric =
            typeof raw === 'number' && Number.isFinite(raw) ? raw : null;

        if (ts === null || numeric === null) {
            return null;
        }

        return {
            ts,
            value: transform ? transform(numeric) : numeric,
        };
    };

    const buildLeadInSeries = (
        seriesName: string,
        prior: { ts: number; value: number } | null,
        seriesData: Array<[number, number | null]>,
        options: {
            color: string;
            width?: number;
            yAxisIndex?: number;
            step?: boolean;
            opacity?: number;
        },
    ): SeriesOption | null => {
        if (!timeWindow || !prior) {
            return null;
        }

        const first = seriesData.find(
            (item): item is [number, number] => item[1] !== null,
        );

        if (!first) {
            return null;
        }

        const [firstTs, firstVal] = first;

        if (firstTs <= timeWindow.startMs || prior.ts >= firstTs) {
            return null;
        }

        const leftEdgeMs = timeWindow.startMs - DAY_MS / 2;
        let edgeVal: number;

        if (options.step) {
            edgeVal = prior.value;
        } else {
            const ratio = Math.max(
                0,
                Math.min(1, (leftEdgeMs - prior.ts) / (firstTs - prior.ts)),
            );
            edgeVal = Number(
                (prior.value + ratio * (firstVal - prior.value)).toFixed(2),
            );
        }

        return {
            name: seriesName,
            type: 'line',
            ...(options.yAxisIndex !== undefined
                ? { yAxisIndex: options.yAxisIndex }
                : {}),
            ...(options.step ? { step: 'end' as const } : {}),
            silent: true,
            tooltip: { show: false },
            showSymbol: false,
            legendHoverLink: false,
            emphasis: { disabled: true },
            lineStyle: {
                color: options.color,
                width: options.width ?? 2,
                type: 'dotted',
                opacity: options.opacity ?? 0.65,
            },
            itemStyle: { color: options.color },
            data: [
                [leftEdgeMs, edgeVal],
                [firstTs, firstVal],
            ],
        };
    };

    const showPrimarySymbol = pts.length <= 100;
    const primarySymbolSize = pts.length <= 35 ? 6 : 4;
    const showSecondarySymbol = pts.length === 1;

    if (pts.length === 0) {
        return {
            useUTC: true,
            animation: false,
            grid: baseGrid,
            xAxis: baseXAxis,
            yAxis: {
                type: 'value',
                min: 0,
                max: 100,
                axisLabel: { show: false },
                splitLine: splitLineStyle,
            },
            series: [
                {
                    name: props.title,
                    type: 'line',
                    data: [],
                },
            ],
        };
    }

    switch (props.chartType) {
        case 'range-bar': {
            const lows = pts.map((p) =>
                pickNumber(p, ['low', 'min', 'body_battery_low']),
            );
            const highs = pts.map((p) =>
                pickNumber(p, ['high', 'peak', 'max', 'body_battery_high']),
            );
            const currents = pts.map((p) =>
                pickNumber(p, [
                    'current',
                    'end',
                    'value',
                    'body_battery_current',
                ]),
            );
            const spans = pts.map((_, idx) => {
                const lo = lows[idx];
                const hi = highs[idx];

                if (lo === null || hi === null) {
                    return null;
                }

                return Math.max(0, hi - lo);
            });

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: {
                    ...baseLegend,
                    data: ['High–Low Range', 'Current'],
                },
                tooltip: {
                    ...baseTooltip,
                    axisPointer: { type: 'shadow' },
                    formatter: (params: unknown) => {
                        const list = Array.isArray(params) ? params : [params];
                        const first = list[0] as AxisTooltipItem | undefined;
                        const idx = first?.dataIndex;

                        if (idx === undefined || !pts[idx]) {
                            return '';
                        }

                        const ts = timestamps[idx];
                        const dateHeading =
                            ts !== null
                                ? utcFullDateFormatter.format(new Date(ts))
                                : '';
                        const travelLabel = pickTravelDayLabel(pts[idx]);
                        const lo = lows[idx];
                        const hi = highs[idx];
                        const cur = currents[idx];
                        const lines = [
                            `<strong>${dateHeading}</strong>${travelLabel ? ` <span style="opacity:0.75">· ${travelLabel}</span>` : ''}`,
                            `Peak: ${hi ?? '—'}`,
                            `Low: ${lo ?? '—'}`,
                            `Current: ${cur ?? '—'}`,
                        ];

                        return lines.join('<br/>');
                    },
                },
                xAxis: baseXAxis,
                yAxis: {
                    type: 'value',
                    min: 0,
                    max: 100,
                    axisLabel: axisLabelStyle,
                    splitLine: splitLineStyle,
                },
                series: [
                    {
                        name: '_base',
                        type: 'bar',
                        stack: 'bb-range',
                        silent: true,
                        itemStyle: { color: 'transparent' },
                        emphasis: { itemStyle: { color: 'transparent' } },
                        barMaxWidth: 20,
                        data: toTimeSeries(lows),
                    },
                    {
                        name: 'High–Low Range',
                        type: 'bar',
                        stack: 'bb-range',
                        barMaxWidth: 20,
                        itemStyle: {
                            color: 'rgba(14, 165, 233, 0.28)',
                            borderColor: 'rgba(14, 165, 233, 0.55)',
                            borderWidth: 1,
                            borderRadius: 4,
                        },
                        data: toTimeSeries(spans),
                    },
                    {
                        name: 'Current',
                        type: 'line',
                        smooth: 0.25,
                        connectNulls: true,
                        showSymbol: showPrimarySymbol,
                        symbolSize: primarySymbolSize,
                        lineStyle: { color: '#0ea5e9', width: 2 },
                        itemStyle: { color: '#0ea5e9' },
                        data: toTimeSeries(currents),
                    },
                ],
            };
        }

        case 'sleep-stages': {
            const deep = pts.map((p) =>
                pickNumber(p, ['deepHours', 'deep', 'deep_hours']),
            );
            const light = pts.map((p) =>
                pickNumber(p, ['lightHours', 'light', 'light_hours']),
            );
            const rem = pts.map((p) =>
                pickNumber(p, ['remHours', 'rem', 'rem_hours']),
            );
            const awake = pts.map((p) =>
                pickNumber(p, ['awakeHours', 'awake', 'awake_hours']),
            );
            const scores = pts.map((p) =>
                pickNumber(p, ['score', 'sleepScore', 'sleep_score']),
            );
            const hasScore = scores.some((v) => v !== null);

            const series: SeriesOption[] = [
                {
                    name: 'Deep',
                    type: 'bar',
                    stack: 'sleep',
                    barMaxWidth: 24,
                    itemStyle: { color: '#4f46e5' },
                    data: toTimeSeries(deep),
                },
                {
                    name: 'Light',
                    type: 'bar',
                    stack: 'sleep',
                    barMaxWidth: 24,
                    itemStyle: { color: '#818cf8' },
                    data: toTimeSeries(light),
                },
                {
                    name: 'REM',
                    type: 'bar',
                    stack: 'sleep',
                    barMaxWidth: 24,
                    itemStyle: { color: '#10b981' },
                    data: toTimeSeries(rem),
                },
                {
                    name: 'Awake',
                    type: 'bar',
                    stack: 'sleep',
                    barMaxWidth: 24,
                    itemStyle: {
                        color: '#fbbf24',
                        borderRadius: [3, 3, 0, 0],
                    },
                    data: toTimeSeries(awake),
                },
            ];

            if (hasScore) {
                series.push({
                    name: 'Score',
                    type: 'line',
                    yAxisIndex: 1,
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: pts.length <= 35 ? 5 : 4,
                    lineStyle: { color: '#a855f7', width: 2 },
                    itemStyle: { color: '#a855f7' },
                    data: toTimeSeries(scores),
                });
            }

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: {
                    ...baseTooltip,
                    axisPointer: { type: 'shadow' },
                },
                xAxis: baseXAxis,
                yAxis: hasScore
                    ? [
                          {
                              type: 'value',
                              name: 'hrs',
                              nameGap: 14,
                              nameTextStyle: leftYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: splitLineStyle,
                          },
                          {
                              type: 'value',
                              name: 'score',
                              min: 0,
                              max: 100,
                              nameGap: 14,
                              nameTextStyle: rightYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: { show: false },
                          },
                      ]
                    : {
                          type: 'value',
                          name: 'hrs',
                          nameGap: 14,
                          nameTextStyle: leftYAxisNameStyle,
                          axisLabel: axisLabelStyle,
                          splitLine: splitLineStyle,
                      },
                series,
            };
        }

        case 'hrv-band': {
            const nightly = pts.map((p) =>
                pickNumber(p, [
                    'lastNight',
                    'lastNightAvg',
                    'last_night_avg',
                    'nightly',
                    'value',
                ]),
            );
            const weekly = pts.map((p) =>
                pickNumber(p, [
                    'weeklyAvg',
                    'weekly_avg',
                    'avg7d',
                    'sevenDayAvg',
                ]),
            );
            const bandLow = props.baseline?.low ?? null;
            const bandHigh = props.baseline?.high ?? null;
            const markAreaData:
                | Array<[{ yAxis: number }, { yAxis: number }]>
                | undefined =
                bandLow !== null && bandHigh !== null
                    ? [[{ yAxis: bandLow }, { yAxis: bandHigh }]]
                    : undefined;

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: baseTooltip,
                xAxis: baseXAxis,
                yAxis: {
                    type: 'value',
                    scale: true,
                    name: 'ms',
                    nameGap: 14,
                    nameTextStyle: leftYAxisNameStyle,
                    axisLabel: axisLabelStyle,
                    splitLine: splitLineStyle,
                },
                series: [
                    {
                        name: 'Nightly HRV',
                        type: 'line',
                        smooth: 0.25,
                        connectNulls: true,
                        showSymbol: showPrimarySymbol,
                        symbolSize: primarySymbolSize,
                        lineStyle: { color: '#10b981', width: 2 },
                        itemStyle: { color: '#10b981' },
                        markArea: markAreaData
                            ? {
                                  silent: true,
                                  itemStyle: {
                                      color: 'rgba(16, 185, 129, 0.12)',
                                  },
                                  label: {
                                      show: true,
                                      position: 'insideTopRight',
                                      formatter: `Baseline ${bandLow}–${bandHigh} ms`,
                                      color: '#10b981',
                                      fontFamily: monoFont,
                                      fontSize: 10,
                                  },
                                  data: markAreaData,
                              }
                            : undefined,
                        data: toTimeSeries(nightly),
                    },
                    {
                        name: '7d Avg',
                        type: 'line',
                        smooth: 0.3,
                        connectNulls: true,
                        showSymbol: showSecondarySymbol,
                        lineStyle: {
                            color: theme.mutedColor.value,
                            width: 1.75,
                            type: 'dashed',
                        },
                        itemStyle: { color: theme.mutedColor.value },
                        data: toTimeSeries(weekly),
                    },
                ],
            };
        }

        case 'bar-goal': {
            const values = pts.map((p) =>
                pickNumber(p, [
                    'value',
                    'steps',
                    'count',
                    'ascended',
                    'floors',
                    'floors_ascended',
                ]),
            );
            const goals = pts.map((p) =>
                pickNumber(p, ['goal', 'step_goal', 'floors_goal']),
            );
            const hasGoal = goals.some((v) => v !== null);

            const series: SeriesOption[] = [
                {
                    name: props.title,
                    type: 'bar',
                    barMaxWidth: 24,
                    itemStyle: {
                        color: accentHex,
                        borderRadius: [3, 3, 0, 0],
                    },
                    data: toTimeSeries(values),
                },
            ];

            if (hasGoal) {
                series.push({
                    name: 'Goal',
                    type: 'line',
                    step: 'middle',
                    connectNulls: true,
                    showSymbol: false,
                    lineStyle: {
                        color: '#f59e0b',
                        width: 1.5,
                        type: 'dashed',
                    },
                    itemStyle: { color: '#f59e0b' },
                    data: toTimeSeries(goals),
                });
            }

            const stepsTooltipFormatter = (params: unknown): string => {
                const list = (
                    Array.isArray(params) ? params : [params]
                ) as AxisTooltipItem[];
                const first = list[0];

                if (!first) {
                    return '';
                }

                const timestamp = extractTooltipTimestamp(first);
                const point =
                    timestamp !== null
                        ? pointByTimestamp.get(timestamp)
                        : undefined;
                const travelLabel = pickTravelDayLabel(point);
                const dateHeading =
                    timestamp !== null
                        ? utcFullDateFormatter.format(new Date(timestamp))
                        : '';
                const lines: string[] = [];

                if (dateHeading) {
                    lines.push(
                        `<strong>${dateHeading}</strong>${travelLabel ? ` <span style="opacity:0.75">· ${travelLabel}</span>` : ''}`,
                    );
                }

                for (const item of list) {
                    if (!item.seriesName || item.seriesName === '_base') {
                        continue;
                    }

                    const val = extractTooltipNumericValue(item);

                    if (val === null) {
                        continue;
                    }

                    lines.push(
                        `${item.marker ?? ''}${item.seriesName}: <strong>${Math.round(val).toLocaleString()}</strong>`,
                    );
                }

                const distanceKm = point
                    ? pickNumber(point, ['distanceKm', 'distance_km'])
                    : null;

                if (distanceKm !== null) {
                    lines.push(
                        `<span style="opacity:0.75">Distance:</span> <strong>${units.formatDistance(distanceKm * 1000)}</strong>`,
                    );
                }

                return lines.join('<br/>');
            };

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: {
                    ...baseTooltip,
                    axisPointer: { type: 'shadow' },
                    formatter:
                        props.metric === 'steps'
                            ? stepsTooltipFormatter
                            : baseTooltip.formatter,
                },
                xAxis: baseXAxis,
                yAxis: {
                    type: 'value',
                    axisLabel: axisLabelStyle,
                    splitLine: splitLineStyle,
                },
                series,
            };
        }

        case 'hr-range': {
            const resting = pts.map((p) =>
                pickNumber(p, ['resting', 'restingHr', 'resting_hr', 'value']),
            );
            const avg7d = pts.map((p) =>
                pickNumber(p, [
                    'sevenDayAvg',
                    'avg7d',
                    'seven_day_avg_rhr',
                    'weeklyAvg',
                ]),
            );
            const mins = pts.map((p) =>
                pickNumber(p, ['min', 'minHr', 'min_hr']),
            );
            const maxs = pts.map((p) =>
                pickNumber(p, ['max', 'maxHr', 'max_hr']),
            );
            const hasMax = maxs.some((v) => v !== null);

            const series: SeriesOption[] = [
                {
                    name: 'Resting HR',
                    type: 'line',
                    yAxisIndex: 0,
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: pts.length <= 35 ? 5 : 4,
                    lineStyle: { color: '#f43f5e', width: 2.25 },
                    itemStyle: { color: '#f43f5e' },
                    data: toTimeSeries(resting),
                },
            ];

            if (avg7d.some((v) => v !== null)) {
                series.push({
                    name: '7d Avg RHR',
                    type: 'line',
                    yAxisIndex: 0,
                    smooth: 0.3,
                    connectNulls: true,
                    showSymbol: showSecondarySymbol,
                    lineStyle: {
                        color: '#fb7185',
                        width: 1.5,
                        type: 'dashed',
                    },
                    itemStyle: { color: '#fb7185' },
                    data: toTimeSeries(avg7d),
                });
            }

            if (mins.some((v) => v !== null)) {
                series.push({
                    name: 'Min HR',
                    type: 'line',
                    yAxisIndex: 0,
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showSecondarySymbol,
                    lineStyle: {
                        color: 'rgba(14, 165, 233, 0.65)',
                        width: 1.25,
                    },
                    itemStyle: { color: '#0ea5e9' },
                    data: toTimeSeries(mins),
                });
            }

            if (hasMax) {
                series.push({
                    name: 'Max HR',
                    type: 'line',
                    yAxisIndex: 1,
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showSecondarySymbol,
                    lineStyle: {
                        color: 'rgba(249, 115, 22, 0.7)',
                        width: 1.5,
                        type: 'dashed',
                    },
                    itemStyle: { color: '#f97316' },
                    data: toTimeSeries(maxs),
                });
            }

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: baseTooltip,
                xAxis: baseXAxis,
                yAxis: hasMax
                    ? [
                          {
                              type: 'value',
                              scale: true,
                              name: 'rhr / min bpm',
                              nameGap: 14,
                              nameTextStyle: leftYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: splitLineStyle,
                          },
                          {
                              type: 'value',
                              scale: true,
                              name: 'max bpm',
                              nameGap: 14,
                              nameTextStyle: rightYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: { show: false },
                          },
                      ]
                    : {
                          type: 'value',
                          scale: true,
                          name: 'bpm',
                          nameGap: 14,
                          nameTextStyle: leftYAxisNameStyle,
                          axisLabel: axisLabelStyle,
                          splitLine: splitLineStyle,
                      },
                series,
            };
        }

        case 'stress-breakdown': {
            const avg = pts.map((p) =>
                pickNumber(p, ['avg', 'avgStress', 'avg_stress', 'value']),
            );
            const restHrs = pts.map((p) => {
                const hrs = pickNumber(p, ['restHours', 'rest', 'rest_hours']);

                if (hrs !== null) {
                    return hrs;
                }

                const sec = pickNumber(p, [
                    'restSeconds',
                    'rest_stress_seconds',
                ]);

                return sec !== null ? Number((sec / 3600).toFixed(2)) : null;
            });
            const lowHrs = pts.map((p) => {
                const hrs = pickNumber(p, ['lowHours', 'low', 'low_hours']);

                if (hrs !== null) {
                    return hrs;
                }

                const sec = pickNumber(p, ['lowSeconds', 'low_stress_seconds']);

                return sec !== null ? Number((sec / 3600).toFixed(2)) : null;
            });
            const medHrs = pts.map((p) => {
                const hrs = pickNumber(p, [
                    'mediumHours',
                    'medHours',
                    'medium',
                    'med',
                    'medium_hours',
                ]);

                if (hrs !== null) {
                    return hrs;
                }

                const sec = pickNumber(p, [
                    'mediumSeconds',
                    'medium_stress_seconds',
                ]);

                return sec !== null ? Number((sec / 3600).toFixed(2)) : null;
            });
            const highHrs = pts.map((p) => {
                const hrs = pickNumber(p, ['highHours', 'high', 'high_hours']);

                if (hrs !== null) {
                    return hrs;
                }

                const sec = pickNumber(p, [
                    'highSeconds',
                    'high_stress_seconds',
                ]);

                return sec !== null ? Number((sec / 3600).toFixed(2)) : null;
            });

            const restPct: Array<number | null> = [];
            const lowPct: Array<number | null> = [];
            const medPct: Array<number | null> = [];
            const highPct: Array<number | null> = [];

            for (let idx = 0; idx < pts.length; idx++) {
                const p = pts[idx];
                const providedRest = pickNumber(p, ['restPct', 'rest_pct']);
                const providedLow = pickNumber(p, ['lowPct', 'low_pct']);
                const providedMed = pickNumber(p, [
                    'mediumPct',
                    'medPct',
                    'medium_pct',
                ]);
                const providedHigh = pickNumber(p, ['highPct', 'high_pct']);

                if (
                    providedRest !== null ||
                    providedLow !== null ||
                    providedMed !== null ||
                    providedHigh !== null
                ) {
                    restPct.push(providedRest ?? 0);
                    lowPct.push(providedLow ?? 0);
                    medPct.push(providedMed ?? 0);
                    highPct.push(providedHigh ?? 0);
                    continue;
                }

                const r = restHrs[idx] ?? 0;
                const l = lowHrs[idx] ?? 0;
                const m = medHrs[idx] ?? 0;
                const h = highHrs[idx] ?? 0;
                const sum = r + l + m + h;

                if (sum <= 0) {
                    restPct.push(null);
                    lowPct.push(null);
                    medPct.push(null);
                    highPct.push(null);
                } else {
                    restPct.push(Number(((r / sum) * 100).toFixed(1)));
                    lowPct.push(Number(((l / sum) * 100).toFixed(1)));
                    medPct.push(Number(((m / sum) * 100).toFixed(1)));
                    highPct.push(Number(((h / sum) * 100).toFixed(1)));
                }
            }

            const hasBreakdown = [restPct, lowPct, medPct, highPct].some(
                (arr) => arr.some((v) => v !== null && v > 0),
            );

            const series: SeriesOption[] = [];

            if (hasBreakdown) {
                series.push(
                    {
                        name: 'Rest (%)',
                        type: 'bar',
                        stack: 'stress-pct',
                        yAxisIndex: 1,
                        barMaxWidth: 20,
                        itemStyle: { color: 'rgba(16, 185, 129, 0.35)' },
                        data: toTimeSeries(restPct),
                    },
                    {
                        name: 'Low (%)',
                        type: 'bar',
                        stack: 'stress-pct',
                        yAxisIndex: 1,
                        barMaxWidth: 20,
                        itemStyle: { color: 'rgba(56, 189, 248, 0.38)' },
                        data: toTimeSeries(lowPct),
                    },
                    {
                        name: 'Med (%)',
                        type: 'bar',
                        stack: 'stress-pct',
                        yAxisIndex: 1,
                        barMaxWidth: 20,
                        itemStyle: { color: 'rgba(245, 158, 11, 0.45)' },
                        data: toTimeSeries(medPct),
                    },
                    {
                        name: 'High (%)',
                        type: 'bar',
                        stack: 'stress-pct',
                        yAxisIndex: 1,
                        barMaxWidth: 20,
                        itemStyle: {
                            color: 'rgba(244, 63, 94, 0.5)',
                            borderRadius: [3, 3, 0, 0],
                        },
                        data: toTimeSeries(highPct),
                    },
                );
            }

            series.push({
                name: 'Avg Stress',
                type: 'line',
                yAxisIndex: 0,
                smooth: 0.25,
                connectNulls: true,
                showSymbol: showPrimarySymbol,
                symbolSize: pts.length <= 35 ? 5 : 4,
                lineStyle: { color: '#f59e0b', width: 2.25 },
                itemStyle: { color: '#f59e0b' },
                data: toTimeSeries(avg),
            });

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: {
                    ...baseTooltip,
                    formatter: (params: unknown) => {
                        const list = (
                            Array.isArray(params) ? params : [params]
                        ) as AxisTooltipItem[];
                        const first = list[0];

                        if (!first) {
                            return '';
                        }

                        const timestamp = extractTooltipTimestamp(first);
                        const idx =
                            timestamp !== null
                                ? timestamps.indexOf(timestamp)
                                : (first.dataIndex ?? -1);
                        const point = idx >= 0 ? pts[idx] : undefined;
                        const travelLabel = pickTravelDayLabel(point);
                        const dateHeading =
                            timestamp !== null
                                ? utcFullDateFormatter.format(
                                      new Date(timestamp),
                                  )
                                : '';
                        const lines: string[] = [];

                        if (dateHeading) {
                            lines.push(
                                `<strong>${dateHeading}</strong>${travelLabel ? ` <span style="opacity:0.75">· ${travelLabel}</span>` : ''}`,
                            );
                        }

                        const avgItem = list.find(
                            (item) => item.seriesName === 'Avg Stress',
                        );
                        const avgVal = avgItem
                            ? extractTooltipNumericValue(avgItem)
                            : null;
                        const maxVal = point
                            ? pickNumber(point, ['max', 'max_stress'])
                            : null;

                        if (avgVal !== null) {
                            lines.push(
                                `${avgItem?.marker ?? ''}Avg Stress: <strong>${avgVal}</strong>${maxVal !== null ? ` <span style="opacity:0.75">(max ${maxVal})</span>` : ''}`,
                            );
                        }

                        const levelMeta: Array<{
                            seriesName: string;
                            label: string;
                            hours: number | null;
                        }> = [
                            {
                                seriesName: 'Rest (%)',
                                label: 'Rest',
                                hours: idx >= 0 ? restHrs[idx] : null,
                            },
                            {
                                seriesName: 'Low (%)',
                                label: 'Low',
                                hours: idx >= 0 ? lowHrs[idx] : null,
                            },
                            {
                                seriesName: 'Med (%)',
                                label: 'Med',
                                hours: idx >= 0 ? medHrs[idx] : null,
                            },
                            {
                                seriesName: 'High (%)',
                                label: 'High',
                                hours: idx >= 0 ? highHrs[idx] : null,
                            },
                        ];

                        for (const level of levelMeta) {
                            const item = list.find(
                                (entry) =>
                                    entry.seriesName === level.seriesName,
                            );
                            const pctVal = item
                                ? extractTooltipNumericValue(item)
                                : null;

                            if (pctVal === null) {
                                continue;
                            }

                            const hrsSuffix =
                                level.hours !== null
                                    ? ` <span style="opacity:0.75">(${formatHoursCompact(level.hours)})</span>`
                                    : '';
                            lines.push(
                                `${item?.marker ?? ''}${level.label}: <strong>${pctVal.toFixed(1)}%</strong>${hrsSuffix}`,
                            );
                        }

                        return lines.join('<br/>');
                    },
                },
                xAxis: baseXAxis,
                yAxis: hasBreakdown
                    ? [
                          {
                              type: 'value',
                              min: 0,
                              max: 100,
                              name: 'score',
                              nameGap: 14,
                              nameTextStyle: leftYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: splitLineStyle,
                          },
                          {
                              type: 'value',
                              min: 0,
                              max: 100,
                              name: '%',
                              nameGap: 14,
                              nameTextStyle: rightYAxisNameStyle,
                              axisLabel: {
                                  ...axisLabelStyle,
                                  formatter: '{value}%',
                              },
                              splitLine: { show: false },
                          },
                      ]
                    : {
                          type: 'value',
                          min: 0,
                          max: 100,
                          axisLabel: axisLabelStyle,
                          splitLine: splitLineStyle,
                      },
                series,
            };
        }

        case 'intensity-stacked': {
            const isWeekly = pts.some((p) => p.granularity === 'weekly');
            const moderate = pts.map((p) =>
                pickNumber(p, [
                    'moderate',
                    'mod',
                    'moderate_intensity_minutes',
                ]),
            );
            const vigorousRaw = pts.map((p) =>
                pickNumber(p, [
                    'vigorous',
                    'vig',
                    'vigorous_intensity_minutes',
                ]),
            );
            const vigorousWeighted = pts.map((p, idx) => {
                const weighted = pickNumber(p, ['vigorousWeighted']);

                if (weighted !== null) {
                    return weighted;
                }

                const raw = vigorousRaw[idx];

                return raw !== null ? raw * 2 : null;
            });
            const totals = pts.map((p, idx) => {
                const explicit = pickNumber(p, ['total']);

                if (explicit !== null) {
                    return explicit;
                }

                const mod = moderate[idx];
                const vigW = vigorousWeighted[idx];

                if (mod === null && vigW === null) {
                    return null;
                }

                return (mod ?? 0) + (vigW ?? 0);
            });
            const goals = pts.map((p) =>
                pickNumber(p, ['goal', 'intensity_minutes_goal']),
            );
            const hasGoal = goals.some((v) => v !== null);

            if (isWeekly) {
                const years = new Set<number>();

                for (const ts of timestamps) {
                    if (ts !== null) {
                        years.add(new Date(ts).getUTCFullYear());
                    }
                }

                const multiYear = years.size > 1;
                const categories = pts.map((p, idx) => {
                    const ts = timestamps[idx];

                    if (ts !== null) {
                        return multiYear
                            ? utcMonthYearFormatter.format(new Date(ts))
                            : utcDayMonthFormatter.format(new Date(ts));
                    }

                    return String(p.date ?? '');
                });
                const barMaxWidth =
                    pts.length <= 8 ? 36 : pts.length <= 60 ? 14 : 8;

                const series: SeriesOption[] = [
                    {
                        name: 'Weekly Total',
                        type: 'bar',
                        barMaxWidth,
                        data: totals.map((val, idx) => {
                            const goalVal = goals[idx];
                            const hit =
                                val !== null &&
                                goalVal !== null &&
                                goalVal > 0 &&
                                val >= goalVal;

                            return {
                                value: val,
                                itemStyle: {
                                    color: hit ? '#10b981' : '#f59e0b',
                                    borderRadius: [3, 3, 0, 0],
                                },
                            };
                        }),
                    },
                ];

                if (hasGoal) {
                    series.push({
                        name: 'Weekly Goal',
                        type: 'line',
                        step: 'middle',
                        connectNulls: true,
                        showSymbol: false,
                        lineStyle: {
                            color: theme.mutedColor.value,
                            width: 1.5,
                            type: 'dashed',
                        },
                        itemStyle: { color: theme.mutedColor.value },
                        data: goals,
                    });
                }

                return {
                    useUTC: true,
                    animation: false,
                    grid: baseGrid,
                    legend: baseLegend,
                    tooltip: {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        formatter: (params: unknown): string => {
                            const list = (
                                Array.isArray(params) ? params : [params]
                            ) as AxisTooltipItem[];
                            const first = list[0];
                            const idx =
                                typeof first?.dataIndex === 'number'
                                    ? first.dataIndex
                                    : -1;
                            const point = idx >= 0 ? pts[idx] : undefined;

                            if (!point) {
                                return '';
                            }

                            const startMs = parseCalendarDateUtc(point.date);
                            const endMs = parseCalendarDateUtc(point.weekEnd);
                            const heading =
                                startMs !== null && endMs !== null
                                    ? formatUtcDateWindow(startMs, endMs)
                                    : startMs !== null
                                      ? utcFullDateFormatter.format(
                                            new Date(startMs),
                                        )
                                      : String(point.date ?? '');

                            const modVal = moderate[idx] ?? 0;
                            const vigRawVal = vigorousRaw[idx] ?? 0;
                            const vigWeightedVal = vigorousWeighted[idx] ?? 0;
                            const totalVal =
                                totals[idx] ?? modVal + vigWeightedVal;
                            const goalVal = goals[idx];

                            const totalItem = list.find(
                                (item) => item.seriesName === 'Weekly Total',
                            );
                            const goalItem = list.find(
                                (item) => item.seriesName === 'Weekly Goal',
                            );

                            const lines: string[] = [
                                `<strong>${heading}</strong>`,
                            ];

                            const pctSuffix =
                                goalVal !== null && goalVal > 0
                                    ? ` <span style="opacity:0.75">· ${Math.round((totalVal / goalVal) * 100)}% of goal</span>`
                                    : '';

                            lines.push(
                                `${totalItem?.marker ?? ''}Weekly Total: <strong>${totalVal.toLocaleString()} min</strong>${pctSuffix}`,
                            );
                            lines.push(
                                `<span style="opacity:0.75">Mod ${modVal.toLocaleString()} · Vig ${vigRawVal.toLocaleString()} (×2 = ${vigWeightedVal.toLocaleString()})</span>`,
                            );

                            if (goalVal !== null) {
                                lines.push(
                                    `${goalItem?.marker ?? ''}Weekly Goal: <strong>${goalVal.toLocaleString()} min</strong>`,
                                );
                            }

                            return lines.join('<br/>');
                        },
                    },
                    xAxis: {
                        type: 'category',
                        data: categories,
                        axisLine: {
                            lineStyle: { color: theme.gridLineColor.value },
                        },
                        axisTick: { show: false },
                        axisLabel: {
                            ...axisLabelStyle,
                            hideOverlap: true,
                        },
                    },
                    yAxis: {
                        type: 'value',
                        name: 'min',
                        nameGap: 14,
                        nameTextStyle: leftYAxisNameStyle,
                        axisLabel: axisLabelStyle,
                        splitLine: splitLineStyle,
                    },
                    series,
                };
            }

            const dailyPace = goals.map((goal) =>
                goal !== null && goal > 0 ? Math.round(goal / 7) : null,
            );
            const hasDailyPace = dailyPace.some((v) => v !== null);

            // Build subtle alternating Mon–Sun week shading bands
            const weekBandAreas: Array<[{ xAxis: number }, { xAxis: number }]> =
                [];

            if (timeWindow && spanDays > 7) {
                const axisMin = timeWindow.startMs - DAY_MS / 2;
                const axisMax = timeWindow.endMs + DAY_MS / 2;
                const startDateObj = new Date(timeWindow.startMs);
                const utcDay = startDateObj.getUTCDay();
                const offsetToMonday = utcDay === 0 ? 6 : utcDay - 1;
                let weekMonMs = timeWindow.startMs - offsetToMonday * DAY_MS;
                let bandIndex = 0;

                while (weekMonMs <= timeWindow.endMs) {
                    if (bandIndex % 2 === 1) {
                        const fromMs = Math.max(
                            weekMonMs - DAY_MS / 2,
                            axisMin,
                        );
                        const toMs = Math.min(
                            weekMonMs + 6.5 * DAY_MS,
                            axisMax,
                        );

                        if (toMs > fromMs) {
                            weekBandAreas.push([
                                { xAxis: fromMs },
                                { xAxis: toMs },
                            ]);
                        }
                    }

                    weekMonMs += 7 * DAY_MS;
                    bandIndex++;
                }
            }

            const dailyBarMaxWidth =
                pts.length <= 10 ? 28 : pts.length <= 35 ? 16 : 6;

            const series: SeriesOption[] = [
                {
                    name: 'Daily Total',
                    type: 'bar',
                    barMaxWidth: dailyBarMaxWidth,
                    itemStyle: {
                        color: '#f59e0b',
                        borderRadius: [3, 3, 0, 0],
                    },
                    ...(weekBandAreas.length > 0
                        ? {
                              markArea: {
                                  silent: true,
                                  itemStyle: {
                                      color: theme.isDark.value
                                          ? 'rgba(255, 255, 255, 0.03)'
                                          : 'rgba(24, 24, 27, 0.03)',
                                  },
                                  data: weekBandAreas,
                              },
                          }
                        : {}),
                    data: toTimeSeries(totals),
                },
            ];

            if (hasDailyPace) {
                series.push({
                    name: 'Daily Pace (Goal ÷ 7)',
                    type: 'line',
                    step: 'middle',
                    connectNulls: true,
                    showSymbol: false,
                    lineStyle: {
                        color: theme.mutedColor.value,
                        width: 1.5,
                        type: 'dashed',
                    },
                    itemStyle: { color: theme.mutedColor.value },
                    data: toTimeSeries(dailyPace),
                });
            }

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: {
                    ...baseTooltip,
                    axisPointer: { type: 'shadow' },
                    formatter: (params: unknown): string => {
                        const list = (
                            Array.isArray(params) ? params : [params]
                        ) as AxisTooltipItem[];
                        const first = list[0];

                        if (!first) {
                            return '';
                        }

                        const timestamp = extractTooltipTimestamp(first);
                        const point =
                            timestamp !== null
                                ? pointByTimestamp.get(timestamp)
                                : undefined;

                        if (!point) {
                            return '';
                        }

                        const dateHeading =
                            timestamp !== null
                                ? utcFullDateFormatter.format(
                                      new Date(timestamp),
                                  )
                                : String(point.date ?? '');

                        const modVal =
                            pickNumber(point, [
                                'moderate',
                                'mod',
                                'moderate_intensity_minutes',
                            ]) ?? 0;
                        const vigRawVal =
                            pickNumber(point, [
                                'vigorous',
                                'vig',
                                'vigorous_intensity_minutes',
                            ]) ?? 0;
                        const vigWeightedVal =
                            pickNumber(point, ['vigorousWeighted']) ??
                            vigRawVal * 2;
                        const dayTotalVal =
                            pickNumber(point, ['total']) ??
                            modVal + vigWeightedVal;
                        const cumVal = pickNumber(point, ['weeklyCumulative']);
                        const goalVal = pickNumber(point, [
                            'goal',
                            'intensity_minutes_goal',
                        ]);

                        const dayItem = list.find(
                            (item) => item.seriesName === 'Daily Total',
                        );
                        const paceItem = list.find(
                            (item) =>
                                item.seriesName === 'Daily Pace (Goal ÷ 7)',
                        );

                        const lines: string[] = [
                            `<strong>${dateHeading}</strong>`,
                            `${dayItem?.marker ?? ''}Day Total: <strong>${dayTotalVal.toLocaleString()} min</strong>`,
                            `<span style="opacity:0.75">Mod ${modVal.toLocaleString()} · Vig ${vigRawVal.toLocaleString()} (×2 = ${vigWeightedVal.toLocaleString()})</span>`,
                        ];

                        if (goalVal !== null && goalVal > 0) {
                            lines.push(
                                `${paceItem?.marker ?? ''}Daily Pace: <strong>${Math.round(goalVal / 7)} min/day</strong>`,
                            );
                        }

                        if (cumVal !== null) {
                            const goalSuffix =
                                goalVal !== null && goalVal > 0
                                    ? ` / ${goalVal.toLocaleString()} min <span style="opacity:0.75">· ${Math.round((cumVal / goalVal) * 100)}%</span>`
                                    : ' min';
                            lines.push(
                                `Week-to-Date: <strong>${cumVal.toLocaleString()}</strong>${goalSuffix}`,
                            );
                        }

                        return lines.join('<br/>');
                    },
                },
                xAxis: baseXAxis,
                yAxis: {
                    type: 'value',
                    name: 'min',
                    nameGap: 14,
                    nameTextStyle: leftYAxisNameStyle,
                    axisLabel: axisLabelStyle,
                    splitLine: splitLineStyle,
                },
                series,
            };
        }

        case 'weight-comp': {
            const weightUnitLabel = units.weightUnit.value;
            const weight = pts.map((p) => {
                const kg = pickNumber(p, [
                    'weightKg',
                    'weight',
                    'weight_kg',
                    'value',
                ]);

                return kg !== null
                    ? Number(units.weightFromKg(kg).toFixed(1))
                    : null;
            });
            const bodyFat = pts.map((p) =>
                pickNumber(p, ['bodyFatPct', 'bodyFat', 'body_fat_pct', 'fat']),
            );
            const hasBodyFat = bodyFat.some((v) => v !== null);
            const weightSeriesName = `Weight (${weightUnitLabel})`;

            const weightSeriesData = toTimeSeries(weight);
            const bodyFatSeriesData = toTimeSeries(bodyFat);

            const series: SeriesOption[] = [
                {
                    name: weightSeriesName,
                    type: 'line',
                    yAxisIndex: 0,
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: primarySymbolSize,
                    lineStyle: { color: '#10b981', width: 2.25 },
                    itemStyle: { color: '#10b981' },
                    data: weightSeriesData,
                },
            ];

            if (hasBodyFat) {
                series.push({
                    name: 'Body Fat (%)',
                    type: 'line',
                    yAxisIndex: 1,
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: pts.length <= 35 ? 5 : 4,
                    lineStyle: {
                        color: '#f59e0b',
                        width: 1.75,
                        type: 'dashed',
                    },
                    itemStyle: { color: '#f59e0b' },
                    data: bodyFatSeriesData,
                });
            }

            const legendData = series
                .map((item) => item.name)
                .filter((name): name is string => typeof name === 'string');

            const weightLeadIn = buildLeadInSeries(
                weightSeriesName,
                pickPriorAnchor(props.priorPoint, 'weightKg', (kg) =>
                    Number(units.weightFromKg(kg).toFixed(1)),
                ),
                weightSeriesData,
                { color: '#10b981', width: 2.25, yAxisIndex: 0 },
            );

            if (weightLeadIn) {
                series.push(weightLeadIn);
            }

            if (hasBodyFat) {
                const bodyFatLeadIn = buildLeadInSeries(
                    'Body Fat (%)',
                    pickPriorAnchor(props.priorPoint, 'bodyFatPct'),
                    bodyFatSeriesData,
                    { color: '#f59e0b', width: 1.75, yAxisIndex: 1 },
                );

                if (bodyFatLeadIn) {
                    series.push(bodyFatLeadIn);
                }
            }

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: {
                    ...baseLegend,
                    data: legendData,
                },
                tooltip: {
                    ...baseTooltip,
                    formatter: (params: unknown): string => {
                        const list = (
                            Array.isArray(params) ? params : [params]
                        ) as AxisTooltipItem[];
                        const first = list[0];

                        if (!first) {
                            return '';
                        }

                        const timestamp = extractTooltipTimestamp(first);
                        const dateHeading =
                            timestamp !== null
                                ? utcFullDateFormatter.format(
                                      new Date(timestamp),
                                  )
                                : '';
                        const lines: string[] = [];

                        if (dateHeading) {
                            lines.push(`<strong>${dateHeading}</strong>`);
                        }

                        const seenWeightSeries = new Set<string>();

                        for (const item of list) {
                            const val = extractTooltipNumericValue(item);

                            if (
                                val === null ||
                                !item.seriesName ||
                                seenWeightSeries.has(item.seriesName)
                            ) {
                                continue;
                            }

                            seenWeightSeries.add(item.seriesName);

                            if (item.seriesName === weightSeriesName) {
                                lines.push(
                                    `${item.marker ?? ''}Weight: <strong>${val.toFixed(1)} ${weightUnitLabel}</strong>`,
                                );
                            } else if (item.seriesName === 'Body Fat (%)') {
                                lines.push(
                                    `${item.marker ?? ''}Body Fat: <strong>${val.toFixed(1)}%</strong>`,
                                );
                            } else {
                                lines.push(
                                    `${item.marker ?? ''}${item.seriesName}: <strong>${val}</strong>`,
                                );
                            }
                        }

                        return lines.join('<br/>');
                    },
                },
                xAxis: baseXAxis,
                yAxis: hasBodyFat
                    ? [
                          {
                              type: 'value',
                              scale: true,
                              name: weightUnitLabel,
                              nameGap: 14,
                              nameTextStyle: leftYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: splitLineStyle,
                          },
                          {
                              type: 'value',
                              scale: true,
                              name: '%',
                              nameGap: 14,
                              nameTextStyle: rightYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: { show: false },
                          },
                      ]
                    : {
                          type: 'value',
                          scale: true,
                          name: weightUnitLabel,
                          nameGap: 14,
                          nameTextStyle: leftYAxisNameStyle,
                          axisLabel: axisLabelStyle,
                          splitLine: splitLineStyle,
                      },
                series,
            };
        }

        case 'vo2-dual': {
            const running = pts.map((p) =>
                pickNumber(p, ['running', 'runningVo2', 'running_vo2']),
            );
            const cycling = pts.map((p) =>
                pickNumber(p, ['cycling', 'cyclingVo2', 'cycling_vo2']),
            );
            const runningSeriesData = toTimeSeries(running);
            const cyclingSeriesData = toTimeSeries(cycling);
            const series: SeriesOption[] = [];

            if (running.some((v) => v !== null)) {
                series.push({
                    name: 'Running VO2',
                    type: 'line',
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: primarySymbolSize,
                    lineStyle: { color: '#f59e0b', width: 2.25 },
                    itemStyle: { color: '#f59e0b' },
                    data: runningSeriesData,
                });
            }

            if (cycling.some((v) => v !== null)) {
                series.push({
                    name: 'Cycling VO2',
                    type: 'line',
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: primarySymbolSize,
                    lineStyle: { color: '#0ea5e9', width: 2.25 },
                    itemStyle: { color: '#0ea5e9' },
                    data: cyclingSeriesData,
                });
            }

            if (series.length === 0) {
                series.push({
                    name: 'VO2 Max',
                    type: 'line',
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: primarySymbolSize,
                    lineStyle: { color: '#6366f1', width: 2.25 },
                    itemStyle: { color: '#6366f1' },
                    data: toTimeSeries(
                        pts.map((p) => pickNumber(p, ['value'])),
                    ),
                });
            }

            const legendData = series
                .map((item) => item.name)
                .filter((name): name is string => typeof name === 'string');

            const runningLeadIn = buildLeadInSeries(
                'Running VO2',
                pickPriorAnchor(props.priorPoint, 'running'),
                runningSeriesData,
                { color: '#f59e0b', width: 2.25 },
            );

            if (runningLeadIn) {
                series.push(runningLeadIn);
            }

            const cyclingLeadIn = buildLeadInSeries(
                'Cycling VO2',
                pickPriorAnchor(props.priorPoint, 'cycling'),
                cyclingSeriesData,
                { color: '#0ea5e9', width: 2.25 },
            );

            if (cyclingLeadIn) {
                series.push(cyclingLeadIn);
            }

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: {
                    ...baseLegend,
                    data: legendData,
                },
                tooltip: baseTooltip,
                xAxis: baseXAxis,
                yAxis: {
                    type: 'value',
                    scale: true,
                    name: 'mL/kg/min',
                    nameGap: 14,
                    nameTextStyle: leftYAxisNameStyle,
                    axisLabel: axisLabelStyle,
                    splitLine: splitLineStyle,
                },
                series,
            };
        }

        case 'fitness-age': {
            const fitnessAge = pts.map((p) =>
                pickNumber(p, ['fitnessAge', 'fitness_age', 'value']),
            );
            const chronologicalAge = pts.map((p) =>
                pickNumber(p, ['chronologicalAge', 'chronological_age', 'age']),
            );
            const hasChronologicalAge = chronologicalAge.some(
                (v) => v !== null,
            );

            const fitnessAgeSeriesData = toTimeSeries(fitnessAge);
            const chronologicalAgeSeriesData = toTimeSeries(chronologicalAge);

            const series: SeriesOption[] = [
                {
                    name: 'Fitness Age',
                    type: 'line',
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: primarySymbolSize,
                    lineStyle: { color: '#8b5cf6', width: 2.25 },
                    itemStyle: { color: '#8b5cf6' },
                    areaStyle: {
                        origin: 'start',
                        color: {
                            type: 'linear',
                            x: 0,
                            y: 0,
                            x2: 0,
                            y2: 1,
                            colorStops: [
                                {
                                    offset: 0,
                                    color: 'rgba(139, 92, 246, 0.18)',
                                },
                                {
                                    offset: 1,
                                    color: 'rgba(139, 92, 246, 0.01)',
                                },
                            ],
                        },
                    },
                    data: fitnessAgeSeriesData,
                },
            ];

            if (hasChronologicalAge) {
                series.push({
                    name: 'Chronological Age',
                    type: 'line',
                    step: 'end',
                    connectNulls: true,
                    showSymbol: showSecondarySymbol,
                    symbolSize: 5,
                    lineStyle: {
                        color: theme.mutedColor.value,
                        width: 1.5,
                        type: 'dashed',
                    },
                    itemStyle: { color: theme.mutedColor.value },
                    data: chronologicalAgeSeriesData,
                });
            }

            const legendData = series
                .map((item) => item.name)
                .filter((name): name is string => typeof name === 'string');

            const fitnessLeadIn = buildLeadInSeries(
                'Fitness Age',
                pickPriorAnchor(props.priorPoint, 'fitnessAge'),
                fitnessAgeSeriesData,
                { color: '#8b5cf6', width: 2.25 },
            );

            if (fitnessLeadIn) {
                series.push(fitnessLeadIn);
            }

            if (hasChronologicalAge) {
                const chronoLeadIn = buildLeadInSeries(
                    'Chronological Age',
                    pickPriorAnchor(props.priorPoint, 'chronologicalAge'),
                    chronologicalAgeSeriesData,
                    {
                        color: theme.mutedColor.value,
                        width: 1.5,
                        step: true,
                        opacity: 0.55,
                    },
                );

                if (chronoLeadIn) {
                    series.push(chronoLeadIn);
                }
            }

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: {
                    ...baseLegend,
                    data: legendData,
                },
                tooltip: {
                    ...baseTooltip,
                    formatter: (params: unknown): string => {
                        const list = (
                            Array.isArray(params) ? params : [params]
                        ) as AxisTooltipItem[];
                        const first = list[0];

                        if (!first) {
                            return '';
                        }

                        const timestamp = extractTooltipTimestamp(first);
                        const point =
                            timestamp !== null
                                ? pointByTimestamp.get(timestamp)
                                : undefined;
                        const dateHeading =
                            timestamp !== null
                                ? utcFullDateFormatter.format(
                                      new Date(timestamp),
                                  )
                                : '';
                        const lines: string[] = [];

                        if (dateHeading) {
                            lines.push(`<strong>${dateHeading}</strong>`);
                        }

                        const fitnessItem = list.find(
                            (item) => item.seriesName === 'Fitness Age',
                        );
                        const chronoItem = list.find(
                            (item) => item.seriesName === 'Chronological Age',
                        );
                        const fitVal = point
                            ? pickNumber(point, ['fitnessAge', 'fitness_age'])
                            : fitnessItem
                              ? extractTooltipNumericValue(fitnessItem)
                              : null;
                        const chronoVal = point
                            ? pickNumber(point, [
                                  'chronologicalAge',
                                  'chronological_age',
                              ])
                            : chronoItem
                              ? extractTooltipNumericValue(chronoItem)
                              : null;

                        if (fitVal !== null) {
                            lines.push(
                                `${fitnessItem?.marker ?? ''}Fitness Age: <strong>${fitVal.toFixed(1)} yrs</strong>`,
                            );
                        }

                        if (chronoVal !== null) {
                            lines.push(
                                `${chronoItem?.marker ?? ''}Age: <strong>${chronoVal.toFixed(1)} yrs</strong>`,
                            );
                        }

                        if (fitVal !== null && chronoVal !== null) {
                            const delta = Number(
                                (fitVal - chronoVal).toFixed(1),
                            );
                            const deltaLabel =
                                Math.abs(delta) < 0.05
                                    ? '0.0 yrs'
                                    : `${delta > 0 ? '+' : ''}${delta.toFixed(1)} yrs`;
                            lines.push(
                                `vs. Age: <strong>${deltaLabel}</strong>`,
                            );
                        }

                        if (point) {
                            const factors: string[] = [];
                            const rhr = pickNumber(point, ['rhr']);
                            const bodyFat = pickNumber(point, ['bodyFatPct']);
                            const bmi = pickNumber(point, ['bmi']);
                            const vigMin = pickNumber(point, [
                                'vigorousMinutesAvg',
                            ]);

                            if (rhr !== null) {
                                factors.push(`RHR ${Math.round(rhr)} bpm`);
                            }

                            if (bodyFat !== null) {
                                factors.push(`Body Fat ${bodyFat.toFixed(1)}%`);
                            } else if (bmi !== null) {
                                factors.push(`BMI ${bmi.toFixed(1)}`);
                            }

                            if (vigMin !== null) {
                                factors.push(
                                    `Vigorous ${vigMin.toFixed(1)} min/wk`,
                                );
                            }

                            if (factors.length > 0) {
                                lines.push(
                                    `<span style="opacity:0.75">${factors.join(' · ')}</span>`,
                                );
                            }
                        }

                        return lines.join('<br/>');
                    },
                },
                xAxis: baseXAxis,
                yAxis: {
                    type: 'value',
                    scale: true,
                    name: 'yrs',
                    nameGap: 14,
                    nameTextStyle: leftYAxisNameStyle,
                    axisLabel: axisLabelStyle,
                    splitLine: splitLineStyle,
                },
                series,
            };
        }

        case 'calories-stacked': {
            const bmr = pts.map((p) =>
                pickNumber(p, [
                    'bmr',
                    'resting',
                    'bmrCalories',
                    'bmr_calories',
                ]),
            );
            const active = pts.map((p) =>
                pickNumber(p, ['active', 'activeCalories', 'active_calories']),
            );

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: {
                    ...baseTooltip,
                    axisPointer: { type: 'shadow' },
                    formatter: (params: unknown) => {
                        const list = (
                            Array.isArray(params) ? params : [params]
                        ) as AxisTooltipItem[];
                        const first = list[0];

                        if (!first) {
                            return '';
                        }

                        const timestamp = extractTooltipTimestamp(first);
                        const point =
                            timestamp !== null
                                ? pointByTimestamp.get(timestamp)
                                : undefined;
                        const travelLabel = pickTravelDayLabel(point);
                        const dateHeading =
                            timestamp !== null
                                ? utcFullDateFormatter.format(
                                      new Date(timestamp),
                                  )
                                : '';
                        const totalVal = point
                            ? pickNumber(point, ['total'])
                            : null;
                        const bmrVal = point
                            ? pickNumber(point, ['bmr', 'bmr_calories'])
                            : null;
                        const activeVal = point
                            ? pickNumber(point, ['active', 'active_calories'])
                            : null;
                        const rawTotal = point
                            ? pickNumber(point, ['rawTotal'])
                            : null;
                        const rawBmr = point
                            ? pickNumber(point, ['rawBmr'])
                            : null;
                        const rawActive = point
                            ? pickNumber(point, ['rawActive'])
                            : null;

                        const lines: string[] = [];

                        if (dateHeading) {
                            lines.push(
                                `<strong>${dateHeading}</strong>${travelLabel ? ` <span style="opacity:0.75">· ${travelLabel} → 24h</span>` : ''}`,
                            );
                        }

                        if (totalVal !== null) {
                            lines.push(
                                `Total: <strong>${formatKcal(totalVal)} kcal</strong>${rawTotal !== null ? ` <span style="opacity:0.75">(raw ${formatKcal(rawTotal)})</span>` : ''}`,
                            );
                        }

                        const bmrItem = list.find(
                            (item) => item.seriesName === 'BMR',
                        );

                        if (bmrVal !== null) {
                            lines.push(
                                `${bmrItem?.marker ?? ''}BMR: <strong>${formatKcal(bmrVal)} kcal</strong>${rawBmr !== null ? ` <span style="opacity:0.75">(raw ${formatKcal(rawBmr)})</span>` : ''}`,
                            );
                        }

                        const activeItem = list.find(
                            (item) => item.seriesName === 'Active',
                        );

                        if (activeVal !== null) {
                            lines.push(
                                `${activeItem?.marker ?? ''}Active: <strong>${formatKcal(activeVal)} kcal</strong>${rawActive !== null ? ` <span style="opacity:0.75">(raw ${formatKcal(rawActive)})</span>` : ''}`,
                            );
                        }

                        return lines.join('<br/>');
                    },
                },
                xAxis: baseXAxis,
                yAxis: {
                    type: 'value',
                    name: 'kcal',
                    nameGap: 14,
                    nameTextStyle: leftYAxisNameStyle,
                    axisLabel: axisLabelStyle,
                    splitLine: splitLineStyle,
                },
                series: [
                    {
                        name: 'BMR',
                        type: 'bar',
                        stack: 'calories',
                        barMaxWidth: 24,
                        itemStyle: {
                            color: theme.isDark.value ? '#3f3f46' : '#d4d4d8',
                        },
                        data: toTimeSeries(bmr),
                    },
                    {
                        name: 'Active',
                        type: 'bar',
                        stack: 'calories',
                        barMaxWidth: 24,
                        itemStyle: {
                            color: '#f97316',
                            borderRadius: [3, 3, 0, 0],
                        },
                        data: toTimeSeries(active),
                    },
                ],
            };
        }

        case 'dual-line': {
            const resp = pts.map((p) =>
                pickNumber(p, [
                    'respiration',
                    'avgRespiration',
                    'avg_respiration',
                    'brpm',
                    'primary',
                ]),
            );
            const spo2 = pts.map((p) =>
                pickNumber(p, ['spo2', 'avgSpo2', 'avg_spo2', 'secondary']),
            );
            const hasSpo2 = spo2.some((v) => v !== null);

            const series: SeriesOption[] = [
                {
                    name: 'Respiration (brpm)',
                    type: 'line',
                    yAxisIndex: 0,
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: pts.length <= 35 ? 5 : 4,
                    lineStyle: { color: '#14b8a6', width: 2.25 },
                    itemStyle: { color: '#14b8a6' },
                    data: toTimeSeries(resp),
                },
            ];

            if (hasSpo2) {
                series.push({
                    name: 'SpO2 (%)',
                    type: 'line',
                    yAxisIndex: 1,
                    smooth: 0.25,
                    connectNulls: true,
                    showSymbol: showPrimarySymbol,
                    symbolSize: pts.length <= 35 ? 5 : 4,
                    lineStyle: { color: '#0ea5e9', width: 2 },
                    itemStyle: { color: '#0ea5e9' },
                    data: toTimeSeries(spo2),
                });
            }

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: baseTooltip,
                xAxis: baseXAxis,
                yAxis: hasSpo2
                    ? [
                          {
                              type: 'value',
                              scale: true,
                              name: 'brpm',
                              nameGap: 14,
                              nameTextStyle: leftYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: splitLineStyle,
                          },
                          {
                              type: 'value',
                              scale: true,
                              max: 100,
                              name: '%',
                              nameGap: 14,
                              nameTextStyle: rightYAxisNameStyle,
                              axisLabel: axisLabelStyle,
                              splitLine: { show: false },
                          },
                      ]
                    : {
                          type: 'value',
                          scale: true,
                          name: 'brpm',
                          nameGap: 14,
                          nameTextStyle: leftYAxisNameStyle,
                          axisLabel: axisLabelStyle,
                          splitLine: splitLineStyle,
                      },
                series,
            };
        }

        case 'bar':
        default: {
            const isHydration = props.metric === 'hydration';
            const hydrationUnitLabel = units.isImperial.value ? 'fl oz' : 'mL';
            const rawValues = pts.map((p) =>
                pickNumber(p, ['value', 'ml', 'hydration_ml', 'hydrationMl']),
            );
            const values = isHydration
                ? rawValues.map((v) => {
                      if (v === null) {
                          return null;
                      }

                      return units.isImperial.value
                          ? Number(units.volumeFromMl(v).toFixed(1))
                          : Math.round(v);
                  })
                : rawValues;

            const hydrationTooltipFormatter = (params: unknown): string => {
                const list = (
                    Array.isArray(params) ? params : [params]
                ) as AxisTooltipItem[];
                const first = list[0];

                if (!first) {
                    return '';
                }

                const timestamp = extractTooltipTimestamp(first);
                const point =
                    timestamp !== null
                        ? pointByTimestamp.get(timestamp)
                        : undefined;
                const rawMl = point
                    ? pickNumber(point, [
                          'value',
                          'ml',
                          'hydration_ml',
                          'hydrationMl',
                      ])
                    : null;
                const dateHeading =
                    timestamp !== null
                        ? utcFullDateFormatter.format(new Date(timestamp))
                        : '';
                const lines: string[] = [];

                if (dateHeading) {
                    lines.push(`<strong>${dateHeading}</strong>`);
                }

                if (rawMl !== null) {
                    const secondary = units.isImperial.value
                        ? `${(units.volumeFromMl(rawMl) / 128).toFixed(2)} gal`
                        : `${(rawMl / 1000).toFixed(2)} L`;
                    lines.push(
                        `${first.marker ?? ''}${props.title}: <strong>${units.formatFluidVolume(rawMl)}</strong> <span style="opacity:0.75">(${secondary})</span>`,
                    );
                }

                return lines.join('<br/>');
            };

            return {
                useUTC: true,
                animation: false,
                grid: baseGrid,
                legend: baseLegend,
                tooltip: {
                    ...baseTooltip,
                    axisPointer: { type: 'shadow' },
                    formatter: isHydration
                        ? hydrationTooltipFormatter
                        : baseTooltip.formatter,
                },
                xAxis: baseXAxis,
                yAxis: {
                    type: 'value',
                    name: isHydration
                        ? hydrationUnitLabel
                        : props.unit || undefined,
                    nameGap: 14,
                    nameTextStyle: leftYAxisNameStyle,
                    axisLabel: axisLabelStyle,
                    splitLine: splitLineStyle,
                },
                series: [
                    {
                        name: props.title,
                        type: 'bar',
                        barMaxWidth: 24,
                        itemStyle: {
                            color: accentHex,
                            borderRadius: [3, 3, 0, 0],
                        },
                        data: toTimeSeries(values),
                    },
                ],
            };
        }
    }
});
</script>

<template>
    <div class="space-y-6">
        <Head :title="title" />

        <!-- Header -->
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <Link
                    :href="dashboard.url()"
                    class="inline-flex items-center gap-1.5 font-mono text-xs text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                >
                    <ArrowLeft class="size-3.5" />
                    Health Overview
                </Link>

                <p
                    class="mt-3 font-mono text-[11px] tracking-widest uppercase"
                    :class="resolvedAccent.textClass"
                >
                    Health
                    <template v-if="unit"> · {{ unit }}</template>
                </p>
                <h1
                    class="mt-1 text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    {{ title }}
                </h1>
                <p
                    v-if="subtitle"
                    class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"
                >
                    {{ subtitle }}
                </p>
            </div>

            <!-- Range Selector -->
            <DateRangeControl
                :presets="RANGES"
                :window="dateWindow"
                surface="page"
                popover-align="right"
                aria-label="Health metric date range"
                class="self-start lg:self-auto"
                @select-preset="selectRange"
                @step="stepRange"
                @apply-custom="applyCustomRange"
            />
        </div>

        <!-- Quick Metric Switcher Pills -->
        <div class="flex flex-wrap gap-1.5">
            <Link
                v-for="item in METRIC_SWITCHER"
                :key="item.key"
                :href="healthShow.url(item.key, { query: currentRangeQuery })"
                preserve-scroll
                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] transition-colors"
                :class="
                    metric === item.key
                        ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                        : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                "
            >
                <span class="size-1.5 rounded-full" :class="item.dotClass" />
                <span>{{ item.label }}</span>
            </Link>
        </div>

        <!-- Summary KPI Cards -->
        <div :class="summaryGridClass">
            <div
                v-for="card in summaryCards"
                :key="card.label"
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :class="resolvedAccent.barClass"
                />
                <div
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ card.label }}
                </div>
                <div
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ card.value }}
                </div>
            </div>
        </div>

        <!-- Main Visualization Card -->
        <section
            class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="mb-4 flex items-center justify-between gap-2">
                <span
                    class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Trend · {{ activeRangeLabel }}
                    <template v-if="dateWindowLabel">
                        · {{ dateWindowLabel }}
                    </template>
                </span>
                <span
                    class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                >
                    {{ points.length }}
                    {{
                        isWeeklyIntensity
                            ? points.length === 1
                                ? 'week'
                                : 'weeks'
                            : points.length === 1
                              ? 'point'
                              : 'points'
                    }}
                </span>
            </div>

            <div v-if="resolvedTimeWindow !== null" class="relative">
                <BaseChart :option="chartOption" :height="320" />
                <div
                    v-if="points.length === 0"
                    class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center"
                >
                    <div
                        class="rounded-lg border border-zinc-200/80 bg-white/90 px-4 py-2.5 backdrop-blur-xs dark:border-zinc-800/80 dark:bg-[#141417]/90"
                    >
                        <p
                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            No {{ title.toLowerCase() }} data for this range.
                        </p>
                        <p
                            class="mt-1 font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            Try selecting a wider timeframe or running a sync.
                        </p>
                    </div>
                </div>
            </div>
            <div
                v-else
                class="flex h-[320px] flex-col items-center justify-center rounded-lg border border-dashed border-zinc-200/80 text-center dark:border-zinc-800/80"
            >
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    No {{ title.toLowerCase() }} data for this range.
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                >
                    Try selecting a wider timeframe or running a sync.
                </p>
            </div>

            <!-- Companion Weekly Targets Strip for Daily Intensity Minutes -->
            <div
                v-if="intensityWeeklyBuckets.length > 0"
                class="mt-5 border-t border-zinc-200/80 pt-4 dark:border-zinc-800/80"
            >
                <div class="mb-3 flex items-center justify-between gap-2">
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Weekly Targets · Mon–Sun
                    </span>
                    <span
                        v-if="intensityWeeklySummary.goalCount > 0"
                        class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ intensityWeeklySummary.hitCount }} of
                        {{ intensityWeeklySummary.goalCount }}
                        {{
                            intensityWeeklySummary.goalCount === 1
                                ? 'week'
                                : 'weeks'
                        }}
                        hit
                    </span>
                </div>

                <div
                    v-if="intensityWeeklyBuckets.length <= 6"
                    :class="intensityWeeklyGridClass"
                >
                    <div
                        v-for="week in intensityWeeklyBuckets"
                        :key="week.weekStart"
                        class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5] p-3 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="truncate font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                {{ week.label }}
                            </span>
                            <span
                                v-if="week.pct !== null"
                                class="shrink-0 rounded-full px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    week.hit
                                        ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                        : 'bg-amber-500/15 text-amber-600 dark:text-amber-400'
                                "
                            >
                                {{ week.pct }}%
                            </span>
                        </div>

                        <div
                            class="mt-1.5 flex items-baseline gap-1 tabular-nums"
                        >
                            <span
                                class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                {{ week.total.toLocaleString() }}
                            </span>
                            <span
                                v-if="week.goal !== null"
                                class="font-mono text-xs text-zinc-400 dark:text-zinc-500"
                            >
                                / {{ week.goal.toLocaleString() }} min
                            </span>
                            <span
                                v-else
                                class="font-mono text-xs text-zinc-400 dark:text-zinc-500"
                            >
                                min
                            </span>
                        </div>

                        <div
                            v-if="week.goal !== null && week.goal > 0"
                            class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :class="
                                    week.hit ? 'bg-emerald-500' : 'bg-amber-500'
                                "
                                :style="{
                                    width: `${Math.min(100, week.pct ?? 0)}%`,
                                }"
                            />
                        </div>

                        <div
                            class="mt-2 truncate font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            Mod {{ week.moderate.toLocaleString() }} · Vig
                            {{ week.vigorous.toLocaleString() }}
                        </div>
                    </div>
                </div>

                <BaseChart
                    v-else
                    :option="intensityWeeklyStripChartOption"
                    :height="150"
                />
            </div>
        </section>

        <!-- Daily Data Table -->
        <section
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex items-center justify-between border-b border-zinc-200/80 px-5 py-3.5 dark:border-zinc-800/80"
            >
                <span
                    class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ isWeeklyIntensity ? 'Weekly Log' : 'Daily Log' }}
                </span>
                <span
                    class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                >
                    {{ rows.length }}
                    {{ rows.length === 1 ? 'record' : 'records' }}
                </span>
            </div>

            <div
                v-if="rows.length > 0 && columns.length > 0"
                class="overflow-x-auto"
            >
                <table class="w-full text-left text-sm tabular-nums">
                    <thead
                        class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                    >
                        <tr>
                            <th
                                v-for="(col, colIdx) in columns"
                                :key="col.key"
                                class="px-5 py-2.5 font-medium whitespace-nowrap"
                                :class="
                                    colIdx === 0 ? 'text-left' : 'text-right'
                                "
                            >
                                {{ col.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-zinc-100 dark:divide-zinc-800/80"
                    >
                        <tr
                            v-for="(row, rowIdx) in rows"
                            :key="`${row.date ?? rowIdx}-${rowIdx}`"
                            class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                        >
                            <td
                                v-for="(col, colIdx) in columns"
                                :key="col.key"
                                class="px-5 py-2.5 font-mono text-xs whitespace-nowrap"
                                :class="
                                    colIdx === 0
                                        ? 'text-left font-medium text-zinc-900 dark:text-zinc-100'
                                        : 'text-right text-zinc-600 dark:text-zinc-300'
                                "
                            >
                                {{ formatCellValue(row[col.key]) }}
                                <span
                                    v-if="colIdx === 0 && row.dayNote"
                                    class="ml-1.5 inline-flex items-center rounded bg-amber-500/12 px-1.5 py-0.5 font-mono text-[10px] font-medium text-amber-700 dark:text-amber-400"
                                >
                                    {{ row.dayNote }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-else
                class="px-5 py-10 text-center font-mono text-xs text-zinc-400 dark:text-zinc-500"
            >
                No daily entries available for this timeframe.
            </div>
        </section>
    </div>
</template>
