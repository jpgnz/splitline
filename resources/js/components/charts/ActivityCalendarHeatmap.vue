<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowUpRight, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type {
    AnnualActivitySummaryPayload,
    AnnualSummaryDailyEntry,
    AnnualSummaryDayActivity,
    AnnualSummaryMetricKey,
    AnnualSummaryMetricOption,
} from '@/components/charts/annualSummaryTypes';
import {
    extractMetricValue,
    formatMetricDaily,
    formatMetricTotal,
    formatZeroMetric,
    isLeapYear,
    MONTH_SHORT_NAMES,
    parseIsoDateParts,
} from '@/components/charts/annualSummaryTypes';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatDurationHuman, formatNumber } from '@/lib/format';
import { resolveActivitySportKey, useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    index as activitiesIndex,
    show as showActivity,
} from '@/routes/activities';

type Granularity = 'day' | 'week' | 'month' | 'year';

interface DayCell {
    iso: string;
    label: string;
    col: number;
    row: number;
    value: number;
    activities: number;
    durationSeconds: number;
    calories: number;
    isFuture: boolean;
    items: AnnualSummaryDayActivity[];
}

interface YearDayGrid {
    year: number;
    cols: number;
    cells: DayCell[];
    monthDividers: string[];
    monthLabels: Array<{ label: string; col: number }>;
}

interface WeekCell {
    index: number;
    startIso: string;
    endIso: string;
    label: string;
    value: number;
    activities: number;
    durationSeconds: number;
    calories: number;
    isFuture: boolean;
}

interface MonthCell {
    month: number;
    startIso: string;
    endIso: string;
    label: string;
    value: number;
    activities: number;
    durationSeconds: number;
    calories: number;
    isFuture: boolean;
}

interface YearRowTotal {
    year: number;
    startIso: string;
    endIso: string;
    value: number;
    activities: number;
    durationSeconds: number;
    calories: number;
}

interface TooltipState {
    title: string;
    valueText: string;
    activities: number;
    meta: string | null;
    hint: string | null;
    x: number;
    y: number;
    alignRight: boolean;
}

interface SelectedDayPopover {
    iso: string;
    label: string;
    items: AnnualSummaryDayActivity[];
    x: number;
    y: number;
    alignRight: boolean;
    placeAbove: boolean;
}

const props = defineProps<{
    summary: AnnualActivitySummaryPayload;
    selectedYears: number[];
    metricOptions: AnnualSummaryMetricOption[];
    defaultMetric: AnnualSummaryMetricKey;
    sportKey?: string;
}>();

const theme = useChartTheme();
const { units, formatDistanceCompact } = useUnits();
const { sportAccentHex } = useSports();

const selectedMetric = ref<AnnualSummaryMetricKey>(props.defaultMetric);
const granularity = ref<Granularity>('day');
const containerRef = ref<HTMLElement | null>(null);
const popoverRef = ref<HTMLElement | null>(null);
const tooltip = ref<TooltipState | null>(null);
const selectedDay = ref<SelectedDayPopover | null>(null);

const GRANULARITY_OPTIONS: Array<{ value: Granularity; label: string }> = [
    { value: 'day', label: 'Day' },
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
    { value: 'year', label: 'Year' },
];

watch(
    () => props.metricOptions,
    (options) => {
        if (!options.some((opt) => opt.key === selectedMetric.value)) {
            selectedMetric.value = options[0]?.key ?? props.defaultMetric;
        }
    },
);

watch([granularity, () => props.selectedYears], () => {
    selectedDay.value = null;
});

function daysInMonthUtc(year: number, monthIndex: number): number {
    return new Date(Date.UTC(year, monthIndex + 1, 0)).getUTCDate();
}

function activitiesRangeUrl(from: string, to: string): string {
    const query: Record<string, string> = {
        range: 'custom',
        from,
        to,
    };

    if (props.sportKey) {
        query.sport = props.sportKey;
    }

    return activitiesIndex.url({ query });
}

function activityAccent(item: AnnualSummaryDayActivity): string {
    const key = resolveActivitySportKey(
        item.sport_category,
        item.effective_discipline,
    );

    return sportAccentHex(key);
}

function closeSelectedDay(): void {
    selectedDay.value = null;
}

function onDayClick(event: MouseEvent, cell: DayCell): void {
    if (cell.activities <= 0) {
        selectedDay.value = null;

        return;
    }

    if (cell.items.length === 1) {
        selectedDay.value = null;
        router.visit(showActivity.url(cell.items[0].id));

        return;
    }

    if (cell.items.length === 0) {
        selectedDay.value = null;
        router.visit(activitiesRangeUrl(cell.iso, cell.iso));

        return;
    }

    if (selectedDay.value?.iso === cell.iso) {
        selectedDay.value = null;

        return;
    }

    const container = containerRef.value;

    if (!container) {
        return;
    }

    const rect = container.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;

    tooltip.value = null;
    selectedDay.value = {
        iso: cell.iso,
        label: cell.label,
        items: cell.items,
        x: Math.min(Math.max(12, x), Math.max(12, rect.width - 12)),
        y: Math.min(Math.max(12, y), Math.max(12, rect.height - 12)),
        alignRight: x > rect.width * 0.55,
        placeAbove: y > rect.height * 0.62,
    };
}

function openRangeActivities(
    from: string,
    to: string,
    activities: number,
): void {
    if (activities <= 0) {
        return;
    }

    selectedDay.value = null;
    router.visit(activitiesRangeUrl(from, to));
}

function onDocumentPointerDown(event: PointerEvent): void {
    if (!selectedDay.value) {
        return;
    }

    const target = event.target as Node | null;

    if (popoverRef.value && target && popoverRef.value.contains(target)) {
        return;
    }

    if (target instanceof Element && target.closest('[data-heatmap-day]')) {
        return;
    }

    selectedDay.value = null;
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && selectedDay.value) {
        selectedDay.value = null;
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown);
    document.addEventListener('keydown', onDocumentKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
    document.removeEventListener('keydown', onDocumentKeydown);
});

const HEAT_STOPS: Array<{ stop: number; rgb: [number, number, number] }> = [
    { stop: 0.0, rgb: [254, 240, 138] }, // #fef08a
    { stop: 0.25, rgb: [250, 204, 21] }, // #facc15
    { stop: 0.55, rgb: [251, 146, 60] }, // #fb923c
    { stop: 0.8, rgb: [234, 88, 12] }, // #ea580c
    { stop: 1.0, rgb: [220, 38, 38] }, // #dc2626
];

const HEAT_GRADIENT_CSS =
    'linear-gradient(to right, #fef08a 0%, #facc15 25%, #fb923c 55%, #ea580c 80%, #dc2626 100%)';

function interpolateHeatColor(value: number, maxValue: number): string {
    if (value <= 0 || maxValue <= 0) {
        return theme.isDark.value ? '#1c1c21' : '#f4f4f5';
    }

    const t = Math.min(1, Math.max(0, value / maxValue));

    for (let i = 0; i < HEAT_STOPS.length - 1; i++) {
        const curr = HEAT_STOPS[i];
        const next = HEAT_STOPS[i + 1];

        if (t >= curr.stop && t <= next.stop) {
            const localT = (t - curr.stop) / (next.stop - curr.stop);
            const r = Math.round(
                curr.rgb[0] + (next.rgb[0] - curr.rgb[0]) * localT,
            );
            const g = Math.round(
                curr.rgb[1] + (next.rgb[1] - curr.rgb[1]) * localT,
            );
            const b = Math.round(
                curr.rgb[2] + (next.rgb[2] - curr.rgb[2]) * localT,
            );

            return `rgb(${r}, ${g}, ${b})`;
        }
    }

    const last = HEAT_STOPS[HEAT_STOPS.length - 1].rgb;

    return `rgb(${last[0]}, ${last[1]}, ${last[2]})`;
}

function emptyCellColor(isFuture: boolean): string {
    if (isFuture) {
        return theme.isDark.value ? '#16161a' : '#fafafa';
    }

    return theme.isDark.value ? '#1e1e24' : '#f4f4f5';
}

const dailyEntryByIso = computed(() => {
    const map = new Map<string, AnnualSummaryDailyEntry>();

    for (const entry of props.summary.daily) {
        map.set(entry.date, entry);
    }

    return map;
});

const todayIso = computed<string>(() => props.summary.today);

const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as const;

const CELL_SIZE = 12;
const GRID_COLS = 54;
const GRID_WIDTH = GRID_COLS * CELL_SIZE;
const GRID_HEIGHT = 7 * CELL_SIZE;

function pad2(n: number): string {
    return String(n).padStart(2, '0');
}

const dayGrids = computed<YearDayGrid[]>(() => {
    const currentUnits = units.value;
    const metric = selectedMetric.value;
    const byIso = dailyEntryByIso.value;
    const today = todayIso.value;

    return props.selectedYears.map((year) => {
        const jan1 = new Date(Date.UTC(year, 0, 1));
        const jan1Dow = (jan1.getUTCDay() + 6) % 7; // Mon=0..Sun=6
        const totalDays = isLeapYear(year) ? 366 : 365;
        const cells: DayCell[] = [];
        const monthFirstPos = new Map<number, { col: number; row: number }>();

        for (let i = 0; i < totalDays; i++) {
            const d = new Date(Date.UTC(year, 0, 1 + i));
            const month = d.getUTCMonth();
            const day = d.getUTCDate();
            const row = (jan1Dow + i) % 7;
            const col = Math.floor((jan1Dow + i) / 7);
            const iso = `${year}-${pad2(month + 1)}-${pad2(day)}`;

            if (day === 1) {
                monthFirstPos.set(month, { col, row });
            }

            const entry = byIso.get(iso);
            const value = entry
                ? extractMetricValue(entry, metric, currentUnits)
                : 0;

            cells.push({
                iso,
                label: `${DAY_NAMES[row]}, ${day} ${MONTH_SHORT_NAMES[month]} ${year}`,
                col,
                row,
                value,
                activities: entry?.activities ?? 0,
                durationSeconds: entry?.duration_seconds ?? 0,
                calories: entry?.calories ?? 0,
                isFuture: iso > today,
                items: entry?.items ?? [],
            });
        }

        const monthDividers: string[] = [];
        const monthLabels: Array<{ label: string; col: number }> = [];

        for (let m = 0; m < 12; m++) {
            const pos = monthFirstPos.get(m);

            if (!pos) {
                continue;
            }

            monthLabels.push({
                label: MONTH_SHORT_NAMES[m],
                col: pos.col,
            });

            if (m === 0) {
                continue;
            }

            const x = pos.col * CELL_SIZE;
            const y = pos.row * CELL_SIZE;

            if (pos.row === 0) {
                monthDividers.push(`M ${x} 0 V ${GRID_HEIGHT}`);
            } else {
                monthDividers.push(
                    `M ${x + CELL_SIZE} 0 V ${y} H ${x} V ${GRID_HEIGHT}`,
                );
            }
        }

        const cols = Math.floor((jan1Dow + totalDays - 1) / 7) + 1;

        return {
            year,
            cols,
            cells,
            monthDividers,
            monthLabels,
        };
    });
});

const weekRows = computed<Array<{ year: number; weeks: WeekCell[] }>>(() => {
    const currentUnits = units.value;
    const metric = selectedMetric.value;
    const byIso = dailyEntryByIso.value;
    const today = todayIso.value;

    return props.selectedYears.map((year) => {
        const jan1 = new Date(Date.UTC(year, 0, 1));
        const jan1Dow = (jan1.getUTCDay() + 6) % 7;
        const totalDays = isLeapYear(year) ? 366 : 365;
        const totalWeeks = Math.floor((jan1Dow + totalDays - 1) / 7) + 1;

        const buckets: Array<{
            startIso: string;
            endIso: string;
            startLabel: string;
            endLabel: string;
            value: number;
            activities: number;
            durationSeconds: number;
            calories: number;
            allFuture: boolean;
        }> = Array.from({ length: totalWeeks }, () => ({
            startIso: '',
            endIso: '',
            startLabel: '',
            endLabel: '',
            value: 0,
            activities: 0,
            durationSeconds: 0,
            calories: 0,
            allFuture: true,
        }));

        for (let i = 0; i < totalDays; i++) {
            const d = new Date(Date.UTC(year, 0, 1 + i));
            const month = d.getUTCMonth();
            const day = d.getUTCDate();
            const col = Math.floor((jan1Dow + i) / 7);
            const iso = `${year}-${pad2(month + 1)}-${pad2(day)}`;
            const shortLabel = `${day} ${MONTH_SHORT_NAMES[month]}`;
            const bucket = buckets[col];

            if (!bucket.startIso) {
                bucket.startIso = iso;
                bucket.startLabel = shortLabel;
            }

            bucket.endIso = iso;
            bucket.endLabel = shortLabel;

            if (iso <= today) {
                bucket.allFuture = false;
            }

            const entry = byIso.get(iso);

            if (entry) {
                bucket.value += extractMetricValue(entry, metric, currentUnits);
                bucket.activities += entry.activities;
                bucket.durationSeconds += entry.duration_seconds;
                bucket.calories += entry.calories;
            }
        }

        const weeks: WeekCell[] = buckets.map((bucket, idx) => ({
            index: idx,
            startIso: bucket.startIso,
            endIso: bucket.endIso,
            label: `${bucket.startLabel} – ${bucket.endLabel} ${year}`,
            value: bucket.value,
            activities: bucket.activities,
            durationSeconds: bucket.durationSeconds,
            calories: bucket.calories,
            isFuture: bucket.allFuture,
        }));

        return { year, weeks };
    });
});

const monthRows = computed<Array<{ year: number; months: MonthCell[] }>>(() => {
    const currentUnits = units.value;
    const metric = selectedMetric.value;
    const todayParts = parseIsoDateParts(todayIso.value);
    const todayYr = todayParts?.year ?? 9999;
    const todayMo = (todayParts?.month ?? 12) - 1;

    const totalsByYear = new Map<
        number,
        Array<{
            value: number;
            activities: number;
            durationSeconds: number;
            calories: number;
        }>
    >();

    for (const year of props.selectedYears) {
        totalsByYear.set(
            year,
            Array.from({ length: 12 }, () => ({
                value: 0,
                activities: 0,
                durationSeconds: 0,
                calories: 0,
            })),
        );
    }

    for (const entry of props.summary.daily) {
        const parts = parseIsoDateParts(entry.date);

        if (!parts) {
            continue;
        }

        const months = totalsByYear.get(parts.year);

        if (!months) {
            continue;
        }

        const bucket = months[parts.month - 1];

        if (!bucket) {
            continue;
        }

        bucket.value += extractMetricValue(entry, metric, currentUnits);
        bucket.activities += entry.activities;
        bucket.durationSeconds += entry.duration_seconds;
        bucket.calories += entry.calories;
    }

    return props.selectedYears.map((year) => {
        const raw = totalsByYear.get(year) ?? [];

        return {
            year,
            months: raw.map((bucket, m) => ({
                month: m,
                startIso: `${year}-${pad2(m + 1)}-01`,
                endIso: `${year}-${pad2(m + 1)}-${pad2(daysInMonthUtc(year, m))}`,
                label: `${MONTH_SHORT_NAMES[m]} ${year}`,
                value: bucket.value,
                activities: bucket.activities,
                durationSeconds: bucket.durationSeconds,
                calories: bucket.calories,
                isFuture: year > todayYr || (year === todayYr && m > todayMo),
            })),
        };
    });
});

const yearRows = computed<YearRowTotal[]>(() => {
    return monthRows.value.map((row) => {
        let value = 0;
        let activities = 0;
        let durationSeconds = 0;
        let calories = 0;

        for (const m of row.months) {
            value += m.value;
            activities += m.activities;
            durationSeconds += m.durationSeconds;
            calories += m.calories;
        }

        return {
            year: row.year,
            startIso: `${row.year}-01-01`,
            endIso: `${row.year}-12-31`,
            value,
            activities,
            durationSeconds,
            calories,
        };
    });
});

const maxScaleValue = computed<number>(() => {
    let maxVal = 0;

    if (granularity.value === 'day') {
        for (const grid of dayGrids.value) {
            for (const cell of grid.cells) {
                if (cell.value > maxVal) {
                    maxVal = cell.value;
                }
            }
        }
    } else if (granularity.value === 'week') {
        for (const row of weekRows.value) {
            for (const week of row.weeks) {
                if (week.value > maxVal) {
                    maxVal = week.value;
                }
            }
        }
    } else if (granularity.value === 'month') {
        for (const row of monthRows.value) {
            for (const month of row.months) {
                if (month.value > maxVal) {
                    maxVal = month.value;
                }
            }
        }
    } else {
        for (const row of yearRows.value) {
            if (row.value > maxVal) {
                maxVal = row.value;
            }
        }
    }

    return maxVal;
});

const zeroLabel = computed<string>(() =>
    formatZeroMetric(selectedMetric.value, units.value),
);

const maxLabel = computed<string>(() => {
    if (maxScaleValue.value <= 0) {
        return zeroLabel.value;
    }

    return granularity.value === 'day'
        ? formatMetricDaily(
              maxScaleValue.value,
              selectedMetric.value,
              units.value,
          )
        : formatMetricTotal(
              maxScaleValue.value,
              selectedMetric.value,
              units.value,
          );
});

const cellStrokeColor = computed<string>(() =>
    theme.isDark.value ? '#27272a' : '#e4e4e7',
);

const monthBorderColor = computed<string>(() =>
    theme.isDark.value ? '#71717a' : '#52525b',
);

function buildMeta(
    activities: number,
    durationSeconds: number,
    calories: number,
): string | null {
    if (activities <= 0) {
        return null;
    }

    const parts: string[] = [
        `${formatNumber(activities, 0)} ${activities === 1 ? 'activity' : 'activities'}`,
    ];

    if (selectedMetric.value !== 'time' && durationSeconds > 0) {
        parts.push(formatDurationHuman(durationSeconds));
    }

    if (selectedMetric.value !== 'calories' && calories > 0) {
        parts.push(`${formatNumber(Math.round(calories), 0)} kcal`);
    }

    return parts.join(' · ');
}

function showTooltip(
    event: MouseEvent,
    title: string,
    value: number,
    activities: number,
    durationSeconds: number,
    calories: number,
    singleActivityName: string | null = null,
): void {
    if (selectedDay.value !== null) {
        return;
    }

    const container = containerRef.value;

    if (!container) {
        return;
    }

    const rect = container.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;

    const valueText =
        value > 0
            ? granularity.value === 'day'
                ? formatMetricDaily(value, selectedMetric.value, units.value)
                : formatMetricTotal(value, selectedMetric.value, units.value)
            : 'No activity';

    let hint: string | null = null;

    if (activities > 0) {
        if (granularity.value === 'day') {
            hint =
                activities === 1
                    ? singleActivityName
                        ? `${singleActivityName} · Click to open`
                        : 'Click to open activity'
                    : 'Click to select activity';
        } else {
            hint = 'Click to view activities';
        }
    }

    tooltip.value = {
        title,
        valueText,
        activities,
        meta: buildMeta(activities, durationSeconds, calories),
        hint,
        x,
        y,
        alignRight: x > rect.width * 0.62,
    };
}

function hideTooltip(): void {
    tooltip.value = null;
}

function formatCompactMonthValue(value: number): string {
    if (value <= 0) {
        return '';
    }

    switch (selectedMetric.value) {
        case 'distance':
            return value >= 100 ? String(Math.round(value)) : value.toFixed(0);
        case 'elevation':
            return value >= 1000
                ? `${(value / 1000).toFixed(1)}k`
                : String(Math.round(value));
        case 'time':
            return `${Math.round(value)}h`;
        case 'activities':
            return String(Math.round(value));
        case 'calories':
            return value >= 1000
                ? `${(value / 1000).toFixed(1)}k`
                : String(Math.round(value));
        case 'load':
            return String(Math.round(value));
    }
}

function isLightHeatText(value: number): boolean {
    if (value <= 0 || maxScaleValue.value <= 0) {
        return false;
    }

    return value / maxScaleValue.value >= 0.68;
}
</script>

<template>
    <div
        ref="containerRef"
        class="relative flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        @mouseleave="hideTooltip"
    >
        <div>
            <!-- Header Controls -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 pb-3.5 dark:border-zinc-800/80"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <label
                        for="calendar-display-value"
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Display value
                    </label>
                    <select
                        id="calendar-display-value"
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
                </div>

                <!-- Day / Week / Month / Year Switcher -->
                <div
                    class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                    role="group"
                    aria-label="Calendar granularity"
                >
                    <button
                        v-for="opt in GRANULARITY_OPTIONS"
                        :key="opt.value"
                        type="button"
                        class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            granularity === opt.value
                                ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="granularity = opt.value"
                    >
                        {{ opt.label }}
                    </button>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="selectedYears.length === 0"
                class="mt-4 flex h-[275px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
            >
                Select at least one year to view the activity calendar.
            </div>

            <!-- DAY MODE: Multi-Year Calendar Heatmap -->
            <div v-else-if="granularity === 'day'" class="mt-3 space-y-2.5">
                <!-- Month Header Strip -->
                <div
                    v-if="dayGrids.length > 0"
                    class="flex items-end gap-2 pl-7"
                >
                    <svg
                        :viewBox="`0 0 ${GRID_WIDTH} 14`"
                        class="block h-3.5 w-full overflow-visible"
                        aria-hidden="true"
                    >
                        <text
                            v-for="m in dayGrids[0].monthLabels"
                            :key="m.label"
                            :x="m.col * CELL_SIZE + 2"
                            y="11"
                            class="fill-zinc-400 font-mono text-[9.5px] dark:fill-zinc-500"
                        >
                            {{ m.label }}
                        </text>
                    </svg>
                </div>

                <!-- Stacked Year Calendar Grids -->
                <div
                    v-for="grid in dayGrids"
                    :key="grid.year"
                    class="flex items-center gap-2"
                >
                    <span
                        class="w-5 shrink-0 -rotate-90 text-center font-mono text-[10px] font-semibold tracking-wider text-zinc-500 tabular-nums select-none dark:text-zinc-400"
                    >
                        {{ grid.year }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <svg
                            :viewBox="`0 0 ${GRID_WIDTH} ${GRID_HEIGHT}`"
                            class="block h-auto w-full"
                            role="img"
                            :aria-label="`${grid.year} daily activity heatmap`"
                        >
                            <!-- Day Cells -->
                            <rect
                                v-for="cell in grid.cells"
                                :key="cell.iso"
                                :x="cell.col * CELL_SIZE"
                                :y="cell.row * CELL_SIZE"
                                :width="CELL_SIZE"
                                :height="CELL_SIZE"
                                :data-heatmap-day="
                                    cell.activities > 0 ? cell.iso : undefined
                                "
                                :fill="
                                    cell.value > 0
                                        ? interpolateHeatColor(
                                              cell.value,
                                              maxScaleValue,
                                          )
                                        : emptyCellColor(cell.isFuture)
                                "
                                :stroke="
                                    selectedDay?.iso === cell.iso
                                        ? theme.isDark.value
                                            ? '#fafafa'
                                            : '#18181b'
                                        : cellStrokeColor
                                "
                                :stroke-width="
                                    selectedDay?.iso === cell.iso ? 1.5 : 0.6
                                "
                                :class="
                                    cell.activities > 0
                                        ? 'cursor-pointer transition-opacity hover:opacity-80'
                                        : 'cursor-default'
                                "
                                @click="onDayClick($event, cell)"
                                @mousemove="
                                    showTooltip(
                                        $event,
                                        cell.label,
                                        cell.value,
                                        cell.activities,
                                        cell.durationSeconds,
                                        cell.calories,
                                        cell.items.length === 1
                                            ? cell.items[0].name
                                            : null,
                                    )
                                "
                                @mouseleave="hideTooltip"
                            />

                            <!-- Bold Month Boundary Dividers -->
                            <path
                                v-for="(d, idx) in grid.monthDividers"
                                :key="idx"
                                :d="d"
                                fill="none"
                                :stroke="monthBorderColor"
                                stroke-width="1.15"
                                pointer-events="none"
                            />

                            <!-- Crisp Outer Frame -->
                            <rect
                                x="0.5"
                                y="0.5"
                                :width="grid.cols * CELL_SIZE - 1"
                                :height="GRID_HEIGHT - 1"
                                fill="none"
                                :stroke="monthBorderColor"
                                stroke-width="1"
                                pointer-events="none"
                            />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- WEEK MODE: 53-Week Strip per Year -->
            <div v-else-if="granularity === 'week'" class="mt-3 space-y-2.5">
                <div
                    class="grid grid-cols-12 pl-12 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                >
                    <span v-for="m in MONTH_SHORT_NAMES" :key="m">{{ m }}</span>
                </div>
                <div
                    v-for="row in weekRows"
                    :key="row.year"
                    class="flex items-center gap-2.5"
                >
                    <span
                        class="w-10 shrink-0 font-mono text-[11px] font-semibold text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ row.year }}
                    </span>
                    <div
                        class="grid min-w-0 flex-1 gap-[2px]"
                        :style="{
                            gridTemplateColumns: `repeat(${row.weeks.length}, minmax(0, 1fr))`,
                        }"
                    >
                        <div
                            v-for="week in row.weeks"
                            :key="week.index"
                            :class="[
                                'h-7 rounded-[2px] border border-zinc-200/70 transition-opacity dark:border-zinc-800/80',
                                week.activities > 0
                                    ? 'cursor-pointer hover:opacity-80'
                                    : 'cursor-default',
                            ]"
                            :style="{
                                backgroundColor:
                                    week.value > 0
                                        ? interpolateHeatColor(
                                              week.value,
                                              maxScaleValue,
                                          )
                                        : emptyCellColor(week.isFuture),
                            }"
                            @click="
                                openRangeActivities(
                                    week.startIso,
                                    week.endIso,
                                    week.activities,
                                )
                            "
                            @mousemove="
                                showTooltip(
                                    $event,
                                    week.label,
                                    week.value,
                                    week.activities,
                                    week.durationSeconds,
                                    week.calories,
                                )
                            "
                            @mouseleave="hideTooltip"
                        />
                    </div>
                </div>
            </div>

            <!-- MONTH MODE: 12-Month Matrix per Year -->
            <div v-else-if="granularity === 'month'" class="mt-3 space-y-2">
                <div class="flex items-center gap-2.5">
                    <span class="w-10 shrink-0" />
                    <div
                        class="grid min-w-0 flex-1 grid-cols-12 gap-1 text-center font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                    >
                        <span v-for="m in MONTH_SHORT_NAMES" :key="m">{{
                            m
                        }}</span>
                    </div>
                </div>

                <div
                    v-for="row in monthRows"
                    :key="row.year"
                    class="flex items-center gap-2.5"
                >
                    <span
                        class="w-10 shrink-0 font-mono text-[11px] font-semibold text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ row.year }}
                    </span>
                    <div class="grid min-w-0 flex-1 grid-cols-12 gap-1">
                        <div
                            v-for="month in row.months"
                            :key="month.month"
                            class="flex h-9 items-center justify-center rounded border border-zinc-200/70 font-mono text-[10px] font-medium tabular-nums transition-opacity dark:border-zinc-800/80"
                            :class="[
                                month.activities > 0
                                    ? 'cursor-pointer hover:opacity-85'
                                    : 'cursor-default',
                                isLightHeatText(month.value)
                                    ? 'text-white'
                                    : 'text-zinc-900 dark:text-zinc-950',
                            ]"
                            :style="{
                                backgroundColor:
                                    month.value > 0
                                        ? interpolateHeatColor(
                                              month.value,
                                              maxScaleValue,
                                          )
                                        : emptyCellColor(month.isFuture),
                            }"
                            @click="
                                openRangeActivities(
                                    month.startIso,
                                    month.endIso,
                                    month.activities,
                                )
                            "
                            @mousemove="
                                showTooltip(
                                    $event,
                                    month.label,
                                    month.value,
                                    month.activities,
                                    month.durationSeconds,
                                    month.calories,
                                )
                            "
                            @mouseleave="hideTooltip"
                        >
                            <span class="hidden sm:inline">
                                {{ formatCompactMonthValue(month.value) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- YEAR MODE: Annual Comparison Bars -->
            <div v-else class="mt-4 space-y-2.5">
                <div
                    v-for="row in yearRows"
                    :key="row.year"
                    :class="[
                        'flex items-center gap-3',
                        row.activities > 0 ? 'cursor-pointer' : '',
                    ]"
                    @click="
                        openRangeActivities(
                            row.startIso,
                            row.endIso,
                            row.activities,
                        )
                    "
                    @mousemove="
                        showTooltip(
                            $event,
                            String(row.year),
                            row.value,
                            row.activities,
                            row.durationSeconds,
                            row.calories,
                        )
                    "
                    @mouseleave="hideTooltip"
                >
                    <span
                        class="w-10 shrink-0 font-mono text-xs font-semibold text-zinc-600 tabular-nums dark:text-zinc-300"
                    >
                        {{ row.year }}
                    </span>
                    <div
                        class="h-7 min-w-0 flex-1 overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-900"
                    >
                        <div
                            class="h-full rounded-md transition-all"
                            :style="{
                                width:
                                    maxScaleValue > 0 && row.value > 0
                                        ? `${Math.max(2, (row.value / maxScaleValue) * 100)}%`
                                        : '0%',
                                backgroundColor: interpolateHeatColor(
                                    row.value,
                                    maxScaleValue,
                                ),
                            }"
                        />
                    </div>
                    <span
                        class="w-24 shrink-0 text-right font-mono text-xs font-medium text-zinc-800 tabular-nums dark:text-zinc-200"
                    >
                        {{
                            row.value > 0
                                ? formatMetricTotal(
                                      row.value,
                                      selectedMetric,
                                      units,
                                  )
                                : zeroLabel
                        }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Heat Color Legend Bar -->
        <div
            v-if="selectedYears.length > 0"
            class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800/80"
        >
            <div
                class="h-2.5 w-full rounded-sm border border-zinc-200/70 dark:border-zinc-800/80"
                :style="{ background: HEAT_GRADIENT_CSS }"
            />
            <div
                class="mt-1.5 flex items-center justify-between font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
            >
                <span>{{ zeroLabel }}</span>
                <span>{{ maxLabel }}</span>
            </div>
        </div>

        <!-- Floating Hover Tooltip -->
        <div
            v-if="tooltip && !selectedDay"
            class="pointer-events-none absolute z-30 max-w-[260px] min-w-[168px] rounded-lg border border-zinc-200 bg-white px-2.5 py-2 font-mono text-[11px] text-zinc-900 shadow-lg dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"
            :style="{
                left: `${tooltip.x}px`,
                top: `${Math.max(8, tooltip.y - 12)}px`,
                transform: tooltip.alignRight
                    ? 'translate(-100%, -100%)'
                    : 'translate(10px, -100%)',
            }"
        >
            <div class="font-semibold">{{ tooltip.title }}</div>
            <div class="mt-0.5 text-zinc-700 dark:text-zinc-200">
                {{ tooltip.valueText }}
            </div>
            <div
                v-if="tooltip.meta"
                class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500"
            >
                {{ tooltip.meta }}
            </div>
            <div
                v-if="tooltip.hint"
                class="mt-1 truncate border-t border-zinc-100 pt-1 text-[10px] text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
            >
                {{ tooltip.hint }}
            </div>
        </div>

        <!-- Floating Multi-Activity Day Picker Popover -->
        <div
            v-if="selectedDay"
            ref="popoverRef"
            role="dialog"
            :aria-label="`Activities on ${selectedDay.label}`"
            class="absolute z-40 w-[280px] overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xl dark:border-zinc-800 dark:bg-[#141417]"
            :style="{
                left: `${selectedDay.x}px`,
                top: `${selectedDay.y}px`,
                transform: `${selectedDay.alignRight ? 'translateX(-100%)' : 'translateX(0)'} ${selectedDay.placeAbove ? 'translateY(calc(-100% - 10px))' : 'translateY(10px)'}`,
            }"
        >
            <div
                class="flex items-center justify-between gap-2 border-b border-zinc-100 px-3 py-2 dark:border-zinc-800/80"
            >
                <div class="min-w-0">
                    <div
                        class="truncate font-mono text-[11px] font-semibold text-zinc-900 dark:text-zinc-100"
                    >
                        {{ selectedDay.label }}
                    </div>
                    <div
                        class="font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ selectedDay.items.length }} activities
                    </div>
                </div>

                <button
                    type="button"
                    aria-label="Close day activities"
                    class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    @click="closeSelectedDay"
                >
                    <X class="size-3.5" />
                </button>
            </div>

            <div
                class="max-h-56 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800/80"
            >
                <Link
                    v-for="item in selectedDay.items"
                    :key="item.id"
                    :href="showActivity.url(item.id)"
                    class="group flex items-start justify-between gap-2 px-3 py-2 transition-colors hover:bg-[#f7f7f5] dark:hover:bg-zinc-900/70"
                    @click="closeSelectedDay"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: activityAccent(item),
                                }"
                            />
                            <span
                                class="truncate text-xs font-medium text-zinc-900 group-hover:text-zinc-950 dark:text-zinc-100 dark:group-hover:text-white"
                            >
                                {{ item.name }}
                            </span>
                        </div>
                        <div
                            class="mt-0.5 flex items-center gap-2 pl-3 font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            <span v-if="item.distance_meters > 0">
                                {{
                                    formatDistanceCompact(
                                        item.distance_meters,
                                        1,
                                    )
                                }}
                            </span>
                            <span v-if="item.duration_seconds > 0">
                                {{ formatDurationHuman(item.duration_seconds) }}
                            </span>
                        </div>
                    </div>

                    <ArrowUpRight
                        class="mt-0.5 size-3.5 shrink-0 text-zinc-300 transition-colors group-hover:text-zinc-700 dark:text-zinc-600 dark:group-hover:text-zinc-200"
                    />
                </Link>
            </div>

            <div
                class="border-t border-zinc-100 bg-[#f7f7f5]/60 px-3 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/40"
            >
                <Link
                    :href="activitiesRangeUrl(selectedDay.iso, selectedDay.iso)"
                    class="inline-flex items-center gap-1 font-mono text-[11px] font-medium text-zinc-600 transition-colors hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100"
                    @click="closeSelectedDay"
                >
                    <span>View day in All Activities</span>
                    <ArrowUpRight class="size-3" />
                </Link>
            </div>
        </div>
    </div>
</template>
