<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Flag,
    MapPin,
    Mountain,
    RefreshCw,
    Search,
    Star,
    TrendingUp,
    Trophy,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import RouteMiniMap from '@/components/routes/RouteMiniMap.vue';
import {
    formatDurationClock,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import { climbCategoryMeta, formatGrade } from '@/lib/segments';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    index as segmentsIndex,
    scan as scanSegments,
    show as showSegment,
    update as updateSegment,
} from '@/routes/segments';
import type { SegmentCard, SegmentsIndexProps } from '@/types/segments';

const props = defineProps<SegmentsIndexProps>();

const page = usePage();
const { getSportMeta, sportBadgeClasses } = useSports();
const {
    formatDistance,
    formatElevation,
    formatPace,
    formatPace100m,
    formatSpeed,
    formatVam,
} = useUnits();

const searchInput = ref<string>(props.filters.search ?? '');
const isScanning = ref<boolean>(false);
const togglingStarId = ref<number | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => props.filters.search,
    (next) => {
        if (next !== searchInput.value) {
            searchInput.value = next ?? '';
        }
    },
);

onBeforeUnmount(() => {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }
});

const kindTabs = computed<
    Array<{ value: string; label: string; count: number }>
>(() => [
    { value: 'all', label: 'All', count: props.summary.total_segments },
    { value: 'climb', label: 'Climbs', count: props.summary.climbs_count },
    { value: 'custom', label: 'Custom', count: props.summary.custom_count },
    { value: 'starred', label: 'Starred', count: props.summary.starred_count },
]);

const categoryPills = [
    { value: 'all', label: 'All Grades' },
    { value: 'hc', label: 'HC' },
    { value: 'cat1', label: 'Cat 1' },
    { value: 'cat2', label: 'Cat 2' },
    { value: 'cat3', label: 'Cat 3' },
    { value: 'cat4', label: 'Cat 4' },
    { value: 'uncategorized', label: 'Uncategorized' },
] as const;

const sortOptions = [
    { value: 'efforts', label: 'Most Efforts' },
    { value: 'recent', label: 'Most Recent' },
    { value: 'climb_score', label: 'Climb Score (FIETS)' },
    { value: 'elevation', label: 'Most Climbing' },
    { value: 'distance', label: 'Longest Distance' },
] as const;

const sportPills = computed<
    Array<{ value: string; label: string; accentHex: string | null }>
>(() => {
    const pills: Array<{
        value: string;
        label: string;
        accentHex: string | null;
    }> = [{ value: 'all', label: 'All Sports', accentHex: null }];

    for (const sport of props.available_sports ?? []) {
        pills.push({
            value: sport.key,
            label: sport.short_label || sport.label,
            accentHex: sport.accent_hex,
        });
    }

    return pills;
});

const showCategoryFilters = computed<boolean>(
    () => props.filters.kind === 'all' || props.filters.kind === 'climb',
);

const hasActiveFilters = computed<boolean>(
    () =>
        props.filters.kind !== 'all' ||
        props.filters.sport !== 'all' ||
        props.filters.category !== 'all' ||
        props.filters.search.trim() !== '',
);

const summaryKpis = computed<
    Array<{ label: string; value: string; sub: string; accentClass: string }>
>(() => [
    {
        label: 'Total Segments',
        value: formatNumber(props.summary.total_segments, 0),
        sub: `${props.summary.starred_count} starred`,
        accentClass: 'bg-emerald-500',
    },
    {
        label: 'Detected Climbs',
        value: formatNumber(props.summary.climbs_count, 0),
        sub:
            props.summary.min_efforts > 1
                ? `min ${props.summary.min_efforts} efforts · Cat 4–HC`
                : 'Cat 4 through HC',
        accentClass: 'bg-amber-500',
    },
    {
        label: 'Custom Segments',
        value: formatNumber(props.summary.custom_count, 0),
        sub: 'user-defined slices',
        accentClass: 'bg-sky-500',
    },
    {
        label: 'Total Efforts',
        value: formatNumber(props.summary.total_efforts, 0),
        sub: 'matched passes',
        accentClass: 'bg-indigo-500',
    },
]);

function isSportActive(value: string): boolean {
    if (value === 'all') {
        return !props.filters.sport || props.filters.sport === 'all';
    }

    return (
        props.filters.sport === value ||
        (props.filters.sport !== 'all' &&
            getSportMeta(props.filters.sport).key === value)
    );
}

function buildQuery(
    overrides: Partial<{
        kind: string;
        sport: string;
        category: string;
        search: string;
        sort: string;
    }>,
): Record<string, string> {
    const merged = {
        kind: props.filters.kind,
        sport: props.filters.sport,
        category: props.filters.category,
        search: props.filters.search,
        sort: props.filters.sort,
        ...overrides,
    };

    const query: Record<string, string> = {};

    if (merged.kind && merged.kind !== 'all') {
        query.kind = merged.kind;
    }

    if (merged.sport && merged.sport !== 'all') {
        query.sport = merged.sport;
    }

    if (
        merged.category &&
        merged.category !== 'all' &&
        (merged.kind === 'all' || merged.kind === 'climb')
    ) {
        query.category = merged.category;
    }

    if (merged.search.trim() !== '') {
        query.search = merged.search.trim();
    }

    if (merged.sort && merged.sort !== 'efforts') {
        query.sort = merged.sort;
    }

    return query;
}

function applyFilters(
    overrides: Partial<{
        kind: string;
        sport: string;
        category: string;
        search: string;
        sort: string;
    }>,
    replace = false,
): void {
    router.get(segmentsIndex.url(), buildQuery(overrides), {
        preserveState: true,
        preserveScroll: true,
        replace,
    });
}

function onSearchInput(): void {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        applyFilters({ search: searchInput.value }, true);
    }, 300);
}

function clearSearch(): void {
    searchInput.value = '';

    if (searchTimer !== null) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }

    applyFilters({ search: '' }, true);
}

function selectKind(kind: string): void {
    applyFilters({
        kind,
        category:
            kind === 'all' || kind === 'climb' ? props.filters.category : 'all',
    });
}

function resetAllFilters(): void {
    searchInput.value = '';

    if (searchTimer !== null) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }

    applyFilters({
        kind: 'all',
        sport: 'all',
        category: 'all',
        search: '',
    });
}

function selectSport(sport: string): void {
    applyFilters({ sport });
}

function selectCategory(category: string): void {
    applyFilters({ category });
}

function onSortChange(event: Event): void {
    const target = event.target as HTMLSelectElement;
    applyFilters({ sort: target.value });
}

function triggerScan(): void {
    if (isScanning.value) {
        return;
    }

    router.post(
        scanSegments.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isScanning.value = true;
            },
            onSuccess: () => {
                const status = page.props.flash?.status;

                if (status) {
                    toast.success(status);
                }
            },
            onError: () => {
                toast.error('Failed to scan activities for climbs & segments.');
            },
            onFinish: () => {
                isScanning.value = false;
            },
        },
    );
}

function toggleStar(segment: SegmentCard): void {
    if (togglingStarId.value !== null) {
        return;
    }

    router.patch(
        updateSegment.url(segment.id),
        { is_starred: !segment.is_starred },
        {
            preserveScroll: true,
            onStart: () => {
                togglingStarId.value = segment.id;
            },
            onSuccess: () => {
                const status = page.props.flash?.status;

                if (status) {
                    toast.success(status);
                }
            },
            onError: () => {
                toast.error('Failed to update starred state.');
            },
            onFinish: () => {
                togglingStarId.value = null;
            },
        },
    );
}

function formatSegmentSpeedOrPace(
    segment: SegmentCard,
    speedMps: number | null,
): string {
    if (segment.metric_mode === 'pace_100m') {
        return formatPace100m(speedMps);
    }

    if (segment.metric_mode === 'pace') {
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

    const currentYear = new Date().getFullYear();

    return parsed.getFullYear() !== currentYear
        ? `${short} ${parsed.getFullYear()}`
        : short;
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Segments & Climbs" />

        <!-- Page Header -->
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    Segments &amp; Climbs
                </h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Auto-detected climbs (Cat 4 → HC) and custom personal
                    segments with all-time PRs, VAM, and W/kg progression.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Scan Unscanned Activities Button -->
                <button
                    v-if="summary.unscanned_count > 0"
                    type="button"
                    :disabled="isScanning"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 font-mono text-xs font-medium text-emerald-700 transition-colors hover:bg-emerald-500/15 disabled:cursor-not-allowed disabled:opacity-60 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300 dark:hover:bg-emerald-500/20"
                    @click="triggerScan"
                >
                    <RefreshCw
                        :class="['size-3.5', isScanning && 'animate-spin']"
                    />
                    <span>
                        {{
                            isScanning
                                ? 'Scanning...'
                                : `Scan ${summary.unscanned_count} ${summary.unscanned_count === 1 ? 'Activity' : 'Activities'}`
                        }}
                    </span>
                </button>

                <!-- Search -->
                <div class="relative w-full sm:w-60">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-zinc-400 dark:text-zinc-500"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        placeholder="Search climb, segment, place..."
                        aria-label="Search segments and climbs"
                        class="w-full rounded-lg border border-zinc-200/90 bg-white py-2 pr-8 pl-8.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus-visible:border-zinc-400 focus-visible:ring-2 focus-visible:ring-zinc-900/15 focus-visible:outline-none dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus-visible:border-zinc-600 dark:focus-visible:ring-zinc-100/15"
                        @input="onSearchInput"
                    />
                    <button
                        v-if="searchInput"
                        type="button"
                        aria-label="Clear search"
                        class="absolute top-1/2 right-2 -translate-y-1/2 cursor-pointer rounded p-0.5 text-zinc-400 transition-colors hover:text-zinc-700 focus-visible:ring-2 focus-visible:ring-zinc-900/20 focus-visible:outline-none dark:hover:text-zinc-200 dark:focus-visible:ring-zinc-100/20"
                        @click="clearSearch"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>

                <!-- Sort Selector -->
                <select
                    :value="filters.sort"
                    aria-label="Sort segments"
                    class="cursor-pointer rounded-lg border border-zinc-200/90 bg-white px-3 py-2 font-mono text-xs text-zinc-700 focus-visible:border-zinc-400 focus-visible:ring-2 focus-visible:ring-zinc-900/15 focus-visible:outline-none dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-200 dark:focus-visible:border-zinc-600 dark:focus-visible:ring-zinc-100/15"
                    @change="onSortChange"
                >
                    <option
                        v-for="option in sortOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
        </header>

        <!-- KPI Strip -->
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                v-for="kpi in summaryKpis"
                :key="kpi.label"
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="flex items-center gap-2">
                    <span :class="['size-1.5 rounded-full', kpi.accentClass]" />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{ kpi.label }}
                    </p>
                </div>
                <p
                    class="mt-2 font-mono text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ kpi.value }}
                </p>
                <p
                    class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400"
                >
                    {{ kpi.sub }}
                </p>
            </div>
        </section>

        <!-- Filter Controls -->
        <section
            class="space-y-3 rounded-xl border border-zinc-200/90 bg-white p-3.5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <!-- Kind Tabs + Sport Pills -->
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="tab in kindTabs"
                        :key="tab.value"
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            filters.kind === tab.value
                                ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-zinc-900/50 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="selectKind(tab.value)"
                    >
                        <Star
                            v-if="tab.value === 'starred'"
                            class="size-3"
                            :class="
                                filters.kind === 'starred'
                                    ? 'fill-current'
                                    : 'text-amber-500'
                            "
                        />
                        <span>{{ tab.label }}</span>
                        <span
                            class="rounded px-1 py-0.25 text-[10px] tabular-nums"
                            :class="
                                filters.kind === tab.value
                                    ? 'bg-white/20 text-white dark:bg-zinc-900/20 dark:text-zinc-950'
                                    : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'
                            "
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>

                <!-- Sport Pills -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="pill in sportPills"
                        :key="pill.value"
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            isSportActive(pill.value)
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
            </div>

            <!-- Climb Category Filter Pills -->
            <div
                v-if="showCategoryFilters"
                class="flex flex-wrap items-center gap-1.5 border-t border-zinc-100 pt-3 dark:border-zinc-800/80"
            >
                <span
                    class="mr-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Climb Grade
                </span>
                <button
                    v-for="cat in categoryPills"
                    :key="cat.value"
                    type="button"
                    class="cursor-pointer rounded-md border px-2 py-1 font-mono text-[11px] font-medium transition-colors"
                    :class="
                        filters.category === cat.value
                            ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                            : 'border-zinc-200/80 bg-zinc-50/70 text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900/40 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                    "
                    @click="selectCategory(cat.value)"
                >
                    {{ cat.label }}
                </button>
            </div>
        </section>

        <!-- Segments Grid -->
        <section
            v-if="segments.length > 0"
            class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="segment in segments"
                :key="segment.id"
                class="group relative flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-4 transition-all hover:border-zinc-300 hover:shadow-sm dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
            >
                <div class="space-y-3.5">
                    <!-- Mini-map Preview + Floating Badges -->
                    <div class="relative">
                        <Link
                            :href="showSegment.url(segment.id)"
                            prefetch
                            class="block"
                        >
                            <RouteMiniMap
                                :encoded-polyline="segment.encoded_polyline"
                                :accent-hex="segment.accent_hex"
                                :is-loop="false"
                            />
                        </Link>

                        <div
                            class="pointer-events-none absolute top-2 left-2 flex flex-wrap items-center gap-1.5"
                        >
                            <span
                                class="inline-flex rounded-md bg-white/90 backdrop-blur-xs dark:bg-zinc-900/90"
                            >
                                <span
                                    :class="[
                                        'rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium',
                                        sportBadgeClasses(
                                            getSportMeta(segment.sport_key)
                                                .color_token,
                                        ),
                                    ]"
                                >
                                    {{
                                        getSportMeta(segment.sport_key)
                                            .short_label
                                    }}
                                </span>
                            </span>
                            <span
                                v-if="climbCategoryMeta(segment.climb_category)"
                                class="inline-flex rounded-md bg-white/90 backdrop-blur-xs dark:bg-zinc-900/90"
                            >
                                <span
                                    :class="[
                                        'rounded-md border px-1.5 py-0.5 font-mono text-[10px] font-semibold',
                                        climbCategoryMeta(
                                            segment.climb_category,
                                        )!.badgeClass,
                                    ]"
                                >
                                    {{
                                        climbCategoryMeta(
                                            segment.climb_category,
                                        )!.shortLabel
                                    }}
                                </span>
                            </span>
                            <span
                                v-if="segment.kind === 'custom'"
                                class="inline-flex rounded-md bg-white/90 backdrop-blur-xs dark:bg-zinc-900/90"
                            >
                                <span
                                    class="inline-flex items-center gap-1 rounded-md border border-sky-500/30 bg-sky-500/12 px-1.5 py-0.5 font-mono text-[10px] font-medium text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/20 dark:text-sky-300"
                                >
                                    <Flag class="size-2.5" />
                                    Custom
                                </span>
                            </span>
                        </div>

                        <div
                            class="absolute top-2 right-2 flex items-center gap-1.5"
                        >
                            <button
                                type="button"
                                :disabled="togglingStarId === segment.id"
                                :aria-label="
                                    segment.is_starred
                                        ? 'Unstar segment'
                                        : 'Star segment'
                                "
                                class="inline-flex size-8 cursor-pointer items-center justify-center rounded-md border border-zinc-200/80 bg-white/90 text-zinc-500 backdrop-blur-xs transition-colors hover:text-amber-500 dark:border-zinc-700/80 dark:bg-zinc-900/90 dark:text-zinc-400 dark:hover:text-amber-400"
                                @click.stop="toggleStar(segment)"
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
                            <span
                                class="pointer-events-none rounded-md bg-zinc-900/90 px-2 py-1 font-mono text-[11px] font-semibold text-white tabular-nums backdrop-blur-xs dark:bg-zinc-100/95 dark:text-zinc-950"
                            >
                                {{ segment.effort_count }}×
                            </span>
                        </div>
                    </div>

                    <!-- Title & Metadata -->
                    <div>
                        <Link
                            :href="showSegment.url(segment.id)"
                            prefetch
                            class="block"
                        >
                            <h2
                                class="truncate text-base font-semibold text-zinc-900 transition-colors group-hover:text-emerald-600 dark:text-zinc-100 dark:group-hover:text-emerald-400"
                            >
                                {{ segment.name }}
                            </h2>
                        </Link>
                        <p
                            v-if="segment.place_label"
                            class="mt-0.5 flex items-center gap-1 truncate text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            <MapPin
                                class="size-3 shrink-0 text-zinc-400 dark:text-zinc-500"
                            />
                            <span class="truncate">{{
                                segment.place_label
                            }}</span>
                        </p>
                        <div
                            class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            <span>{{
                                formatDistance(segment.distance_meters, 2)
                            }}</span>
                            <span
                                v-if="segment.elevation_gain !== null"
                                class="inline-flex items-center gap-1"
                            >
                                <Mountain class="size-3" />
                                {{ formatElevation(segment.elevation_gain) }}
                            </span>
                            <span
                                v-if="segment.avg_grade_pct !== null"
                                class="inline-flex items-center gap-1"
                            >
                                <TrendingUp class="size-3" />
                                {{ formatGrade(segment.avg_grade_pct) }} avg
                            </span>
                            <span
                                v-if="segment.fiets_score !== null"
                                title="FIETS climb difficulty index (elevation gain, grade, and length)"
                                class="cursor-help underline decoration-zinc-300 decoration-dotted underline-offset-2 dark:decoration-zinc-600"
                            >
                                FIETS {{ segment.fiets_score.toFixed(1) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- PR Footer -->
                <Link
                    :href="showSegment.url(segment.id)"
                    prefetch
                    class="mt-4 flex items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800/80"
                >
                    <div class="min-w-0">
                        <p
                            class="flex items-center gap-1 font-mono text-[10px] tracking-wider text-amber-600 uppercase dark:text-amber-400"
                        >
                            <Trophy class="size-3" />
                            Personal Best
                        </p>
                        <p
                            class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{
                                formatDurationClock(
                                    segment.best_elapsed_seconds,
                                )
                            }}
                            <span
                                v-if="segment.best_speed_mps !== null"
                                class="ml-1 text-xs font-normal text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    formatSegmentSpeedOrPace(
                                        segment,
                                        segment.best_speed_mps,
                                    )
                                }}
                            </span>
                        </p>
                    </div>

                    <div class="text-right">
                        <p
                            v-if="segment.best_vam !== null"
                            class="font-mono text-xs font-medium text-zinc-700 tabular-nums dark:text-zinc-300"
                        >
                            {{ formatVam(segment.best_vam) }}
                        </p>
                        <p
                            v-else-if="segment.best_w_per_kg !== null"
                            class="font-mono text-xs font-medium text-zinc-700 tabular-nums dark:text-zinc-300"
                        >
                            {{ segment.best_w_per_kg.toFixed(2) }} W/kg
                        </p>
                        <p
                            class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                segment.last_effort_at
                                    ? `Last ${formatDateWithYear(segment.last_effort_at)}`
                                    : 'No efforts yet'
                            }}
                        </p>
                    </div>
                </Link>
            </article>
        </section>

        <!-- Empty State -->
        <section
            v-else
            class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300/90 bg-white px-6 py-16 text-center dark:border-zinc-800 dark:bg-[#141417]"
        >
            <div
                class="flex size-12 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400"
            >
                <Mountain class="size-6" />
            </div>
            <h2
                class="mt-4 text-base font-semibold text-zinc-900 dark:text-zinc-100"
            >
                No segments or climbs found
            </h2>
            <p class="mt-1.5 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                <template v-if="hasActiveFilters">
                    Try clearing your filters or search query to view all
                    segments and climbs.
                </template>
                <template v-else-if="summary.unscanned_count > 0">
                    You have {{ summary.unscanned_count }} unscanned outdoor
                    {{
                        summary.unscanned_count === 1
                            ? 'activity'
                            : 'activities'
                    }}. Run a scan to automatically detect sustained climbs and
                    match efforts.
                </template>
                <template v-else>
                    Open any outdoor activity to create a custom segment
                    directly on its route map.
                </template>
            </p>
            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                <button
                    v-if="summary.unscanned_count > 0"
                    type="button"
                    :disabled="isScanning"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 font-mono text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                    @click="triggerScan"
                >
                    <RefreshCw
                        :class="['size-3.5', isScanning && 'animate-spin']"
                    />
                    <span>
                        {{
                            isScanning
                                ? 'Scanning Activities...'
                                : `Scan ${summary.unscanned_count} ${summary.unscanned_count === 1 ? 'Activity' : 'Activities'}`
                        }}
                    </span>
                </button>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="cursor-pointer rounded-lg border border-zinc-200 bg-white px-3.5 py-2 font-mono text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    @click="resetAllFilters"
                >
                    Reset Filters
                </button>
            </div>
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
