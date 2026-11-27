<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CloudSun,
    MapPin,
    Mountain,
    Repeat,
    Route as RouteIcon,
    Trophy,
} from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed } from 'vue';
import BaseChart, {
    type ChartPointClickPayload,
} from '@/components/charts/BaseChart.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatDurationClock, formatShortDate } from '@/lib/format';
import { useUnits } from '@/lib/units';
import { show as showActivity } from '@/routes/activities';
import { show as showRoute } from '@/routes/routes';
import type { ActivityMatchedRoutePayload } from '@/types/routes';

const props = defineProps<{
    matchedRoute: ActivityMatchedRoutePayload;
}>();

const theme = useChartTheme();
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

const usesPace = computed<boolean>(
    () =>
        props.matchedRoute.route.metric_mode === 'pace' ||
        props.matchedRoute.route.metric_mode === 'pace_100m',
);

const hasAnyPower = computed<boolean>(() =>
    props.matchedRoute.attempts.some((a) => a.avg_power !== null),
);

const hasAnyWeather = computed<boolean>(() =>
    props.matchedRoute.attempts.some((a) => a.weather !== null),
);

function formatMetricSpeedOrPace(speedMps: number | null): string {
    if (props.matchedRoute.route.metric_mode === 'pace_100m') {
        return formatPace100m(speedMps);
    }

    if (props.matchedRoute.route.metric_mode === 'pace') {
        return formatPace(speedMps);
    }

    return formatSpeed(speedMps);
}

function formatDateWithYear(dateStr: string): string {
    const short = formatShortDate(dateStr);
    const parsed = new Date(`${dateStr}T00:00:00`);

    if (Number.isNaN(parsed.getTime())) {
        return short;
    }

    return `${short} ${parsed.getFullYear()}`;
}

function formatSignedDelta(seconds: number): string {
    if (!Number.isFinite(seconds) || Math.abs(seconds) < 1) {
        return 'On median';
    }

    const sign = seconds > 0 ? '+' : '-';

    return `${sign}${formatDurationClock(Math.abs(seconds))}`;
}

function formatWeatherDelta(seconds: number): string {
    if (!Number.isFinite(seconds) || Math.abs(seconds) < 1) {
        return 'Even';
    }

    const sign = seconds > 0 ? '+' : '-';

    return `${sign}${formatDurationClock(Math.abs(seconds))}`;
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

const progressionOption = computed<EChartsOption>(() => {
    const rows = props.matchedRoute.progression;
    const accent = props.matchedRoute.route.accent_hex || '#10b981';
    const isPace = usesPace.value;
    const isPace100m = props.matchedRoute.route.metric_mode === 'pace_100m';
    const labels = rows.map((r) => formatShortDate(r.date));

    const data = rows.map((row) => {
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

        if (row.is_current && row.is_pr) {
            return {
                value,
                symbol: 'diamond',
                symbolSize: 13,
                itemStyle: {
                    color: '#f59e0b',
                    borderColor: '#0ea5e9',
                    borderWidth: 2.5,
                },
            };
        }

        if (row.is_current) {
            return {
                value,
                symbol: 'circle',
                symbolSize: 11,
                itemStyle: {
                    color: '#0ea5e9',
                    borderColor: theme.isDark.value ? '#141417' : '#ffffff',
                    borderWidth: 2.5,
                },
            };
        }

        if (row.is_pr) {
            return {
                value,
                symbol: 'diamond',
                symbolSize: 10,
                itemStyle: {
                    color: '#f59e0b',
                    borderColor: theme.isDark.value ? '#141417' : '#ffffff',
                    borderWidth: 2,
                },
            };
        }

        return value;
    });

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
            top: 34,
            bottom: 12,
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

                const attempt = rows[idx];
                const tags: string[] = [];

                if (attempt.is_current) {
                    tags.push('This Activity');
                }

                if (attempt.is_pr) {
                    tags.push('◆ PR');
                }

                const lines = [
                    `<div style="font-weight:600;margin-bottom:3px;">${escapeHtml(attempt.name)}${tags.length > 0 ? ` (${escapeHtml(tags.join(' · '))})` : ''}</div>`,
                    `<div style="opacity:0.75;margin-bottom:4px;">${escapeHtml(formatDateWithYear(attempt.date))}</div>`,
                    `<div>Time: <strong>${escapeHtml(formatDurationClock(attempt.duration_seconds))}</strong></div>`,
                    `<div>${isPace ? 'Pace' : 'Speed'}: <strong>${escapeHtml(formatMetricSpeedOrPace(attempt.avg_speed))}</strong></div>`,
                ];

                if (attempt.avg_hr !== null) {
                    lines.push(
                        `<div>Avg HR: <strong>${attempt.avg_hr} bpm</strong></div>`,
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
        yAxis: {
            type: 'value',
            inverse: isPace,
            scale: true,
            name: primaryYAxisName,
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
                formatter: (val: number) =>
                    isPace
                        ? formatPaceSeconds(val).replace(/ \/.*$/, '')
                        : `${val.toFixed(1)}`,
            },
        },
        series: [
            {
                name: isPace ? 'Pace' : 'Speed',
                type: 'line',
                data,
                smooth: 0.25,
                symbol: 'circle',
                symbolSize: 6,
                lineStyle: { color: accent, width: 2.2 },
                itemStyle: { color: accent },
                areaStyle: {
                    color: accent,
                    opacity: 0.08,
                },
            },
        ],
    };
});

function onPointClick(payload: ChartPointClickPayload): void {
    const attempt = props.matchedRoute.progression[payload.dataIndex];

    if (attempt && !attempt.is_current) {
        router.visit(showActivity.url(attempt.activity_id));
    }
}
</script>

<template>
    <div class="space-y-6">
        <!-- Matched Route Banner -->
        <section
            class="flex flex-col gap-4 rounded-xl border border-zinc-200/90 bg-white p-5 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1 rounded-md border border-emerald-500/25 bg-emerald-500/10 px-2 py-0.5 font-mono text-[10px] font-medium text-emerald-700 dark:text-emerald-300"
                    >
                        <RouteIcon class="size-3" />
                        Matched Route
                    </span>
                    <span
                        class="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-0.5 font-mono text-[10px] text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300"
                    >
                        <Repeat
                            v-if="matchedRoute.route.is_loop"
                            class="size-2.5"
                        />
                        {{
                            matchedRoute.route.is_loop
                                ? 'Loop'
                                : 'Point-to-Point'
                        }}
                    </span>
                </div>

                <h2
                    class="truncate text-lg font-semibold text-zinc-900 dark:text-zinc-100"
                >
                    {{ matchedRoute.route.name }}
                </h2>

                <p
                    v-if="matchedRoute.route.place_label"
                    class="flex items-center gap-1.5 truncate text-xs text-zinc-500 dark:text-zinc-400"
                >
                    <MapPin
                        class="size-3 shrink-0 text-zinc-400 dark:text-zinc-500"
                    />
                    <span class="truncate">{{
                        matchedRoute.route.place_label
                    }}</span>
                </p>

                <div
                    class="flex flex-wrap items-center gap-3 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                >
                    <span>{{
                        formatDistance(matchedRoute.route.distance_meters, 2)
                    }}</span>
                    <span
                        v-if="matchedRoute.route.elevation_gain !== null"
                        class="inline-flex items-center gap-1"
                    >
                        <Mountain class="size-3" />
                        {{ formatElevation(matchedRoute.route.elevation_gain) }}
                    </span>
                    <span>
                        {{ matchedRoute.route.activity_count }} total
                        {{
                            matchedRoute.route.activity_count === 1
                                ? 'attempt'
                                : 'attempts'
                        }}
                    </span>
                </div>
            </div>

            <Link
                :href="showRoute.url(matchedRoute.route.id)"
                prefetch
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3.5 py-2 font-mono text-xs font-medium text-zinc-800 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800"
            >
                Full Route History
                <ArrowUpRight class="size-3.5" />
            </Link>
        </section>

        <!-- Current Attempt KPI Cards -->
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                class="rounded-xl border p-4"
                :class="
                    matchedRoute.current.is_pr
                        ? 'border-amber-500/35 bg-amber-500/[0.05] dark:border-amber-500/30 dark:bg-amber-500/[0.07]'
                        : 'border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]'
                "
            >
                <p
                    class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider uppercase"
                    :class="
                        matchedRoute.current.is_pr
                            ? 'text-amber-700 dark:text-amber-400'
                            : 'text-zinc-500 dark:text-zinc-400'
                    "
                >
                    <Trophy v-if="matchedRoute.current.is_pr" class="size-3" />
                    Attempt Rank
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    #{{ matchedRoute.current.rank }}
                    <span
                        class="text-sm font-normal text-zinc-500 dark:text-zinc-400"
                    >
                        of {{ matchedRoute.current.total_attempts }}
                    </span>
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{
                        matchedRoute.current.is_pr
                            ? 'Fastest on this route!'
                            : formatMetricSpeedOrPace(
                                  matchedRoute.current.avg_speed,
                              )
                    }}
                </p>
            </div>

            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    vs Route PR
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold tabular-nums"
                    :class="
                        matchedRoute.current.is_pr
                            ? 'text-amber-600 dark:text-amber-400'
                            : 'text-zinc-900 dark:text-zinc-100'
                    "
                >
                    {{
                        matchedRoute.current.is_pr
                            ? 'Fastest Attempt'
                            : `+${formatDurationClock(Math.max(0, matchedRoute.current.delta_pr_seconds))}`
                    }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    PR:
                    {{
                        formatDurationClock(
                            matchedRoute.summary.pr_duration_seconds,
                        )
                    }}
                </p>
            </div>

            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    vs Median
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold tabular-nums"
                    :class="
                        matchedRoute.current.delta_median_seconds < -1
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : matchedRoute.current.delta_median_seconds > 1
                              ? 'text-rose-600 dark:text-rose-400'
                              : 'text-zinc-900 dark:text-zinc-100'
                    "
                >
                    {{
                        formatSignedDelta(
                            matchedRoute.current.delta_median_seconds,
                        )
                    }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    Median:
                    {{
                        formatDurationClock(
                            matchedRoute.summary.median_duration_seconds,
                        )
                    }}
                </p>
            </div>

            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    This Attempt
                </p>
                <p
                    class="mt-2 font-mono text-2xl font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{
                        formatDurationClock(
                            matchedRoute.current.duration_seconds,
                        )
                    }}
                </p>
                <p
                    class="mt-0.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{
                        formatMetricSpeedOrPace(matchedRoute.current.avg_speed)
                    }}
                </p>
            </div>
        </section>

        <!-- Progression Chart -->
        <section
            class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3
                    class="font-mono text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                >
                    Route {{ usesPace ? 'Pace' : 'Speed' }} Progression
                </h3>
                <span
                    class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                >
                    Sky dot = this activity · Gold diamond = PR
                </span>
            </div>
            <BaseChart
                :option="progressionOption"
                :height="240"
                @point-click="onPointClick"
            />
        </section>

        <!-- Compact Weather Summary Strip (if available) -->
        <section
            v-if="
                matchedRoute.weather_impact &&
                (matchedRoute.weather_impact.wind
                    ?.headwind_vs_tailwind_delta_seconds !== null ||
                    matchedRoute.weather_impact.temperature !== null)
            "
            class="flex flex-wrap items-center gap-4 rounded-xl border border-zinc-200/90 bg-white px-4 py-3 font-mono text-xs tabular-nums dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <span
                class="inline-flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"
            >
                <CloudSun class="size-3.5 text-sky-500" />
                Weather Context ({{ matchedRoute.weather_impact.sample_count }}
                {{
                    matchedRoute.weather_impact.sample_count === 1
                        ? 'attempt'
                        : 'attempts'
                }}):
            </span>
            <span
                v-if="
                    matchedRoute.weather_impact.wind &&
                    matchedRoute.weather_impact.wind
                        .headwind_vs_tailwind_delta_seconds !== null
                "
                class="text-zinc-500 dark:text-zinc-400"
            >
                Headwind vs Tailwind
                <strong class="text-zinc-900 dark:text-zinc-100">
                    {{
                        formatWeatherDelta(
                            matchedRoute.weather_impact.wind
                                .headwind_vs_tailwind_delta_seconds,
                        )
                    }}
                </strong>
            </span>
            <span
                v-if="matchedRoute.weather_impact.temperature"
                class="text-zinc-500 dark:text-zinc-400"
            >
                Warm vs Cool
                <strong class="text-zinc-900 dark:text-zinc-100">
                    {{
                        formatWeatherDelta(
                            matchedRoute.weather_impact.temperature
                                .warm_vs_cool_delta_seconds,
                        )
                    }}
                </strong>
            </span>
        </section>

        <!-- Attempts List -->
        <section
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="border-b border-zinc-100 px-4 py-3 dark:border-zinc-800/80"
            >
                <h3
                    class="font-mono text-xs font-medium tracking-wider text-zinc-600 uppercase dark:text-zinc-300"
                >
                    All Attempts on This Route ({{
                        matchedRoute.attempts.length
                    }})
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table
                    class="w-full min-w-[640px] border-collapse text-left tabular-nums"
                >
                    <thead>
                        <tr
                            class="border-b border-zinc-200/80 bg-zinc-50/70 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:text-zinc-400"
                        >
                            <th class="px-4 py-2.5">Date / Activity</th>
                            <th class="px-3 py-2.5 text-right">Duration</th>
                            <th class="px-3 py-2.5 text-right">vs PR</th>
                            <th class="px-3 py-2.5 text-right">
                                {{ usesPace ? 'Pace' : 'Speed' }}
                            </th>
                            <th class="px-3 py-2.5 text-right">Avg HR</th>
                            <th
                                v-if="hasAnyPower"
                                class="px-3 py-2.5 text-right"
                            >
                                Power
                            </th>
                            <th v-if="hasAnyWeather" class="px-4 py-2.5">
                                Weather
                            </th>
                        </tr>
                    </thead>

                    <tbody
                        class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800/70"
                    >
                        <tr
                            v-for="attempt in matchedRoute.attempts"
                            :key="attempt.activity_id"
                            :class="
                                attempt.is_current
                                    ? 'bg-emerald-500/[0.06] dark:bg-emerald-500/[0.09]'
                                    : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40'
                            "
                        >
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="w-6 shrink-0 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                    >
                                        #{{ attempt.rank }}
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span
                                                v-if="attempt.is_current"
                                                class="truncate font-semibold text-zinc-900 dark:text-zinc-100"
                                            >
                                                {{ attempt.name }}
                                            </span>
                                            <Link
                                                v-else
                                                :href="
                                                    showActivity.url(
                                                        attempt.activity_id,
                                                    )
                                                "
                                                prefetch
                                                class="truncate font-medium text-zinc-800 hover:text-emerald-600 dark:text-zinc-200 dark:hover:text-emerald-400"
                                            >
                                                {{ attempt.name }}
                                            </Link>
                                            <span
                                                v-if="attempt.is_current"
                                                class="shrink-0 rounded bg-emerald-500/15 px-1.5 py-0.5 font-mono text-[9px] font-semibold text-emerald-700 uppercase dark:text-emerald-300"
                                            >
                                                Current
                                            </span>
                                        </div>
                                        <p
                                            class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{
                                                formatDateWithYear(attempt.date)
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td
                                class="px-3 py-2.5 text-right font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatDurationClock(
                                        attempt.duration_seconds,
                                    )
                                }}
                            </td>

                            <td
                                class="px-3 py-2.5 text-right font-mono text-xs"
                            >
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
                                class="px-3 py-2.5 text-right font-mono text-xs text-zinc-700 dark:text-zinc-200"
                            >
                                {{ formatMetricSpeedOrPace(attempt.avg_speed) }}
                            </td>

                            <td
                                class="px-3 py-2.5 text-right font-mono text-xs text-zinc-600 dark:text-zinc-300"
                            >
                                {{
                                    attempt.avg_hr !== null
                                        ? `${attempt.avg_hr} bpm`
                                        : '—'
                                }}
                            </td>

                            <td
                                v-if="hasAnyPower"
                                class="px-3 py-2.5 text-right font-mono text-xs text-zinc-600 dark:text-zinc-300"
                            >
                                {{
                                    attempt.avg_power !== null
                                        ? `${Math.round(attempt.avg_power)} W`
                                        : '—'
                                }}
                            </td>

                            <td v-if="hasAnyWeather" class="px-4 py-2.5">
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
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ODbL Place Naming Attribution -->
        <p
            v-if="matchedRoute.route.place_label"
            class="text-right font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
        >
            Place names &copy;
            <a
                href="https://www.openstreetmap.org/copyright"
                target="_blank"
                rel="noopener noreferrer"
                class="underline decoration-zinc-300 underline-offset-2 transition-colors hover:text-zinc-700 dark:decoration-zinc-700 dark:hover:text-zinc-200"
            >
                OpenStreetMap contributors
            </a>
        </p>
    </div>
</template>
