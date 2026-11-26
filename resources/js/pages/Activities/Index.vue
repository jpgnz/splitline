<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeftRight,
    ArrowRight,
    ArrowUp,
    ArrowUpDown,
    Calendar,
    ChevronLeft,
    ChevronRight,
    RotateCcw,
    Search,
    Star,
    Table,
    Trophy,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DateRangeControl, {
    type DateRangePreset,
    type DateWindowMeta,
} from '@/components/DateRangeControl.vue';
import {
    formatDurationClock,
    formatDurationHuman,
    formatNumber,
    formatShortDate,
    formatTimeOfDay,
} from '@/lib/format';
import { DEFAULT_SPORT_CATALOG, useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    compare as activitiesCompare,
    index as activitiesIndex,
    show as showActivity,
} from '@/routes/activities';

interface ActivityFilters {
    search: string;
    sport: string;
    range: string;
    from: string | null;
    to: string | null;
    pr: boolean;
    favorite: boolean;
    view: 'table' | 'calendar';
    month: string;
    sort: string;
    dir: 'asc' | 'desc';
}

interface AvailableSportOption {
    key: string;
    label: string;
    short_label: string;
    accent_hex: string;
}

interface ActivitySummary {
    count: number;
    total_distance_meters: number;
    total_duration_seconds: number;
    total_elevation_gain: number;
    total_calories: number;
    avg_hr: number | null;
    total_load: number;
}

interface ActivityListItem {
    id: number;
    provider: string;
    external_id: string | null;
    garmin_activity_id: number | null;
    name: string;
    activity_type_key: string;
    sport_category: string;
    sport_discipline?: string | null;
    biking_discipline?: string | null;
    discipline_override: string | null;
    effective_discipline: string | null;
    is_indoor: boolean;
    started_at_local: string;
    location_name: string | null;
    distance_meters: number;
    duration_seconds: number;
    moving_duration_seconds: number | null;
    elevation_gain: number | null;
    avg_speed: number | null;
    avg_hr: number | null;
    max_hr: number | null;
    avg_power: number | null;
    normalized_power: number | null;
    training_load: number | null;
    calories: number | null;
    is_favorite: boolean;
    is_pr: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface ActivityPaginator {
    data: ActivityListItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

interface CalendarActivityItem {
    id: number;
    name: string;
    sport_category: string;
    effective_discipline: string | null;
    is_indoor?: boolean;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number | null;
    training_load: number | null;
}

interface CalendarDay {
    date: string;
    dayOfMonth: number;
    inMonth: boolean;
    isToday: boolean;
    activities: CalendarActivityItem[];
    total_distance_meters: number;
    total_duration_seconds: number;
}

interface CalendarWeek {
    weekNumber: number;
    isFuture: boolean;
    isCurrent: boolean;
    days: CalendarDay[];
    totals: {
        count: number;
        distance_meters: number;
        duration_seconds: number;
        elevation_gain: number;
        load: number;
    };
}

interface ActivityCalendar {
    month: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
    weeks: CalendarWeek[];
}

interface ActivitiesIndexProps {
    filters: ActivityFilters;
    summary: ActivitySummary;
    activities: ActivityPaginator;
    calendar: ActivityCalendar;
    dateWindow: DateWindowMeta;
    available_sports?: AvailableSportOption[];
}

const props = defineProps<ActivitiesIndexProps>();
const { getSportMeta, resolveActivitySportKey, sportBadgeClasses } =
    useSports();
const {
    formatDistance,
    formatDistanceCompact,
    formatElevation,
    formatPace,
    formatPace100m,
    formatSpeed,
} = useUnits();

interface SportFilterPill {
    value: string;
    label: string;
    accentHex: string | null;
}

const FALLBACK_SPORT_KEYS = [
    'running',
    'cycling',
    'hiking',
    'walking',
    'swimming',
    'cardio',
] as const;

const sportPills = computed<SportFilterPill[]>(() => {
    const items: SportFilterPill[] = [
        {
            value: 'all',
            label: 'All Sports',
            accentHex: null,
        },
    ];

    if (props.available_sports && props.available_sports.length > 0) {
        for (const sport of props.available_sports) {
            items.push({
                value: sport.key,
                label: sport.label,
                accentHex: sport.accent_hex,
            });
        }

        return items;
    }

    for (const key of FALLBACK_SPORT_KEYS) {
        const meta = DEFAULT_SPORT_CATALOG[key];

        if (meta) {
            items.push({
                value: meta.key,
                label: meta.label,
                accentHex: meta.accent_hex,
            });
        }
    }

    return items;
});

const DATE_RANGES: DateRangePreset[] = [
    { value: '30d', label: '30d' },
    { value: '12w', label: '12w' },
    { value: '6m', label: '6m' },
    { value: '1y', label: '1y' },
    { value: 'all', label: 'All' },
];

const WEEKDAY_HEADERS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const searchInput = ref<string>(props.filters.search ?? '');
const selectedCompareIds = ref<number[]>([]);

const compareUrl = computed<string>(() =>
    activitiesCompare.url({
        query: { ids: selectedCompareIds.value.join(',') },
    }),
);

function isSelectedForCompare(id: number): boolean {
    return selectedCompareIds.value.includes(id);
}

function toggleCompareSelection(id: number): void {
    const idx = selectedCompareIds.value.indexOf(id);

    if (idx !== -1) {
        selectedCompareIds.value = selectedCompareIds.value.filter(
            (item) => item !== id,
        );

        return;
    }

    if (selectedCompareIds.value.length >= 3) {
        return;
    }

    selectedCompareIds.value = [...selectedCompareIds.value, id];
}

function clearCompareSelection(): void {
    selectedCompareIds.value = [];
}

let searchTimer: number | null = null;

watch(
    () => props.filters.search,
    (next) => {
        if ((next ?? '') !== searchInput.value) {
            searchInput.value = next ?? '';
        }
    },
);

onBeforeUnmount(() => {
    if (searchTimer !== null) {
        window.clearTimeout(searchTimer);
        searchTimer = null;
    }
});

const activeSport = computed<string>(() => props.filters.sport || 'all');

const hasActiveFilters = computed<boolean>(
    () =>
        Boolean(props.filters.search) ||
        activeSport.value !== 'all' ||
        props.filters.range !== 'all' ||
        props.filters.pr ||
        props.filters.favorite,
);

const hasCalendarFacetFilters = computed<boolean>(
    () =>
        Boolean(props.filters.search) ||
        activeSport.value !== 'all' ||
        props.filters.pr ||
        props.filters.favorite,
);

function emptyWeekLabel(week: CalendarWeek): string {
    if (week.isFuture) {
        return 'Upcoming';
    }

    if (hasCalendarFacetFilters.value) {
        return 'No matching sessions';
    }

    if (week.isCurrent) {
        return 'No sessions yet';
    }

    return 'Rest week';
}

type ActivityFilterOverrides = Partial<ActivityFilters> & {
    end?: string | null;
};

function buildQuery(
    overrides: ActivityFilterOverrides = {},
): Record<string, string | number> {
    const merged: ActivityFilters = {
        ...props.filters,
        ...overrides,
    };
    const nextEnd =
        overrides.end !== undefined
            ? overrides.end
            : props.dateWindow.isLatest
              ? null
              : props.dateWindow.end;

    const query: Record<string, string | number> = {};

    if (merged.search.trim() !== '') {
        query.search = merged.search.trim();
    }

    if (merged.sport && merged.sport !== 'all') {
        query.sport = merged.sport;
    }

    if (merged.range && merged.range !== 'all') {
        query.range = merged.range;
    }

    if (merged.range === 'custom') {
        if (merged.from) {
            query.from = merged.from;
        }

        if (merged.to) {
            query.to = merged.to;
        }
    } else if (merged.range !== 'all' && nextEnd) {
        query.end = nextEnd;
    }

    if (merged.pr) {
        query.pr = 1;
    }

    if (merged.favorite) {
        query.favorite = 1;
    }

    if (merged.view === 'calendar') {
        query.view = 'calendar';

        if (merged.month) {
            query.month = merged.month;
        }
    }

    if (merged.sort && merged.sort !== 'date') {
        query.sort = merged.sort;
    }

    if (merged.dir && (merged.sort !== 'date' || merged.dir !== 'desc')) {
        query.dir = merged.dir;
    }

    return query;
}

function applyFilters(
    overrides: ActivityFilterOverrides,
    options: { replace?: boolean } = {},
): void {
    router.get(activitiesIndex.url(), buildQuery(overrides), {
        preserveState: true,
        preserveScroll: true,
        replace: options.replace ?? false,
    });
}

function onSearchInput(): void {
    if (searchTimer !== null) {
        window.clearTimeout(searchTimer);
    }

    searchTimer = window.setTimeout(() => {
        applyFilters({ search: searchInput.value }, { replace: true });
    }, 300);
}

function clearSearch(): void {
    searchInput.value = '';

    if (searchTimer !== null) {
        window.clearTimeout(searchTimer);
        searchTimer = null;
    }

    applyFilters({ search: '' }, { replace: true });
}

function selectSport(sport: string): void {
    applyFilters({ sport });
}

function selectRange(range: string): void {
    applyFilters({ range, end: null, from: null, to: null });
}

function stepRange(end: string | null): void {
    applyFilters({
        range: props.filters.range,
        end,
        from: null,
        to: null,
    });
}

function applyCustomRange(payload: {
    from: string | null;
    to: string | null;
}): void {
    applyFilters({
        range: 'custom',
        end: null,
        from: payload.from,
        to: payload.to,
    });
}

function togglePr(): void {
    applyFilters({ pr: !props.filters.pr });
}

function toggleFavorite(): void {
    applyFilters({ favorite: !props.filters.favorite });
}

function setView(view: 'table' | 'calendar'): void {
    if (view === props.filters.view) {
        return;
    }

    applyFilters({
        view,
        month: view === 'calendar' ? props.calendar.month : props.filters.month,
    });
}

function stepMonth(month: string): void {
    applyFilters({ view: 'calendar', month });
}

function toggleSort(column: string): void {
    const isSameColumn = props.filters.sort === column;
    const nextDir: 'asc' | 'desc' = isSameColumn
        ? props.filters.dir === 'desc'
            ? 'asc'
            : 'desc'
        : 'desc';

    applyFilters({ sort: column, dir: nextDir });
}

function resetFilters(): void {
    searchInput.value = '';

    applyFilters({
        search: '',
        sport: 'all',
        range: 'all',
        end: null,
        from: null,
        to: null,
        pr: false,
        favorite: false,
        sort: 'date',
        dir: 'desc',
    });
}

function isSportPillActive(pillValue: string): boolean {
    if (pillValue === 'all') {
        return activeSport.value === 'all';
    }

    if (activeSport.value === pillValue) {
        return true;
    }

    return (
        activeSport.value !== 'all' &&
        getSportMeta(activeSport.value).key === pillValue
    );
}

function resolveSportMeta(
    sportCategory: string,
    effectiveDiscipline: string | null,
    isIndoor?: boolean,
) {
    const key = resolveActivitySportKey(
        sportCategory,
        effectiveDiscipline,
        isIndoor,
    );

    return getSportMeta(key);
}

function sportAccentHex(
    sportCategory: string,
    effectiveDiscipline: string | null,
    isIndoor?: boolean,
): string {
    return resolveSportMeta(sportCategory, effectiveDiscipline, isIndoor)
        .accent_hex;
}

function sportChipClasses(
    sportCategory: string,
    effectiveDiscipline: string | null,
    isIndoor?: boolean,
): string {
    const meta = resolveSportMeta(sportCategory, effectiveDiscipline, isIndoor);

    return `${sportBadgeClasses(meta.color_token)} hover:brightness-95 dark:hover:brightness-110`;
}

function sportDisplayLabel(
    sportCategory: string,
    effectiveDiscipline: string | null,
    isIndoor?: boolean,
): string {
    return resolveSportMeta(sportCategory, effectiveDiscipline, isIndoor).label;
}

function formatActivityPaceOrSpeed(activity: ActivityListItem): string {
    const meta = resolveSportMeta(
        activity.sport_category,
        activity.effective_discipline,
        activity.is_indoor,
    );

    if (meta.metric_mode === 'pace_100m') {
        return formatPace100m(activity.avg_speed);
    }

    if (meta.metric_mode === 'pace') {
        return formatPace(activity.avg_speed);
    }

    return formatSpeed(activity.avg_speed);
}

function formatActivityDate(isoOrLocal: string): string {
    const short = formatShortDate(isoOrLocal);
    const parsed = new Date(isoOrLocal);

    if (Number.isNaN(parsed.getTime())) {
        return short;
    }

    const year = parsed.getFullYear();
    const currentYear = new Date().getFullYear();

    return year !== currentYear ? `${short} ${year}` : short;
}

function calendarCellIntensityClass(day: CalendarDay): string {
    if (!day.inMonth) {
        return 'bg-zinc-50/60 opacity-50 dark:bg-zinc-950/30';
    }

    const minutes = day.total_duration_seconds / 60;
    const km = day.total_distance_meters / 1000;

    if (day.activities.length === 0) {
        return 'bg-white dark:bg-[#141417]';
    }

    if (minutes >= 120 || km >= 40) {
        return 'bg-emerald-500/12 dark:bg-emerald-500/14';
    }

    if (minutes >= 60 || km >= 15) {
        return 'bg-emerald-500/7 dark:bg-emerald-500/9';
    }

    return 'bg-emerald-500/[0.035] dark:bg-emerald-500/[0.045]';
}

function formatCalendarChipMetric(item: CalendarActivityItem): string {
    if (item.distance_meters > 0) {
        return formatDistanceCompact(item.distance_meters, 1);
    }

    return formatDurationHuman(item.duration_seconds);
}

function decodePaginationLabel(label: string): string {
    return label
        .replace(/&laquo;/g, '‹')
        .replace(/&raquo;/g, '›')
        .replace(/&amp;/g, '&')
        .trim();
}

const summaryKpis = computed<
    Array<{ label: string; value: string; accentClass: string }>
>(() => [
    {
        label: 'Activities',
        value: formatNumber(props.summary.count, 0),
        accentClass: 'bg-zinc-400 dark:bg-zinc-500',
    },
    {
        label: 'Distance',
        value: formatDistance(props.summary.total_distance_meters, 1),
        accentClass: 'bg-amber-500',
    },
    {
        label: 'Duration',
        value: formatDurationHuman(props.summary.total_duration_seconds),
        accentClass: 'bg-sky-500',
    },
    {
        label: 'Elevation',
        value: formatElevation(props.summary.total_elevation_gain),
        accentClass: 'bg-emerald-500',
    },
    {
        label: 'Avg HR',
        value:
            props.summary.avg_hr !== null
                ? `${Math.round(props.summary.avg_hr)} bpm`
                : '—',
        accentClass: 'bg-rose-500',
    },
    {
        label: 'Training Load',
        value: formatNumber(props.summary.total_load, 0),
        accentClass: 'bg-indigo-500',
    },
]);
</script>

<template>
    <div class="space-y-6">
        <Head title="All Activities" />

        <!-- Page Header -->
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    All Activities
                </h1>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Debounced Search -->
                <div class="relative w-full sm:w-64">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-zinc-400 dark:text-zinc-500"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        placeholder="Search title or location..."
                        aria-label="Search activities"
                        class="w-full rounded-lg border border-zinc-200/90 bg-white py-2 pr-8 pl-8.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600"
                        @input="onSearchInput"
                    />
                    <button
                        v-if="searchInput"
                        type="button"
                        aria-label="Clear search"
                        class="absolute top-1/2 right-2 -translate-y-1/2 cursor-pointer rounded p-0.5 text-zinc-400 transition-colors hover:text-zinc-700 dark:hover:text-zinc-200"
                        @click="clearSearch"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>

                <!-- Table / Calendar Segmented Toggle -->
                <div
                    class="inline-flex rounded-lg border border-zinc-200/90 bg-white p-1 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-3 py-1.5 font-mono text-xs font-medium transition-colors"
                        :class="
                            filters.view === 'table'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="setView('table')"
                    >
                        <Table class="size-3.5" />
                        Table
                    </button>
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-3 py-1.5 font-mono text-xs font-medium transition-colors"
                        :class="
                            filters.view === 'calendar'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="setView('calendar')"
                    >
                        <Calendar class="size-3.5" />
                        Calendar
                    </button>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <section
            class="space-y-3 rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <!-- Sport & Discipline Pills -->
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="pill in sportPills"
                    :key="pill.value"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] font-medium transition-colors"
                    :class="
                        isSportPillActive(pill.value)
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

            <!-- Date Range & Facet Toggles -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-3 dark:border-zinc-800/80"
            >
                <DateRangeControl
                    :presets="DATE_RANGES"
                    :window="dateWindow"
                    popover-align="left"
                    aria-label="Activities date range"
                    @select-preset="selectRange"
                    @step="stepRange"
                    @apply-custom="applyCustomRange"
                />

                <!-- PRs & Favorites Chips -->
                <div class="ml-auto flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        :disabled="!hasActiveFilters"
                        :tabindex="hasActiveFilters ? 0 : -1"
                        :aria-hidden="!hasActiveFilters"
                        :class="[
                            'inline-flex items-center gap-1 rounded-lg px-2 py-1.5 font-mono text-[11px] transition-colors',
                            hasActiveFilters
                                ? 'cursor-pointer text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                : 'pointer-events-none opacity-0',
                        ]"
                        @click="resetFilters"
                    >
                        <RotateCcw class="size-3" />
                        <span>Reset</span>
                    </button>

                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            filters.pr
                                ? 'border-amber-500/60 bg-amber-50 text-amber-800 dark:border-amber-500/50 dark:bg-amber-500/15 dark:text-amber-300'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-zinc-900/50 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="togglePr"
                    >
                        <Trophy class="size-3 text-amber-500" />
                        <span>PRs</span>
                    </button>

                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            filters.favorite
                                ? 'border-amber-500/60 bg-amber-50 text-amber-800 dark:border-amber-500/50 dark:bg-amber-500/15 dark:text-amber-300'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-zinc-900/50 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="toggleFavorite"
                    >
                        <Star
                            class="size-3 text-amber-500"
                            :class="filters.favorite && 'fill-amber-500'"
                        />
                        <span>Favorites</span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Dynamic Filtered Summary Strip -->
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div
                v-for="kpi in summaryKpis"
                :key="kpi.label"
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-3.5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-8"
                    :class="kpi.accentClass"
                />
                <div
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ kpi.label }}
                </div>
                <div
                    class="mt-1 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ kpi.value }}
                </div>
            </div>
        </section>

        <!-- TABLE VIEW -->
        <section
            v-if="filters.view === 'table'"
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div v-if="activities.data.length > 0" class="overflow-x-auto">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead
                        class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                    >
                        <tr>
                            <th
                                class="w-10 py-2.5 pr-1 pl-4 font-medium whitespace-nowrap"
                                title="Select 2–3 activities to compare"
                            >
                                <ArrowLeftRight
                                    class="size-3.5 text-zinc-400 dark:text-zinc-500"
                                    aria-label="Compare"
                                />
                            </th>
                            <th
                                class="px-4 py-2.5 font-medium whitespace-nowrap"
                            >
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('date')"
                                >
                                    <span>Date</span>
                                    <ArrowUp
                                        v-if="
                                            filters.sort === 'date' &&
                                            filters.dir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="filters.sort === 'date'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th
                                class="px-4 py-2.5 font-medium whitespace-nowrap"
                            >
                                Activity
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('distance')"
                                >
                                    <span>Distance</span>
                                    <ArrowUp
                                        v-if="
                                            filters.sort === 'distance' &&
                                            filters.dir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="filters.sort === 'distance'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('duration')"
                                >
                                    <span>Duration</span>
                                    <ArrowUp
                                        v-if="
                                            filters.sort === 'duration' &&
                                            filters.dir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="filters.sort === 'duration'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Pace / Speed
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('elevation')"
                                >
                                    <span>Vert</span>
                                    <ArrowUp
                                        v-if="
                                            filters.sort === 'elevation' &&
                                            filters.dir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="filters.sort === 'elevation'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('hr')"
                                >
                                    <span>Avg HR</span>
                                    <ArrowUp
                                        v-if="
                                            filters.sort === 'hr' &&
                                            filters.dir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="filters.sort === 'hr'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-900 dark:hover:text-zinc-100"
                                    @click="toggleSort('load')"
                                >
                                    <span>Load</span>
                                    <ArrowUp
                                        v-if="
                                            filters.sort === 'load' &&
                                            filters.dir === 'asc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowDown
                                        v-else-if="filters.sort === 'load'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                        </tr>
                    </thead>

                    <tbody
                        class="divide-y divide-zinc-100 dark:divide-zinc-800/80"
                    >
                        <tr
                            v-for="activity in activities.data"
                            :key="activity.id"
                            class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                            :class="
                                isSelectedForCompare(activity.id)
                                    ? 'bg-emerald-500/[0.04] dark:bg-emerald-500/[0.07]'
                                    : undefined
                            "
                        >
                            <!-- Compare Checkbox -->
                            <td class="w-10 py-3 pr-1 pl-4">
                                <input
                                    type="checkbox"
                                    :checked="isSelectedForCompare(activity.id)"
                                    :disabled="
                                        !isSelectedForCompare(activity.id) &&
                                        selectedCompareIds.length >= 3
                                    "
                                    :aria-label="`Select ${activity.name} for comparison`"
                                    class="size-3.5 cursor-pointer rounded border-zinc-300 text-emerald-600 accent-emerald-600 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-900"
                                    @change="
                                        toggleCompareSelection(activity.id)
                                    "
                                />
                            </td>

                            <!-- Date & Time -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div
                                    class="font-mono text-xs font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        formatActivityDate(
                                            activity.started_at_local,
                                        )
                                    }}
                                </div>
                                <div
                                    class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                                >
                                    {{
                                        formatTimeOfDay(
                                            activity.started_at_local,
                                        )
                                    }}
                                </div>
                            </td>

                            <!-- Title, Sport Dot, Badges, Location -->
                            <td class="max-w-xs px-4 py-3">
                                <div class="flex items-start gap-2.5">
                                    <span
                                        class="mt-1.5 size-2 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor: sportAccentHex(
                                                activity.sport_category,
                                                activity.effective_discipline,
                                                activity.is_indoor,
                                            ),
                                        }"
                                    />
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <Link
                                                :href="
                                                    showActivity.url(
                                                        activity.id,
                                                    )
                                                "
                                                class="truncate font-medium text-zinc-900 transition-colors hover:text-amber-600 dark:text-zinc-100 dark:hover:text-amber-400"
                                            >
                                                {{ activity.name }}
                                            </Link>

                                            <Star
                                                v-if="activity.is_favorite"
                                                class="size-3 shrink-0 fill-amber-500 text-amber-500"
                                                aria-label="Favorite"
                                            />
                                            <span
                                                v-if="activity.is_pr"
                                                class="inline-flex items-center rounded border border-amber-500/40 bg-amber-50 px-1.5 py-0.5 font-mono text-[10px] leading-none font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
                                            >
                                                PR
                                            </span>
                                            <span
                                                v-if="activity.is_indoor"
                                                class="inline-flex items-center rounded border border-zinc-200 bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] leading-none text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                                            >
                                                INDOOR
                                            </span>
                                        </div>

                                        <div
                                            class="mt-0.5 flex items-center gap-1.5 truncate font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                                        >
                                            <span>
                                                {{
                                                    sportDisplayLabel(
                                                        activity.sport_category,
                                                        activity.effective_discipline,
                                                        activity.is_indoor,
                                                    )
                                                }}
                                            </span>
                                            <template
                                                v-if="activity.location_name"
                                            >
                                                <span>·</span>
                                                <span class="truncate">
                                                    {{ activity.location_name }}
                                                </span>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Distance -->
                            <td
                                class="px-4 py-3 text-right font-mono text-xs font-medium whitespace-nowrap text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    activity.distance_meters > 0
                                        ? formatDistance(
                                              activity.distance_meters,
                                              2,
                                          )
                                        : '—'
                                }}
                            </td>

                            <!-- Duration -->
                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                {{
                                    formatDurationClock(
                                        activity.duration_seconds,
                                    )
                                }}
                            </td>

                            <!-- Pace / Speed -->
                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                {{ formatActivityPaceOrSpeed(activity) }}
                            </td>

                            <!-- Vert -->
                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                {{ formatElevation(activity.elevation_gain) }}
                            </td>

                            <!-- Avg HR -->
                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap"
                            >
                                <span
                                    v-if="activity.avg_hr !== null"
                                    class="text-rose-600 dark:text-rose-400"
                                >
                                    {{ activity.avg_hr }}
                                    <span
                                        class="text-[10px] text-zinc-400 dark:text-zinc-500"
                                    >
                                        bpm
                                    </span>
                                </span>
                                <span
                                    v-else
                                    class="text-zinc-400 dark:text-zinc-500"
                                >
                                    —
                                </span>
                            </td>

                            <!-- Power / Load -->
                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap"
                            >
                                <div class="text-zinc-900 dark:text-zinc-100">
                                    {{
                                        activity.training_load !== null
                                            ? formatNumber(
                                                  activity.training_load,
                                                  0,
                                              )
                                            : '—'
                                    }}
                                </div>
                                <div
                                    v-if="activity.avg_power !== null"
                                    class="text-[10px] text-sky-600 dark:text-sky-400"
                                >
                                    {{ Math.round(activity.avg_power) }} W
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div
                v-else
                class="flex flex-col items-center justify-center px-6 py-16 text-center"
            >
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    No activities match your current filters.
                </p>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="mt-3 cursor-pointer rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 font-mono text-xs text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    @click="resetFilters"
                >
                    Clear filters
                </button>
            </div>

            <!-- Pagination Footer -->
            <div
                v-if="activities.last_page > 1"
                class="flex flex-col items-center justify-between gap-3 border-t border-zinc-200/80 px-4 py-3 sm:flex-row dark:border-zinc-800/80"
            >
                <p
                    class="font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    Page {{ activities.current_page }} of
                    {{ activities.last_page }} ·
                    {{ formatNumber(activities.total, 0) }} total
                </p>

                <nav
                    class="flex flex-wrap items-center gap-1"
                    aria-label="Pagination"
                >
                    <template
                        v-for="(link, idx) in activities.links"
                        :key="`${link.label}-${idx}`"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            preserve-state
                            class="inline-flex min-w-8 items-center justify-center rounded-md border px-2.5 py-1 font-mono text-xs tabular-nums transition-colors"
                            :class="
                                link.active
                                    ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                    : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                            "
                        >
                            {{ decodePaginationLabel(link.label) }}
                        </Link>
                        <span
                            v-else
                            class="inline-flex min-w-8 items-center justify-center rounded-md border border-transparent px-2.5 py-1 font-mono text-xs text-zinc-400 tabular-nums dark:text-zinc-600"
                        >
                            {{ decodePaginationLabel(link.label) }}
                        </span>
                    </template>
                </nav>
            </div>
        </section>

        <!-- CALENDAR / TRAINING HEATMAP VIEW -->
        <section
            v-else
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <!-- Month Stepper Header -->
            <div
                class="flex items-center justify-between border-b border-zinc-200/80 px-4 py-3 dark:border-zinc-800/80"
            >
                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="stepMonth(calendar.prevMonth)"
                >
                    <ChevronLeft class="size-3.5" />
                    <span class="hidden sm:inline">Prev</span>
                </button>

                <h2
                    class="font-mono text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                >
                    {{ calendar.monthLabel }}
                </h2>

                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="stepMonth(calendar.nextMonth)"
                >
                    <span class="hidden sm:inline">Next</span>
                    <ChevronRight class="size-3.5" />
                </button>
            </div>

            <!-- 8-Column Desktop Header -->
            <div
                class="hidden grid-cols-8 border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[10px] tracking-wider text-zinc-500 uppercase lg:grid dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
            >
                <div
                    v-for="dayName in WEEKDAY_HEADERS"
                    :key="dayName"
                    class="border-r border-zinc-200/70 px-3 py-2 dark:border-zinc-800/70"
                >
                    {{ dayName }}
                </div>
                <div class="px-3 py-2">Week Total</div>
            </div>

            <!-- Weeks Grid -->
            <div class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70">
                <div
                    v-for="week in calendar.weeks"
                    :key="week.weekNumber"
                    class="grid grid-cols-1 lg:grid-cols-8"
                >
                    <!-- Mon..Sun Day Cells -->
                    <div
                        v-for="day in week.days"
                        :key="day.date"
                        class="flex min-h-24 flex-col justify-between gap-2 border-b border-zinc-100 p-2.5 transition-colors lg:min-h-28 lg:border-r lg:border-b-0 lg:border-zinc-200/70 dark:border-zinc-800/70"
                        :class="calendarCellIntensityClass(day)"
                    >
                        <div class="flex items-center justify-between gap-1">
                            <span
                                class="inline-flex size-6 items-center justify-center rounded-md font-mono text-xs tabular-nums"
                                :class="
                                    day.isToday
                                        ? 'bg-zinc-900 font-semibold text-white dark:bg-zinc-100 dark:text-zinc-950'
                                        : 'text-zinc-600 dark:text-zinc-400'
                                "
                            >
                                {{ day.dayOfMonth }}
                            </span>

                            <span
                                v-if="day.total_duration_seconds > 0"
                                class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                            >
                                {{
                                    formatDurationHuman(
                                        day.total_duration_seconds,
                                    )
                                }}
                            </span>
                        </div>

                        <div class="space-y-1">
                            <Link
                                v-for="item in day.activities"
                                :key="item.id"
                                :href="showActivity.url(item.id)"
                                class="flex items-center justify-between gap-1.5 rounded-md border px-1.5 py-1 text-[11px] transition-colors"
                                :class="
                                    sportChipClasses(
                                        item.sport_category,
                                        item.effective_discipline,
                                        item.is_indoor,
                                    )
                                "
                                :title="item.name"
                            >
                                <span class="flex min-w-0 items-center gap-1">
                                    <span
                                        class="size-1.5 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor: sportAccentHex(
                                                item.sport_category,
                                                item.effective_discipline,
                                                item.is_indoor,
                                            ),
                                        }"
                                    />
                                    <span class="truncate font-medium">
                                        {{ item.name }}
                                    </span>
                                </span>
                                <span
                                    class="shrink-0 font-mono text-[10px] tabular-nums opacity-80"
                                >
                                    {{ formatCalendarChipMetric(item) }}
                                </span>
                            </Link>
                        </div>
                    </div>

                    <!-- Week Total Cell -->
                    <div
                        class="flex flex-col justify-between gap-2 bg-[#f7f7f5]/60 p-3 dark:bg-zinc-900/40"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-400 uppercase tabular-nums dark:text-zinc-500"
                            >
                                W{{ week.weekNumber }}
                            </span>
                            <span
                                v-if="!week.isFuture || week.totals.count > 0"
                                class="font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                            >
                                {{ week.totals.count }}
                                {{
                                    week.totals.count === 1
                                        ? 'session'
                                        : 'sessions'
                                }}
                            </span>
                        </div>

                        <div
                            v-if="week.totals.count > 0"
                            class="space-y-1 font-mono text-xs tabular-nums"
                        >
                            <div
                                class="font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatDistance(
                                        week.totals.distance_meters,
                                        1,
                                    )
                                }}
                            </div>
                            <div
                                class="text-[11px] text-zinc-600 dark:text-zinc-400"
                            >
                                {{
                                    formatDurationHuman(
                                        week.totals.duration_seconds,
                                    )
                                }}
                            </div>
                            <div
                                v-if="week.totals.elevation_gain > 0"
                                class="text-[11px] text-emerald-600 dark:text-emerald-400"
                            >
                                +{{
                                    formatElevation(week.totals.elevation_gain)
                                }}
                            </div>
                            <div
                                v-if="week.totals.load > 0"
                                class="text-[10px] text-zinc-400 dark:text-zinc-500"
                            >
                                Load {{ formatNumber(week.totals.load, 0) }}
                            </div>
                        </div>
                        <div
                            v-else
                            class="font-mono text-[11px] text-zinc-400 dark:text-zinc-600"
                        >
                            {{ emptyWeekLabel(week) }}
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Floating Compare Action Bar -->
        <div
            v-if="selectedCompareIds.length > 0"
            class="fixed bottom-5 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-full border border-zinc-200/90 bg-white/95 px-4 py-2 shadow-lg backdrop-blur-md dark:border-zinc-700/90 dark:bg-[#18181b]/95"
        >
            <span
                class="font-mono text-xs text-zinc-600 tabular-nums dark:text-zinc-300"
            >
                {{ selectedCompareIds.length }}/3 selected
            </span>

            <Link
                v-if="selectedCompareIds.length >= 2"
                :href="compareUrl"
                class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600 px-3.5 py-1.5 font-mono text-xs font-medium text-white transition-colors hover:bg-emerald-500"
            >
                <ArrowLeftRight class="size-3.5" />
                <span>
                    Compare {{ selectedCompareIds.length }} Activities
                </span>
                <ArrowRight class="size-3.5" />
            </Link>
            <span
                v-else
                class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
            >
                Pick 1–2 more to compare
            </span>

            <button
                type="button"
                class="cursor-pointer rounded-full px-2 py-1 font-mono text-[11px] text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                @click="clearCompareSelection"
            >
                Clear
            </button>
        </div>
    </div>
</template>
