<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    MapPin,
    Mountain,
    RefreshCw,
    Repeat,
    Route as RouteIcon,
    Search,
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
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    index as routesIndex,
    scan as scanRoutes,
    show as showRoute,
} from '@/routes/routes';
import type { MatchedRouteCard, MatchedRoutesIndexProps } from '@/types/routes';

const props = defineProps<MatchedRoutesIndexProps>();

const page = usePage();
const { getSportMeta, sportBadgeClasses } = useSports();
const {
    formatDistance,
    formatDistanceCompact,
    formatElevation,
    formatPace,
    formatPace100m,
    formatSpeed,
} = useUnits();

const searchInput = ref<string>(props.filters.search ?? '');
const isScanning = ref<boolean>(false);
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

const sortOptions = [
    { value: 'count', label: 'Most Repeated' },
    { value: 'recent', label: 'Most Recent' },
    { value: 'distance', label: 'Longest Distance' },
    { value: 'elevation', label: 'Most Climbing' },
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

const summaryKpis = computed<
    Array<{ label: string; value: string; sub?: string; accentClass: string }>
>(() => [
    {
        label: 'Matched Routes',
        value: formatNumber(props.summary.route_count, 0),
        sub: '2+ attempts',
        accentClass: 'bg-emerald-500',
    },
    {
        label: 'Total Attempts',
        value: formatNumber(props.summary.total_attempts, 0),
        sub: 'clustered activities',
        accentClass: 'bg-sky-500',
    },
    {
        label: 'Most Repeated',
        value:
            props.summary.most_repeated_count > 0
                ? `${props.summary.most_repeated_count}×`
                : '—',
        sub: props.summary.most_repeated_name ?? 'No routes yet',
        accentClass: 'bg-amber-500',
    },
    {
        label: 'Repeated Distance',
        value: formatDistanceCompact(props.summary.total_distance_meters, 1),
        sub: 'across matched routes',
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
    overrides: Partial<{ sport: string; search: string; sort: string }>,
): Record<string, string> {
    const merged = {
        sport: props.filters.sport,
        search: props.filters.search,
        sort: props.filters.sort,
        ...overrides,
    };

    const query: Record<string, string> = {};

    if (merged.sport && merged.sport !== 'all') {
        query.sport = merged.sport;
    }

    if (merged.search.trim() !== '') {
        query.search = merged.search.trim();
    }

    if (merged.sort && merged.sort !== 'count') {
        query.sort = merged.sort;
    }

    return query;
}

function applyFilters(
    overrides: Partial<{ sport: string; search: string; sort: string }>,
    replace = false,
): void {
    router.get(routesIndex.url(), buildQuery(overrides), {
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

function selectSport(sport: string): void {
    applyFilters({ sport });
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
        scanRoutes.url(),
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
                toast.error('Failed to scan activities for matched routes.');
            },
            onFinish: () => {
                isScanning.value = false;
            },
        },
    );
}

function formatRouteSpeedOrPace(
    route: MatchedRouteCard,
    speedMps: number | null,
): string {
    if (route.metric_mode === 'pace_100m') {
        return formatPace100m(speedMps);
    }

    if (route.metric_mode === 'pace') {
        return formatPace(speedMps);
    }

    return formatSpeed(speedMps);
}

function formatDeltaSeconds(deltaSeconds: number | null): string {
    if (deltaSeconds === null || !Number.isFinite(deltaSeconds)) {
        return '—';
    }

    if (Math.abs(deltaSeconds) < 1) {
        return 'On median';
    }

    const sign = deltaSeconds < 0 ? '-' : '+';

    return `${sign}${formatDurationClock(Math.abs(deltaSeconds))}`;
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
        <Head title="Matched Routes" />

        <!-- Page Header -->
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    Matched Routes
                </h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Automatically clustered loops and repeated routes with PR
                    history, progression, and weather context.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Scan Un-fingerprinted Activities Button -->
                <button
                    v-if="summary.unfingerprinted_count > 0"
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
                                : `Scan ${summary.unfingerprinted_count} ${summary.unfingerprinted_count === 1 ? 'Activity' : 'Activities'}`
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
                        placeholder="Search route or location..."
                        aria-label="Search matched routes"
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
                    aria-label="Sort matched routes"
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
                    v-if="kpi.sub"
                    class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400"
                >
                    {{ kpi.sub }}
                </p>
            </div>
        </section>

        <!-- Sport Filter Bar -->
        <section
            class="rounded-xl border border-zinc-200/90 bg-white p-3.5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
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
        </section>

        <!-- Matched Routes Grid -->
        <section
            v-if="routes.length > 0"
            class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="route in routes"
                :key="route.id"
                :href="showRoute.url(route.id)"
                prefetch
                class="group flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-4 transition-all hover:border-zinc-300 hover:shadow-sm dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
            >
                <div class="space-y-3.5">
                    <!-- Mini-map Preview + Floating Badges -->
                    <div class="relative">
                        <RouteMiniMap
                            :encoded-polyline="route.encoded_polyline"
                            :accent-hex="route.accent_hex"
                            :is-loop="route.is_loop"
                        />

                        <div
                            class="pointer-events-none absolute top-2 left-2 flex items-center gap-1.5"
                        >
                            <span
                                class="inline-flex rounded-md bg-white/90 backdrop-blur-xs dark:bg-zinc-900/90"
                            >
                                <span
                                    :class="[
                                        'rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium',
                                        sportBadgeClasses(
                                            getSportMeta(route.sport_key)
                                                .color_token,
                                        ),
                                    ]"
                                >
                                    {{
                                        getSportMeta(route.sport_key)
                                            .short_label
                                    }}
                                </span>
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md border border-zinc-200/80 bg-white/90 px-1.5 py-0.5 font-mono text-[10px] text-zinc-600 backdrop-blur-xs dark:border-zinc-700/80 dark:bg-zinc-900/90 dark:text-zinc-300"
                            >
                                <Repeat v-if="route.is_loop" class="size-2.5" />
                                {{ route.is_loop ? 'Loop' : 'Point-to-Point' }}
                            </span>
                        </div>

                        <div class="pointer-events-none absolute top-2 right-2">
                            <span
                                class="rounded-md bg-zinc-900/90 px-2 py-0.5 font-mono text-[11px] font-semibold text-white tabular-nums backdrop-blur-xs dark:bg-zinc-100/95 dark:text-zinc-950"
                            >
                                {{ route.activity_count }}×
                            </span>
                        </div>
                    </div>

                    <!-- Title & Metadata -->
                    <div>
                        <h2
                            class="truncate text-base font-semibold text-zinc-900 transition-colors group-hover:text-emerald-600 dark:text-zinc-100 dark:group-hover:text-emerald-400"
                        >
                            {{ route.name }}
                        </h2>
                        <p
                            v-if="route.place_label"
                            class="mt-0.5 flex items-center gap-1 truncate text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            <MapPin
                                class="size-3 shrink-0 text-zinc-400 dark:text-zinc-500"
                            />
                            <span class="truncate">{{
                                route.place_label
                            }}</span>
                        </p>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            <span>{{
                                formatDistance(route.distance_meters, 2)
                            }}</span>
                            <span
                                v-if="route.elevation_gain !== null"
                                class="inline-flex items-center gap-1"
                            >
                                <Mountain class="size-3" />
                                {{ formatElevation(route.elevation_gain) }}
                            </span>
                            <span
                                v-if="!route.place_label && route.location_name"
                                class="inline-flex items-center gap-1 truncate"
                            >
                                <MapPin class="size-3 shrink-0" />
                                <span class="truncate">{{
                                    route.location_name
                                }}</span>
                            </span>
                        </div>
                    </div>

                    <!-- PR / Median / Latest Strip -->
                    <div
                        class="grid grid-cols-3 gap-2 rounded-lg border border-zinc-200/70 bg-[#f7f7f5] p-2.5 tabular-nums dark:border-zinc-800/70 dark:bg-zinc-900/50"
                    >
                        <div>
                            <p
                                class="flex items-center gap-1 font-mono text-[10px] text-amber-600 uppercase dark:text-amber-400"
                            >
                                <Trophy class="size-2.5" />
                                PR
                            </p>
                            <p
                                class="mt-0.5 font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatDurationClock(
                                        route.pr_duration_seconds,
                                    )
                                }}
                            </p>
                            <p
                                class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    formatRouteSpeedOrPace(
                                        route,
                                        route.pr_speed_mps,
                                    )
                                }}
                            </p>
                        </div>

                        <div>
                            <p
                                class="font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Median
                            </p>
                            <p
                                class="mt-0.5 font-mono text-xs font-semibold text-zinc-800 dark:text-zinc-200"
                            >
                                {{
                                    formatDurationClock(
                                        route.median_duration_seconds,
                                    )
                                }}
                            </p>
                            <p
                                class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    formatRouteSpeedOrPace(
                                        route,
                                        route.median_speed_mps,
                                    )
                                }}
                            </p>
                        </div>

                        <div>
                            <p
                                class="font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Latest
                            </p>
                            <p
                                class="mt-0.5 font-mono text-xs font-semibold text-zinc-800 dark:text-zinc-200"
                            >
                                {{
                                    formatDurationClock(
                                        route.latest_duration_seconds,
                                    )
                                }}
                            </p>
                            <p
                                class="inline-flex items-center gap-0.5 font-mono text-[10px]"
                                :class="
                                    route.trend_delta_seconds !== null &&
                                    route.trend_delta_seconds < -1
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : route.trend_delta_seconds !== null &&
                                            route.trend_delta_seconds > 1
                                          ? 'text-rose-600 dark:text-rose-400'
                                          : 'text-zinc-500 dark:text-zinc-400'
                                "
                            >
                                <ArrowDownRight
                                    v-if="
                                        route.trend_delta_seconds !== null &&
                                        route.trend_delta_seconds < -1
                                    "
                                    class="size-2.5"
                                />
                                <ArrowUpRight
                                    v-else-if="
                                        route.trend_delta_seconds !== null &&
                                        route.trend_delta_seconds > 1
                                    "
                                    class="size-2.5"
                                />
                                {{
                                    formatDeltaSeconds(
                                        route.trend_delta_seconds,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Card Footer -->
                <div
                    class="mt-3 flex items-center justify-between border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 dark:border-zinc-800/70 dark:text-zinc-400"
                >
                    <span>
                        Last: {{ formatDateWithYear(route.last_activity_at) }}
                    </span>
                    <span
                        class="text-zinc-500 transition-colors group-hover:text-zinc-900 dark:text-zinc-400 dark:group-hover:text-zinc-100"
                    >
                        View history →
                    </span>
                </div>
            </Link>
        </section>

        <!-- Empty State -->
        <section
            v-else
            class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300/90 bg-white px-6 py-14 text-center dark:border-zinc-800 dark:bg-[#141417]"
        >
            <div
                class="flex size-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
            >
                <RouteIcon class="size-5" />
            </div>
            <h2
                class="mt-4 text-base font-semibold text-zinc-900 dark:text-zinc-100"
            >
                No matched routes found
            </h2>
            <p class="mt-1.5 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                <template v-if="filters.search || filters.sport !== 'all'">
                    Try clearing your search or sport filter to view all
                    repeated routes.
                </template>
                <template v-else-if="summary.unfingerprinted_count > 0">
                    You have {{ summary.unfingerprinted_count }} outdoor GPS
                    {{
                        summary.unfingerprinted_count === 1
                            ? 'activity'
                            : 'activities'
                    }}
                    ready to scan for repeated routes.
                </template>
                <template v-else>
                    Routes appear automatically once you complete the same
                    outdoor GPS loop or route two or more times.
                </template>
            </p>
            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                <button
                    v-if="summary.unfingerprinted_count > 0"
                    type="button"
                    :disabled="isScanning"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 font-mono text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                    @click="triggerScan"
                >
                    <RefreshCw
                        :class="['size-3.5', isScanning && 'animate-spin']"
                    />
                    Scan {{ summary.unfingerprinted_count }}
                    {{
                        summary.unfingerprinted_count === 1
                            ? 'Activity'
                            : 'Activities'
                    }}
                </button>
                <button
                    v-if="filters.search || filters.sport !== 'all'"
                    type="button"
                    class="cursor-pointer rounded-lg border border-zinc-200 bg-white px-3.5 py-2 font-mono text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    @click="
                        searchInput = '';
                        applyFilters({ sport: 'all', search: '' });
                    "
                >
                    Reset Filters
                </button>
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
