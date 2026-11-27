<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeft,
    ArrowLeftRight,
    ArrowUp,
    ArrowUpDown,
    Check,
    CloudSun,
    MapPin,
    Mountain,
    Pencil,
    Repeat,
    RotateCcw,
    Thermometer,
    Trophy,
    Unlink,
    Wind,
    X,
} from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
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
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    compare as activitiesCompare,
    show as showActivity,
} from '@/routes/activities';
import { index as routesIndex, update as updateRoute } from '@/routes/routes';
import { unlink as unlinkActivityRoute } from '@/routes/routes/activities';
import type { RouteAttemptItem, MatchedRouteShowProps } from '@/types/routes';

const props = defineProps<MatchedRouteShowProps>();

const page = usePage();
const theme = useChartTheme();
const { getSportMeta, sportBadgeClasses } = useSports();
const {
    formatDistance,
    formatElevation,
    formatPace,
    formatPace100m,
    formatPaceSeconds,
    formatSpeed,
    formatSpeedKmh,
    formatTemp,
    paceSecondsFromMps,
    paceUnit,
    speedFromMps,
    speedUnit,
} = useUnits();

const sportMeta = computed(() => getSportMeta(props.route.sport_key));
const usesPace = computed<boolean>(
    () =>
        props.route.metric_mode === 'pace' ||
        props.route.metric_mode === 'pace_100m',
);

const isEditingName = ref<boolean>(false);
const renameInputRef = ref<HTMLInputElement | null>(null);
const confirmingUnlinkId = ref<number | null>(null);
const unlinkingId = ref<number | null>(null);

const renameForm = useForm({
    name_override: props.route.name_override ?? props.route.name,
});

watch(
    () => [props.route.name, props.route.name_override],
    () => {
        if (!isEditingName.value) {
            renameForm.name_override =
                props.route.name_override ?? props.route.name;
        }
    },
);

function startEditingName(): void {
    renameForm.name_override = props.route.name_override ?? props.route.name;
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
    renameForm.patch(updateRoute.url(props.route.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditingName.value = false;
            toast.success(page.props.flash?.status ?? 'Route name updated.');
        },
    });
}

function resetAutoName(): void {
    renameForm.name_override = '';
    renameForm.patch(updateRoute.url(props.route.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditingName.value = false;
            toast.success(
                page.props.flash?.status ??
                    'Route name reset to auto-detected name.',
            );
        },
    });
}

function confirmUnlink(activityId: number): void {
    if (unlinkingId.value !== null) {
        return;
    }

    router.delete(
        unlinkActivityRoute.url({
            routeCluster: props.route.id,
            activity: activityId,
        }),
        {
            preserveScroll: true,
            onStart: () => {
                unlinkingId.value = activityId;
            },
            onSuccess: () => {
                confirmingUnlinkId.value = null;
                toast.success(
                    page.props.flash?.status ?? 'Activity unlinked from route.',
                );
            },
            onError: () => {
                toast.error('Failed to unlink activity from route.');
            },
            onFinish: () => {
                unlinkingId.value = null;
            },
        },
    );
}

type AttemptSortKey = 'date' | 'duration' | 'pace' | 'delta' | 'hr' | 'power';

const sortKey = ref<AttemptSortKey>('date');
const sortDir = ref<'asc' | 'desc'>('desc');

function toggleSort(column: AttemptSortKey): void {
    if (sortKey.value === column) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = column;
        sortDir.value =
            column === 'duration' ||
            column === 'delta' ||
            (column === 'pace' && usesPace.value)
                ? 'asc'
                : 'desc';
    }
}

const hasAnyPower = computed<boolean>(() =>
    props.attempts.some((a) => a.avg_power !== null),
);

const hasAnyWeather = computed<boolean>(() =>
    props.attempts.some((a) => a.weather !== null),
);

const prActivityId = computed<number | null>(
    () =>
        props.summary.pr_activity_id ??
        props.attempts.find((a) => a.is_pr)?.activity_id ??
        null,
);

const latestAttempt = computed<RouteAttemptItem | null>(() => {
    if (props.attempts.length === 0) {
        return null;
    }

    return [...props.attempts].sort((a, b) =>
        b.started_at_local.localeCompare(a.started_at_local),
    )[0];
});

const latestVsPrCompareUrl = computed<string | null>(() => {
    if (
        prActivityId.value === null ||
        latestAttempt.value === null ||
        latestAttempt.value.activity_id === prActivityId.value
    ) {
        return null;
    }

    return activitiesCompare.url({
        query: {
            ids: `${prActivityId.value},${latestAttempt.value.activity_id}`,
        },
    });
});

function compareVsPrUrl(activityId: number): string {
    return activitiesCompare.url({
        query: { ids: `${prActivityId.value},${activityId}` },
    });
}

const sortedAttempts = computed<RouteAttemptItem[]>(() => {
    const list = [...props.attempts];
    const dir = sortDir.value === 'asc' ? 1 : -1;
    const isPace = usesPace.value;

    list.sort((a, b) => {
        let cmp = 0;

        switch (sortKey.value) {
            case 'duration':
                cmp = a.duration_seconds - b.duration_seconds;
                break;
            case 'pace': {
                const aSpeed =
                    a.avg_speed !== null && a.avg_speed > 0
                        ? a.avg_speed
                        : null;
                const bSpeed =
                    b.avg_speed !== null && b.avg_speed > 0
                        ? b.avg_speed
                        : null;

                if (aSpeed === null && bSpeed === null) {
                    return (a.activity_id - b.activity_id) * dir;
                }

                if (aSpeed === null) {
                    return 1;
                }

                if (bSpeed === null) {
                    return -1;
                }

                cmp = isPace ? 1 / aSpeed - 1 / bSpeed : aSpeed - bSpeed;
                break;
            }
            case 'delta':
                cmp = a.delta_seconds - b.delta_seconds;
                break;
            case 'hr': {
                if (a.avg_hr === null && b.avg_hr === null) {
                    return (a.activity_id - b.activity_id) * dir;
                }

                if (a.avg_hr === null) {
                    return 1;
                }

                if (b.avg_hr === null) {
                    return -1;
                }

                cmp = a.avg_hr - b.avg_hr;
                break;
            }
            case 'power': {
                if (a.avg_power === null && b.avg_power === null) {
                    return (a.activity_id - b.activity_id) * dir;
                }

                if (a.avg_power === null) {
                    return 1;
                }

                if (b.avg_power === null) {
                    return -1;
                }

                cmp = a.avg_power - b.avg_power;
                break;
            }
            case 'date':
            default:
                cmp = a.started_at_local.localeCompare(b.started_at_local);
                break;
        }

        if (cmp !== 0) {
            return cmp * dir;
        }

        return (a.activity_id - b.activity_id) * dir;
    });

    return list;
});

function formatMetricSpeedOrPace(speedMps: number | null): string {
    if (props.route.metric_mode === 'pace_100m') {
        return formatPace100m(speedMps);
    }

    if (props.route.metric_mode === 'pace') {
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

function escapeHtml(raw: string): string {
    return raw
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
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

function formatSignedDuration(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds)) {
        return '—';
    }

    if (Math.abs(seconds) < 1) {
        return '0:00';
    }

    const sign = seconds > 0 ? '+' : '-';

    return `${sign}${formatDurationClock(Math.abs(seconds))}`;
}

const progressionOption = computed<EChartsOption>(() => {
    const rows = props.progression;
    const accent = props.route.accent_hex || '#10b981';
    const isPace = usesPace.value;
    const isPace100m = props.route.metric_mode === 'pace_100m';

    const labels = rows.map((row) => formatShortDate(row.date));
    const hasHr = rows.some((row) => row.avg_hr !== null);

    const primaryData = rows.map((row) => {
        let value: number | null = null;

        if (row.avg_speed !== null && row.avg_speed > 0) {
            if (isPace100m) {
                value = Number((100 / row.avg_speed).toFixed(2));
            } else if (isPace) {
                value = paceSecondsFromMps(row.avg_speed);
            } else {
                value = Number(speedFromMps(row.avg_speed).toFixed(2));
            }
        }

        if (row.is_pr) {
            return {
                value,
                symbol: 'diamond',
                symbolSize: 12,
                itemStyle: {
                    color: '#f59e0b',
                    borderColor: theme.isDark.value ? '#141417' : '#ffffff',
                    borderWidth: 2,
                },
            };
        }

        return value;
    });

    const hrData = rows.map((row) => row.avg_hr);
    const primaryYAxisName = isPace100m
        ? 'Pace (/100m)'
        : isPace
          ? `Pace (${paceUnit.value})`
          : `Speed (${speedUnit.value})`;

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

                const attempt = rows[idx];
                const lines = [
                    `<div style="font-weight:600;margin-bottom:4px;">${escapeHtml(attempt.name)}${attempt.is_pr ? ' ◆ PR' : ''}</div>`,
                    `<div style="opacity:0.75;margin-bottom:4px;">${escapeHtml(formatDateWithYear(attempt.date))}</div>`,
                    `<div>Time: <strong>${escapeHtml(formatDurationClock(attempt.duration_seconds))}</strong></div>`,
                    `<div>${isPace ? 'Pace' : 'Speed'}: <strong>${escapeHtml(formatMetricSpeedOrPace(attempt.avg_speed))}</strong></div>`,
                ];

                if (attempt.avg_hr !== null) {
                    lines.push(
                        `<div>Avg HR: <strong>${attempt.avg_hr} bpm</strong></div>`,
                    );
                }

                if (
                    attempt.weather?.temperature_c !== null &&
                    attempt.weather
                ) {
                    lines.push(
                        `<div>Weather: <strong>${escapeHtml(formatTemp(attempt.weather.temperature_c))} · ${escapeHtml(attempt.weather.wind_label)}</strong></div>`,
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
                inverse: isPace,
                scale: true,
                name: primaryYAxisName,
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
                    formatter: (val: number) =>
                        isPace
                            ? formatPaceSeconds(val).replace(/ \/.*$/, '')
                            : `${val.toFixed(1)}`,
                },
            },
            ...(hasHr
                ? [
                      {
                          type: 'value' as const,
                          scale: true,
                          name: 'HR (bpm)',
                          nameGap: 14,
                          nameTextStyle: {
                              color: '#f43f5e',
                              fontFamily: theme.fontFamilyMono,
                              fontSize: 10,
                              align: 'right' as const,
                          },
                          splitLine: { show: false },
                          axisLabel: {
                              color: '#f43f5e',
                              fontFamily: theme.fontFamilyMono,
                              fontSize: 10,
                          },
                      },
                  ]
                : []),
        ],
        series: [
            {
                name: isPace ? 'Pace' : 'Speed',
                type: 'line',
                yAxisIndex: 0,
                data: primaryData,
                smooth: 0.25,
                symbol: 'circle',
                symbolSize: 7,
                lineStyle: { color: accent, width: 2.5 },
                itemStyle: { color: accent },
                areaStyle: {
                    color: accent,
                    opacity: 0.08,
                },
            },
            ...(hasHr
                ? [
                      {
                          name: 'Avg HR',
                          type: 'line' as const,
                          yAxisIndex: 1,
                          data: hrData,
                          smooth: 0.25,
                          symbol: 'circle',
                          symbolSize: 5,
                          lineStyle: {
                              color: '#f43f5e',
                              width: 1.5,
                              type: 'dashed' as const,
                          },
                          itemStyle: { color: '#f43f5e' },
                      },
                  ]
                : []),
        ],
    };
});

function onProgressionClick(payload: ChartPointClickPayload): void {
    const attempt = props.progression[payload.dataIndex];

    if (attempt) {
        router.visit(showActivity.url(attempt.activity_id));
    }
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="route.name" />

        <!-- Top Breadcrumb Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Link
                :href="routesIndex.url()"
                prefetch
                class="inline-flex items-center gap-1.5 font-mono text-xs text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            >
                <ArrowLeft class="size-3.5" />
                Matched Routes
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
                    class="inline-flex items-center gap-1 rounded-md border border-zinc-200/90 bg-white px-2 py-0.5 font-mono text-[11px] text-zinc-600 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-300"
                >
                    <Repeat v-if="route.is_loop" class="size-3" />
                    {{ route.is_loop ? 'Loop' : 'Point-to-Point' }}
                </span>
                <span
                    v-if="!route.place_label && route.location_name"
                    class="inline-flex items-center gap-1 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                >
                    <MapPin class="size-3.5" />
                    {{ route.location_name }}
                </span>
            </div>
        </div>

        <!-- Title & Inline Rename Header -->
        <div
            class="flex flex-col gap-3 rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                v-if="!isEditingName"
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2.5">
                        <h1
                            class="truncate text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                        >
                            {{ route.name }}
                        </h1>
                        <button
                            type="button"
                            title="Rename route"
                            aria-label="Rename route"
                            class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-zinc-200/90 text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                            @click="startEditingName"
                        >
                            <Pencil class="size-3.5" />
                        </button>
                    </div>
                    <p
                        v-if="route.place_label"
                        class="mt-1 flex items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400"
                    >
                        <MapPin
                            class="size-3.5 shrink-0 text-zinc-400 dark:text-zinc-500"
                        />
                        <span class="truncate">{{ route.place_label }}</span>
                    </p>
                    <p
                        v-if="
                            route.name_override &&
                            route.name_override !== route.auto_name
                        "
                        class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Auto-detected: {{ route.auto_name }}
                    </p>
                </div>

                <div
                    class="flex flex-wrap items-center gap-4 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                >
                    <span>{{ formatDistance(route.distance_meters, 2) }}</span>
                    <span
                        v-if="route.elevation_gain !== null"
                        class="inline-flex items-center gap-1"
                    >
                        <Mountain class="size-3.5" />
                        {{ formatElevation(route.elevation_gain) }}
                    </span>
                    <span v-if="route.last_activity_at">
                        Last completed
                        {{ formatDateWithYear(route.last_activity_at) }}
                    </span>
                    <Link
                        v-if="latestVsPrCompareUrl"
                        :href="latestVsPrCompareUrl"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/35 bg-emerald-500/10 px-2.5 py-1.5 font-mono text-[11px] font-medium text-emerald-700 transition-colors hover:bg-emerald-500/15 dark:border-emerald-500/30 dark:text-emerald-300 dark:hover:bg-emerald-500/20"
                    >
                        <ArrowLeftRight class="size-3" />
                        Compare Latest vs PR
                    </Link>
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
                        placeholder="Custom route name..."
                        aria-label="Route name"
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
                        v-if="route.name_override"
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
                    Attempts
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatNumber(summary.attempt_count, 0) }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{
                        summary.avg_hr !== null
                            ? `Avg HR ${Math.round(summary.avg_hr)} bpm`
                            : 'Matched activities'
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
                    Route PR
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatDurationClock(summary.pr_duration_seconds) }}
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
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Median Attempt
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatDurationClock(summary.median_duration_seconds) }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{ formatMetricSpeedOrPace(summary.median_speed_mps) }}
                </p>
            </div>

            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Distance &amp; Climb
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatDistance(route.distance_meters, 2) }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{
                        route.elevation_gain !== null
                            ? `${formatElevation(route.elevation_gain)} gain`
                            : 'No elevation data'
                    }}
                </p>
            </div>
        </section>

        <!-- Map + Progression Chart -->
        <section class="grid grid-cols-1 gap-6 xl:grid-cols-12">
            <!-- MapLibre Route Preview -->
            <div
                class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white xl:col-span-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800/80"
                >
                    <h2
                        class="font-mono text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                    >
                        Canonical Route
                    </h2>
                </div>
                <div class="p-3">
                    <ActivityMap
                        v-if="polyline.length >= 2"
                        :polyline="polyline"
                        :accent-color="route.accent_hex"
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

            <!-- Progression Over Time -->
            <div
                class="rounded-xl border border-zinc-200/90 bg-white xl:col-span-7 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800/80"
                >
                    <h2
                        class="font-mono text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                    >
                        {{ usesPace ? 'Pace' : 'Speed' }} &amp; HR Progression
                    </h2>
                    <span
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Click any point to inspect activity · ◆ marks PR
                    </span>
                </div>
                <div class="p-4">
                    <BaseChart
                        v-if="progression.length > 0"
                        :option="progressionOption"
                        :height="312"
                        @point-click="onProgressionClick"
                    />
                    <div
                        v-else
                        class="flex h-72 items-center justify-center font-mono text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        No attempts recorded yet
                    </div>
                </div>
            </div>
        </section>

        <!-- Weather Impact Card -->
        <section
            v-if="weather_impact"
            class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <CloudSun class="size-4 text-sky-500" />
                    <h2
                        class="font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                    >
                        Weather Impact Breakdown
                    </h2>
                </div>
                <span
                    class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                >
                    Based on {{ weather_impact.sample_count }}
                    {{
                        weather_impact.sample_count === 1
                            ? 'attempt'
                            : 'attempts'
                    }}
                    with weather data
                </span>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- Wind Breakdown -->
                <div
                    v-if="weather_impact.wind"
                    class="rounded-lg border border-zinc-200/80 bg-zinc-50/70 p-4 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 font-mono text-xs font-medium text-zinc-700 dark:text-zinc-200"
                        >
                            <Wind class="size-3.5 text-sky-500" />
                            Wind Exposure
                        </span>
                        <span
                            v-if="
                                weather_impact.wind
                                    .headwind_vs_tailwind_delta_seconds !== null
                            "
                            class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            Headwind vs Tailwind:
                            <strong
                                :class="
                                    weather_impact.wind
                                        .headwind_vs_tailwind_delta_seconds > 0
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : 'text-emerald-600 dark:text-emerald-400'
                                "
                            >
                                {{
                                    formatSignedDuration(
                                        weather_impact.wind
                                            .headwind_vs_tailwind_delta_seconds,
                                    )
                                }}
                            </strong>
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <div
                            class="rounded-md border border-rose-500/20 bg-rose-500/[0.05] p-2.5"
                        >
                            <p
                                class="font-mono text-[10px] text-rose-600 uppercase dark:text-rose-400"
                            >
                                Headwind
                            </p>
                            <template v-if="weather_impact.wind.headwind">
                                <p
                                    class="mt-1 font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        formatDurationClock(
                                            weather_impact.wind.headwind
                                                .avg_duration_seconds,
                                        )
                                    }}
                                </p>
                                <p
                                    class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ weather_impact.wind.headwind.count }}× ·
                                    {{
                                        formatMetricSpeedOrPace(
                                            weather_impact.wind.headwind
                                                .avg_speed,
                                        )
                                    }}
                                </p>
                            </template>
                            <p
                                v-else
                                class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                —
                            </p>
                        </div>

                        <div
                            class="rounded-md border border-zinc-200/80 bg-white/80 p-2.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                        >
                            <p
                                class="font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Calm / Cross
                            </p>
                            <template v-if="weather_impact.wind.neutral">
                                <p
                                    class="mt-1 font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        formatDurationClock(
                                            weather_impact.wind.neutral
                                                .avg_duration_seconds,
                                        )
                                    }}
                                </p>
                                <p
                                    class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ weather_impact.wind.neutral.count }}× ·
                                    {{
                                        formatMetricSpeedOrPace(
                                            weather_impact.wind.neutral
                                                .avg_speed,
                                        )
                                    }}
                                </p>
                            </template>
                            <p
                                v-else
                                class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                —
                            </p>
                        </div>

                        <div
                            class="rounded-md border border-emerald-500/20 bg-emerald-500/[0.05] p-2.5"
                        >
                            <p
                                class="font-mono text-[10px] text-emerald-600 uppercase dark:text-emerald-400"
                            >
                                Tailwind
                            </p>
                            <template v-if="weather_impact.wind.tailwind">
                                <p
                                    class="mt-1 font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        formatDurationClock(
                                            weather_impact.wind.tailwind
                                                .avg_duration_seconds,
                                        )
                                    }}
                                </p>
                                <p
                                    class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ weather_impact.wind.tailwind.count }}× ·
                                    {{
                                        formatMetricSpeedOrPace(
                                            weather_impact.wind.tailwind
                                                .avg_speed,
                                        )
                                    }}
                                </p>
                            </template>
                            <p
                                v-else
                                class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                —
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Temperature Breakdown -->
                <div
                    v-if="weather_impact.temperature"
                    class="rounded-lg border border-zinc-200/80 bg-zinc-50/70 p-4 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 font-mono text-xs font-medium text-zinc-700 dark:text-zinc-200"
                        >
                            <Thermometer class="size-3.5 text-amber-500" />
                            Temperature Split ({{
                                formatTemp(
                                    weather_impact.temperature.threshold_c,
                                )
                            }})
                        </span>
                        <span
                            class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            Warm vs Cool:
                            <strong
                                :class="
                                    weather_impact.temperature
                                        .warm_vs_cool_delta_seconds > 0
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : 'text-emerald-600 dark:text-emerald-400'
                                "
                            >
                                {{
                                    formatSignedDuration(
                                        weather_impact.temperature
                                            .warm_vs_cool_delta_seconds,
                                    )
                                }}
                            </strong>
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <div
                            class="rounded-md border border-sky-500/20 bg-sky-500/[0.05] p-2.5"
                        >
                            <p
                                class="font-mono text-[10px] text-sky-700 uppercase dark:text-sky-400"
                            >
                                Cool (avg
                                {{
                                    formatTemp(
                                        weather_impact.temperature.cool
                                            .avg_temp_c,
                                    )
                                }})
                            </p>
                            <p
                                class="mt-1 font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatDurationClock(
                                        weather_impact.temperature.cool
                                            .avg_duration_seconds,
                                    )
                                }}
                            </p>
                            <p
                                class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{ weather_impact.temperature.cool.count }}× ·
                                {{
                                    formatMetricSpeedOrPace(
                                        weather_impact.temperature.cool
                                            .avg_speed,
                                    )
                                }}
                                <span
                                    v-if="
                                        weather_impact.temperature.cool
                                            .avg_hr !== null
                                    "
                                >
                                    ·
                                    {{
                                        Math.round(
                                            weather_impact.temperature.cool
                                                .avg_hr,
                                        )
                                    }}
                                    bpm
                                </span>
                            </p>
                        </div>

                        <div
                            class="rounded-md border border-amber-500/20 bg-amber-500/[0.05] p-2.5"
                        >
                            <p
                                class="font-mono text-[10px] text-amber-700 uppercase dark:text-amber-400"
                            >
                                Warm (avg
                                {{
                                    formatTemp(
                                        weather_impact.temperature.warm
                                            .avg_temp_c,
                                    )
                                }})
                            </p>
                            <p
                                class="mt-1 font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatDurationClock(
                                        weather_impact.temperature.warm
                                            .avg_duration_seconds,
                                    )
                                }}
                            </p>
                            <p
                                class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{ weather_impact.temperature.warm.count }}× ·
                                {{
                                    formatMetricSpeedOrPace(
                                        weather_impact.temperature.warm
                                            .avg_speed,
                                    )
                                }}
                                <span
                                    v-if="
                                        weather_impact.temperature.warm
                                            .avg_hr !== null
                                    "
                                >
                                    ·
                                    {{
                                        Math.round(
                                            weather_impact.temperature.warm
                                                .avg_hr,
                                        )
                                    }}
                                    bpm
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Attempts Comparison Table -->
        <section
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex items-center justify-between border-b border-zinc-100 px-5 py-3.5 dark:border-zinc-800/80"
            >
                <h2
                    class="font-mono text-xs font-medium tracking-wider text-zinc-700 uppercase dark:text-zinc-200"
                >
                    Attempts Comparison ({{ attempts.length }})
                </h2>
                <span
                    class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                >
                    Click column headers to sort
                </span>
            </div>

            <div class="overflow-x-auto">
                <table
                    class="w-full min-w-[720px] border-collapse text-left tabular-nums"
                >
                    <thead>
                        <tr
                            class="border-b border-zinc-200/80 bg-zinc-50/70 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:text-zinc-400"
                        >
                            <th class="px-4 py-2.5">
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('date')"
                                >
                                    Date / Activity
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'date' &&
                                            sortDir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            sortKey === 'date' &&
                                            sortDir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th class="px-3 py-2.5 text-right">
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('duration')"
                                >
                                    Duration
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'duration' &&
                                            sortDir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            sortKey === 'duration' &&
                                            sortDir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th class="px-3 py-2.5 text-right">
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('delta')"
                                >
                                    vs PR
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'delta' &&
                                            sortDir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            sortKey === 'delta' &&
                                            sortDir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th class="px-3 py-2.5 text-right">
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('pace')"
                                >
                                    {{ usesPace ? 'Pace' : 'Speed' }}
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'pace' &&
                                            sortDir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            sortKey === 'pace' &&
                                            sortDir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th class="px-3 py-2.5 text-right">
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('hr')"
                                >
                                    Avg HR
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'hr' &&
                                            sortDir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            sortKey === 'hr' &&
                                            sortDir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th
                                v-if="hasAnyPower"
                                class="px-3 py-2.5 text-right"
                            >
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('power')"
                                >
                                    Avg Power
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'power' &&
                                            sortDir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            sortKey === 'power' &&
                                            sortDir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th v-if="hasAnyWeather" class="px-3 py-2.5">
                                Weather &amp; Wind
                            </th>
                            <th class="px-4 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>

                    <tbody
                        class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800/70"
                    >
                        <tr
                            v-for="attempt in sortedAttempts"
                            :key="attempt.activity_id"
                            class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                            :class="
                                attempt.is_pr
                                    ? 'bg-amber-500/[0.04] dark:bg-amber-500/[0.06]'
                                    : undefined
                            "
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="w-6 shrink-0 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                    >
                                        #{{ attempt.rank }}
                                    </span>
                                    <div class="min-w-0">
                                        <Link
                                            :href="
                                                showActivity.url(
                                                    attempt.activity_id,
                                                )
                                            "
                                            prefetch
                                            class="block truncate font-medium text-zinc-900 hover:text-emerald-600 dark:text-zinc-100 dark:hover:text-emerald-400"
                                        >
                                            {{ attempt.name }}
                                        </Link>
                                        <p
                                            class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{
                                                formatDateWithYear(attempt.date)
                                            }}
                                            ·
                                            {{
                                                formatDistance(
                                                    attempt.distance_meters,
                                                    2,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td
                                class="px-3 py-3 text-right font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatDurationClock(
                                        attempt.duration_seconds,
                                    )
                                }}
                            </td>

                            <td class="px-3 py-3 text-right font-mono text-xs">
                                <span
                                    v-if="attempt.is_pr"
                                    class="inline-flex items-center gap-1 rounded-md border border-amber-500/30 bg-amber-500/12 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                                >
                                    <Trophy class="size-2.5" />
                                    PR
                                </span>
                                <span
                                    v-else
                                    class="text-zinc-500 dark:text-zinc-400"
                                >
                                    +{{
                                        formatDurationClock(
                                            Math.max(0, attempt.delta_seconds),
                                        )
                                    }}
                                </span>
                            </td>

                            <td
                                class="px-3 py-3 text-right font-mono text-xs text-zinc-700 dark:text-zinc-200"
                            >
                                {{ formatMetricSpeedOrPace(attempt.avg_speed) }}
                            </td>

                            <td
                                class="px-3 py-3 text-right font-mono text-xs text-zinc-600 dark:text-zinc-300"
                            >
                                {{
                                    attempt.avg_hr !== null
                                        ? `${attempt.avg_hr} bpm`
                                        : '—'
                                }}
                            </td>

                            <td
                                v-if="hasAnyPower"
                                class="px-3 py-3 text-right font-mono text-xs text-zinc-600 dark:text-zinc-300"
                            >
                                {{
                                    attempt.avg_power !== null
                                        ? `${Math.round(attempt.avg_power)} W`
                                        : '—'
                                }}
                            </td>

                            <td v-if="hasAnyWeather" class="px-3 py-3">
                                <div
                                    v-if="attempt.weather"
                                    class="flex flex-wrap items-center gap-1.5 font-mono text-[11px]"
                                >
                                    <span
                                        v-if="
                                            attempt.weather.temperature_c !==
                                            null
                                        "
                                        class="text-zinc-700 dark:text-zinc-200"
                                    >
                                        {{
                                            formatTemp(
                                                attempt.weather.temperature_c,
                                            )
                                        }}
                                    </span>
                                    <span
                                        :class="[
                                            'rounded-md border px-1.5 py-0.5 text-[10px] font-medium',
                                            windPillClass(
                                                attempt.weather.wind_label,
                                            ),
                                        ]"
                                    >
                                        {{ attempt.weather.wind_label }}
                                        <template
                                            v-if="
                                                attempt.weather
                                                    .wind_speed_kmh !== null &&
                                                attempt.weather.wind_label !==
                                                    'Calm'
                                            "
                                        >
                                            ·
                                            {{
                                                formatSpeedKmh(
                                                    attempt.weather
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

                            <td class="px-4 py-3 text-right">
                                <div
                                    v-if="
                                        confirmingUnlinkId ===
                                        attempt.activity_id
                                    "
                                    class="inline-flex items-center gap-1.5"
                                >
                                    <button
                                        type="button"
                                        :disabled="
                                            unlinkingId === attempt.activity_id
                                        "
                                        class="cursor-pointer rounded-md bg-rose-600 px-2 py-1 font-mono text-[10px] font-medium text-white hover:bg-rose-700 disabled:opacity-60"
                                        @click="
                                            confirmUnlink(attempt.activity_id)
                                        "
                                    >
                                        {{
                                            unlinkingId === attempt.activity_id
                                                ? 'Unlinking...'
                                                : 'Confirm'
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded-md border border-zinc-200 px-2 py-1 font-mono text-[10px] text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                        @click="confirmingUnlinkId = null"
                                    >
                                        Cancel
                                    </button>
                                </div>
                                <div
                                    v-else
                                    class="inline-flex items-center justify-end gap-1.5"
                                >
                                    <Link
                                        v-if="
                                            prActivityId !== null &&
                                            !attempt.is_pr &&
                                            attempt.activity_id !== prActivityId
                                        "
                                        :href="
                                            compareVsPrUrl(attempt.activity_id)
                                        "
                                        title="Compare this attempt against Route PR"
                                        class="inline-flex items-center gap-1 rounded-md border border-emerald-500/30 bg-emerald-500/[0.06] px-2 py-1 font-mono text-[10px] font-medium whitespace-nowrap text-emerald-700 transition-colors hover:bg-emerald-500/15 dark:border-emerald-500/30 dark:text-emerald-300 dark:hover:bg-emerald-500/20"
                                    >
                                        <ArrowLeftRight class="size-3" />
                                        Compare vs PR
                                    </Link>
                                    <button
                                        type="button"
                                        title="Unlink this activity from the route"
                                        class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-zinc-200/90 px-2 py-1 font-mono text-[10px] text-zinc-500 transition-colors hover:border-rose-300 hover:bg-rose-50 hover:text-rose-600 dark:border-zinc-800 dark:text-zinc-400 dark:hover:border-rose-500/40 dark:hover:bg-rose-500/10 dark:hover:text-rose-300"
                                        @click="
                                            confirmingUnlinkId =
                                                attempt.activity_id
                                        "
                                    >
                                        <Unlink class="size-3" />
                                        Unlink
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ODbL Place Naming Attribution -->
        <p
            class="text-right font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
        >
            Place names &copy;
            <a
                href="https://www.openstreetmap.org/copyright"
                target="_blank"
                rel="noopener noreferrer"
                class="underline decoration-zinc-300 underline-offset-2 transition-colors hover:text-zinc-600 dark:decoration-zinc-700 dark:hover:text-zinc-300"
            >
                OpenStreetMap contributors (ODbL)
            </a>
        </p>
    </div>
</template>
