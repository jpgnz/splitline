<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Activity, ArrowUpRight, Flame, MapPin } from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed, ref } from 'vue';
import InfoPopover from '@/components/InfoPopover.vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import DateRangeControl, {
    type DateRangePreset,
    type DateWindowMeta,
} from '@/components/DateRangeControl.vue';
import type { CompactGoalProgress } from '@/components/goals/GoalProgressStrip.vue';
import GoalProgressStrip from '@/components/goals/GoalProgressStrip.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatDurationHuman,
    formatNumber,
    formatShortDate,
    formatTimeOfDay,
} from '@/lib/format';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    index as activitiesIndex,
    show as showActivity,
} from '@/routes/activities';

type VolumeRange = '12w' | '6m' | '1y' | 'all' | 'custom';
type ZoneRange = '30d' | '90d' | 'all' | 'custom';
type GeneralVolumeMetric = 'duration' | 'distance' | 'load';

interface ZoneAggregate {
    zone: number;
    label: string;
    seconds: number;
    pct: number;
}

interface GeneralWeeklyVolumePoint {
    week_start: string;
    label: string;
    sessions: number;
    duration_seconds: number;
    distance_meters: number;
    training_load: number;
}

interface GeneralRecentActivity {
    id: number;
    name: string;
    started_at_local: string;
    activity_type_key: string;
    location_name: string | null;
    is_indoor: boolean;
    distance_meters: number;
    duration_seconds: number;
    avg_speed: number | null;
    avg_hr: number | null;
    avg_cadence: number | null;
    calories: number | null;
    training_load: number | null;
}

interface SportsGeneralProps {
    sport: {
        key: string;
        label: string;
        short_label: string;
        href: string;
        color_token: string;
        accent_hex: string;
        metric_mode: 'pace' | 'speed' | 'pace_100m' | 'duration';
        cadence_unit: 'spm' | 'rpm' | 'strokes/min' | null;
    };
    filters: {
        volumeRange: VolumeRange;
        volumeWindow: DateWindowMeta;
        zoneRange: ZoneRange;
        zoneWindow: DateWindowMeta;
    };
    summary: {
        ytd_sessions: number;
        ytd_duration_seconds: number;
        ytd_distance_meters: number;
        ytd_load: number;
        ytd_avg_hr: number | null;
        ytd_avg_speed: number | null;
        total_sessions: number;
        total_duration_seconds: number;
        total_distance_meters: number;
        total_load: number;
        avg_hr: number | null;
        avg_speed: number | null;
    };
    weeklyVolume: GeneralWeeklyVolumePoint[];
    hrZones: {
        range: string;
        total_seconds: number;
        zones: ZoneAggregate[];
        polarization: {
            easy_pct: number;
            moderate_pct: number;
            hard_pct: number;
        };
    };
    streak: {
        current_weeks: number;
        longest_weeks: number;
    };
    goals: CompactGoalProgress[];
    recentActivities: GeneralRecentActivity[];
}

const props = defineProps<SportsGeneralProps>();
const theme = useChartTheme();
const units = useUnits();
const { getSportMeta } = useSports();

const sportMeta = computed(() => getSportMeta(props.sport.key));
const accentHex = computed<string>(
    () => props.sport.accent_hex || sportMeta.value.accent_hex,
);

const volumeMetric = ref<GeneralVolumeMetric>('duration');

const VOLUME_RANGE_OPTIONS: DateRangePreset[] = [
    { value: '12w', label: '12w' },
    { value: '6m', label: '6m' },
    { value: '1y', label: '1y' },
    { value: 'all', label: 'All' },
];

const ZONE_RANGE_OPTIONS: DateRangePreset[] = [
    { value: '30d', label: '30d' },
    { value: '90d', label: '90d' },
    { value: 'all', label: 'All' },
];

const hasDistance = computed<boolean>(
    () =>
        props.summary.ytd_distance_meters > 0 ||
        props.summary.total_distance_meters > 0 ||
        props.weeklyVolume.some((w) => w.distance_meters > 0),
);

const showsPace = computed<boolean>(
    () =>
        props.sport.metric_mode === 'pace' ||
        props.sport.metric_mode === 'pace_100m',
);

const goalAccent = computed<'amber' | 'sky' | 'emerald' | 'indigo'>(() => {
    const token = props.sport.color_token || sportMeta.value.color_token;

    if (token === 'amber' || token === 'orange' || token === 'rose') {
        return 'amber';
    }

    if (token === 'emerald' || token === 'teal' || token === 'lime') {
        return 'emerald';
    }

    if (token === 'sky' || token === 'blue' || token === 'cyan') {
        return 'sky';
    }

    return 'indigo';
});

const volumeMetricOptions = computed<
    Array<{ value: GeneralVolumeMetric; label: string }>
>(() => {
    const opts: Array<{ value: GeneralVolumeMetric; label: string }> = [
        { value: 'duration', label: 'Duration' },
    ];

    if (hasDistance.value) {
        opts.push({ value: 'distance', label: 'Distance' });
    }

    opts.push({ value: 'load', label: 'Load' });

    return opts;
});

function applyFilters(
    overrides: Partial<{
        volumeRange: VolumeRange;
        volumeEnd: string | null;
        volumeFrom: string | null;
        volumeTo: string | null;
        zoneRange: ZoneRange;
        zoneEnd: string | null;
        zoneFrom: string | null;
        zoneTo: string | null;
    }>,
): void {
    const nextVolumeRange = overrides.volumeRange ?? props.filters.volumeRange;
    const nextVolumeEnd =
        overrides.volumeEnd !== undefined
            ? overrides.volumeEnd
            : props.filters.volumeWindow.isLatest
              ? null
              : props.filters.volumeWindow.end;
    const nextVolumeFrom =
        overrides.volumeFrom !== undefined
            ? overrides.volumeFrom
            : props.filters.volumeWindow.from;
    const nextVolumeTo =
        overrides.volumeTo !== undefined
            ? overrides.volumeTo
            : props.filters.volumeWindow.to;

    const nextZoneRange = overrides.zoneRange ?? props.filters.zoneRange;
    const nextZoneEnd =
        overrides.zoneEnd !== undefined
            ? overrides.zoneEnd
            : props.filters.zoneWindow.isLatest
              ? null
              : props.filters.zoneWindow.end;
    const nextZoneFrom =
        overrides.zoneFrom !== undefined
            ? overrides.zoneFrom
            : props.filters.zoneWindow.from;
    const nextZoneTo =
        overrides.zoneTo !== undefined
            ? overrides.zoneTo
            : props.filters.zoneWindow.to;

    const params: Record<string, string> = {
        volumeRange: nextVolumeRange,
        zoneRange: nextZoneRange,
    };

    if (nextVolumeRange === 'custom') {
        if (nextVolumeFrom) {
            params.volumeFrom = nextVolumeFrom;
        }

        if (nextVolumeTo) {
            params.volumeTo = nextVolumeTo;
        }
    } else if (nextVolumeRange !== 'all' && nextVolumeEnd) {
        params.volumeEnd = nextVolumeEnd;
    }

    if (nextZoneRange === 'custom') {
        if (nextZoneFrom) {
            params.zoneFrom = nextZoneFrom;
        }

        if (nextZoneTo) {
            params.zoneTo = nextZoneTo;
        }
    } else if (nextZoneRange !== 'all' && nextZoneEnd) {
        params.zoneEnd = nextZoneEnd;
    }

    router.get(props.sport.href, params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function selectVolumeRange(volumeRange: string): void {
    applyFilters({
        volumeRange: volumeRange as VolumeRange,
        volumeEnd: null,
        volumeFrom: null,
        volumeTo: null,
    });
}

function stepVolumeRange(end: string | null): void {
    applyFilters({
        volumeRange: props.filters.volumeRange,
        volumeEnd: end,
        volumeFrom: null,
        volumeTo: null,
    });
}

function applyCustomVolumeRange(payload: {
    from: string | null;
    to: string | null;
}): void {
    applyFilters({
        volumeRange: 'custom',
        volumeEnd: null,
        volumeFrom: payload.from,
        volumeTo: payload.to,
    });
}

function selectZoneRange(zoneRange: string): void {
    applyFilters({
        zoneRange: zoneRange as ZoneRange,
        zoneEnd: null,
        zoneFrom: null,
        zoneTo: null,
    });
}

function stepZoneRange(end: string | null): void {
    applyFilters({
        zoneRange: props.filters.zoneRange,
        zoneEnd: end,
        zoneFrom: null,
        zoneTo: null,
    });
}

function applyCustomZoneRange(payload: {
    from: string | null;
    to: string | null;
}): void {
    applyFilters({
        zoneRange: 'custom',
        zoneEnd: null,
        zoneFrom: payload.from,
        zoneTo: payload.to,
    });
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function formatSportPace(speedMps: number | null): string {
    if (speedMps === null || !Number.isFinite(speedMps) || speedMps <= 0) {
        return '—';
    }

    if (props.sport.metric_mode === 'pace_100m') {
        return units.formatPace100m(speedMps);
    }

    if (props.sport.metric_mode === 'pace') {
        return units.formatPace(speedMps);
    }

    return units.formatSpeed(speedMps);
}

function formatSportDistance(meters: number, decimals = 1): string {
    if (props.sport.metric_mode === 'pace_100m' && meters < 1000) {
        return `${formatNumber(Math.round(meters), 0)} m`;
    }

    return units.formatDistanceCompact(meters, decimals);
}

function hrZoneBarClass(zone: number): string {
    const map: Record<number, string> = {
        1: 'bg-zinc-400 dark:bg-zinc-500',
        2: 'bg-sky-500',
        3: 'bg-emerald-500',
        4: 'bg-amber-500',
        5: 'bg-rose-500',
    };

    return map[zone] ?? 'bg-zinc-400';
}

const hasHrZoneData = computed<boolean>(() => props.hrZones.total_seconds > 0);

const weeklyVolumeSummary = computed(() => {
    let sessions = 0;
    let durationSec = 0;
    let distanceM = 0;
    let load = 0;

    for (const w of props.weeklyVolume) {
        sessions += w.sessions;
        durationSec += w.duration_seconds;
        distanceM += w.distance_meters;
        load += w.training_load;
    }

    return { sessions, durationSec, distanceM, load };
});

const weeklyVolumeChartOption = computed<EChartsOption>(() => {
    const weeks = props.weeklyVolume;
    const metric = volumeMetric.value;
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    const yAxisName =
        metric === 'duration'
            ? 'min'
            : metric === 'distance'
              ? units.distanceUnit.value
              : 'load';

    const seriesData = weeks.map((w) => {
        if (metric === 'duration') {
            return Math.round(w.duration_seconds / 60);
        }

        if (metric === 'distance') {
            return Number(
                units.distanceFromMeters(w.distance_meters).toFixed(2),
            );
        }

        return Math.round(w.training_load);
    });

    return {
        animation: false,
        grid: {
            left: 12,
            right: 12,
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
                const week =
                    first?.dataIndex !== undefined
                        ? weeks[first.dataIndex]
                        : undefined;

                if (!week) {
                    return '';
                }

                const lines = [
                    `<strong>Week of ${escapeHtml(week.label)}</strong>`,
                    `Duration: <strong>${formatDurationHuman(week.duration_seconds)}</strong> · ${week.sessions} ${week.sessions === 1 ? 'session' : 'sessions'}`,
                ];

                if (hasDistance.value) {
                    lines.push(
                        `Distance: <strong>${formatSportDistance(week.distance_meters, 2)}</strong>`,
                    );
                }

                if (week.training_load > 0) {
                    lines.push(
                        `Load: <strong>${formatNumber(week.training_load, 0)}</strong>`,
                    );
                }

                return lines.join('<br/>');
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
            name: yAxisName,
            nameGap: 14,
            nameTextStyle: { ...axisLabelStyle, align: 'left' },
            axisLabel: axisLabelStyle,
            splitLine: {
                lineStyle: {
                    color: theme.gridLineColor.value,
                    type: 'dashed',
                },
            },
        },
        series: [
            {
                name:
                    metric === 'duration'
                        ? 'Duration'
                        : metric === 'distance'
                          ? 'Distance'
                          : 'Load',
                type: 'bar',
                barMaxWidth: 24,
                itemStyle: {
                    color: accentHex.value,
                    borderRadius: [4, 4, 0, 0],
                },
                data: seriesData,
            },
        ],
    };
});

const activeSpeed = computed<number | null>(
    () => props.summary.ytd_avg_speed ?? props.summary.avg_speed,
);

const activeAvgHr = computed<number | null>(
    () => props.summary.ytd_avg_hr ?? props.summary.avg_hr,
);
</script>

<template>
    <div class="space-y-6">
        <Head :title="sport.label" />

        <!-- Page Header -->
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    {{ sport.label }}
                </h1>
            </div>
        </header>

        <!-- YTD & All-Time KPIs + Weekly Streak -->
        <section
            class="grid grid-cols-2 gap-3.5 sm:grid-cols-3"
            :class="
                hasDistance && showsPace
                    ? 'lg:grid-cols-6'
                    : hasDistance
                      ? 'lg:grid-cols-5'
                      : 'lg:grid-cols-4'
            "
        >
            <!-- YTD Sessions -->
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    YTD Sessions
                </p>
                <p
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatNumber(summary.ytd_sessions, 0) }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{ formatNumber(summary.total_sessions, 0) }} all-time
                </p>
            </div>

            <!-- YTD Duration -->
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    YTD Duration
                </p>
                <p
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatDurationHuman(summary.ytd_duration_seconds) }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{ formatDurationHuman(summary.total_duration_seconds) }}
                    all-time
                </p>
            </div>

            <!-- YTD Distance (when > 0) -->
            <div
                v-if="hasDistance"
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    YTD Distance
                </p>
                <p
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatSportDistance(summary.ytd_distance_meters, 1) }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{ formatSportDistance(summary.total_distance_meters, 0) }}
                    all-time
                </p>
            </div>

            <!-- Avg Pace (Swimming /100m or Walking /km|/mi) -->
            <div
                v-if="showsPace"
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Avg Pace
                </p>
                <p
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatSportPace(activeSpeed) }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                >
                    {{
                        summary.ytd_avg_speed !== null
                            ? 'YTD average'
                            : 'All-time average'
                    }}
                </p>
            </div>

            <!-- Training Load & Avg HR -->
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    YTD Load &amp; Avg HR
                </p>
                <p
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatNumber(summary.ytd_load, 0) }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    Avg HR
                    {{
                        activeAvgHr !== null
                            ? `${Math.round(activeAvgHr)} bpm`
                            : '—'
                    }}
                </p>
            </div>

            <!-- Weekly Streak Card -->
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <div class="flex items-center justify-between">
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Weekly Streak
                    </p>
                    <Flame class="size-3.5" :style="{ color: accentHex }" />
                </div>
                <div class="mt-1.5 flex items-baseline gap-1.5 tabular-nums">
                    <span
                        class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        {{ formatNumber(streak.current_weeks, 0) }}
                    </span>
                    <span
                        class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        {{ streak.current_weeks === 1 ? 'week' : 'weeks' }}
                    </span>
                </div>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    Best {{ formatNumber(streak.longest_weeks, 0) }}
                    {{ streak.longest_weeks === 1 ? 'week' : 'weeks' }}
                </p>
            </div>
        </section>

        <!-- Active Goals -->
        <GoalProgressStrip
            v-if="goals.length > 0"
            :goals="goals"
            :accent="goalAccent"
            :title="`${sport.label} Goals`"
        />

        <!-- Weekly Volume + HR Zone Distribution -->
        <section class="grid gap-4 lg:grid-cols-12">
            <!-- Weekly Volume Chart -->
            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-5 lg:col-span-7 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="mb-4 space-y-2.5">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h2
                                class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                Weekly Volume
                            </h2>
                            <div
                                class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                            >
                                <span>
                                    {{
                                        formatDurationHuman(
                                            weeklyVolumeSummary.durationSec,
                                        )
                                    }}
                                </span>
                                <span>
                                    {{
                                        formatNumber(
                                            weeklyVolumeSummary.sessions,
                                            0,
                                        )
                                    }}
                                    {{
                                        weeklyVolumeSummary.sessions === 1
                                            ? 'session'
                                            : 'sessions'
                                    }}
                                </span>
                                <span v-if="hasDistance">
                                    {{
                                        formatSportDistance(
                                            weeklyVolumeSummary.distanceM,
                                            1,
                                        )
                                    }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="inline-flex self-start rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            role="group"
                            aria-label="Weekly volume metric"
                        >
                            <button
                                v-for="opt in volumeMetricOptions"
                                :key="opt.value"
                                type="button"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    volumeMetric === opt.value
                                        ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="volumeMetric = opt.value"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>

                    <DateRangeControl
                        :presets="VOLUME_RANGE_OPTIONS"
                        :window="filters.volumeWindow"
                        popover-align="left"
                        aria-label="Volume time range"
                        @select-preset="selectVolumeRange"
                        @step="stepVolumeRange"
                        @apply-custom="applyCustomVolumeRange"
                    />
                </div>

                <BaseChart
                    v-if="weeklyVolume.length > 0"
                    :option="weeklyVolumeChartOption"
                    :height="265"
                />
                <div
                    v-else
                    class="flex h-[265px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                >
                    No weekly volume recorded yet.
                </div>
            </div>

            <!-- HR Zone Distribution -->
            <div
                class="flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 lg:col-span-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div>
                    <div class="space-y-2.5">
                        <h2
                            class="flex items-center gap-1.5 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            <span>HR Zone Distribution</span>
                            <InfoPopover topic="hr_polarization" size="sm" />
                        </h2>

                        <DateRangeControl
                            :presets="ZONE_RANGE_OPTIONS"
                            :window="filters.zoneWindow"
                            popover-align="left"
                            aria-label="Heart rate zone range"
                            @select-preset="selectZoneRange"
                            @step="stepZoneRange"
                            @apply-custom="applyCustomZoneRange"
                        />
                    </div>

                    <div v-if="hasHrZoneData" class="mt-4 space-y-4">
                        <!-- 3-Segment Polarization Bar -->
                        <div>
                            <div
                                class="flex h-2.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                            >
                                <div
                                    v-if="hrZones.polarization.easy_pct > 0"
                                    class="h-full bg-emerald-500 transition-all"
                                    :style="{
                                        width: `${hrZones.polarization.easy_pct}%`,
                                    }"
                                    :title="`Easy (Z1–Z2): ${hrZones.polarization.easy_pct.toFixed(1)}%`"
                                />
                                <div
                                    v-if="hrZones.polarization.moderate_pct > 0"
                                    class="h-full bg-amber-500 transition-all"
                                    :style="{
                                        width: `${hrZones.polarization.moderate_pct}%`,
                                    }"
                                    :title="`Moderate (Z3): ${hrZones.polarization.moderate_pct.toFixed(1)}%`"
                                />
                                <div
                                    v-if="hrZones.polarization.hard_pct > 0"
                                    class="h-full bg-rose-500 transition-all"
                                    :style="{
                                        width: `${hrZones.polarization.hard_pct}%`,
                                    }"
                                    :title="`Hard (Z4–Z5): ${hrZones.polarization.hard_pct.toFixed(1)}%`"
                                />
                            </div>

                            <div class="mt-2.5 grid grid-cols-3 gap-2">
                                <div
                                    class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                                >
                                    <div
                                        class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-emerald-500"
                                        />
                                        Easy
                                    </div>
                                    <div
                                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                hrZones.polarization.easy_pct,
                                                1,
                                            )
                                        }}%
                                    </div>
                                </div>
                                <div
                                    class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                                >
                                    <div
                                        class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-amber-500"
                                        />
                                        Mod
                                    </div>
                                    <div
                                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                hrZones.polarization
                                                    .moderate_pct,
                                                1,
                                            )
                                        }}%
                                    </div>
                                </div>
                                <div
                                    class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                                >
                                    <div
                                        class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-rose-500"
                                        />
                                        Hard
                                    </div>
                                    <div
                                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                hrZones.polarization.hard_pct,
                                                1,
                                            )
                                        }}%
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Z1–Z5 Individual Breakdown -->
                        <div class="space-y-2">
                            <div
                                v-for="z in hrZones.zones"
                                :key="z.zone"
                                class="grid grid-cols-[5.5rem_1fr_3rem_3.5rem] items-center gap-2 text-xs tabular-nums"
                            >
                                <span
                                    class="truncate font-mono text-[11px] text-zinc-600 dark:text-zinc-400"
                                >
                                    Z{{ z.zone }} · {{ z.label }}
                                </span>
                                <div
                                    class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                                >
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :class="hrZoneBarClass(z.zone)"
                                        :style="{
                                            width: `${Math.min(100, Math.max(0, z.pct))}%`,
                                        }"
                                    />
                                </div>
                                <span
                                    class="text-right font-mono text-[11px] font-medium text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ formatNumber(z.pct, 0) }}%
                                </span>
                                <span
                                    class="text-right font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ formatDurationHuman(z.seconds) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="mt-4 flex h-48 items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                    >
                        No HR zone data for {{ filters.zoneWindow.label }}
                    </div>
                </div>

                <p
                    v-if="hasHrZoneData"
                    class="mt-3 border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
                >
                    Total HR time ({{ filters.zoneWindow.label }}):
                    {{ formatDurationHuman(hrZones.total_seconds) }}
                </p>
            </div>
        </section>

        <!-- Recent Sessions Table -->
        <section
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex items-center justify-between gap-3 border-b border-zinc-200/80 px-5 py-4 dark:border-zinc-800/80"
            >
                <h2
                    class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                >
                    Recent {{ sport.label }} Sessions
                </h2>

                <Link
                    :href="activitiesIndex.url({ query: { sport: sport.key } })"
                    class="inline-flex items-center gap-1 font-mono text-xs text-zinc-600 transition-colors hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100"
                >
                    <span>All sessions</span>
                    <ArrowUpRight class="size-3.5" />
                </Link>
            </div>

            <div v-if="recentActivities.length > 0" class="overflow-x-auto">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead
                        class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                    >
                        <tr>
                            <th
                                class="px-4 py-2.5 font-medium whitespace-nowrap"
                            >
                                Date
                            </th>
                            <th
                                class="px-4 py-2.5 font-medium whitespace-nowrap"
                            >
                                Session
                            </th>
                            <th
                                class="px-4 py-2.5 font-medium whitespace-nowrap"
                            >
                                Location
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Duration
                            </th>
                            <th
                                v-if="hasDistance"
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Distance
                            </th>
                            <th
                                v-if="showsPace"
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Pace
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Avg HR
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Calories
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Load
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70"
                    >
                        <tr
                            v-for="item in recentActivities"
                            :key="item.id"
                            class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div
                                    class="font-mono text-xs text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ formatShortDate(item.started_at_local) }}
                                </div>
                                <div
                                    class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    {{ formatTimeOfDay(item.started_at_local) }}
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="size-1.5 shrink-0 rounded-full"
                                        :style="{ backgroundColor: accentHex }"
                                    />
                                    <Link
                                        :href="showActivity.url(item.id)"
                                        class="max-w-56 truncate font-medium text-zinc-900 transition-colors hover:text-zinc-600 dark:text-zinc-100 dark:hover:text-zinc-300"
                                    >
                                        {{ item.name }}
                                    </Link>
                                    <span
                                        v-if="item.is_indoor"
                                        class="rounded border border-zinc-200/90 bg-[#f7f7f5] px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400"
                                    >
                                        Indoor
                                    </span>
                                </div>
                            </td>

                            <td
                                class="max-w-36 truncate px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <span
                                    v-if="item.location_name"
                                    class="inline-flex items-center gap-1"
                                >
                                    <MapPin class="size-3 text-zinc-400" />
                                    <span class="truncate">
                                        {{ item.location_name }}
                                    </span>
                                </span>
                                <span v-else>—</span>
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs font-medium whitespace-nowrap text-zinc-900 dark:text-zinc-100"
                            >
                                {{ formatDurationClock(item.duration_seconds) }}
                            </td>

                            <td
                                v-if="hasDistance"
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                {{
                                    item.distance_meters > 0
                                        ? formatSportDistance(
                                              item.distance_meters,
                                              2,
                                          )
                                        : '—'
                                }}
                            </td>

                            <td
                                v-if="showsPace"
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                {{ formatSportPace(item.avg_speed) }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{
                                    item.avg_hr !== null
                                        ? `${Math.round(item.avg_hr)} bpm`
                                        : '—'
                                }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{
                                    item.calories !== null
                                        ? `${formatNumber(item.calories, 0)} kcal`
                                        : '—'
                                }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{
                                    item.training_load !== null
                                        ? formatNumber(item.training_load, 0)
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-else
                class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center"
            >
                <Activity class="size-5 text-zinc-400 dark:text-zinc-500" />
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    No {{ sport.label.toLowerCase() }} sessions recorded yet.
                </p>
            </div>
        </section>
    </div>
</template>
