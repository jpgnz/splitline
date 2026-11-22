<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { EChartsOption } from 'echarts';
import { Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import type {
    EffortContextPayload,
    EffortSummaryBlock,
} from '@/types/activity';

const props = defineProps<{
    effortContext: EffortContextPayload;
}>();

type EffortMetric = 'trimp' | 'garmin_load';

const theme = useChartTheme();
const metric = ref<EffortMetric>('trimp');

const activeBlock = computed<EffortSummaryBlock>(() =>
    metric.value === 'trimp'
        ? props.effortContext.trimp
        : props.effortContext.garmin_load,
);

const companionScore = computed<{ label: string; value: number | null }>(() =>
    metric.value === 'trimp'
        ? {
              label: 'Garmin Exercise Load',
              value: props.effortContext.garmin_load.activity_score,
          }
        : {
              label: 'HR Effort (TRIMP)',
              value: props.effortContext.trimp.activity_score,
          },
);

const ratingClass = computed<string>(() => {
    switch (activeBlock.value.rating) {
        case 'Very Hard':
            return 'text-rose-600 dark:text-rose-400';
        case 'Tough':
            return 'text-indigo-600 dark:text-indigo-400';
        case 'Moderate':
            return 'text-amber-600 dark:text-amber-400';
        case 'Easy':
            return 'text-emerald-600 dark:text-emerald-400';
        default:
            return 'text-zinc-500 dark:text-zinc-400';
    }
});

const dailyBarMax = computed<number>(() => {
    const vals = props.effortContext.daily_bars.map((d) =>
        metric.value === 'trimp' ? d.trimp : d.load,
    );

    return Math.max(...vals, 1);
});

function dailyBarHeightPct(value: number): number {
    if (value <= 0) {
        return 4;
    }

    return Math.max(12, Math.round((value / dailyBarMax.value) * 100));
}

function sportDotClass(sport: string, discipline: string | null): string {
    if (sport === 'running') {
        return 'bg-amber-500';
    }

    if (sport === 'biking') {
        if (discipline === 'mtb') {
            return 'bg-emerald-500';
        }

        return 'bg-sky-500';
    }

    if (sport === 'walking') {
        return 'bg-teal-500';
    }

    if (sport === 'hiking') {
        return 'bg-lime-500';
    }

    return 'bg-zinc-400';
}

const trendOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const isDark = theme.isDark.value;
    const rows = props.effortContext.twelve_week_trend;
    const isTrimp = metric.value === 'trimp';

    const labels = rows.map((r) => r.label);
    const lows = rows.map((r) =>
        isTrimp ? r.trimp_band_low : r.load_band_low,
    );
    const diffs = rows.map((r) => {
        const low = isTrimp ? r.trimp_band_low : r.load_band_low;
        const high = isTrimp ? r.trimp_band_high : r.load_band_high;

        return Math.max(0, high - low);
    });
    const totals = rows.map((r) => (isTrimp ? r.trimp : r.load));
    const accentHex = isTrimp ? '#6366f1' : '#0ea5e9';

    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    return {
        animation: false,
        grid: {
            left: 16,
            right: 40,
            top: 16,
            bottom: 24,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: {
                    color: theme.mutedColor.value,
                    type: 'dashed',
                },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const idx = first?.dataIndex;

                if (idx === undefined || !rows[idx]) {
                    return '';
                }

                const row = rows[idx];
                const val = isTrimp ? row.trimp : row.load;
                const low = isTrimp ? row.trimp_band_low : row.load_band_low;
                const high = isTrimp ? row.trimp_band_high : row.load_band_high;
                const metricTitle = isTrimp
                    ? 'HR Effort (TRIMP)'
                    : 'Garmin Load';

                return `<div><div style="opacity:0.65;margin-bottom:2px">Week of ${row.label}</div><strong>${metricTitle}:</strong> ${val}<br/><span style="opacity:0.7">Typical range: ${low}–${high}</span></div>`;
            },
        },
        xAxis: {
            type: 'category',
            data: labels,
            boundaryGap: true,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: {
            type: 'value',
            position: 'right',
            min: 0,
            splitNumber: 2,
            axisLabel: axisLabelStyle,
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            // Invisible base for stacked typical-range band
            {
                name: 'Typical Low',
                type: 'line',
                stack: 'typical-band',
                data: lows,
                showSymbol: false,
                smooth: 0.25,
                lineStyle: { opacity: 0 },
                silent: true,
                tooltip: { show: false },
            },
            // Shaded typical-range band
            {
                name: 'Typical Range',
                type: 'line',
                stack: 'typical-band',
                data: diffs,
                showSymbol: false,
                smooth: 0.25,
                lineStyle: { opacity: 0 },
                areaStyle: {
                    color: isDark
                        ? 'rgba(161, 161, 170, 0.16)'
                        : 'rgba(161, 161, 170, 0.22)',
                },
                silent: true,
                tooltip: { show: false },
            },
            // Weekly totals as crisp dot markers
            {
                name: isTrimp ? 'Weekly TRIMP' : 'Weekly Load',
                type: 'scatter',
                symbolSize: (val: unknown, params: { dataIndex: number }) =>
                    rows[params.dataIndex]?.is_current_week ? 10 : 6,
                data: totals.map((val, idx) => ({
                    value: val,
                    itemStyle: rows[idx]?.is_current_week
                        ? {
                              color: accentHex,
                              borderColor: isDark ? '#141417' : '#ffffff',
                              borderWidth: 2,
                          }
                        : {
                              color: isDark ? '#141417' : '#ffffff',
                              borderColor: isDark ? '#a1a1aa' : '#52525b',
                              borderWidth: 1.75,
                          },
                })),
            },
        ],
    };
});
</script>

<template>
    <section
        class="rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <!-- Header + Metric Toggle -->
        <div
            class="flex flex-col gap-3 border-b border-zinc-200/80 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5 dark:border-zinc-800/80"
        >
            <div class="flex items-center gap-2">
                <h2
                    class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Effort &amp; Weekly Load Context
                </h2>
                <span
                    class="inline-flex items-center gap-1 rounded border border-zinc-200/80 bg-[#f7f7f5] px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400"
                    title="HR-zone weighted Training Impulse (TRIMP), inspired by Dr. Eric Banister's model. Portable across any heart-rate monitor."
                >
                    <Info class="size-2.5" />
                    {{
                        metric === 'trimp'
                            ? 'Banister HR-Zone TRIMP'
                            : 'Garmin Exercise Load'
                    }}
                </span>
            </div>

            <div
                class="inline-flex self-start rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 sm:self-auto dark:border-zinc-800 dark:bg-zinc-900/60"
            >
                <button
                    type="button"
                    class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                    :class="
                        metric === 'trimp'
                            ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                    "
                    @click="metric = 'trimp'"
                >
                    HR Effort (TRIMP)
                </button>
                <button
                    type="button"
                    class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                    :class="
                        metric === 'garmin_load'
                            ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                    "
                    @click="metric = 'garmin_load'"
                >
                    Garmin Load
                </button>
            </div>
        </div>

        <!-- Body Grid -->
        <div
            class="grid grid-cols-1 divide-y divide-zinc-200/80 lg:grid-cols-12 lg:divide-x lg:divide-y-0 dark:divide-zinc-800/80"
        >
            <!-- Left: This Session's Effort -->
            <div class="p-4 sm:p-5 lg:col-span-4">
                <div
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{
                        metric === 'trimp'
                            ? 'Session HR Effort'
                            : 'Session Exercise Load'
                    }}
                </div>

                <div class="mt-2 flex items-baseline gap-2.5">
                    <span
                        class="text-4xl font-semibold tracking-tight tabular-nums"
                        :class="
                            metric === 'trimp'
                                ? 'text-indigo-600 dark:text-indigo-400'
                                : 'text-sky-600 dark:text-sky-400'
                        "
                    >
                        {{
                            activeBlock.activity_score !== null
                                ? formatNumber(activeBlock.activity_score, 0)
                                : '—'
                        }}
                    </span>
                    <span
                        v-if="activeBlock.rating"
                        class="text-lg font-semibold tracking-tight"
                        :class="ratingClass"
                    >
                        {{ activeBlock.rating }}
                    </span>
                </div>

                <p
                    class="mt-2.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300"
                >
                    {{ activeBlock.summary }}
                </p>

                <div
                    v-if="companionScore.value !== null"
                    class="mt-4 rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/70 px-3 py-2 font-mono text-xs dark:border-zinc-800/80 dark:bg-zinc-900/40"
                >
                    <span class="text-zinc-500 dark:text-zinc-400">
                        {{ companionScore.label }}:
                    </span>
                    <span
                        class="ml-1.5 font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatNumber(companionScore.value, 0) }}
                    </span>
                </div>
            </div>

            <!-- Right: Weekly Effort + 12-Week Trend + Same-Week Activities -->
            <div
                class="divide-y divide-zinc-200/80 lg:col-span-8 dark:divide-zinc-800/80"
            >
                <!-- Weekly Mini Bars + Status -->
                <div class="p-4 sm:p-5">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span
                            class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Weekly Effort
                        </span>
                        <span
                            class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            {{ effortContext.week_label }}
                        </span>
                    </div>

                    <div
                        class="mt-3.5 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <!-- Mon-Sun Mini Bars -->
                        <div class="flex items-end gap-2.5">
                            <div
                                v-for="bar in effortContext.daily_bars"
                                :key="bar.date"
                                class="flex w-7 flex-col items-center gap-1.5"
                                :title="`${formatShortDate(bar.date)}: ${metric === 'trimp' ? bar.trimp : bar.load}`"
                            >
                                <div
                                    class="flex h-16 w-full items-end justify-center"
                                >
                                    <div
                                        class="w-3.5 rounded-t transition-all"
                                        :class="
                                            bar.is_activity_day
                                                ? metric === 'trimp'
                                                    ? 'bg-indigo-500'
                                                    : 'bg-sky-500'
                                                : (metric === 'trimp'
                                                        ? bar.trimp
                                                        : bar.load) > 0
                                                  ? 'bg-zinc-600 dark:bg-zinc-400'
                                                  : 'bg-zinc-200 dark:bg-zinc-800'
                                        "
                                        :style="{
                                            height: `${dailyBarHeightPct(metric === 'trimp' ? bar.trimp : bar.load)}%`,
                                        }"
                                    />
                                </div>
                                <span
                                    class="font-mono text-[10px]"
                                    :class="
                                        bar.is_activity_day
                                            ? 'font-semibold text-zinc-900 dark:text-zinc-100'
                                            : 'text-zinc-400 dark:text-zinc-500'
                                    "
                                >
                                    {{ bar.day_label }}
                                </span>
                            </div>
                        </div>

                        <!-- Weekly Total & Status -->
                        <div class="min-w-0 flex-1 sm:pl-4">
                            <div class="flex items-baseline gap-2.5">
                                <span
                                    class="text-2xl font-semibold tracking-tight tabular-nums"
                                    :class="
                                        metric === 'trimp'
                                            ? 'text-indigo-600 dark:text-indigo-400'
                                            : 'text-sky-600 dark:text-sky-400'
                                    "
                                >
                                    {{
                                        formatNumber(activeBlock.week_total, 0)
                                    }}
                                </span>
                                <span
                                    v-if="activeBlock.typical_high > 0"
                                    class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                >
                                    typical {{ activeBlock.typical_low }}–{{
                                        activeBlock.typical_high
                                    }}
                                </span>
                            </div>
                            <div
                                class="mt-0.5 text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >
                                {{ activeBlock.week_status }}
                            </div>
                            <p
                                class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                {{ activeBlock.week_status_detail }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- 12-Week Rolling Trend Chart -->
                <div class="px-4 py-3 sm:px-5">
                    <div class="mb-1 flex items-center justify-between">
                        <span
                            class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            12-Week Trend
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            Shaded band = 3-week typical range
                        </span>
                    </div>
                    <BaseChart :option="trendOption" :height="155" />
                </div>

                <!-- Same-Week Activities List -->
                <div
                    v-if="effortContext.week_activities.length > 0"
                    class="divide-y divide-zinc-100 dark:divide-zinc-800/80"
                >
                    <div
                        v-for="row in effortContext.week_activities"
                        :key="row.id"
                        class="flex items-center justify-between gap-3 px-4 py-2.5 font-mono text-xs tabular-nums sm:px-5"
                        :class="
                            row.is_current
                                ? 'bg-[#f7f7f5]/90 dark:bg-zinc-900/60'
                                : 'hover:bg-zinc-50/70 dark:hover:bg-zinc-900/30'
                        "
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="
                                    sportDotClass(
                                        row.sport_category,
                                        row.effective_discipline,
                                    )
                                "
                            />
                            <span
                                class="w-16 shrink-0 text-[11px] text-zinc-400 dark:text-zinc-500"
                            >
                                {{ formatShortDate(row.started_at_local) }}
                            </span>
                            <span
                                v-if="row.is_current"
                                class="truncate font-sans text-xs font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{ row.name }}
                            </span>
                            <Link
                                v-else
                                :href="`/activities/${row.id}`"
                                class="truncate font-sans text-xs font-medium text-sky-600 hover:underline dark:text-sky-400"
                            >
                                {{ row.name }}
                            </Link>
                        </div>

                        <div class="flex shrink-0 items-center gap-4">
                            <span class="text-zinc-500 dark:text-zinc-400">
                                {{ formatDurationClock(row.duration_seconds) }}
                            </span>
                            <span
                                class="w-10 text-right font-semibold"
                                :class="
                                    row.is_current
                                        ? metric === 'trimp'
                                            ? 'text-indigo-600 dark:text-indigo-400'
                                            : 'text-sky-600 dark:text-sky-400'
                                        : 'text-zinc-700 dark:text-zinc-300'
                                "
                            >
                                {{
                                    metric === 'trimp'
                                        ? (row.trimp ?? '—')
                                        : (row.load ?? '—')
                                }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
