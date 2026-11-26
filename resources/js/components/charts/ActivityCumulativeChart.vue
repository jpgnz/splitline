<script setup lang="ts">
import type { EChartsOption, SeriesOption } from 'echarts';
import { computed, ref, watch } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import type {
    AnnualActivitySummaryPayload,
    AnnualSummaryMetricKey,
    AnnualSummaryMetricOption,
} from '@/components/charts/annualSummaryTypes';
import {
    extractMetricValue,
    formatMetricAxisTick,
    formatMetricTotal,
    monthDayToRefIndex,
    parseIsoDateParts,
    REF_DAY_LABELS,
    REF_YEAR_LENGTH,
} from '@/components/charts/annualSummaryTypes';
import { useChartTheme } from '@/composables/useChartTheme';
import { useUnits } from '@/lib/units';

const props = defineProps<{
    summary: AnnualActivitySummaryPayload;
    selectedYears: number[];
    yearColorMap: Record<number, string>;
    metricOptions: AnnualSummaryMetricOption[];
    defaultMetric: AnnualSummaryMetricKey;
}>();

const theme = useChartTheme();
const { units } = useUnits();

const selectedMetric = ref<AnnualSummaryMetricKey>(props.defaultMetric);
const isYtdZoomed = ref<boolean>(false);
const projectionBlend = ref<number>(50);

watch(
    () => props.metricOptions,
    (options) => {
        if (!options.some((opt) => opt.key === selectedMetric.value)) {
            selectedMetric.value = options[0]?.key ?? props.defaultMetric;
        }
    },
);

const todayParts = computed(() => {
    const parsed = parseIsoDateParts(props.summary.today);

    if (parsed) {
        return parsed;
    }

    const now = new Date();

    return {
        year: now.getFullYear(),
        month: now.getMonth() + 1,
        day: now.getDate(),
    };
});

const currentYear = computed<number>(() => todayParts.value.year);

const todayRefIndex = computed<number>(() =>
    monthDayToRefIndex(todayParts.value.month, todayParts.value.day),
);

const hasCurrentYearSelected = computed<boolean>(() =>
    props.selectedYears.includes(currentYear.value),
);

const canZoomYtd = computed<boolean>(
    () =>
        hasCurrentYearSelected.value &&
        todayRefIndex.value < REF_YEAR_LENGTH - 1,
);

watch(canZoomYtd, (allowed) => {
    if (!allowed) {
        isYtdZoomed.value = false;
    }
});

const dailyByYearRefIndex = computed(() => {
    const map = new Map<number, Float64Array>();
    const currentUnits = units.value;
    const metric = selectedMetric.value;

    for (const year of props.selectedYears) {
        map.set(year, new Float64Array(REF_YEAR_LENGTH));
    }

    for (const entry of props.summary.daily) {
        const parts = parseIsoDateParts(entry.date);

        if (!parts) {
            continue;
        }

        const bucket = map.get(parts.year);

        if (!bucket) {
            continue;
        }

        const idx = monthDayToRefIndex(parts.month, parts.day);
        bucket[idx] += extractMetricValue(entry, metric, currentUnits);
    }

    return map;
});

const cumulativeByYear = computed(() => {
    const result = new Map<number, Array<number | null>>();
    const currYr = currentYear.value;
    const cutoff = todayRefIndex.value;

    for (const year of props.selectedYears) {
        const daily = dailyByYearRefIndex.value.get(year);
        const series: Array<number | null> = Array.from(
            { length: REF_YEAR_LENGTH },
            () => null,
        );
        let running = 0;

        for (let i = 0; i < REF_YEAR_LENGTH; i++) {
            if (year === currYr && i > cutoff) {
                series[i] = null;
                continue;
            }

            if (daily) {
                running += daily[i];
            }

            series[i] = Number(running.toFixed(3));
        }

        result.set(year, series);
    }

    return result;
});

const last25DaysSum = computed<number>(() => {
    const t = todayParts.value;
    const endUtc = Date.UTC(t.year, t.month - 1, t.day);
    const startUtc = endUtc - 24 * 86_400_000;
    const currentUnits = units.value;
    const metric = selectedMetric.value;
    let total = 0;

    for (const entry of props.summary.daily) {
        const parts = parseIsoDateParts(entry.date);

        if (!parts) {
            continue;
        }

        const ts = Date.UTC(parts.year, parts.month - 1, parts.day);

        if (ts >= startUtc && ts <= endUtc) {
            total += extractMetricValue(entry, metric, currentUnits);
        }
    }

    return total;
});

const projectionStats = computed(() => {
    if (!hasCurrentYearSelected.value) {
        return null;
    }

    const currSeries = cumulativeByYear.value.get(currentYear.value);

    if (!currSeries) {
        return null;
    }

    const cutoff = todayRefIndex.value;
    const currentYtd = currSeries[cutoff] ?? 0;
    const remainingDays = Math.max(0, REF_YEAR_LENGTH - 1 - cutoff);

    if (remainingDays === 0) {
        return {
            currentYtd,
            projectedYearEnd: currentYtd,
            hasTail: false,
        };
    }

    // Recent 25-day daily rate projection
    const recentDailyRate = last25DaysSum.value / 25;
    const recentRemaining = recentDailyRate * remainingDays;

    // Previous selected years' remaining-year accumulation
    const priorRemainings: number[] = [];

    for (const year of props.selectedYears) {
        if (year >= currentYear.value) {
            continue;
        }

        const series = cumulativeByYear.value.get(year);

        if (!series) {
            continue;
        }

        const yearEnd = series[REF_YEAR_LENGTH - 1] ?? 0;
        const atToday = series[cutoff] ?? 0;

        if (yearEnd > 0) {
            priorRemainings.push(Math.max(0, yearEnd - atToday));
        }
    }

    const historicalRemaining =
        priorRemainings.length > 0
            ? priorRemainings.reduce((sum, val) => sum + val, 0) /
              priorRemainings.length
            : (currentYtd / Math.max(1, cutoff + 1)) * remainingDays;

    const weight = projectionBlend.value / 100;
    const projectedRemaining =
        (1 - weight) * historicalRemaining + weight * recentRemaining;

    return {
        currentYtd,
        projectedYearEnd: currentYtd + Math.max(0, projectedRemaining),
        hasTail: true,
    };
});

const showProjectionTail = computed<boolean>(
    () =>
        projectionStats.value !== null &&
        projectionStats.value.hasTail &&
        !isYtdZoomed.value,
);

const xMaxIndex = computed<number>(() =>
    isYtdZoomed.value && canZoomYtd.value
        ? Math.max(1, todayRefIndex.value)
        : REF_YEAR_LENGTH - 1,
);

const xAxisLabels = computed<string[]>(() =>
    REF_DAY_LABELS.slice(0, xMaxIndex.value + 1),
);

const tickIndices = computed<Set<number>>(() => {
    const maxIdx = xMaxIndex.value;

    if (!isYtdZoomed.value) {
        return new Set([0, 38, 96, 154, 212, 269, 327, 365]);
    }

    const count = 6;
    const indices = new Set<number>([0, maxIdx]);

    for (let i = 1; i < count; i++) {
        indices.add(Math.round((i / count) * maxIdx));
    }

    return indices;
});

function hexToRgba(hex: string, alpha: number): string {
    const cleaned = hex.replace('#', '');

    if (cleaned.length !== 6) {
        return `rgba(37, 99, 235, ${alpha})`;
    }

    const r = Number.parseInt(cleaned.slice(0, 2), 16);
    const g = Number.parseInt(cleaned.slice(2, 4), 16);
    const b = Number.parseInt(cleaned.slice(4, 6), 16);

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

const chartOption = computed<EChartsOption>(() => {
    const currentUnits = units.value;
    const metric = selectedMetric.value;
    const maxIdx = xMaxIndex.value;
    const currYr = currentYear.value;
    const monoFont = theme.fontFamilyMono;
    const ticks = tickIndices.value;

    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    const series: SeriesOption[] = [];

    // Draw older years first so the newest / current year sits cleanly on top
    const orderedForDraw = [...props.selectedYears].reverse();

    for (const year of orderedForDraw) {
        const values = cumulativeByYear.value.get(year);

        if (!values) {
            continue;
        }

        const color = props.yearColorMap[year] ?? '#2563eb';
        const isCurrent = year === currYr;
        const isNewest = year === props.selectedYears[0];
        const sliced = values.slice(0, maxIdx + 1);

        series.push({
            name: String(year),
            type: 'line',
            showSymbol: false,
            smooth: false,
            connectNulls: false,
            z: isCurrent || isNewest ? 4 : 2,
            lineStyle: {
                color,
                width: isCurrent || isNewest ? 2.25 : 1.5,
            },
            itemStyle: { color },
            areaStyle:
                isCurrent || (!hasCurrentYearSelected.value && isNewest)
                    ? {
                          color: {
                              type: 'linear',
                              x: 0,
                              y: 0,
                              x2: 0,
                              y2: 1,
                              colorStops: [
                                  {
                                      offset: 0,
                                      color: hexToRgba(
                                          color,
                                          theme.isDark.value ? 0.35 : 0.28,
                                      ),
                                  },
                                  {
                                      offset: 1,
                                      color: hexToRgba(color, 0.03),
                                  },
                              ],
                          },
                      }
                    : undefined,
            data: sliced,
        });
    }

    if (showProjectionTail.value && projectionStats.value) {
        const cutoff = todayRefIndex.value;
        const startVal = projectionStats.value.currentYtd;
        const endVal = projectionStats.value.projectedYearEnd;
        const span = Math.max(1, REF_YEAR_LENGTH - 1 - cutoff);
        const projData: Array<number | null> = Array.from(
            { length: maxIdx + 1 },
            () => null,
        );

        for (let i = cutoff; i <= maxIdx; i++) {
            const frac = (i - cutoff) / span;
            projData[i] = Number(
                (startVal + frac * (endVal - startVal)).toFixed(3),
            );
        }

        const projColor = props.yearColorMap[currYr] ?? '#2563eb';

        series.push({
            name: `${currYr} Projected`,
            type: 'line',
            showSymbol: false,
            smooth: false,
            z: 3,
            lineStyle: {
                color: projColor,
                width: 1.5,
                type: 'dashed',
                opacity: 0.75,
            },
            itemStyle: { color: projColor },
            data: projData,
        });
    }

    return {
        animation: false,
        grid: {
            left: 10,
            right: 16,
            top: 16,
            bottom: 10,
            containLabel: true,
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
                const items = list as Array<{
                    seriesName?: string;
                    value?: number | null;
                    color?: string;
                    dataIndex?: number;
                }>;

                const firstIndex = items[0]?.dataIndex;
                const dateLabel =
                    firstIndex !== undefined
                        ? (xAxisLabels.value[firstIndex] ?? '')
                        : '';

                const validRows = items
                    .filter(
                        (item) =>
                            item.value !== null &&
                            item.value !== undefined &&
                            Number.isFinite(item.value),
                    )
                    // Avoid duplicate row on exact today boundary where actual & projected overlap
                    .filter((item) => {
                        if (
                            item.seriesName === `${currYr} Projected` &&
                            firstIndex === todayRefIndex.value
                        ) {
                            return false;
                        }

                        return true;
                    })
                    .sort((a, b) => {
                        const aYr = Number.parseInt(a.seriesName ?? '0', 10);
                        const bYr = Number.parseInt(b.seriesName ?? '0', 10);

                        return bYr - aYr;
                    });

                if (validRows.length === 0) {
                    return '';
                }

                const lines = [`<strong>${dateLabel}</strong>`];

                for (const row of validRows) {
                    lines.push(
                        `<span style="color:${row.color ?? '#2563eb'};">●</span> ${row.seriesName}: <strong>${formatMetricTotal(row.value as number, metric, currentUnits)}</strong>`,
                    );
                }

                return lines.join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: xAxisLabels.value,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                ...axisLabelStyle,
                interval: 0,
                formatter: (value: string, index: number) =>
                    ticks.has(index) ? value : '',
            },
        },
        yAxis: {
            type: 'value',
            min: 0,
            axisLabel: {
                ...axisLabelStyle,
                formatter: (val: number) =>
                    formatMetricAxisTick(val, metric, currentUnits),
            },
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

const formattedProjectedYearEnd = computed<string | null>(() => {
    if (!projectionStats.value) {
        return null;
    }

    return formatMetricTotal(
        projectionStats.value.projectedYearEnd,
        selectedMetric.value,
        units.value,
    );
});
</script>

<template>
    <div
        class="flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <div>
            <!-- Header Controls -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 pb-3.5 dark:border-zinc-800/80"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <label
                        for="cumulative-display-value"
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Display value
                    </label>
                    <select
                        id="cumulative-display-value"
                        v-model="selectedMetric"
                        class="h-8 cursor-pointer rounded-lg border border-zinc-200/90 bg-[#f7f7f5] px-2.5 pr-7 font-mono text-xs font-medium text-zinc-900 transition-colors hover:border-zinc-300 focus:border-zinc-400 focus:outline-none dark:border-zinc-700/90 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:border-zinc-600"
                    >
                        <option
                            v-for="opt in metricOptions"
                            :key="opt.key"
                            :value="opt.key"
                        >
                            {{ opt.label }}
                        </option>
                    </select>

                    <button
                        v-if="canZoomYtd"
                        type="button"
                        class="h-8 cursor-pointer rounded-lg border px-2.5 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            isYtdZoomed
                                ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        :title="
                            isYtdZoomed
                                ? 'Show full calendar year with projection'
                                : 'Zoom to year-to-date comparison'
                        "
                        @click="isYtdZoomed = !isYtdZoomed"
                    >
                        {{ isYtdZoomed ? 'Full Year' : 'YTD Zoom' }}
                    </button>
                </div>

                <div
                    v-if="formattedProjectedYearEnd !== null"
                    class="text-right tabular-nums"
                >
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                    >
                        Projected year end
                    </p>
                    <p
                        class="font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                    >
                        {{ formattedProjectedYearEnd }}
                    </p>
                </div>
            </div>

            <!-- Inline Year Legend -->
            <div
                v-if="selectedYears.length > 0"
                class="mt-3 flex flex-wrap items-center justify-end gap-x-3.5 gap-y-1 font-mono text-[11px] tabular-nums"
            >
                <span
                    v-for="year in selectedYears"
                    :key="year"
                    class="inline-flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{
                            backgroundColor: yearColorMap[year] ?? '#2563eb',
                        }"
                    />
                    <span>{{ year }}</span>
                </span>
            </div>

            <!-- Cumulative Line Chart -->
            <div class="mt-2">
                <BaseChart
                    v-if="selectedYears.length > 0"
                    :option="chartOption"
                    :height="275"
                />
                <div
                    v-else
                    class="flex h-[275px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                >
                    Select at least one year to compare cumulative totals.
                </div>
            </div>
        </div>

        <!-- Projection Trend Blend Slider -->
        <div
            v-if="showProjectionTail"
            class="mt-3 flex flex-wrap items-center justify-center gap-3 border-t border-zinc-100 pt-3 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
        >
            <span class="text-right">
                Previous years' trends ({{ 100 - projectionBlend }}%)
            </span>
            <input
                v-model.number="projectionBlend"
                type="range"
                min="0"
                max="100"
                step="5"
                aria-label="Projection blend between previous years trends and last 25 days"
                class="h-1.5 w-32 cursor-pointer appearance-none rounded-full bg-zinc-200 accent-blue-600 dark:bg-zinc-700 dark:accent-blue-400"
            />
            <span class="text-left">
                Last 25 days ({{ projectionBlend }}%)
            </span>
        </div>
    </div>
</template>
