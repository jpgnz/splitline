<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    ArrowUpRight,
    CalendarClock,
    Gauge,
    Layers,
    RotateCcw,
    Sparkles,
    TrendingUp,
} from '@lucide/vue';
import type {
    EChartsOption,
    SeriesOption,
    YAXisComponentOption,
} from 'echarts';
import { computed, ref } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import DateRangeControl, {
    type DateRangePreset,
} from '@/components/DateRangeControl.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import { show as showActivity } from '@/routes/activities';
import { index as fitnessIndex } from '@/routes/fitness';
import type {
    FitnessPageProps,
    HealthOverlayKey,
    LoadSource,
    ProjectionMode,
    StatusBadge,
} from '@/types/fitness';

const props = defineProps<FitnessPageProps>();

const theme = useChartTheme();
const { getSportMeta, sportBadgeClasses } = useSports();
const { formatDistanceCompact } = useUnits();

const projectionMode = ref<ProjectionMode>('rest');
const healthOverlay = ref<HealthOverlayKey>('none');
const acwrView = ref<'acwr' | 'ramp'>('acwr');

const RANGE_PRESETS: DateRangePreset[] = [
    { value: '90d', label: '90d' },
    { value: '180d', label: '180d' },
    { value: '1y', label: '1y' },
    { value: 'all', label: 'All' },
];

const PROJECTION_MODES: Array<{ value: ProjectionMode; label: string }> = [
    { value: 'rest', label: 'Rest (0 load)' },
    { value: 'maintenance', label: 'Taper (50% load)' },
    { value: 'off', label: 'Off' },
];

const PROJECTION_HORIZONS: number[] = [7, 14, 21];

const HEALTH_OVERLAYS: Array<{
    value: HealthOverlayKey;
    label: string;
    unit: string;
    color: string;
}> = [
    { value: 'none', label: 'None', unit: '', color: '#71717a' },
    { value: 'hrv', label: 'HRV', unit: 'ms', color: '#8b5cf6' },
    { value: 'resting_hr', label: 'Resting HR', unit: 'bpm', color: '#f43f5e' },
    {
        value: 'sleep_score',
        label: 'Sleep Score',
        unit: 'pts',
        color: '#6366f1',
    },
];

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

function isSportActive(value: string): boolean {
    if (value === 'all') {
        return !props.filters.sport || props.filters.sport === 'all';
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
    projection_days?: number;
}

function buildQuery(
    overrides: QueryOverrides = {},
): Record<string, string | number> {
    const nextSport = overrides.sport ?? props.filters.sport;
    const nextRange = overrides.range ?? props.filters.range;
    const nextProjectionDays =
        overrides.projection_days ?? props.filters.projection_days;
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

    const query: Record<string, string | number> = {};

    if (nextSport && nextSport !== 'all') {
        query.sport = nextSport;
    }

    if (nextRange && nextRange !== '180d') {
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

    if (nextProjectionDays && nextProjectionDays !== 14) {
        query.projection_days = nextProjectionDays;
    }

    return query;
}

function navigate(overrides: QueryOverrides): void {
    router.get(fitnessIndex.url(), buildQuery(overrides), {
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

function selectProjectionDays(days: number): void {
    if (days === props.filters.projection_days) {
        return;
    }

    navigate({ projection_days: days });
}

function resetFilters(): void {
    router.get(
        fitnessIndex.url(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

const hasActiveFilter = computed<boolean>(
    () =>
        props.filters.sport !== 'all' ||
        props.filters.range !== '180d' ||
        !props.dateWindow.isLatest,
);

function toneBadgeClasses(tone: StatusBadge['tone']): string {
    switch (tone) {
        case 'emerald':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
        case 'sky':
            return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300';
        case 'amber':
            return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';
        case 'rose':
            return 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300';
        default:
            return 'border-zinc-300/80 bg-zinc-100 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-300';
    }
}

function loadSourceBadge(source: LoadSource): {
    label: string;
    classes: string;
} {
    switch (source) {
        case 'tss':
            return {
                label: 'TSS',
                classes:
                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            };
        case 'garmin_load':
            return {
                label: 'Garmin Load',
                classes:
                    'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
            };
        case 'trimp':
            return {
                label: 'TRIMP',
                classes:
                    'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
            };
    }
}

function formatSigned(value: number, decimals = 1): string {
    const prefix = value > 0 ? '+' : '';

    return `${prefix}${formatNumber(value, decimals)}`;
}

const rampToneClass = computed<string>(() => {
    const ramp = props.summary.ramp_rate_7d;

    if (ramp > 7) {
        return 'text-rose-600 dark:text-rose-400';
    }

    if (ramp > 5) {
        return 'text-amber-600 dark:text-amber-400';
    }

    if (ramp >= 1) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    return 'text-zinc-500 dark:text-zinc-400';
});

const activeProjectionPoints = computed(() => {
    if (projectionMode.value === 'off') {
        return [];
    }

    return projectionMode.value === 'maintenance'
        ? props.projections.maintenance
        : props.projections.rest;
});

const activeProjectedPeakDate = computed<string | null>(() => {
    if (projectionMode.value === 'off') {
        return props.summary.projected_peak_date;
    }

    return projectionMode.value === 'maintenance'
        ? props.summary.projected_peak_dates.maintenance
        : props.summary.projected_peak_dates.rest;
});

function overlayHasData(key: HealthOverlayKey): boolean {
    if (key === 'none') {
        return true;
    }

    return props.health_availability[key];
}

const activeOverlayMeta = computed(
    () =>
        HEALTH_OVERLAYS.find((item) => item.value === healthOverlay.value) ??
        HEALTH_OVERLAYS[0],
);

const pmcChartOption = computed<EChartsOption>(() => {
    const history = props.daily;
    const projected = activeProjectionPoints.value;
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    const historyLen = history.length;
    const allDates = [
        ...history.map((d) => d.date),
        ...projected.map((p) => p.date),
    ];
    const xLabels = allDates.map((d) => formatShortDate(d));
    const totalLen = allDates.length;

    const lastHistoryPoint =
        historyLen > 0 ? history[historyLen - 1] : undefined;

    const loadSeriesData: Array<number | null> = [
        ...history.map((d) => (d.load > 0 ? d.load : null)),
        ...projected.map((p) => (p.load > 0 ? p.load : null)),
    ];

    const ctlHistoryData: Array<number | null> = [
        ...history.map((d) => d.ctl),
        ...projected.map(() => null),
    ];
    const atlHistoryData: Array<number | null> = [
        ...history.map((d) => d.atl),
        ...projected.map(() => null),
    ];
    const tsbHistoryData: Array<number | null> = [
        ...history.map((d) => d.tsb),
        ...projected.map(() => null),
    ];

    // Connect dashed projection curves seamlessly from the final historical point
    const padBeforeProjection: null[] =
        historyLen > 1
            ? Array.from({ length: historyLen - 1 }, () => null)
            : [];
    const ctlProjectionData: Array<number | null> =
        projected.length > 0 && lastHistoryPoint
            ? [
                  ...padBeforeProjection,
                  lastHistoryPoint.ctl,
                  ...projected.map((p) => p.ctl),
              ]
            : [];
    const atlProjectionData: Array<number | null> =
        projected.length > 0 && lastHistoryPoint
            ? [
                  ...padBeforeProjection,
                  lastHistoryPoint.atl,
                  ...projected.map((p) => p.atl),
              ]
            : [];
    const tsbProjectionData: Array<number | null> =
        projected.length > 0 && lastHistoryPoint
            ? [
                  ...padBeforeProjection,
                  lastHistoryPoint.tsb,
                  ...projected.map((p) => p.tsb),
              ]
            : [];

    const hasOverlay =
        healthOverlay.value !== 'none' && overlayHasData(healthOverlay.value);

    const overlayData: Array<number | null> = hasOverlay
        ? [
              ...history.map((d) => {
                  if (healthOverlay.value === 'hrv') {
                      return d.hrv;
                  }

                  if (healthOverlay.value === 'resting_hr') {
                      return d.resting_hr;
                  }

                  if (healthOverlay.value === 'sleep_score') {
                      return d.sleep_score;
                  }

                  return null;
              }),
              ...projected.map(() => null),
          ]
        : [];

    const yAxes: YAXisComponentOption[] = [
        {
            type: 'value',
            name: 'CTL / ATL / Load',
            nameGap: 14,
            nameTextStyle: { ...axisLabelStyle, align: 'left' },
            min: 0,
            axisLabel: axisLabelStyle,
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        {
            type: 'value',
            name: 'Form (TSB)',
            nameGap: 14,
            nameTextStyle: { ...axisLabelStyle, align: 'right' },
            axisLabel: axisLabelStyle,
            splitLine: { show: false },
        },
    ];

    if (hasOverlay) {
        yAxes.push({
            type: 'value',
            position: 'right',
            offset: 54,
            scale: true,
            name: `${activeOverlayMeta.value.label} (${activeOverlayMeta.value.unit})`,
            nameGap: 14,
            nameTextStyle: {
                color: activeOverlayMeta.value.color,
                fontFamily: monoFont,
                fontSize: 10,
                align: 'right',
            },
            axisLabel: {
                color: activeOverlayMeta.value.color,
                fontFamily: monoFont,
                fontSize: 10,
            },
            splitLine: { show: false },
        });
    }

    const series: SeriesOption[] = [
        {
            name: 'Daily Load',
            type: 'bar',
            yAxisIndex: 0,
            barMaxWidth: 7,
            z: 1,
            itemStyle: {
                color: theme.isDark.value
                    ? 'rgba(161, 161, 170, 0.22)'
                    : 'rgba(113, 113, 122, 0.18)',
                borderRadius: [2, 2, 0, 0],
            },
            data: loadSeriesData,
        },
        {
            name: 'Form (TSB)',
            type: 'line',
            yAxisIndex: 1,
            smooth: 0.2,
            showSymbol: false,
            z: 2,
            lineStyle: { color: '#0ea5e9', width: 1.5 },
            itemStyle: { color: '#0ea5e9' },
            areaStyle: {
                color: theme.isDark.value
                    ? 'rgba(14, 165, 233, 0.14)'
                    : 'rgba(14, 165, 233, 0.12)',
            },
            markArea: {
                silent: true,
                itemStyle: { borderWidth: 0 },
                data: [
                    [
                        {
                            yAxis: 5,
                            itemStyle: {
                                color: theme.isDark.value
                                    ? 'rgba(14, 165, 233, 0.08)'
                                    : 'rgba(14, 165, 233, 0.06)',
                            },
                        },
                        { yAxis: 25 },
                    ],
                    [
                        {
                            yAxis: -30,
                            itemStyle: {
                                color: theme.isDark.value
                                    ? 'rgba(16, 185, 129, 0.08)'
                                    : 'rgba(16, 185, 129, 0.06)',
                            },
                        },
                        { yAxis: -10 },
                    ],
                ],
            },
            markLine: {
                silent: true,
                symbol: ['none', 'none'],
                label: { show: false },
                lineStyle: {
                    color: theme.gridLineColor.value,
                    type: 'dashed',
                    width: 1,
                },
                data: [{ yAxis: 0 }],
            },
            data: tsbHistoryData,
        },
        {
            name: 'Fitness (CTL)',
            type: 'line',
            yAxisIndex: 0,
            smooth: 0.25,
            showSymbol: false,
            z: 4,
            lineStyle: { color: '#10b981', width: 2.25 },
            itemStyle: { color: '#10b981' },
            markLine:
                projected.length > 0 && historyLen > 0
                    ? {
                          silent: true,
                          symbol: ['none', 'none'],
                          lineStyle: {
                              color: theme.mutedColor.value,
                              type: 'dashed',
                              width: 1,
                          },
                          label: {
                              formatter: 'Projection →',
                              color: theme.mutedColor.value,
                              fontFamily: monoFont,
                              fontSize: 10,
                              position: 'insideEndTop',
                          },
                          data: [{ xAxis: historyLen - 1 }],
                      }
                    : undefined,
            data: ctlHistoryData,
        },
        {
            name: 'Fatigue (ATL)',
            type: 'line',
            yAxisIndex: 0,
            smooth: 0.25,
            showSymbol: false,
            z: 3,
            lineStyle: { color: '#f59e0b', width: 1.85 },
            itemStyle: { color: '#f59e0b' },
            data: atlHistoryData,
        },
    ];

    if (projected.length > 0 && totalLen > historyLen) {
        series.push(
            {
                name: 'Projected CTL',
                type: 'line',
                yAxisIndex: 0,
                smooth: 0.2,
                showSymbol: false,
                z: 4,
                lineStyle: { color: '#10b981', width: 1.75, type: 'dashed' },
                itemStyle: { color: '#10b981' },
                data: ctlProjectionData,
            },
            {
                name: 'Projected ATL',
                type: 'line',
                yAxisIndex: 0,
                smooth: 0.2,
                showSymbol: false,
                z: 3,
                lineStyle: { color: '#f59e0b', width: 1.5, type: 'dashed' },
                itemStyle: { color: '#f59e0b' },
                data: atlProjectionData,
            },
            {
                name: 'Projected TSB',
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.2,
                showSymbol: false,
                z: 2,
                lineStyle: { color: '#0ea5e9', width: 1.5, type: 'dashed' },
                itemStyle: { color: '#0ea5e9' },
                data: tsbProjectionData,
            },
        );
    }

    if (hasOverlay) {
        series.push({
            name: activeOverlayMeta.value.label,
            type: 'line',
            yAxisIndex: 2,
            connectNulls: true,
            smooth: 0.2,
            symbol: 'circle',
            symbolSize: 4,
            showSymbol: overlayData.filter((v) => v !== null).length <= 45,
            z: 5,
            lineStyle: {
                color: activeOverlayMeta.value.color,
                width: 1.5,
                type: 'dotted',
            },
            itemStyle: { color: activeOverlayMeta.value.color },
            data: overlayData,
        });
    }

    return {
        animation: false,
        grid: {
            left: 12,
            right: hasOverlay ? 72 : 16,
            top: 46,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            data: [
                'Fitness (CTL)',
                'Fatigue (ATL)',
                'Form (TSB)',
                'Daily Load',
                ...(hasOverlay ? [activeOverlayMeta.value.label] : []),
            ],
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: { color: theme.mutedColor.value, type: 'dashed' },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const idx = first?.dataIndex;

                if (idx === undefined || idx < 0 || idx >= allDates.length) {
                    return '';
                }

                if (idx < historyLen) {
                    const day = history[idx];
                    const lines = [
                        `<strong>${formatShortDate(day.date)}</strong>`,
                        `<span style="color:#10b981">●</span> Fitness (CTL): ${formatNumber(day.ctl, 1)}`,
                        `<span style="color:#f59e0b">●</span> Fatigue (ATL): ${formatNumber(day.atl, 1)}`,
                        `<span style="color:#0ea5e9">●</span> Form (TSB): ${formatSigned(day.tsb, 1)}`,
                        `Daily Load: ${formatNumber(day.load, 1)}${day.activity_count > 0 ? ` (${day.activity_count} ${day.activity_count === 1 ? 'session' : 'sessions'})` : ''}`,
                    ];

                    if (day.acwr !== null) {
                        lines.push(`ACWR: ${formatNumber(day.acwr, 2)}`);
                    }

                    lines.push(`7d Ramp: ${formatSigned(day.ramp_rate, 1)}`);

                    if (hasOverlay) {
                        const rawVal =
                            healthOverlay.value === 'hrv'
                                ? day.hrv
                                : healthOverlay.value === 'resting_hr'
                                  ? day.resting_hr
                                  : day.sleep_score;

                        if (rawVal !== null) {
                            lines.push(
                                `<span style="color:${activeOverlayMeta.value.color}">●</span> ${activeOverlayMeta.value.label}: ${formatNumber(rawVal, 0)} ${activeOverlayMeta.value.unit}`,
                            );
                        }
                    }

                    return lines.join('<br/>');
                }

                const proj = projected[idx - historyLen];

                if (!proj) {
                    return '';
                }

                const modeLabel =
                    projectionMode.value === 'maintenance'
                        ? 'Taper 50%'
                        : 'Rest 0 load';

                return [
                    `<strong>${formatShortDate(proj.date)}</strong> <span style="opacity:0.7">(${modeLabel})</span>`,
                    `<span style="color:#10b981">●</span> Projected CTL: ${formatNumber(proj.ctl, 1)}`,
                    `<span style="color:#f59e0b">●</span> Projected ATL: ${formatNumber(proj.atl, 1)}`,
                    `<span style="color:#0ea5e9">●</span> Projected TSB: ${formatSigned(proj.tsb, 1)}`,
                    `Assumed Load: ${formatNumber(proj.load, 1)} / day`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: xLabels,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: yAxes,
        series,
    };
});

const acwrChartOption = computed<EChartsOption>(() => {
    const items = props.daily;
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    if (acwrView.value === 'ramp') {
        return {
            animation: false,
            grid: {
                left: 12,
                right: 16,
                top: 32,
                bottom: 12,
                containLabel: true,
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                formatter: (params: unknown) => {
                    const list = Array.isArray(params) ? params : [params];
                    const first = list[0] as { dataIndex?: number } | undefined;
                    const day =
                        first?.dataIndex !== undefined
                            ? items[first.dataIndex]
                            : undefined;

                    if (!day) {
                        return '';
                    }

                    return [
                        `<strong>${formatShortDate(day.date)}</strong>`,
                        `7d CTL Ramp: ${formatSigned(day.ramp_rate, 1)}`,
                        `Fitness (CTL): ${formatNumber(day.ctl, 1)}`,
                    ].join('<br/>');
                },
            },
            xAxis: {
                type: 'category',
                data: items.map((i) => formatShortDate(i.date)),
                axisLine: { lineStyle: { color: theme.gridLineColor.value } },
                axisTick: { show: false },
                axisLabel: axisLabelStyle,
            },
            yAxis: {
                type: 'value',
                name: 'CTL Δ / 7d',
                nameGap: 12,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            series: [
                {
                    name: '7d Ramp Rate',
                    type: 'bar',
                    barMaxWidth: 8,
                    markLine: {
                        silent: true,
                        symbol: ['none', 'none'],
                        label: {
                            color: theme.mutedColor.value,
                            fontFamily: monoFont,
                            fontSize: 10,
                            formatter: '+5 / +7',
                        },
                        lineStyle: {
                            type: 'dashed',
                            color: '#f59e0b',
                            width: 1,
                        },
                        data: [{ yAxis: 5 }, { yAxis: 7 }],
                    },
                    data: items.map((i) => ({
                        value: i.ramp_rate,
                        itemStyle: {
                            color:
                                i.ramp_rate > 7
                                    ? '#f43f5e'
                                    : i.ramp_rate > 5
                                      ? '#f59e0b'
                                      : i.ramp_rate >= 0
                                        ? '#10b981'
                                        : '#71717a',
                            borderRadius:
                                i.ramp_rate >= 0 ? [2, 2, 0, 0] : [0, 0, 2, 2],
                        },
                    })),
                },
            ],
        };
    }

    const validAcwrValues = items
        .map((i) => i.acwr)
        .filter((v): v is number => v !== null);
    const maxAcwr = Math.max(
        1.8,
        ...(validAcwrValues.length > 0 ? validAcwrValues : [1.6]),
    );
    const yMax = Math.ceil((maxAcwr + 0.1) * 10) / 10;

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 32,
            bottom: 12,
            containLabel: true,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: { color: theme.mutedColor.value, type: 'dashed' },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const day =
                    first?.dataIndex !== undefined
                        ? items[first.dataIndex]
                        : undefined;

                if (!day) {
                    return '';
                }

                return [
                    `<strong>${formatShortDate(day.date)}</strong>`,
                    `ACWR: ${day.acwr !== null ? formatNumber(day.acwr, 2) : '—'}`,
                    `ATL / CTL: ${formatNumber(day.atl, 1)} / ${formatNumber(day.ctl, 1)}`,
                    `7d Ramp: ${formatSigned(day.ramp_rate, 1)}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: items.map((i) => formatShortDate(i.date)),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: {
            type: 'value',
            min: 0,
            max: yMax,
            name: 'ATL / CTL',
            nameGap: 12,
            nameTextStyle: { ...axisLabelStyle, align: 'left' },
            axisLabel: axisLabelStyle,
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'ACWR',
                type: 'line',
                smooth: 0.25,
                showSymbol: false,
                connectNulls: false,
                lineStyle: {
                    color: theme.isDark.value ? '#e4e4e7' : '#18181b',
                    width: 2,
                },
                itemStyle: {
                    color: theme.isDark.value ? '#e4e4e7' : '#18181b',
                },
                markArea: {
                    silent: true,
                    data: [
                        [
                            {
                                yAxis: 0.8,
                                itemStyle: {
                                    color: theme.isDark.value
                                        ? 'rgba(16, 185, 129, 0.12)'
                                        : 'rgba(16, 185, 129, 0.1)',
                                },
                            },
                            { yAxis: 1.3 },
                        ],
                        [
                            {
                                yAxis: 1.3,
                                itemStyle: {
                                    color: theme.isDark.value
                                        ? 'rgba(245, 158, 11, 0.1)'
                                        : 'rgba(245, 158, 11, 0.08)',
                                },
                            },
                            { yAxis: 1.5 },
                        ],
                        [
                            {
                                yAxis: 1.5,
                                itemStyle: {
                                    color: theme.isDark.value
                                        ? 'rgba(244, 63, 94, 0.12)'
                                        : 'rgba(244, 63, 94, 0.09)',
                                },
                            },
                            { yAxis: yMax },
                        ],
                    ],
                },
                markLine: {
                    silent: true,
                    symbol: ['none', 'none'],
                    label: {
                        color: theme.mutedColor.value,
                        fontFamily: monoFont,
                        fontSize: 10,
                        position: 'insideEndTop',
                    },
                    lineStyle: { type: 'dashed', width: 1 },
                    data: [
                        { yAxis: 0.8, lineStyle: { color: '#10b981' } },
                        { yAxis: 1.3, lineStyle: { color: '#f59e0b' } },
                        { yAxis: 1.5, lineStyle: { color: '#f43f5e' } },
                    ],
                },
                data: items.map((i) => i.acwr),
            },
        ],
    };
});

const weeklySportChartOption = computed<EChartsOption>(() => {
    const weeks = props.weekly_sport_load;
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
            top: 38,
            bottom: 12,
            containLabel: true,
        },
        legend: {
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
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const week =
                    first?.dataIndex !== undefined
                        ? weeks[first.dataIndex]
                        : undefined;

                if (!week) {
                    return '';
                }

                return [
                    `<strong>Week of ${week.label}</strong>`,
                    `Total Load: ${formatNumber(week.total, 0)} (${week.activity_count} ${week.activity_count === 1 ? 'session' : 'sessions'})`,
                    `<span style="color:#10b981">●</span> Running: ${formatNumber(week.running, 0)}`,
                    `<span style="color:#0ea5e9">●</span> Cycling: ${formatNumber(week.cycling, 0)}`,
                    `<span style="color:#8b5cf6">●</span> Other: ${formatNumber(week.other, 0)}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: weeks.map((w) => w.label),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: {
            type: 'value',
            name: 'Weekly Load',
            nameGap: 12,
            nameTextStyle: { ...axisLabelStyle, align: 'left' },
            axisLabel: axisLabelStyle,
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'Running',
                type: 'bar',
                stack: 'sport',
                barMaxWidth: 24,
                itemStyle: { color: '#10b981' },
                data: weeks.map((w) => w.running),
            },
            {
                name: 'Cycling',
                type: 'bar',
                stack: 'sport',
                barMaxWidth: 24,
                itemStyle: { color: '#0ea5e9' },
                data: weeks.map((w) => w.cycling),
            },
            {
                name: 'Other',
                type: 'bar',
                stack: 'sport',
                barMaxWidth: 24,
                itemStyle: {
                    color: '#8b5cf6',
                    borderRadius: [3, 3, 0, 0],
                },
                data: weeks.map((w) => w.other),
            },
        ],
    };
});
</script>

<template>
    <div class="space-y-6">
        <Head title="Training Load & Form" />

        <!-- Page Header -->
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex size-2 rounded-full bg-emerald-500"
                    />
                    <span
                        class="font-mono text-[10px] font-medium tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Performance Management
                    </span>
                </div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-950 dark:text-zinc-50"
                >
                    Training Load &amp; Form
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Unified cross-sport CTL, ATL, TSB, acute:chronic workload
                    ratio, and taper projections.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <DateRangeControl
                    :presets="RANGE_PRESETS"
                    :window="dateWindow"
                    surface="page"
                    popover-align="right"
                    :max-date="today"
                    aria-label="Training load date range"
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
                aria-label="Filter training load by sport"
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

        <!-- Empty State -->
        <section
            v-if="!has_data"
            class="rounded-xl border border-dashed border-zinc-300/90 bg-white p-10 text-center dark:border-zinc-800 dark:bg-[#141417]"
        >
            <div
                class="mx-auto flex size-11 items-center justify-center rounded-xl border border-zinc-200/90 bg-zinc-50 text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400"
            >
                <Activity class="size-5" />
            </div>
            <h2
                class="mt-4 text-base font-semibold text-zinc-900 dark:text-zinc-100"
            >
                No training load data in this window
            </h2>
            <p
                class="mx-auto mt-1.5 max-w-md text-sm text-zinc-500 dark:text-zinc-400"
            >
                Sync activities with power TSS, Garmin Exercise Load, or heart
                rate zones — or widen your sport and date filters — to build
                your Performance Management Chart.
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

        <!-- Main Analytics Content -->
        <template v-else>
            <!-- KPI Strip -->
            <section
                class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5"
            >
                <!-- Fitness (CTL) -->
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
                                Fitness (CTL)
                            </p>
                        </div>
                        <TrendingUp
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <p
                        class="mt-2 font-mono text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                    >
                        {{ formatNumber(summary.ctl, 1) }}
                    </p>
                    <p
                        class="mt-1 font-mono text-[11px] tabular-nums"
                        :class="rampToneClass"
                    >
                        {{ formatSigned(summary.ramp_rate_7d, 1) }} / 7d ramp
                    </p>
                </div>

                <!-- Fatigue (ATL) -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-amber-500" />
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Fatigue (ATL)
                            </p>
                        </div>
                        <Activity
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <p
                        class="mt-2 font-mono text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                    >
                        {{ formatNumber(summary.atl, 1) }}
                    </p>
                    <p
                        class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        7-day acute load EWMA
                    </p>
                </div>

                <!-- Form (TSB) -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-sky-500" />
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Form (TSB)
                            </p>
                        </div>
                        <Sparkles
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <p
                        class="mt-2 font-mono text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                    >
                        {{ formatSigned(summary.tsb, 1) }}
                    </p>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <span
                            class="inline-flex items-center rounded-md border px-1.5 py-0.5 font-mono text-[10px] font-medium"
                            :class="toneBadgeClasses(summary.form_zone.tone)"
                        >
                            {{ summary.form_zone.label }}
                        </span>
                    </div>
                </div>

                <!-- ACWR -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-indigo-500" />
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                ACWR
                            </p>
                        </div>
                        <Gauge
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <p
                        class="mt-2 font-mono text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                    >
                        {{
                            summary.acwr !== null
                                ? formatNumber(summary.acwr, 2)
                                : '—'
                        }}
                    </p>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <span
                            class="inline-flex items-center rounded-md border px-1.5 py-0.5 font-mono text-[10px] font-medium"
                            :class="toneBadgeClasses(summary.acwr_status.tone)"
                        >
                            {{ summary.acwr_status.label }}
                        </span>
                    </div>
                </div>

                <!-- 7-Day Load -->
                <div
                    class="col-span-2 rounded-xl border border-zinc-200/90 bg-white p-4 md:col-span-1 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="size-1.5 rounded-full bg-violet-500" />
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                7-Day Load
                            </p>
                        </div>
                        <Layers
                            class="size-3.5 text-zinc-400 dark:text-zinc-500"
                        />
                    </div>
                    <p
                        class="mt-2 font-mono text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                    >
                        {{ formatNumber(summary.load_7d, 0) }}
                    </p>
                    <p
                        class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        28d: {{ formatNumber(summary.load_28d, 0) }}
                    </p>
                </div>
            </section>

            <!-- Main Performance Management Chart -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-col gap-3 border-b border-zinc-100 pb-4 xl:flex-row xl:items-center xl:justify-between dark:border-zinc-800/80"
                >
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                            >
                                Performance Management Chart
                            </h2>
                            <span
                                v-if="
                                    projectionMode !== 'off' &&
                                    activeProjectedPeakDate
                                "
                                class="inline-flex items-center gap-1.5 rounded-md border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 font-mono text-[10px] font-medium text-sky-700 dark:text-sky-300"
                            >
                                <CalendarClock class="size-3" />
                                <span>
                                    Peak Form (TSB ≥ +5):
                                    {{
                                        formatShortDate(activeProjectedPeakDate)
                                    }}
                                </span>
                            </span>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ summary.form_zone.description }}
                            <span v-if="projectionMode === 'maintenance'">
                                Taper assumes
                                {{
                                    formatNumber(
                                        summary.maintenance_daily_load,
                                        0,
                                    )
                                }}
                                load/day (50% of 28d avg).
                            </span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5">
                        <!-- Projection Mode Toggle -->
                        <div class="flex items-center gap-1.5">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                            >
                                Taper
                            </span>
                            <div
                                class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                                role="group"
                                aria-label="Taper projection scenario"
                            >
                                <button
                                    v-for="mode in PROJECTION_MODES"
                                    :key="mode.value"
                                    type="button"
                                    class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] font-medium whitespace-nowrap transition-colors"
                                    :class="
                                        projectionMode === mode.value
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="projectionMode = mode.value"
                                >
                                    {{ mode.label }}
                                </button>
                            </div>

                            <div
                                v-if="projectionMode !== 'off'"
                                class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                                role="group"
                                aria-label="Projection horizon"
                            >
                                <button
                                    v-for="days in PROJECTION_HORIZONS"
                                    :key="days"
                                    type="button"
                                    class="cursor-pointer rounded-md px-1.5 py-1 font-mono text-[10px] font-medium transition-colors"
                                    :class="
                                        filters.projection_days === days
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="selectProjectionDays(days)"
                                >
                                    +{{ days }}d
                                </button>
                            </div>
                        </div>

                        <!-- Health Overlay Toggle -->
                        <div class="flex items-center gap-1.5">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                            >
                                Overlay
                            </span>
                            <div
                                class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                                role="group"
                                aria-label="Health telemetry overlay"
                            >
                                <button
                                    v-for="overlay in HEALTH_OVERLAYS"
                                    :key="overlay.value"
                                    type="button"
                                    :disabled="!overlayHasData(overlay.value)"
                                    :title="
                                        overlayHasData(overlay.value)
                                            ? `Overlay ${overlay.label}`
                                            : `No ${overlay.label} data in this window`
                                    "
                                    class="rounded-md px-2 py-1 font-mono text-[11px] font-medium whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                                    :class="
                                        healthOverlay === overlay.value
                                            ? 'cursor-pointer bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                            : overlayHasData(overlay.value)
                                              ? 'cursor-pointer text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                              : 'text-zinc-400 dark:text-zinc-600'
                                    "
                                    @click="healthOverlay = overlay.value"
                                >
                                    {{ overlay.label }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <BaseChart :option="pmcChartOption" :height="360" />
                </div>

                <!-- Reference Band Legend -->
                <div
                    class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-zinc-100 pt-3 font-mono text-[10px] text-zinc-500 dark:border-zinc-800/80 dark:text-zinc-400"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="size-2 rounded-xs bg-sky-500/25 ring-1 ring-sky-500/40"
                        />
                        Fresh / Race Ready (+5 to +25 TSB)
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="size-2 rounded-xs bg-emerald-500/25 ring-1 ring-emerald-500/40"
                        />
                        Optimal Training (-30 to -10 TSB)
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="size-2 rounded-xs bg-rose-500/25 ring-1 ring-rose-500/40"
                        />
                        High Risk (&lt; -30 TSB)
                    </span>
                </div>
            </section>

            <!-- 2-Column Analytical Grid: ACWR & Ramp Rate + Weekly Cross-Sport Load -->
            <section class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <!-- ACWR & Ramp Rate Panel -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 pb-3.5 dark:border-zinc-800/80"
                    >
                        <div class="space-y-0.5">
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                            >
                                ACWR &amp; Ramp Rate
                            </h2>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Sweet spot 0.8–1.3 · Caution 1.3–1.5 · High risk
                                &gt; 1.5
                            </p>
                        </div>

                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            role="group"
                            aria-label="Workload ratio chart mode"
                        >
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    acwrView === 'acwr'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="acwrView = 'acwr'"
                            >
                                ACWR
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    acwrView === 'ramp'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="acwrView = 'ramp'"
                            >
                                7d Ramp
                            </button>
                        </div>
                    </div>

                    <div class="mt-4">
                        <BaseChart :option="acwrChartOption" :height="250" />
                    </div>
                </div>

                <!-- Weekly Cross-Sport Load Breakdown -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 pb-3.5 dark:border-zinc-800/80"
                    >
                        <div class="space-y-0.5">
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                            >
                                12-Week Cross-Sport Load
                            </h2>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Weekly training stress stacked by primary sport
                                bucket.
                            </p>
                        </div>

                        <!-- Load Source Mix Pill -->
                        <div
                            v-if="summary.load_source_mix.total > 0"
                            class="inline-flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200/90 bg-[#f7f7f5] px-2.5 py-1 font-mono text-[10px] text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300"
                            title="Share of sessions in window by load calculation source"
                        >
                            <span class="inline-flex items-center gap-1">
                                <span
                                    class="size-1.5 rounded-full bg-emerald-500"
                                />
                                TSS
                                {{
                                    formatNumber(
                                        summary.load_source_mix.tss_pct,
                                        0,
                                    )
                                }}%
                            </span>
                            <span class="text-zinc-300 dark:text-zinc-700"
                                >·</span
                            >
                            <span class="inline-flex items-center gap-1">
                                <span
                                    class="size-1.5 rounded-full bg-sky-500"
                                />
                                Garmin
                                {{
                                    formatNumber(
                                        summary.load_source_mix.garmin_load_pct,
                                        0,
                                    )
                                }}%
                            </span>
                            <span class="text-zinc-300 dark:text-zinc-700"
                                >·</span
                            >
                            <span class="inline-flex items-center gap-1">
                                <span
                                    class="size-1.5 rounded-full bg-amber-500"
                                />
                                TRIMP
                                {{
                                    formatNumber(
                                        summary.load_source_mix.trimp_pct,
                                        0,
                                    )
                                }}%
                            </span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <BaseChart
                            :option="weeklySportChartOption"
                            :height="250"
                        />
                    </div>
                </div>
            </section>

            <!-- Recent Training Load Sessions Table -->
            <section
                class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800/80"
                >
                    <div>
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-zinc-50"
                        >
                            Recent Training Load Sessions
                        </h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Latest scored sessions in the selected window with
                            transparent load source tracking.
                        </p>
                    </div>
                    <span
                        class="font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ recent_sessions.length }}
                        {{
                            recent_sessions.length === 1
                                ? 'session'
                                : 'sessions'
                        }}
                    </span>
                </div>

                <div
                    v-if="recent_sessions.length === 0"
                    class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400"
                >
                    No scored sessions recorded inside this date window.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-zinc-100 bg-[#fafaf9] font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:text-zinc-400"
                            >
                                <th class="px-5 py-2.5 font-medium">Date</th>
                                <th class="px-4 py-2.5 font-medium">
                                    Activity
                                </th>
                                <th class="px-4 py-2.5 font-medium">Sport</th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Duration
                                </th>
                                <th
                                    class="hidden px-4 py-2.5 text-right font-medium sm:table-cell"
                                >
                                    Distance
                                </th>
                                <th
                                    class="hidden px-4 py-2.5 text-right font-medium md:table-cell"
                                >
                                    Avg HR
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Load
                                </th>
                                <th class="px-5 py-2.5 text-right font-medium">
                                    Source
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-zinc-100 dark:divide-zinc-800/70"
                        >
                            <tr
                                v-for="session in recent_sessions"
                                :key="session.id"
                                class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                            >
                                <td
                                    class="px-5 py-3 font-mono text-xs whitespace-nowrap text-zinc-500 tabular-nums dark:text-zinc-400"
                                >
                                    {{ formatShortDate(session.date) }}
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="showActivity.url(session.id)"
                                        class="group inline-flex items-center gap-1 font-medium text-zinc-900 transition-colors hover:text-emerald-600 dark:text-zinc-100 dark:hover:text-emerald-400"
                                    >
                                        <span class="max-w-[260px] truncate">{{
                                            session.name
                                        }}</span>
                                        <ArrowUpRight
                                            class="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100"
                                        />
                                    </Link>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-mono text-[10px] font-medium"
                                        :class="
                                            sportBadgeClasses(session.sport_key)
                                        "
                                    >
                                        {{ session.sport_label }}
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 tabular-nums dark:text-zinc-300"
                                >
                                    {{
                                        formatDurationClock(
                                            session.duration_seconds,
                                        )
                                    }}
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 tabular-nums sm:table-cell dark:text-zinc-400"
                                >
                                    {{
                                        session.distance_meters > 0
                                            ? formatDistanceCompact(
                                                  session.distance_meters,
                                                  1,
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 tabular-nums md:table-cell dark:text-zinc-400"
                                >
                                    {{
                                        session.avg_hr !== null
                                            ? `${session.avg_hr} bpm`
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-mono text-xs font-semibold whitespace-nowrap text-zinc-950 tabular-nums dark:text-zinc-50"
                                >
                                    {{ formatNumber(session.load, 1) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right whitespace-nowrap"
                                >
                                    <span
                                        class="inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium"
                                        :class="
                                            loadSourceBadge(session.load_source)
                                                .classes
                                        "
                                    >
                                        {{
                                            loadSourceBadge(session.load_source)
                                                .label
                                        }}
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
