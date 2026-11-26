<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ActivityCalendarHeatmap from '@/components/charts/ActivityCalendarHeatmap.vue';
import ActivityCumulativeChart from '@/components/charts/ActivityCumulativeChart.vue';
import type {
    AnnualActivitySummaryPayload,
    AnnualSummaryMetricKey,
    AnnualSummaryMode,
} from '@/components/charts/annualSummaryTypes';
import {
    getYearColor,
    metricOptionsForMode,
} from '@/components/charts/annualSummaryTypes';
import { useChartTheme } from '@/composables/useChartTheme';

const DEFAULT_VISIBLE_YEARS = 4;

const props = withDefaults(
    defineProps<{
        summary: AnnualActivitySummaryPayload;
        mode: AnnualSummaryMode;
        sportKey?: string;
        title?: string;
    }>(),
    {
        sportKey: undefined,
        title: 'Annual Activity Summary',
    },
);

const theme = useChartTheme();

const metricOptions = computed(() => metricOptionsForMode(props.mode));

const defaultLeftMetric = computed<AnnualSummaryMetricKey>(() =>
    props.mode === 'health' ? 'time' : 'distance',
);

const defaultRightMetric = computed<AnnualSummaryMetricKey>(() =>
    props.mode === 'health' ? 'calories' : 'distance',
);

const selectedYears = ref<number[]>(
    props.summary.years.slice(0, DEFAULT_VISIBLE_YEARS),
);

watch(
    () => props.summary.years,
    (availableYears) => {
        const availableSet = new Set(availableYears);
        const kept = selectedYears.value.filter((yr) => availableSet.has(yr));

        if (kept.length > 0) {
            selectedYears.value = kept.sort((a, b) => b - a);
        } else {
            selectedYears.value = availableYears.slice(
                0,
                DEFAULT_VISIBLE_YEARS,
            );
        }
    },
);

const yearColorMap = computed<Record<number, string>>(() => {
    const map: Record<number, string> = {};
    const dark = theme.isDark.value;

    selectedYears.value.forEach((year, idx) => {
        map[year] = getYearColor(idx, dark);
    });

    return map;
});

function toggleYear(year: number): void {
    if (selectedYears.value.includes(year)) {
        if (selectedYears.value.length === 1) {
            return;
        }

        selectedYears.value = selectedYears.value.filter((y) => y !== year);
    } else {
        selectedYears.value = [...selectedYears.value, year].sort(
            (a, b) => b - a,
        );
    }
}

function selectRecentYears(): void {
    selectedYears.value = props.summary.years.slice(0, DEFAULT_VISIBLE_YEARS);
}

function selectAllYears(): void {
    selectedYears.value = [...props.summary.years];
}

const isRecentSelection = computed<boolean>(() => {
    const recent = props.summary.years.slice(0, DEFAULT_VISIBLE_YEARS);

    return (
        selectedYears.value.length === recent.length &&
        recent.every((yr, idx) => selectedYears.value[idx] === yr)
    );
});

const isAllSelected = computed<boolean>(
    () =>
        props.summary.years.length > 0 &&
        selectedYears.value.length === props.summary.years.length,
);
</script>

<template>
    <section class="space-y-3">
        <!-- Section Header + Shared Year Filter Pills -->
        <div
            class="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between"
        >
            <h2
                class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
            >
                {{ title }}
            </h2>

            <div
                v-if="summary.years.length > 1"
                class="flex flex-wrap items-center gap-1.5"
                role="group"
                aria-label="Filter years"
            >
                <span
                    class="mr-0.5 font-mono text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                >
                    Years
                </span>
                <button
                    v-for="year in summary.years"
                    :key="year"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-0.5 font-mono text-[11px] font-medium tabular-nums transition-colors"
                    :class="
                        selectedYears.includes(year)
                            ? 'border-zinc-300 bg-white text-zinc-900 dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-100'
                            : 'border-transparent bg-zinc-200/60 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'
                    "
                    @click="toggleYear(year)"
                >
                    <span
                        v-if="selectedYears.includes(year)"
                        class="size-1.5 rounded-full"
                        :style="{ backgroundColor: yearColorMap[year] }"
                    />
                    <span>{{ year }}</span>
                </button>

                <template v-if="summary.years.length > DEFAULT_VISIBLE_YEARS">
                    <span
                        class="mx-0.5 text-zinc-300 dark:text-zinc-700"
                        aria-hidden="true"
                    >
                        |
                    </span>
                    <button
                        v-if="!isRecentSelection"
                        type="button"
                        class="cursor-pointer rounded-md px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                        @click="selectRecentYears"
                    >
                        Last {{ DEFAULT_VISIBLE_YEARS }}
                    </button>
                    <button
                        v-if="!isAllSelected"
                        type="button"
                        class="cursor-pointer rounded-md px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                        @click="selectAllYears"
                    >
                        All
                    </button>
                </template>
            </div>
        </div>

        <!-- Empty State -->
        <div
            v-if="summary.years.length === 0"
            class="flex h-44 items-center justify-center rounded-xl border border-dashed border-zinc-200/90 bg-white font-mono text-xs text-zinc-400 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-500"
        >
            No recorded activities match this view yet.
        </div>

        <!-- Side-by-Side Cumulative Trend & Year Activity Calendar -->
        <div v-else class="grid gap-4 xl:grid-cols-2">
            <ActivityCumulativeChart
                :summary="summary"
                :selected-years="selectedYears"
                :year-color-map="yearColorMap"
                :metric-options="metricOptions"
                :default-metric="defaultLeftMetric"
            />

            <ActivityCalendarHeatmap
                :summary="summary"
                :selected-years="selectedYears"
                :metric-options="metricOptions"
                :default-metric="defaultRightMetric"
                :sport-key="sportKey"
            />
        </div>
    </section>
</template>
