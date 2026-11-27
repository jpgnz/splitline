<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CheckCircle2,
    MapPin,
    SlidersHorizontal,
} from '@lucide/vue';
import type { EChartsOption, SeriesOption } from 'echarts';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { bulkReclassify } from '@/actions/App/Http/Controllers/SportsController';
import BaseChart from '@/components/charts/BaseChart.vue';
import LatestActivityPill from '@/components/activities/LatestActivityPill.vue';
import ActivitySummaryCharts from '@/components/charts/ActivitySummaryCharts.vue';
import type { AnnualActivitySummaryPayload } from '@/components/charts/annualSummaryTypes';
import type { CompactGoalProgress } from '@/components/goals/GoalProgressStrip.vue';
import GoalProgressStrip from '@/components/goals/GoalProgressStrip.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatDurationHuman,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import { show as showActivity } from '@/routes/activities';

interface FamilyTab {
    key: string;
    label: string;
    short_label: string;
    href: string;
    slug: string;
}

interface DisciplineOption {
    value: string;
    label: string;
}

interface FamilyDisciplineSummary {
    key: string;
    label: string;
    short_label: string;
    href: string;
    color_token: string;
    accent_hex: string;
    metric_mode: 'pace' | 'speed' | 'pace_100m' | 'duration';
    ytd_sessions: number;
    ytd_distance_meters: number;
    ytd_elevation_gain: number;
    ytd_duration_seconds: number;
    ytd_load: number;
    total_sessions: number;
    total_distance_meters: number;
    total_elevation_gain: number;
    total_duration_seconds: number;
    latest_ftp: number | null;
    avg_grit: number | null;
    avg_flow: number | null;
    avg_speed: number | null;
}

interface FamilyWeeklyDisciplineBucket {
    distance_meters: number;
    elevation_gain: number;
    load: number;
}

interface FamilyWeeklyVolumePoint {
    week_start: string;
    label: string;
    total_distance_meters: number;
    total_elevation_gain: number;
    total_load: number;
    by_discipline: Record<string, FamilyWeeklyDisciplineBucket>;
}

interface ReclassifyActivityItem {
    id: number;
    name: string;
    started_at_local: string;
    activity_type_key: string;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number | null;
    avg_speed: number | null;
    avg_hr: number | null;
    avg_power: number | null;
    grit: number | null;
    unpaved_pct: number | null;
    location_name: string | null;
}

interface LatestFamilyActivity {
    id: number;
    name: string;
    sport_category: string;
    effective_discipline: string | null;
    started_at_local: string;
    is_today: boolean;
    distance_meters: number;
    duration_seconds: number;
    avg_speed: number | null;
    avg_hr: number | null;
}

interface SportsOverviewProps {
    family: {
        key: string;
        label: string;
        short_label: string;
        href: string;
        sport_category: string;
        tabs: FamilyTab[];
        discipline_options: DisciplineOption[];
    };
    disciplineSummaries: FamilyDisciplineSummary[];
    weeklyVolume: FamilyWeeklyVolumePoint[];
    annualSummary: AnnualActivitySummaryPayload;
    goals: CompactGoalProgress[];
    latestActivity?: LatestFamilyActivity | null;
    reclassifyInbox: ReclassifyActivityItem[];
    reclassifyInboxTotal: number;
}

type VolumeMetric = 'distance' | 'vertical' | 'load';

const props = defineProps<SportsOverviewProps>();
const theme = useChartTheme();
const units = useUnits();
const { getSportMeta, resolveActivitySportKey } = useSports();

const volumeMetric = ref<VolumeMetric>('distance');
const selectedIds = ref<number[]>([]);
const isReclassifying = ref<boolean>(false);
const activeRowId = ref<number | null>(null);

const familyMeta = computed(() => getSportMeta(props.family.key));

const latestActivityMeta = computed(() => {
    const item = props.latestActivity;

    if (!item) {
        return null;
    }

    const sportKey = resolveActivitySportKey(
        item.sport_category,
        item.effective_discipline,
    );

    return getSportMeta(sportKey);
});

const goalAccent = computed<'amber' | 'sky' | 'emerald' | 'indigo'>(() => {
    const token = familyMeta.value.color_token;

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

const sessionNoun = computed<{ singular: string; plural: string }>(() => {
    if (props.family.sport_category === 'running') {
        return { singular: 'run', plural: 'runs' };
    }

    if (props.family.sport_category === 'biking') {
        return { singular: 'ride', plural: 'rides' };
    }

    return { singular: 'session', plural: 'sessions' };
});

const summaryGridClass = computed<string>(() => {
    const count = props.disciplineSummaries.length;

    if (count === 1) {
        return 'grid gap-4';
    }

    if (count === 2) {
        return 'grid gap-4 lg:grid-cols-2';
    }

    return 'grid gap-4 md:grid-cols-2 xl:grid-cols-3';
});

watch(
    () => props.reclassifyInbox,
    (items) => {
        const validIds = new Set(items.map((item) => item.id));
        selectedIds.value = selectedIds.value.filter((id) => validIds.has(id));
    },
);

const allVisibleSelected = computed<boolean>(
    () =>
        props.reclassifyInbox.length > 0 &&
        selectedIds.value.length === props.reclassifyInbox.length,
);

const someVisibleSelected = computed<boolean>(
    () =>
        selectedIds.value.length > 0 &&
        selectedIds.value.length < props.reclassifyInbox.length,
);

function toggleSelectAll(): void {
    if (allVisibleSelected.value) {
        selectedIds.value = [];

        return;
    }

    selectedIds.value = props.reclassifyInbox.map((item) => item.id);
}

function toggleItemSelection(id: number): void {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((item) => item !== id);
    } else {
        selectedIds.value = [...selectedIds.value, id];
    }
}

function formatActivityTypeKey(key: string): string {
    return key
        .trim()
        .toLowerCase()
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase())
        .replace(/\bMtb\b/g, 'MTB');
}

function submitReclassify(
    activityIds: number[],
    discipline: string,
    rowId: number | null = null,
): void {
    if (activityIds.length === 0 || isReclassifying.value) {
        return;
    }

    const disciplineLabel =
        props.family.discipline_options.find(
            (option) => option.value === discipline,
        )?.label ?? formatActivityTypeKey(discipline);
    const count = activityIds.length;

    router.post(
        bulkReclassify.url(),
        {
            activity_ids: activityIds,
            discipline,
        },
        {
            preserveScroll: true,
            onStart: () => {
                isReclassifying.value = true;
                activeRowId.value = rowId;
            },
            onSuccess: () => {
                selectedIds.value = selectedIds.value.filter(
                    (id) => !activityIds.includes(id),
                );
                toast.success(
                    count === 1
                        ? `Activity reclassified as ${disciplineLabel}.`
                        : `${formatNumber(count, 0)} activities reclassified as ${disciplineLabel}.`,
                );
            },
            onError: () => {
                toast.error('Unable to reclassify selected activities.');
            },
            onFinish: () => {
                isReclassifying.value = false;
                activeRowId.value = null;
            },
        },
    );
}

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function formatPrimaryRate(
    metricMode: FamilyDisciplineSummary['metric_mode'],
    avgSpeed: number | null,
): string {
    if (avgSpeed === null || !Number.isFinite(avgSpeed) || avgSpeed <= 0) {
        return '—';
    }

    if (metricMode === 'pace') {
        return units.formatPace(avgSpeed);
    }

    if (metricMode === 'pace_100m') {
        return units.formatPace100m(avgSpeed);
    }

    return units.formatSpeed(avgSpeed);
}

function formatInboxRate(avgSpeed: number | null): string {
    if (avgSpeed === null || !Number.isFinite(avgSpeed) || avgSpeed <= 0) {
        return '—';
    }

    if (props.family.sport_category === 'running') {
        return units.formatPace(avgSpeed);
    }

    return units.formatSpeed(avgSpeed);
}

function disciplineMetricValue(
    bucket: FamilyWeeklyDisciplineBucket | undefined,
    metric: VolumeMetric,
): number {
    if (!bucket) {
        return 0;
    }

    if (metric === 'distance') {
        return units.distanceFromMeters(bucket.distance_meters);
    }

    if (metric === 'vertical') {
        return units.elevationFromMeters(bucket.elevation_gain);
    }

    return bucket.load;
}

function totalMetricValue(
    week: FamilyWeeklyVolumePoint,
    metric: VolumeMetric,
): number {
    if (metric === 'distance') {
        return units.distanceFromMeters(week.total_distance_meters);
    }

    if (metric === 'vertical') {
        return units.elevationFromMeters(week.total_elevation_gain);
    }

    return week.total_load;
}

function formatMetricValue(value: number, metric: VolumeMetric): string {
    if (metric === 'distance') {
        return `${formatNumber(value, 1)} ${units.distanceUnit.value}`;
    }

    if (metric === 'vertical') {
        return `${formatNumber(Math.round(value), 0)} ${units.elevationUnit.value}`;
    }

    return `${formatNumber(Math.round(value), 0)} load`;
}

const weeklyTotals = computed(() => {
    const metric = volumeMetric.value;
    let total = 0;

    const perDiscipline = props.disciplineSummaries.map((discipline) => ({
        key: discipline.key,
        short_label: discipline.short_label,
        accent_hex: discipline.accent_hex,
        value: 0,
    }));

    for (const week of props.weeklyVolume) {
        total += totalMetricValue(week, metric);

        for (const item of perDiscipline) {
            item.value += disciplineMetricValue(
                week.by_discipline[item.key],
                metric,
            );
        }
    }

    return {
        total,
        perDiscipline,
    };
});

const otherSeriesValues = computed<number[]>(() => {
    const metric = volumeMetric.value;

    return props.weeklyVolume.map((week) => {
        const total = totalMetricValue(week, metric);
        let sumKnown = 0;

        for (const discipline of props.disciplineSummaries) {
            sumKnown += disciplineMetricValue(
                week.by_discipline[discipline.key],
                metric,
            );
        }

        const remainder = total - sumKnown;

        return remainder > 0.05
            ? Number(remainder.toFixed(metric === 'distance' ? 1 : 0))
            : 0;
    });
});

const hasOtherVolume = computed<boolean>(() =>
    otherSeriesValues.value.some((v) => v > 0),
);

const volumeMetricOptions: Array<{ value: VolumeMetric; label: string }> = [
    { value: 'distance', label: 'Distance' },
    { value: 'vertical', label: 'Vertical' },
    { value: 'load', label: 'Load' },
];

const weeklyVolumeChartOption = computed<EChartsOption>(() => {
    const weeks = props.weeklyVolume;
    const metric = volumeMetric.value;
    const monoFont = theme.fontFamilyMono;
    const decimals = metric === 'distance' ? 1 : 0;

    const yAxisUnit =
        metric === 'distance'
            ? units.distanceUnit.value
            : metric === 'vertical'
              ? units.elevationUnit.value
              : 'load';

    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    const series: SeriesOption[] = props.disciplineSummaries.map(
        (discipline, idx) => {
            const isTopSeries =
                !hasOtherVolume.value &&
                idx === props.disciplineSummaries.length - 1;

            return {
                name: discipline.short_label,
                type: 'bar',
                stack: 'volume',
                barMaxWidth: 26,
                itemStyle: {
                    color: discipline.accent_hex,
                    borderRadius: isTopSeries ? [3, 3, 0, 0] : [0, 0, 0, 0],
                },
                data: weeks.map((week) =>
                    Number(
                        disciplineMetricValue(
                            week.by_discipline[discipline.key],
                            metric,
                        ).toFixed(decimals),
                    ),
                ),
            };
        },
    );

    if (hasOtherVolume.value) {
        series.push({
            name: 'Unclassified',
            type: 'bar',
            stack: 'volume',
            barMaxWidth: 26,
            itemStyle: {
                color: '#a1a1aa',
                borderRadius: [3, 3, 0, 0],
            },
            data: otherSeriesValues.value,
        });
    }

    return {
        animation: false,
        grid: {
            left: 12,
            right: 12,
            top: 48,
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
                const index = first?.dataIndex;
                const week = index !== undefined ? weeks[index] : undefined;

                if (!week || index === undefined) {
                    return '';
                }

                const lines: string[] = [
                    `<strong>Week of ${escapeHtml(week.label)}</strong>`,
                ];

                for (const discipline of props.disciplineSummaries) {
                    const val = disciplineMetricValue(
                        week.by_discipline[discipline.key],
                        metric,
                    );

                    if (val > 0) {
                        lines.push(
                            `<span style="color:${discipline.accent_hex};">●</span> ${escapeHtml(discipline.short_label)}: <strong>${formatMetricValue(val, metric)}</strong>`,
                        );
                    }
                }

                const otherVal = otherSeriesValues.value[index] ?? 0;

                if (otherVal > 0) {
                    lines.push(
                        `<span style="color:#a1a1aa;">●</span> Unclassified: <strong>${formatMetricValue(otherVal, metric)}</strong>`,
                    );
                }

                const totalVal = totalMetricValue(week, metric);
                lines.push(
                    `<div style="margin-top:3px;border-top:1px solid rgba(113,113,122,0.25);padding-top:3px;">Total: <strong>${formatMetricValue(totalVal, metric)}</strong></div>`,
                );

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
            name: yAxisUnit,
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
        series,
    };
});

function optionAccentHex(optionValue: string): string {
    const keyMap: Record<string, string> =
        props.family.sport_category === 'running'
            ? {
                  road: 'road_running',
                  trail: 'trail_running',
                  track: 'track_running',
              }
            : {
                  road: 'road_cycling',
                  gravel: 'gravel_cycling',
                  mtb: 'mtb_cycling',
                  ebike: 'ebike_cycling',
                  commute: 'commute_cycling',
              };

    const sportKey = keyMap[optionValue];

    return sportKey
        ? getSportMeta(sportKey).accent_hex
        : familyMeta.value.accent_hex;
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="`${family.label} Overview`" />

        <!-- Page Header + Segmented Discipline Tabs -->
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    {{ family.label }} Overview
                </h1>
            </div>

            <div class="flex flex-wrap items-center gap-2.5 lg:justify-end">
                <LatestActivityPill
                    v-if="latestActivity && latestActivityMeta"
                    :activity-id="latestActivity.id"
                    :name="latestActivity.name"
                    :accent-hex="latestActivityMeta.accent_hex"
                    :is-today="latestActivity.is_today"
                    :date="latestActivity.started_at_local"
                    :distance-meters="latestActivity.distance_meters"
                    :duration-seconds="latestActivity.duration_seconds"
                    :avg-speed="latestActivity.avg_speed"
                    :avg-hr="latestActivity.avg_hr"
                    :metric-mode="latestActivityMeta.metric_mode"
                    show-date
                />

                <nav
                    v-if="family.tabs.length > 0"
                    aria-label="Discipline navigation"
                    class="inline-flex flex-wrap self-start rounded-lg border border-zinc-200/90 bg-white p-1 lg:self-auto dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <Link
                        v-for="tab in family.tabs"
                        :key="tab.key"
                        :href="tab.href"
                        prefetch
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-mono text-xs font-medium transition-colors"
                        :class="
                            tab.key === family.key
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                    >
                        <span
                            v-if="tab.key === family.key"
                            class="size-1.5 rounded-full"
                            :style="{ backgroundColor: familyMeta.accent_hex }"
                        />
                        <span>{{ tab.short_label }}</span>
                    </Link>
                </nav>
            </div>
        </header>

        <!-- Dynamic Grid of Discipline Summary Cards -->
        <section :class="summaryGridClass">
            <Link
                v-for="item in disciplineSummaries"
                :key="item.key"
                :href="item.href"
                prefetch
                class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 transition-colors hover:border-zinc-400/80 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-16"
                    :style="{ backgroundColor: item.accent_hex }"
                />

                <div>
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                                :style="{
                                    backgroundColor: `${item.accent_hex}1f`,
                                    color: item.accent_hex,
                                }"
                            >
                                <span
                                    class="size-2.5 rounded-full"
                                    :style="{
                                        backgroundColor: item.accent_hex,
                                    }"
                                />
                            </span>
                            <div class="min-w-0">
                                <h2
                                    class="truncate text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                                >
                                    {{ item.label }}
                                </h2>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                v-if="item.latest_ftp !== null"
                                class="rounded-md bg-sky-500/12 px-2 py-0.5 font-mono text-[11px] font-medium text-sky-700 tabular-nums dark:text-sky-300"
                            >
                                FTP {{ Math.round(item.latest_ftp) }} W
                            </span>
                            <span
                                v-if="
                                    item.avg_grit !== null ||
                                    item.avg_flow !== null
                                "
                                class="rounded-md bg-emerald-500/12 px-2 py-0.5 font-mono text-[11px] font-medium text-emerald-700 tabular-nums dark:text-emerald-300"
                            >
                                Grit {{ formatNumber(item.avg_grit, 1) }} · Flow
                                {{ formatNumber(item.avg_flow, 1) }}
                            </span>
                            <ArrowUpRight
                                class="size-4 text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-zinc-900 dark:group-hover:text-zinc-100"
                            />
                        </div>
                    </div>

                    <!-- YTD Hero Stats -->
                    <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                YTD Distance
                            </p>
                            <p
                                class="mt-1 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                            >
                                {{
                                    units.formatDistanceCompact(
                                        item.ytd_distance_meters,
                                        0,
                                    )
                                }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                YTD {{ sessionNoun.plural }}
                            </p>
                            <p
                                class="mt-1 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                            >
                                {{ formatNumber(item.ytd_sessions, 0) }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                YTD Vert
                            </p>
                            <p
                                class="mt-1 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                            >
                                {{
                                    units.formatElevation(
                                        item.ytd_elevation_gain,
                                    )
                                }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                YTD Time
                            </p>
                            <p
                                class="mt-1 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                            >
                                {{
                                    formatDurationHuman(
                                        item.ytd_duration_seconds,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- All-Time Telemetry Footer -->
                <div
                    class="mt-5 grid grid-cols-2 gap-3 border-t border-zinc-100 pt-3.5 font-mono text-xs tabular-nums sm:grid-cols-4 dark:border-zinc-800/80"
                >
                    <div>
                        <span
                            class="block text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                        >
                            All-Time Dist
                        </span>
                        <span
                            class="font-medium text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                units.formatDistanceCompact(
                                    item.total_distance_meters,
                                    0,
                                )
                            }}
                        </span>
                    </div>
                    <div>
                        <span
                            class="block text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                        >
                            All-Time {{ sessionNoun.plural }}
                        </span>
                        <span
                            class="font-medium text-zinc-700 dark:text-zinc-300"
                        >
                            {{ formatNumber(item.total_sessions, 0) }}
                        </span>
                    </div>
                    <div>
                        <span
                            class="block text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                        >
                            All-Time Vert
                        </span>
                        <span
                            class="font-medium text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                units.formatElevation(item.total_elevation_gain)
                            }}
                        </span>
                    </div>
                    <div>
                        <span
                            class="block text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                        >
                            {{
                                item.metric_mode === 'pace'
                                    ? 'Avg Pace'
                                    : 'Avg Speed'
                            }}
                        </span>
                        <span
                            class="font-medium text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                formatPrimaryRate(
                                    item.metric_mode,
                                    item.avg_speed,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </Link>
        </section>

        <!-- Active Family Goals -->
        <GoalProgressStrip
            v-if="goals.length > 0"
            :goals="goals"
            :accent="goalAccent"
            :title="`${family.label} Goals`"
        />

        <!-- 24-Week Stacked Weekly Volume Chart -->
        <section
            class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        24-Week {{ family.label }} Volume
                    </span>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        <span>
                            Total
                            <strong
                                class="font-medium text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatMetricValue(
                                        weeklyTotals.total,
                                        volumeMetric,
                                    )
                                }}
                            </strong>
                        </span>
                        <span
                            v-for="item in weeklyTotals.perDiscipline"
                            :key="item.key"
                            class="inline-flex items-center gap-1"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :style="{ backgroundColor: item.accent_hex }"
                            />
                            {{ item.short_label }}
                            {{ formatMetricValue(item.value, volumeMetric) }}
                        </span>
                    </div>
                </div>

                <!-- Metric Switcher -->
                <div
                    class="inline-flex self-start rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 sm:self-auto dark:border-zinc-800 dark:bg-zinc-900/60"
                    role="group"
                    aria-label="Volume chart metric"
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

            <BaseChart
                v-if="weeklyVolume.length > 0"
                :option="weeklyVolumeChartOption"
                :height="280"
            />
            <div
                v-else
                class="flex h-[240px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
            >
                No weekly {{ family.label.toLowerCase() }} volume recorded yet.
            </div>
        </section>

        <!-- Multi-Year Cumulative & Activity Calendar Summary -->
        <ActivitySummaryCharts
            :summary="annualSummary"
            mode="sport"
            :sport-key="family.key"
            :title="`${family.label} Annual Summary`"
        />

        <!-- Generalized Reclassification Inbox -->
        <section
            v-if="reclassifyInbox.length > 0"
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex flex-col gap-3 border-b border-zinc-200/80 px-5 py-4 lg:flex-row lg:items-center lg:justify-between dark:border-zinc-800/80"
            >
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <SlidersHorizontal
                            class="size-3.5 text-zinc-500 dark:text-zinc-400"
                        />
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Reclassification Inbox
                        </span>
                        <span
                            class="rounded-full bg-amber-500/15 px-2 py-0.5 font-mono text-[10px] font-medium text-amber-700 tabular-nums dark:text-amber-300"
                        >
                            {{ formatNumber(reclassifyInboxTotal, 0) }}
                            unclassified
                            {{
                                reclassifyInboxTotal === 1
                                    ? sessionNoun.singular
                                    : sessionNoun.plural
                            }}
                            <template
                                v-if="
                                    reclassifyInboxTotal >
                                    reclassifyInbox.length
                                "
                            >
                                · showing latest
                                {{ formatNumber(reclassifyInbox.length, 0) }}
                            </template>
                        </span>
                        <span
                            v-if="selectedIds.length > 0"
                            class="rounded-full bg-zinc-900 px-2 py-0.5 font-mono text-[10px] font-medium text-white tabular-nums dark:bg-zinc-100 dark:text-zinc-950"
                        >
                            {{ selectedIds.length }} selected
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Assign generic {{ family.label.toLowerCase() }}
                        activities to a discipline so volume, benchmarks, and
                        dynamics stay accurate.
                    </p>
                </div>

                <!-- Bulk Assign Toolbar -->
                <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                    <span
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Assign selected:
                    </span>
                    <button
                        v-for="option in family.discipline_options"
                        :key="option.value"
                        type="button"
                        :disabled="selectedIds.length === 0 || isReclassifying"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-[#f7f7f5] px-2.5 py-1.5 font-mono text-[11px] font-medium text-zinc-800 transition-colors hover:border-zinc-400 hover:bg-white disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-200 dark:hover:border-zinc-600 dark:hover:bg-zinc-800"
                        @click="
                            submitReclassify([...selectedIds], option.value)
                        "
                    >
                        <span
                            class="size-1.5 rounded-full"
                            :style="{
                                backgroundColor: optionAccentHex(option.value),
                            }"
                        />
                        <span>{{ option.label }}</span>
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead
                        class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                    >
                        <tr>
                            <th class="w-10 py-2.5 pl-5">
                                <input
                                    type="checkbox"
                                    :checked="allVisibleSelected"
                                    :indeterminate="someVisibleSelected"
                                    aria-label="Select all unclassified activities"
                                    class="size-4 cursor-pointer rounded border-zinc-300 accent-zinc-900 dark:border-zinc-700 dark:accent-zinc-100"
                                    @change="toggleSelectAll"
                                />
                            </th>
                            <th class="px-4 py-2.5 font-medium">Activity</th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Distance
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Duration
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Vert
                            </th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                {{
                                    family.sport_category === 'running'
                                        ? 'Pace'
                                        : 'Speed'
                                }}
                            </th>
                            <th class="px-4 py-2.5 font-medium">Signals</th>
                            <th class="py-2.5 pr-5 pl-4 text-right font-medium">
                                Classify
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-zinc-100 dark:divide-zinc-800/80"
                    >
                        <tr
                            v-for="item in reclassifyInbox"
                            :key="item.id"
                            class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                            :class="
                                selectedIds.includes(item.id)
                                    ? 'bg-zinc-50/90 dark:bg-zinc-900/50'
                                    : ''
                            "
                        >
                            <td class="py-3 pl-5 align-middle">
                                <input
                                    type="checkbox"
                                    :checked="selectedIds.includes(item.id)"
                                    :aria-label="`Select ${item.name}`"
                                    class="size-4 cursor-pointer rounded border-zinc-300 accent-zinc-900 dark:border-zinc-700 dark:accent-zinc-100"
                                    @change="toggleItemSelection(item.id)"
                                />
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <Link
                                    :href="showActivity.url(item.id)"
                                    class="block font-medium text-zinc-900 transition-colors hover:text-zinc-600 dark:text-zinc-100 dark:hover:text-zinc-300"
                                >
                                    {{ item.name }}
                                </Link>
                                <div
                                    class="mt-0.5 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    <span>
                                        {{
                                            formatShortDate(
                                                item.started_at_local,
                                            )
                                        }}
                                    </span>
                                    <span
                                        v-if="item.location_name"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <MapPin class="size-3 text-zinc-400" />
                                        {{ item.location_name }}
                                    </span>
                                    <span
                                        class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
                                    >
                                        {{
                                            formatActivityTypeKey(
                                                item.activity_type_key,
                                            )
                                        }}
                                    </span>
                                </div>
                            </td>
                            <td
                                class="px-4 py-3 text-right align-middle font-mono text-xs whitespace-nowrap text-zinc-800 dark:text-zinc-200"
                            >
                                {{
                                    units.formatDistanceCompact(
                                        item.distance_meters,
                                        1,
                                    )
                                }}
                            </td>
                            <td
                                class="px-4 py-3 text-right align-middle font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{ formatDurationClock(item.duration_seconds) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right align-middle font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{ units.formatElevation(item.elevation_gain) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right align-middle font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{ formatInboxRate(item.avg_speed) }}
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <div
                                    class="flex flex-wrap items-center gap-1.5 font-mono text-[10px]"
                                >
                                    <span
                                        v-if="item.avg_hr !== null"
                                        class="rounded bg-rose-500/10 px-1.5 py-0.5 text-rose-700 dark:text-rose-300"
                                    >
                                        {{ Math.round(item.avg_hr) }} bpm
                                    </span>
                                    <span
                                        v-if="item.avg_power !== null"
                                        class="rounded bg-sky-500/10 px-1.5 py-0.5 text-sky-700 dark:text-sky-300"
                                    >
                                        {{ Math.round(item.avg_power) }} W
                                    </span>
                                    <span
                                        v-if="item.grit !== null"
                                        class="rounded bg-emerald-500/10 px-1.5 py-0.5 text-emerald-700 dark:text-emerald-300"
                                    >
                                        Grit {{ formatNumber(item.grit, 1) }}
                                    </span>
                                    <span
                                        v-if="item.unpaved_pct !== null"
                                        class="rounded bg-amber-500/10 px-1.5 py-0.5 text-amber-700 dark:text-amber-300"
                                    >
                                        {{ Math.round(item.unpaved_pct) }}%
                                        unpaved
                                    </span>
                                    <span
                                        v-if="
                                            item.avg_hr === null &&
                                            item.avg_power === null &&
                                            item.grit === null &&
                                            item.unpaved_pct === null
                                        "
                                        class="text-zinc-400 dark:text-zinc-500"
                                    >
                                        —
                                    </span>
                                </div>
                            </td>
                            <td
                                class="py-3 pr-5 pl-4 text-right align-middle whitespace-nowrap"
                            >
                                <div
                                    class="inline-flex flex-wrap items-center justify-end gap-1"
                                >
                                    <button
                                        v-for="option in family.discipline_options"
                                        :key="option.value"
                                        type="button"
                                        :disabled="
                                            isReclassifying &&
                                            (activeRowId === null ||
                                                activeRowId === item.id)
                                        "
                                        class="cursor-pointer rounded-md border border-zinc-200/90 bg-[#f7f7f5] px-2 py-1 font-mono text-[10px] font-medium text-zinc-700 transition-colors hover:border-zinc-400 hover:bg-white disabled:cursor-not-allowed disabled:opacity-45 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-600 dark:hover:bg-zinc-800"
                                        @click="
                                            submitReclassify(
                                                [item.id],
                                                option.value,
                                                item.id,
                                            )
                                        "
                                    >
                                        {{ option.label }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Empty State when all activities are classified -->
        <section
            v-else
            class="flex flex-col items-center justify-center rounded-xl border border-zinc-200/90 bg-white px-6 py-8 text-center dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <span
                class="flex size-10 items-center justify-center rounded-full bg-emerald-500/12 text-emerald-600 dark:text-emerald-400"
            >
                <CheckCircle2 class="size-5" />
            </span>
            <h2
                class="mt-3 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
            >
                All {{ family.label.toLowerCase() }} activities classified
            </h2>
            <p
                class="mt-1 max-w-md font-mono text-xs text-zinc-500 dark:text-zinc-400"
            >
                All {{ family.label.toLowerCase() }} activities are categorized.
            </p>
        </section>
    </div>
</template>
