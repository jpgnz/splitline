<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    CloudSun,
    Flag,
    MapPin,
    Mountain,
    Pencil,
    RotateCcw,
    Star,
    Trash2,
    TrendingUp,
    Trophy,
    X,
} from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InfoPopover from '@/components/InfoPopover.vue';
import BaseChart, {
    type ChartPointClickPayload,
} from '@/components/charts/BaseChart.vue';
import ActivityMap from '@/components/maps/ActivityMap.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import { climbCategoryMeta, formatGrade } from '@/lib/segments';
import { useSports } from '@/lib/sports';
import { distanceFromMeters, elevationFromMeters, useUnits } from '@/lib/units';
import { show as showActivity } from '@/routes/activities';
import {
    destroy as destroySegment,
    index as segmentsIndex,
    show as showSegment,
    update as updateSegment,
} from '@/routes/segments';
import type { SegmentShowProps } from '@/types/segments';

const props = defineProps<SegmentShowProps>();

const page = usePage();
const theme = useChartTheme();
const { getSportMeta, sportBadgeClasses } = useSports();
const {
    units,
    distanceUnit,
    elevationUnit,
    vamUnit,
    formatDistance,
    formatElevation,
    formatPace,
    formatPace100m,
    formatSpeed,
    formatSpeedKmh,
    formatTemp,
    formatVam,
} = useUnits();

const sportMeta = computed(() => getSportMeta(props.segment.sport_key));
const categoryMeta = computed(() =>
    climbCategoryMeta(props.segment.climb_category),
);
const hasMixedSports = computed<boolean>(
    () => (props.available_sports?.length ?? 0) > 1,
);
const selectedSportMeta = computed(() =>
    props.selected_sport ? getSportMeta(props.selected_sport) : null,
);
const effortsKpiLabel = computed<string>(() => {
    const parts: string[] = [];

    if (props.selected_year !== null) {
        parts.push(String(props.selected_year));
    }

    if (selectedSportMeta.value) {
        parts.push(selectedSportMeta.value.short_label);
    }

    return parts.length > 0 ? `${parts.join(' ')} Efforts` : 'Efforts';
});
const prKpiLabel = computed<string>(() => {
    const parts: string[] = [];

    if (props.selected_year !== null) {
        parts.push(String(props.selected_year));
    }

    if (selectedSportMeta.value) {
        parts.push(selectedSportMeta.value.short_label);
    }

    return parts.length > 0 ? `${parts.join(' ')} Best` : 'All-Time PR';
});
const usesPace = computed<boolean>(
    () =>
        props.segment.metric_mode === 'pace' ||
        props.segment.metric_mode === 'pace_100m',
);

const isEditingName = ref<boolean>(false);
const renameInputRef = ref<HTMLInputElement | null>(null);
const isTogglingStar = ref<boolean>(false);
const confirmingDelete = ref<boolean>(false);
const isDeleting = ref<boolean>(false);

const renameForm = useForm({
    name_override: props.segment.name_override ?? props.segment.name,
});

watch(
    () => [props.segment.name, props.segment.name_override],
    () => {
        if (!isEditingName.value) {
            renameForm.name_override =
                props.segment.name_override ?? props.segment.name;
        }
    },
);

function startEditingName(): void {
    renameForm.name_override =
        props.segment.name_override ?? props.segment.name;
    renameForm.clearErrors();
    isEditingName.value = true;
    nextTick(() => {
        renameInputRef.value?.focus();
        renameInputRef.value?.select();
    });
}

function cancelEditingName(): void {
    renameForm.reset();
    renameForm.clearErrors();
    isEditingName.value = false;
}

function submitRename(): void {
    renameForm.patch(updateSegment.url(props.segment.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditingName.value = false;
            toast.success(page.props.flash?.status ?? 'Segment name updated.');
        },
    });
}

function resetAutoName(): void {
    renameForm.name_override = '';
    renameForm.patch(updateSegment.url(props.segment.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditingName.value = false;
            toast.success(
                page.props.flash?.status ??
                    'Segment name reset to auto-detected name.',
            );
        },
    });
}

function toggleStar(): void {
    if (isTogglingStar.value) {
        return;
    }

    router.patch(
        updateSegment.url(props.segment.id),
        { is_starred: !props.segment.is_starred },
        {
            preserveScroll: true,
            onStart: () => {
                isTogglingStar.value = true;
            },
            onSuccess: () => {
                toast.success(page.props.flash?.status ?? 'Segment updated.');
            },
            onFinish: () => {
                isTogglingStar.value = false;
            },
        },
    );
}

function confirmDeleteSegment(): void {
    if (isDeleting.value) {
        return;
    }

    router.delete(destroySegment.url(props.segment.id), {
        onStart: () => {
            isDeleting.value = true;
        },
        onSuccess: () => {
            toast.success(page.props.flash?.status ?? 'Segment deleted.');
        },
        onError: () => {
            toast.error('Failed to delete segment.');
        },
        onFinish: () => {
            isDeleting.value = false;
            confirmingDelete.value = false;
        },
    });
}

function applyLeaderboardFilters(
    year: number | null,
    sport: string | null,
): void {
    const query: Record<string, string> = {};

    if (year !== null) {
        query.year = String(year);
    }

    if (sport !== null) {
        query.sport = sport;
    }

    router.get(showSegment.url(props.segment.id), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function selectYear(year: number | null): void {
    applyLeaderboardFilters(year, props.selected_sport ?? null);
}

function selectSport(sport: string | null): void {
    applyLeaderboardFilters(props.selected_year ?? null, sport);
}

const hasAnyPower = computed<boolean>(() =>
    props.efforts.some((e) => e.avg_power !== null),
);

const hasAnyVam = computed<boolean>(() =>
    props.efforts.some((e) => e.vam !== null && e.vam > 0),
);

const hasAnyWeather = computed<boolean>(() =>
    props.efforts.some((e) => e.weather !== null),
);

function formatMetricSpeedOrPace(speedMps: number | null): string {
    if (props.segment.metric_mode === 'pace_100m') {
        return formatPace100m(speedMps);
    }

    if (props.segment.metric_mode === 'pace') {
        return formatPace(speedMps);
    }

    return formatSpeed(speedMps);
}

function formatDateWithYear(dateStr: string | null): string {
    if (!dateStr) {
        return '—';
    }

    const short = formatShortDate(dateStr);
    const parsed = new Date(`${dateStr}T00:00:00`);

    if (Number.isNaN(parsed.getTime())) {
        return short;
    }

    return `${short} ${parsed.getFullYear()}`;
}

function formatDeltaPr(seconds: number): string {
    if (!Number.isFinite(seconds) || Math.abs(seconds) < 0.5) {
        return 'PR';
    }

    return `+${formatDurationClock(Math.abs(seconds))}`;
}

function rankBadgeClass(rank: number): string {
    if (rank === 1) {
        return 'border-amber-500/40 bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300';
    }

    if (rank === 2) {
        return 'border-zinc-300 bg-zinc-100 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
    }

    if (rank === 3) {
        return 'border-orange-500/35 bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300';
    }

    return 'border-zinc-200/80 bg-zinc-50 text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400';
}

function windPillClass(
    label: 'Headwind' | 'Tailwind' | 'Crosswind' | 'Calm',
): string {
    switch (label) {
        case 'Headwind':
            return 'border-rose-500/25 bg-rose-500/10 text-rose-700 dark:text-rose-300';
        case 'Tailwind':
            return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
        case 'Crosswind':
            return 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300';
        default:
            return 'border-zinc-300/70 bg-zinc-100/80 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-300';
    }
}

function escapeHtml(raw: string): string {
    return raw
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const elevationProfileOption = computed<EChartsOption>(() => {
    const currentUnits = units.value;
    const rows = props.elevation_profile;
    const accent = props.segment.accent_hex || '#f59e0b';

    const data = rows.map((pt) => [
        Number(distanceFromMeters(pt.distance_meters, currentUnits).toFixed(3)),
        Number(elevationFromMeters(pt.altitude, currentUnits).toFixed(1)),
    ]);

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 40,
            bottom: 24,
            containLabel: true,
        },
        tooltip: {
            trigger: 'axis',
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const idx = first?.dataIndex;

                if (idx === undefined || !rows[idx]) {
                    return '';
                }

                const pt = rows[idx];
                const lines = [
                    `<div>Distance: <strong>${escapeHtml(formatDistance(pt.distance_meters, 2))}</strong></div>`,
                    `<div>Elevation: <strong>${escapeHtml(formatElevation(pt.altitude))}</strong></div>`,
                ];

                if (pt.grade_pct !== null) {
                    lines.push(
                        `<div>Grade: <strong>${pt.grade_pct.toFixed(1)}%</strong></div>`,
                    );
                }

                return lines.join('');
            },
        },
        xAxis: {
            type: 'value',
            name: distanceUnit.value,
            nameLocation: 'middle',
            nameGap: 18,
            nameTextStyle: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
            },
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            splitLine: { show: false },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
            },
        },
        yAxis: {
            type: 'value',
            scale: true,
            name: elevationUnit.value,
            nameGap: 12,
            nameTextStyle: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
                align: 'left',
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
            },
        },
        series: [
            {
                type: 'line',
                data,
                showSymbol: false,
                smooth: 0.2,
                lineStyle: { color: accent, width: 2 },
                areaStyle: { color: accent, opacity: 0.16 },
            },
        ],
    };
});

const progressionOption = computed<EChartsOption>(() => {
    const currentUnits = units.value;
    const rows = props.progression;
    const accent =
        selectedSportMeta.value?.accent_hex ||
        props.segment.accent_hex ||
        '#10b981';
    const labels = rows.map((row) => formatShortDate(row.date));
    const hasVam = rows.some((row) => row.vam !== null && row.vam > 0);
    const isPace = usesPace.value;
    const showEffortSport = hasMixedSports.value;
    const tintByEffortSport =
        hasMixedSports.value && props.selected_sport === null;

    const timeData = rows.map((row) => {
        if (row.is_display_pr) {
            return {
                value: row.elapsed_seconds,
                symbol: 'diamond',
                symbolSize: 12,
                itemStyle: {
                    color: '#10b981',
                    borderColor: '#f59e0b',
                    borderWidth: 2.5,
                },
            };
        }

        if (tintByEffortSport) {
            return {
                value: row.elapsed_seconds,
                itemStyle: {
                    color: getSportMeta(row.sport_key).accent_hex,
                },
            };
        }

        return row.elapsed_seconds;
    });

    const vamData = rows.map((row) =>
        row.vam !== null && row.vam > 0
            ? Math.round(elevationFromMeters(row.vam, currentUnits))
            : null,
    );

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 56,
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
                fontFamily: theme.fontFamilyMono,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const idx = first?.dataIndex;

                if (idx === undefined || !rows[idx]) {
                    return '';
                }

                const effort = rows[idx];
                const effortSportSuffix = showEffortSport
                    ? ` · ${getSportMeta(effort.sport_key).label}`
                    : '';
                const lines = [
                    `<div style="font-weight:600;margin-bottom:2px;">${escapeHtml(effort.activity_name)}${effort.is_display_pr ? ' (◆ PR)' : ''}</div>`,
                    `<div style="opacity:0.75;margin-bottom:4px;">${escapeHtml(formatDateWithYear(effort.date))}${escapeHtml(effortSportSuffix)}</div>`,
                    `<div>Time: <strong>${escapeHtml(formatDurationClock(effort.elapsed_seconds))}</strong></div>`,
                    `<div>${isPace ? 'Pace' : 'Speed'}: <strong>${escapeHtml(formatMetricSpeedOrPace(effort.avg_speed))}</strong></div>`,
                ];

                if (effort.vam !== null && effort.vam > 0) {
                    lines.push(
                        `<div>VAM: <strong>${escapeHtml(formatVam(effort.vam))}</strong></div>`,
                    );
                }

                if (effort.avg_power !== null) {
                    const wkg =
                        effort.w_per_kg !== null
                            ? ` (${effort.w_per_kg.toFixed(2)} W/kg)`
                            : '';
                    lines.push(
                        `<div>Power: <strong>${Math.round(effort.avg_power)} W${escapeHtml(wkg)}</strong></div>`,
                    );
                }

                if (effort.avg_hr !== null) {
                    lines.push(
                        `<div>Avg HR: <strong>${effort.avg_hr} bpm</strong></div>`,
                    );
                }

                return lines.join('');
            },
        },
        xAxis: {
            type: 'category',
            data: labels,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
            },
        },
        yAxis: [
            {
                type: 'value',
                inverse: true,
                scale: true,
                name: 'Time (faster ↑)',
                nameGap: 14,
                nameTextStyle: {
                    color: theme.mutedColor.value,
                    fontFamily: theme.fontFamilyMono,
                    fontSize: 10,
                    align: 'left',
                },
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
                axisLabel: {
                    color: theme.mutedColor.value,
                    fontFamily: theme.fontFamilyMono,
                    fontSize: 10,
                    formatter: (val: number) => formatDurationClock(val),
                },
            },
            ...(hasVam
                ? [
                      {
                          type: 'value' as const,
                          scale: true,
                          name: `VAM (${vamUnit.value})`,
                          nameGap: 14,
                          nameTextStyle: {
                              color: '#f59e0b',
                              fontFamily: theme.fontFamilyMono,
                              fontSize: 10,
                              align: 'right' as const,
                          },
                          splitLine: { show: false },
                          axisLabel: {
                              color: '#f59e0b',
                              fontFamily: theme.fontFamilyMono,
                              fontSize: 10,
                          },
                      },
                  ]
                : []),
        ],
        series: [
            {
                name: 'Elapsed Time',
                type: 'line',
                yAxisIndex: 0,
                data: timeData,
                smooth: 0.2,
                symbol: 'circle',
                symbolSize: 7,
                lineStyle: { color: accent, width: 2.2 },
                itemStyle: { color: accent },
                areaStyle: { color: accent, opacity: 0.1 },
            },
            ...(hasVam
                ? [
                      {
                          name: `VAM (${vamUnit.value})`,
                          type: 'line' as const,
                          yAxisIndex: 1,
                          data: vamData,
                          smooth: 0.2,
                          symbol: 'circle',
                          symbolSize: 5,
                          lineStyle: {
                              color: '#f59e0b',
                              width: 1.5,
                              type: 'dashed' as const,
                          },
                          itemStyle: { color: '#f59e0b' },
                      },
                  ]
                : []),
        ],
    };
});

function onProgressionClick(payload: ChartPointClickPayload): void {
    const effort = props.progression[payload.dataIndex];

    if (effort) {
        router.visit(showActivity.url(effort.activity_id));
    }
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="segment.name" />

        <!-- Top Navigation & Badges -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Link
                :href="segmentsIndex.url()"
                class="inline-flex items-center gap-1.5 font-mono text-xs text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            >
                <ArrowLeft class="size-3.5" />
                Segments &amp; Climbs
            </Link>

            <div class="flex flex-wrap items-center gap-2">
                <span
                    :class="[
                        'rounded-md border px-2 py-0.5 font-mono text-[11px] font-medium',
                        sportBadgeClasses(sportMeta.color_token),
                    ]"
                >
                    {{ sportMeta.label }}
                </span>
                <span
                    v-if="categoryMeta"
                    :class="[
                        'rounded-md border px-2 py-0.5 font-mono text-[11px] font-semibold',
                        categoryMeta.badgeClass,
                    ]"
                >
                    {{ categoryMeta.label }}
                </span>
                <span
                    v-if="segment.kind === 'custom'"
                    class="inline-flex items-center gap-1 rounded-md border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 font-mono text-[11px] font-medium text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-300"
                >
                    <Flag class="size-3" />
                    Custom Segment
                </span>
            </div>
        </div>

        <!-- Title, Rename, Star & Delete Header -->
        <div
            class="flex flex-col gap-3 rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                v-if="!isEditingName"
                class="flex flex-wrap items-start justify-between gap-3"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1
                            class="truncate text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                        >
                            {{ segment.name }}
                        </h1>
                        <button
                            type="button"
                            :disabled="isTogglingStar"
                            :title="
                                segment.is_starred
                                    ? 'Unstar segment'
                                    : 'Star segment'
                            "
                            :aria-label="
                                segment.is_starred
                                    ? 'Unstar segment'
                                    : 'Star segment'
                            "
                            class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-zinc-200/90 text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-amber-500 dark:border-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800"
                            @click="toggleStar"
                        >
                            <Star
                                class="size-4"
                                :class="
                                    segment.is_starred
                                        ? 'fill-amber-400 text-amber-500 dark:fill-amber-400 dark:text-amber-400'
                                        : ''
                                "
                            />
                        </button>
                        <button
                            type="button"
                            title="Rename segment"
                            aria-label="Rename segment"
                            class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-zinc-200/90 text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                            @click="startEditingName"
                        >
                            <Pencil class="size-3.5" />
                        </button>

                        <template v-if="!confirmingDelete">
                            <button
                                type="button"
                                title="Delete segment"
                                aria-label="Delete segment"
                                class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-zinc-200/90 text-zinc-400 transition-colors hover:border-rose-500/40 hover:bg-rose-500/10 hover:text-rose-600 dark:border-zinc-800 dark:text-zinc-500 dark:hover:text-rose-400"
                                @click="confirmingDelete = true"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </template>
                        <div
                            v-else
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-500/35 bg-rose-500/10 px-2.5 py-1"
                        >
                            <span
                                class="font-mono text-[11px] font-medium text-rose-700 dark:text-rose-300"
                            >
                                Delete?
                            </span>
                            <button
                                type="button"
                                :disabled="isDeleting"
                                class="cursor-pointer rounded bg-rose-600 px-2 py-0.5 font-mono text-[10px] font-medium text-white hover:bg-rose-700 disabled:opacity-60"
                                @click="confirmDeleteSegment"
                            >
                                {{ isDeleting ? 'Deleting...' : 'Confirm' }}
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded px-1.5 py-0.5 font-mono text-[10px] text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100"
                                @click="confirmingDelete = false"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>

                    <p
                        v-if="segment.place_label"
                        class="mt-1 flex items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        <MapPin
                            class="size-3.5 shrink-0 text-zinc-400 dark:text-zinc-500"
                        />
                        <span class="truncate">{{ segment.place_label }}</span>
                    </p>
                    <p
                        v-if="
                            segment.name_override &&
                            segment.name_override !== segment.auto_name
                        "
                        class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Auto-detected: {{ segment.auto_name }}
                    </p>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    <span>{{
                        formatDistance(segment.distance_meters, 2)
                    }}</span>
                    <span
                        v-if="segment.elevation_gain !== null"
                        class="inline-flex items-center gap-1"
                    >
                        <Mountain class="size-3.5" />
                        {{ formatElevation(segment.elevation_gain) }} gain
                    </span>
                    <span
                        v-if="segment.avg_grade_pct !== null"
                        class="inline-flex items-center gap-1"
                    >
                        <TrendingUp class="size-3.5" />
                        {{ formatGrade(segment.avg_grade_pct) }} avg
                        <template v-if="segment.max_grade_pct !== null">
                            · {{ formatGrade(segment.max_grade_pct) }} max
                        </template>
                    </span>
                    <span
                        v-if="segment.fiets_score !== null"
                        class="inline-flex items-center gap-1"
                    >
                        <span>FIETS {{ segment.fiets_score.toFixed(2) }}</span>
                        <InfoPopover topic="fiets" />
                    </span>
                </div>
            </div>

            <form
                v-else
                class="flex flex-col gap-3 sm:flex-row sm:items-center"
                @submit.prevent="submitRename"
            >
                <div class="flex-1">
                    <input
                        ref="renameInputRef"
                        v-model="renameForm.name_override"
                        type="text"
                        maxlength="160"
                        autofocus
                        placeholder="Custom segment name..."
                        aria-label="Segment name"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        @keydown.escape="cancelEditingName"
                    />
                    <p
                        v-if="renameForm.errors.name_override"
                        class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                    >
                        {{ renameForm.errors.name_override }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="submit"
                        :disabled="renameForm.processing"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-2 font-mono text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                    >
                        <Check class="size-3.5" />
                        Save
                    </button>
                    <button
                        v-if="segment.name_override"
                        type="button"
                        :disabled="renameForm.processing"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 font-mono text-xs text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="resetAutoName"
                    >
                        <RotateCcw class="size-3.5" />
                        Reset Auto
                    </button>
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-zinc-200 px-2.5 py-2 font-mono text-xs text-zinc-500 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        @click="cancelEditingName"
                    >
                        <X class="size-3.5" />
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <!-- KPI Strip -->
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ effortsKpiLabel }}
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatNumber(summary.effort_count, 0) }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{
                        summary.median_elapsed_seconds !== null
                            ? `Median ${formatDurationClock(summary.median_elapsed_seconds)}`
                            : `${summary.all_time_effort_count} all-time`
                    }}
                </p>
            </div>

            <div
                class="rounded-xl border border-amber-500/30 bg-amber-500/[0.04] p-4 dark:border-amber-500/25 dark:bg-amber-500/[0.06]"
            >
                <p
                    class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-amber-700 uppercase dark:text-amber-400"
                >
                    <Trophy class="size-3" />
                    {{ prKpiLabel }}
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatDurationClock(summary.pr_elapsed_seconds) }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-600 tabular-nums dark:text-zinc-300"
                >
                    {{ formatMetricSpeedOrPace(summary.pr_speed_mps) }}
                    <span
                        v-if="summary.pr_date"
                        class="text-zinc-500 dark:text-zinc-400"
                    >
                        · {{ formatDateWithYear(summary.pr_date) }}
                    </span>
                </p>
            </div>

            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <p
                    class="flex items-center gap-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    <span>Best VAM</span>
                    <InfoPopover topic="vam" />
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatVam(summary.best_vam) }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{
                        segment.avg_grade_pct !== null
                            ? `${formatGrade(segment.avg_grade_pct)} avg grade`
                            : 'Vertical ascent speed'
                    }}
                </p>
            </div>

            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <p
                    class="flex items-center gap-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    <span>Best Power / W/kg</span>
                    <InfoPopover topic="w_per_kg" />
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    <template v-if="summary.best_w_per_kg !== null">
                        {{ summary.best_w_per_kg.toFixed(2) }} W/kg
                    </template>
                    <template v-else-if="summary.best_avg_power !== null">
                        {{ Math.round(summary.best_avg_power) }} W
                    </template>
                    <template v-else>—</template>
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{
                        summary.best_avg_power !== null
                            ? `${Math.round(summary.best_avg_power)} W peak avg`
                            : 'No power telemetry'
                    }}
                </p>
            </div>
        </section>

        <!-- Map + Progression / Elevation Profile -->
        <section class="grid grid-cols-1 gap-6 xl:grid-cols-12">
            <!-- MapLibre Segment Preview -->
            <div
                class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white xl:col-span-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800/80"
                >
                    <h2
                        class="font-mono text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                    >
                        Segment Map
                    </h2>
                </div>
                <div class="p-3">
                    <ActivityMap
                        v-if="polyline.length >= 2"
                        :polyline="polyline"
                        :accent-color="segment.accent_hex"
                        :height="320"
                    />
                    <div
                        v-else
                        class="flex h-80 items-center justify-center rounded-lg bg-[#f7f7f5] font-mono text-xs text-zinc-500 dark:bg-zinc-900/50 dark:text-zinc-400"
                    >
                        No GPS coordinates available
                    </div>
                </div>
            </div>

            <!-- Progression & Elevation Charts -->
            <div class="space-y-6 xl:col-span-7">
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div
                        class="mb-3 flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2
                            class="font-mono text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                        >
                            Effort Progression
                        </h2>
                        <span
                            class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            Click any point to inspect activity · ◆ marks PR
                        </span>
                    </div>
                    <BaseChart
                        v-if="progression.length > 0"
                        :option="progressionOption"
                        height="220px"
                        @point-click="onProgressionClick"
                    />
                    <p
                        v-else
                        class="py-14 text-center text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        No matched efforts recorded for this timeframe.
                    </p>
                </div>

                <div
                    v-if="elevation_profile.length >= 2"
                    class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <h2
                            class="font-mono text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                        >
                            Elevation Profile
                        </h2>
                        <span
                            v-if="segment.avg_grade_pct !== null"
                            class="font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{ formatGrade(segment.avg_grade_pct) }} avg
                            <template v-if="segment.max_grade_pct !== null">
                                · {{ formatGrade(segment.max_grade_pct) }} max
                            </template>
                        </span>
                    </div>
                    <BaseChart
                        :option="elevationProfileOption"
                        height="165px"
                    />
                </div>
            </div>
        </section>

        <!-- Personal Leaderboard Table -->
        <section
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800/80"
            >
                <h2
                    class="font-mono text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                >
                    Personal Leaderboard ({{ efforts.length }})
                </h2>

                <div class="flex flex-wrap items-center gap-2">
                    <!-- Discipline Filter Pills (shown when efforts span multiple disciplines) -->
                    <div
                        v-if="hasMixedSports"
                        class="flex flex-wrap items-center gap-1"
                    >
                        <button
                            type="button"
                            class="cursor-pointer rounded-md border px-2 py-1 font-mono text-[11px] font-medium transition-colors"
                            :class="
                                selected_sport === null
                                    ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                    : 'border-zinc-200/80 text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="selectSport(null)"
                        >
                            All
                        </button>
                        <button
                            v-for="sportOption in available_sports"
                            :key="sportOption.key"
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 font-mono text-[11px] font-medium tabular-nums transition-colors"
                            :class="
                                selected_sport === sportOption.key
                                    ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                    : 'border-zinc-200/80 text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="selectSport(sportOption.key)"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :style="{
                                    backgroundColor: sportOption.accent_hex,
                                }"
                            />
                            <span>{{ sportOption.short_label }}</span>
                            <span class="opacity-65">{{
                                sportOption.count
                            }}</span>
                        </button>
                    </div>

                    <span
                        v-if="hasMixedSports && available_years.length > 0"
                        class="hidden h-4 w-px bg-zinc-200 sm:inline-block dark:bg-zinc-800"
                    />

                    <!-- Year Filter Pills -->
                    <div
                        v-if="available_years.length > 0"
                        class="flex flex-wrap items-center gap-1"
                    >
                        <button
                            type="button"
                            class="cursor-pointer rounded-md border px-2 py-1 font-mono text-[11px] font-medium transition-colors"
                            :class="
                                selected_year === null
                                    ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                    : 'border-zinc-200/80 text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="selectYear(null)"
                        >
                            All-Time
                        </button>
                        <button
                            v-for="year in available_years"
                            :key="year"
                            type="button"
                            class="cursor-pointer rounded-md border px-2 py-1 font-mono text-[11px] font-medium tabular-nums transition-colors"
                            :class="
                                selected_year === year
                                    ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                    : 'border-zinc-200/80 text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="selectYear(year)"
                        >
                            {{ year }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="efforts.length > 0" class="overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr
                            class="border-b border-zinc-100 bg-zinc-50/70 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:text-zinc-400"
                        >
                            <th class="px-4 py-2.5">Rank</th>
                            <th class="px-4 py-2.5">Date</th>
                            <th class="px-4 py-2.5">Activity</th>
                            <th class="px-4 py-2.5 text-right">Time</th>
                            <th class="px-4 py-2.5 text-right">vs PR</th>
                            <th class="px-4 py-2.5 text-right">
                                {{ usesPace ? 'Pace' : 'Speed' }}
                            </th>
                            <th v-if="hasAnyVam" class="px-4 py-2.5 text-right">
                                <span
                                    class="inline-flex items-center justify-end gap-1"
                                >
                                    <span>VAM</span>
                                    <InfoPopover topic="vam" />
                                </span>
                            </th>
                            <th
                                v-if="hasAnyPower"
                                class="px-4 py-2.5 text-right"
                            >
                                Power
                            </th>
                            <th class="px-4 py-2.5 text-right">Avg HR</th>
                            <th v-if="hasAnyWeather" class="px-4 py-2.5">
                                Weather
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-zinc-100 dark:divide-zinc-800/70"
                    >
                        <tr
                            v-for="effort in efforts"
                            :key="effort.id"
                            class="transition-colors hover:bg-zinc-50/70 dark:hover:bg-zinc-900/40"
                        >
                            <td class="px-4 py-2.5">
                                <span
                                    :class="[
                                        'inline-flex min-w-7 items-center justify-center rounded-md border px-1.5 py-0.5 font-mono text-[11px] font-semibold tabular-nums',
                                        rankBadgeClass(effort.display_rank),
                                    ]"
                                >
                                    #{{ effort.display_rank }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-2.5 font-mono text-xs whitespace-nowrap text-zinc-600 tabular-nums dark:text-zinc-300"
                            >
                                {{ formatDateWithYear(effort.date) }}
                            </td>
                            <td class="max-w-xs px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <Link
                                        :href="
                                            showActivity.url(effort.activity_id)
                                        "
                                        class="min-w-0 truncate font-medium text-zinc-900 transition-colors hover:text-emerald-600 dark:text-zinc-100 dark:hover:text-emerald-400"
                                    >
                                        {{ effort.activity_name }}
                                    </Link>
                                    <span
                                        v-if="hasMixedSports"
                                        :class="[
                                            'shrink-0 rounded border px-1.5 py-0.25 font-mono text-[10px] font-medium',
                                            sportBadgeClasses(
                                                getSportMeta(effort.sport_key)
                                                    .color_token,
                                            ),
                                        ]"
                                    >
                                        {{
                                            getSportMeta(effort.sport_key)
                                                .short_label
                                        }}
                                    </span>
                                </div>
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-mono text-xs font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                            >
                                {{
                                    formatDurationClock(effort.elapsed_seconds)
                                }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-mono text-xs tabular-nums"
                                :class="
                                    effort.is_display_pr
                                        ? 'font-semibold text-amber-600 dark:text-amber-400'
                                        : 'text-zinc-500 dark:text-zinc-400'
                                "
                            >
                                {{ formatDeltaPr(effort.delta_pr_seconds) }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-mono text-xs text-zinc-700 tabular-nums dark:text-zinc-300"
                            >
                                {{ formatMetricSpeedOrPace(effort.avg_speed) }}
                            </td>
                            <td
                                v-if="hasAnyVam"
                                class="px-4 py-2.5 text-right font-mono text-xs text-zinc-700 tabular-nums dark:text-zinc-300"
                            >
                                {{ formatVam(effort.vam) }}
                            </td>
                            <td
                                v-if="hasAnyPower"
                                class="px-4 py-2.5 text-right font-mono text-xs text-zinc-700 tabular-nums dark:text-zinc-300"
                            >
                                <template v-if="effort.avg_power !== null">
                                    {{ Math.round(effort.avg_power) }} W
                                    <span
                                        v-if="effort.w_per_kg !== null"
                                        class="text-zinc-500 dark:text-zinc-400"
                                    >
                                        · {{ effort.w_per_kg.toFixed(2) }} W/kg
                                    </span>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-mono text-xs text-zinc-600 tabular-nums dark:text-zinc-400"
                            >
                                {{
                                    effort.avg_hr !== null
                                        ? `${effort.avg_hr} bpm`
                                        : '—'
                                }}
                            </td>
                            <td
                                v-if="hasAnyWeather"
                                class="px-4 py-2.5 whitespace-nowrap"
                            >
                                <div
                                    v-if="effort.weather"
                                    class="flex items-center gap-1.5 font-mono text-[11px]"
                                >
                                    <span
                                        v-if="
                                            effort.weather.temperature_c !==
                                            null
                                        "
                                        class="inline-flex items-center gap-1 text-zinc-600 dark:text-zinc-300"
                                    >
                                        <CloudSun class="size-3" />
                                        {{
                                            formatTemp(
                                                effort.weather.temperature_c,
                                            )
                                        }}
                                    </span>
                                    <span
                                        :class="[
                                            'rounded-md border px-1.5 py-0.5 text-[10px] font-medium',
                                            windPillClass(
                                                effort.weather.wind_label,
                                            ),
                                        ]"
                                    >
                                        {{ effort.weather.wind_label }}
                                        <template
                                            v-if="
                                                effort.weather
                                                    .wind_speed_kmh !== null &&
                                                effort.weather.wind_label !==
                                                    'Calm'
                                            "
                                        >
                                            ·
                                            {{
                                                formatSpeedKmh(
                                                    effort.weather
                                                        .wind_speed_kmh,
                                                    0,
                                                )
                                            }}
                                        </template>
                                    </span>
                                </div>
                                <span
                                    v-else
                                    class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    —
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p
                v-else
                class="px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400"
            >
                No matched efforts recorded yet.
            </p>
        </section>

        <!-- ODbL Attribution Footer -->
        <footer
            class="pt-2 text-right font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
        >
            Place names &copy;
            <a
                href="https://www.openstreetmap.org/copyright"
                target="_blank"
                rel="noopener noreferrer"
                class="underline decoration-zinc-300 underline-offset-2 transition-colors hover:text-zinc-700 dark:decoration-zinc-700 dark:hover:text-zinc-200"
            >
                OpenStreetMap contributors (ODbL)
            </a>
        </footer>
    </div>
</template>
