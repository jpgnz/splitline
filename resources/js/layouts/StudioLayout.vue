<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    BatteryCharging,
    Bike,
    Compass,
    Dumbbell,
    Flame,
    Footprints,
    HeartPulse,
    LayoutGrid,
    ListFilter,
    Map,
    Menu,
    Moon,
    Mountain,
    MountainSnow,
    Navigation,
    RefreshCw,
    Route,
    Settings,
    Sun,
    Target,
    Timer,
    Waves,
    // Wrench,
    X,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { Toaster } from '@/components/ui/sonner';
import { useAppearance } from '@/composables/useAppearance';
import { formatRelativeTime } from '@/lib/format';
import { useSports } from '@/lib/sports';
import { dashboard } from '@/routes';
import { index as activitiesIndex } from '@/routes/activities';
// import { index as gearIndex } from '@/routes/gear';
import { index as goalsIndex } from '@/routes/goals';
import { index as heatmapIndex } from '@/routes/heatmap';
import { index as routesIndex } from '@/routes/routes';
import { index as settingsIndex } from '@/routes/settings';
import { status as syncStatus, store as syncStore } from '@/routes/sync';
import { useEnrichmentQueueFeed } from '@/composables/useEnrichmentQueueFeed';
import type { EnrichmentQueueJobs } from '@/types/global';

type StudioNavChild = {
    key: string;
    label: string;
    href: string;
    icon: Component;
    accentHex?: string;
};

type StudioNavItem = {
    key: string;
    label: string;
    href: string;
    icon: Component;
    accentHex?: string;
    exact?: boolean;
    activePrefixes?: string[];
    children?: StudioNavChild[];
};

type StudioNavGroup = {
    title: string;
    items: StudioNavItem[];
};

const SPORT_ICON_MAP: Record<string, Component> = {
    footprints: Footprints,
    bike: Bike,
    mountain: Mountain,
    'mountain-snow': MountainSnow,
    compass: Compass,
    timer: Timer,
    activity: Activity,
    zap: Zap,
    'battery-charging': BatteryCharging,
    navigation: Navigation,
    waves: Waves,
    dumbbell: Dumbbell,
    flame: Flame,
    'heart-pulse': HeartPulse,
};

function resolveSportIcon(iconName: string | null | undefined): Component {
    if (!iconName) {
        return Activity;
    }

    return SPORT_ICON_MAP[iconName.toLowerCase()] ?? Activity;
}

const page = usePage();
const { resolvedAppearance, updateAppearance } = useAppearance();
const { nav: sportsNav, pinSubDisciplines } = useSports();

const appName = computed<string>(() => page.props.name || 'Splitline');
const appVersion = computed<string>(() => page.props.appVersion);

const dashboardHref = dashboard.url();
const settingsHref = settingsIndex.url();

const navGroups = computed<StudioNavGroup[]>(() => {
    const groups: StudioNavGroup[] = [
        {
            title: 'Core',
            items: [
                {
                    key: 'dashboard',
                    label: 'Health Overview',
                    href: dashboardHref,
                    icon: LayoutGrid,
                    exact: true,
                    activePrefixes: ['/health/'],
                },
                {
                    key: 'activities',
                    label: 'All Activities',
                    href: activitiesIndex.url(),
                    icon: ListFilter,
                },
                {
                    key: 'heatmap',
                    label: 'Route Heatmap',
                    href: heatmapIndex.url(),
                    icon: Map,
                },
                {
                    key: 'routes',
                    label: 'Matched Routes',
                    href: routesIndex.url(),
                    icon: Route,
                },
            ],
        },
    ];

    if (sportsNav.value.length > 0) {
        const sportItems: StudioNavItem[] = sportsNav.value.map((item) => {
            const hasPinnedChildren =
                pinSubDisciplines.value &&
                Array.isArray(item.children) &&
                item.children.length > 0;

            return {
                key: item.key,
                label: item.label,
                href: item.href,
                icon: resolveSportIcon(item.icon),
                accentHex: item.accent_hex,
                exact: hasPinnedChildren,
                children: hasPinnedChildren
                    ? item.children!.map((child) => ({
                          key: child.key,
                          label: child.label,
                          href: child.href,
                          icon: resolveSportIcon(child.icon),
                          accentHex: child.accent_hex,
                      }))
                    : undefined,
            };
        });

        groups.push({
            title: 'Sports',
            items: sportItems,
        });
    }

    groups.push({
        title: 'Planning',
        items: [
            {
                key: 'goals',
                label: 'Goals',
                href: goalsIndex.url(),
                icon: Target,
            },
            // Gear is hidden for now until Garmin Personal API exposes gear-service
            // (or we decide to maintain equipment manually).
            // {
            //     key: 'gear',
            //     label: 'Gear',
            //     href: gearIndex.url(),
            //     icon: Wrench,
            // },
        ],
    });

    return groups;
});

const mobileDrawerOpen = ref(false);
const isSyncPosting = ref(false);

const currentPath = computed<string>(() => page.url.split('?')[0] || '/');

const sync = computed(() => page.props.sync);

const { publishEnrichments } = useEnrichmentQueueFeed();

const liveEnrichments = ref<EnrichmentQueueJobs | null>(
    page.props.sync?.enrichments ?? null,
);

watch(
    () => page.props.sync?.enrichments,
    (next) => {
        liveEnrichments.value = next ?? null;
    },
    { deep: true },
);

const isProviderConfigured = computed<boolean>(
    () => sync.value?.provider_configured !== false,
);

const isSyncRunning = computed<boolean>(
    () => sync.value?.status === 'running' || sync.value?.status === 'queued',
);

const isSyncing = computed<boolean>(
    () => isSyncPosting.value || isSyncRunning.value,
);

const activeEnrichmentJobs = computed<number>(
    () => liveEnrichments.value?.jobs.total ?? 0,
);

const hasActiveEnrichments = computed<boolean>(
    () => activeEnrichmentJobs.value > 0,
);

const enrichmentTooltip = computed<string | undefined>(() => {
    const jobs = liveEnrichments.value?.jobs;

    if (!jobs || jobs.total <= 0) {
        return undefined;
    }

    const parts: string[] = [];

    if (jobs.streams > 0) {
        parts.push(`${jobs.streams} streams`);
    }

    if (jobs.weather > 0) {
        parts.push(`${jobs.weather} weather`);
    }

    if (jobs.default > 0) {
        parts.push(`${jobs.default} default`);
    }

    return parts.length > 0
        ? `Background enrichment queue: ${parts.join(', ')}`
        : `Background enrichment queue: ${jobs.total} queued`;
});

const isSyncFailed = computed<boolean>(
    () => !isSyncing.value && sync.value?.status === 'failed',
);

const syncLabel = computed<string>(() => {
    if (isSyncing.value) {
        return 'Syncing...';
    }

    if (isSyncFailed.value) {
        return 'Sync Error';
    }

    if (!isProviderConfigured.value) {
        return 'Not Configured';
    }

    return 'Connected';
});

const syncDotClass = computed<string>(() => {
    if (isSyncing.value) {
        return 'animate-pulse bg-amber-500';
    }

    if (isSyncFailed.value) {
        return 'bg-rose-500';
    }

    if (!isProviderConfigured.value) {
        return 'bg-zinc-400 dark:bg-zinc-500';
    }

    return 'bg-emerald-500';
});

const syncButtonTooltip = computed<string | undefined>(() => {
    if (!isProviderConfigured.value) {
        return 'Set GARMIN_KEY in your environment to enable syncing.';
    }

    return undefined;
});

const lastSyncedLabel = computed<string>(() =>
    formatRelativeTime(sync.value?.last_synced_at),
);

const isDarkMode = computed<boolean>(() => resolvedAppearance.value === 'dark');

function isNavActive(
    item: Pick<StudioNavItem, 'href' | 'exact' | 'activePrefixes'>,
): boolean {
    if (
        item.activePrefixes?.some((prefix) =>
            currentPath.value.startsWith(prefix),
        )
    ) {
        return true;
    }

    if (item.exact) {
        return currentPath.value === item.href;
    }

    return (
        currentPath.value === item.href ||
        currentPath.value.startsWith(`${item.href}/`)
    );
}

function toggleTheme(): void {
    updateAppearance(isDarkMode.value ? 'light' : 'dark');
}

function triggerSync(): void {
    if (isSyncing.value || !isProviderConfigured.value) {
        return;
    }

    router.post(
        syncStore.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isSyncPosting.value = true;
            },
            onFinish: () => {
                isSyncPosting.value = false;
            },
        },
    );
}

const SYNC_POLL_INTERVAL_MS = 2500;
const ENRICHMENT_POLL_INTERVAL_MS = 10000;

let pollTimer: ReturnType<typeof setInterval> | null = null;
let pollInFlight = false;
let lastPolledSyncRunning: boolean | null = null;

function stopSyncPolling(): void {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function pollSyncStatus(): Promise<void> {
    if (pollInFlight) {
        return;
    }

    pollInFlight = true;

    try {
        const response = await fetch(syncStatus.url(), {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            return;
        }

        const data = (await response.json()) as {
            status?: string;
            enrichments?: EnrichmentQueueJobs;
        };

        // Page props only refresh once a reload completes, so track the last polled
        // sync state locally to avoid firing a second reload on the next tick.
        const wasSyncRunning = lastPolledSyncRunning ?? isSyncRunning.value;
        const wasEnriching = hasActiveEnrichments.value;

        if (data.enrichments) {
            liveEnrichments.value = data.enrichments;
            publishEnrichments(data.enrichments);
        }

        const nextSyncRunning =
            data.status === 'running' || data.status === 'queued';
        const nextEnriching = (data.enrichments?.jobs.total ?? 0) > 0;

        lastPolledSyncRunning = nextSyncRunning;

        if (!nextSyncRunning && !nextEnriching) {
            stopSyncPolling();
            lastPolledSyncRunning = null;

            if (wasSyncRunning || wasEnriching) {
                router.reload();
            }
        } else if (wasSyncRunning && !nextSyncRunning) {
            router.reload();
        }
    } catch {
        // Transient network error while polling; retry on next tick.
    } finally {
        pollInFlight = false;
    }
}

watch(
    (): number | null => {
        if (isSyncRunning.value) {
            return SYNC_POLL_INTERVAL_MS;
        }

        return hasActiveEnrichments.value ? ENRICHMENT_POLL_INTERVAL_MS : null;
    },
    (intervalMs) => {
        stopSyncPolling();

        if (intervalMs === null) {
            lastPolledSyncRunning = null;

            return;
        }

        pollTimer = setInterval(() => {
            void pollSyncStatus();
        }, intervalMs);
    },
    { immediate: true },
);

onUnmounted(() => {
    stopSyncPolling();
});

watch(
    () => page.url,
    () => {
        mobileDrawerOpen.value = false;
    },
);
</script>

<template>
    <div
        class="flex min-h-screen flex-col bg-[#f7f7f5] font-sans text-zinc-900 md:flex-row dark:bg-[#101012] dark:text-zinc-100"
    >
        <!-- Mobile Top Bar -->
        <header
            class="sticky top-0 z-30 flex items-center justify-between border-b border-zinc-200/90 bg-white/95 px-4 py-3 backdrop-blur-xs md:hidden dark:border-zinc-800/80 dark:bg-[#141417]/95"
        >
            <Link
                :href="dashboardHref"
                prefetch
                class="flex items-center gap-2.5"
            >
                <svg
                    viewBox="0 0 64 64"
                    fill="none"
                    class="size-6 shrink-0"
                    aria-hidden="true"
                >
                    <rect
                        width="64"
                        height="64"
                        rx="14"
                        class="fill-zinc-900 dark:fill-zinc-100"
                    />
                    <path
                        d="M12 41L23 41L29 22L36 44L42 29L52 29"
                        stroke="#10B981"
                        stroke-width="4.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <circle
                        cx="52"
                        cy="29"
                        r="3.5"
                        class="fill-zinc-100 dark:fill-zinc-950"
                    />
                </svg>
                <span class="text-sm font-semibold tracking-tight">
                    {{ appName }}
                </span>
            </Link>

            <div class="flex items-center gap-1.5">
                <button
                    type="button"
                    class="inline-flex size-8 cursor-pointer items-center justify-center rounded-md border border-zinc-200 bg-white text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    :aria-label="
                        isDarkMode
                            ? 'Switch to light mode'
                            : 'Switch to dark mode'
                    "
                    @click="toggleTheme"
                >
                    <Sun v-if="isDarkMode" class="size-4" />
                    <Moon v-else class="size-4" />
                </button>
                <button
                    type="button"
                    class="inline-flex size-8 cursor-pointer items-center justify-center rounded-md border border-zinc-200 bg-white text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    aria-label="Open navigation menu"
                    @click="mobileDrawerOpen = true"
                >
                    <Menu class="size-4" />
                </button>
            </div>
        </header>

        <!-- Mobile Slide-Over Drawer -->
        <div
            v-if="mobileDrawerOpen"
            class="fixed inset-0 z-50 flex md:hidden"
            role="dialog"
            aria-modal="true"
            aria-label="Navigation"
        >
            <div
                class="fixed inset-0 bg-zinc-950/50 backdrop-blur-xs"
                @click="mobileDrawerOpen = false"
            />
            <aside
                class="relative z-10 flex h-full w-64 max-w-[80vw] flex-col justify-between overflow-y-auto border-r border-zinc-200/90 bg-white p-4 dark:border-zinc-800/80 dark:bg-[#141417]"
            >
                <div>
                    <div class="flex items-center justify-between px-2.5 py-2">
                        <Link
                            :href="dashboardHref"
                            prefetch
                            class="flex items-center gap-2.5"
                        >
                            <svg
                                viewBox="0 0 64 64"
                                fill="none"
                                class="size-6 shrink-0"
                                aria-hidden="true"
                            >
                                <rect
                                    width="64"
                                    height="64"
                                    rx="14"
                                    class="fill-zinc-900 dark:fill-zinc-100"
                                />
                                <path
                                    d="M12 41L23 41L29 22L36 44L42 29L52 29"
                                    stroke="#10B981"
                                    stroke-width="4.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                                <circle
                                    cx="52"
                                    cy="29"
                                    r="3.5"
                                    class="fill-zinc-100 dark:fill-zinc-950"
                                />
                            </svg>
                            <span class="text-sm font-semibold tracking-tight">
                                {{ appName }}
                            </span>
                        </Link>
                        <button
                            type="button"
                            class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                            aria-label="Close navigation menu"
                            @click="mobileDrawerOpen = false"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <nav class="mt-5 space-y-5">
                        <div v-for="group in navGroups" :key="group.title">
                            <p
                                class="px-2.5 font-mono text-[10px] tracking-widest text-zinc-400 uppercase dark:text-zinc-500"
                            >
                                {{ group.title }}
                            </p>
                            <div class="mt-1.5 space-y-0.5">
                                <template
                                    v-for="item in group.items"
                                    :key="item.key"
                                >
                                    <Link
                                        :href="item.href"
                                        prefetch
                                        :class="[
                                            'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors',
                                            isNavActive(item)
                                                ? 'bg-zinc-100 text-zinc-950 dark:bg-zinc-800 dark:text-white'
                                                : 'text-zinc-600 hover:bg-zinc-100/70 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white',
                                        ]"
                                    >
                                        <component
                                            :is="item.icon"
                                            class="size-4 shrink-0"
                                            :style="
                                                item.accentHex
                                                    ? { color: item.accentHex }
                                                    : undefined
                                            "
                                        />
                                        <span class="truncate">{{
                                            item.label
                                        }}</span>
                                    </Link>

                                    <div
                                        v-if="
                                            item.children &&
                                            item.children.length > 0
                                        "
                                        class="mt-0.5 ml-3 space-y-0.5 border-l border-zinc-200/80 pl-2.5 dark:border-zinc-800"
                                    >
                                        <Link
                                            v-for="child in item.children"
                                            :key="child.key"
                                            :href="child.href"
                                            prefetch
                                            :class="[
                                                'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium transition-colors',
                                                isNavActive({
                                                    href: child.href,
                                                })
                                                    ? 'bg-zinc-100 text-zinc-950 dark:bg-zinc-800 dark:text-white'
                                                    : 'text-zinc-500 hover:bg-zinc-100/70 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white',
                                            ]"
                                        >
                                            <component
                                                :is="child.icon"
                                                class="size-3.5 shrink-0"
                                                :style="
                                                    child.accentHex
                                                        ? {
                                                              color: child.accentHex,
                                                          }
                                                        : undefined
                                                "
                                            />
                                            <span class="truncate">{{
                                                child.label
                                            }}</span>
                                        </Link>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </nav>
                </div>

                <!-- Mobile Rail Footer -->
                <div class="mt-6 space-y-2.5">
                    <div
                        class="rounded-lg border border-zinc-200 bg-[#f7f7f5] p-3 dark:border-zinc-800 dark:bg-zinc-900/60"
                        :title="
                            isSyncFailed && sync?.error_message
                                ? sync.error_message
                                : syncButtonTooltip
                        "
                    >
                        <div
                            class="flex items-center justify-between font-mono text-[11px]"
                        >
                            <span
                                class="flex items-center gap-1.5 text-zinc-700 dark:text-zinc-300"
                            >
                                <span
                                    :class="[
                                        'size-1.5 rounded-full',
                                        syncDotClass,
                                    ]"
                                />
                                {{ syncLabel }}
                            </span>
                            <span class="text-zinc-400 dark:text-zinc-500">
                                {{ lastSyncedLabel }}
                            </span>
                        </div>
                        <div
                            v-if="hasActiveEnrichments && !isSyncing"
                            class="mt-1.5 flex items-center gap-1.5 font-mono text-[10px] text-sky-600 dark:text-sky-400"
                            :title="enrichmentTooltip"
                        >
                            <span
                                class="size-1.5 animate-pulse rounded-full bg-sky-500"
                            />
                            <span class="truncate tabular-nums">
                                Enriching · {{ activeEnrichmentJobs }}
                                {{
                                    activeEnrichmentJobs === 1 ? 'job' : 'jobs'
                                }}
                            </span>
                        </div>
                        <button
                            type="button"
                            :disabled="isSyncing || !isProviderConfigured"
                            :title="syncButtonTooltip"
                            class="mt-2.5 flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-md border border-zinc-200 bg-white py-1.5 text-xs font-medium text-zinc-900 transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                            @click="triggerSync"
                        >
                            <RefreshCw
                                :class="['size-3', isSyncing && 'animate-spin']"
                            />
                            {{ isSyncing ? 'Syncing...' : 'Sync Now' }}
                        </button>
                    </div>

                    <div class="flex items-center justify-between gap-1.5">
                        <Link
                            :href="settingsHref"
                            prefetch
                            :class="[
                                'flex flex-1 items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors',
                                isNavActive({ href: settingsHref })
                                    ? 'bg-zinc-100 text-zinc-950 dark:bg-zinc-800 dark:text-white'
                                    : 'text-zinc-500 hover:bg-zinc-100/70 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100',
                            ]"
                        >
                            <Settings class="size-3.5" />
                            <span>Settings</span>
                        </Link>
                        <button
                            type="button"
                            class="inline-flex size-7 cursor-pointer items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                            :aria-label="
                                isDarkMode
                                    ? 'Switch to light mode'
                                    : 'Switch to dark mode'
                            "
                            :title="
                                isDarkMode
                                    ? 'Switch to light mode'
                                    : 'Switch to dark mode'
                            "
                            @click="toggleTheme"
                        >
                            <Sun v-if="isDarkMode" class="size-3.5" />
                            <Moon v-else class="size-3.5" />
                        </button>
                    </div>

                    <div
                        class="flex items-center justify-between gap-2 px-2.5 pt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                    >
                        <span>Version</span>
                        <span class="truncate" :title="appVersion">
                            {{ appVersion }}
                        </span>
                    </div>
                </div>
            </aside>
        </div>

        <!-- Desktop Slim Fixed Left Rail -->
        <aside
            class="sticky top-0 hidden h-screen w-56 shrink-0 border-r border-zinc-200/90 bg-white p-4 md:flex md:flex-col md:justify-between dark:border-zinc-800/80 dark:bg-[#141417]"
        >
            <div class="overflow-y-auto">
                <Link
                    :href="dashboardHref"
                    prefetch
                    class="flex items-center gap-2.5 px-2.5 py-2"
                >
                    <svg
                        viewBox="0 0 64 64"
                        fill="none"
                        class="size-6 shrink-0"
                        aria-hidden="true"
                    >
                        <rect
                            width="64"
                            height="64"
                            rx="14"
                            class="fill-zinc-900 dark:fill-zinc-100"
                        />
                        <path
                            d="M12 41L23 41L29 22L36 44L42 29L52 29"
                            stroke="#10B981"
                            stroke-width="4.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <circle
                            cx="52"
                            cy="29"
                            r="3.5"
                            class="fill-zinc-100 dark:fill-zinc-950"
                        />
                    </svg>
                    <span class="text-sm font-semibold tracking-tight">
                        {{ appName }}
                    </span>
                </Link>

                <nav class="mt-5 space-y-5">
                    <div v-for="group in navGroups" :key="group.title">
                        <p
                            class="px-2.5 font-mono text-[10px] tracking-widest text-zinc-400 uppercase dark:text-zinc-500"
                        >
                            {{ group.title }}
                        </p>
                        <div class="mt-1.5 space-y-0.5">
                            <template
                                v-for="item in group.items"
                                :key="item.key"
                            >
                                <Link
                                    :href="item.href"
                                    prefetch
                                    :class="[
                                        'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors',
                                        isNavActive(item)
                                            ? 'bg-zinc-100 text-zinc-950 dark:bg-zinc-800 dark:text-white'
                                            : 'text-zinc-600 hover:bg-zinc-100/70 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white',
                                    ]"
                                >
                                    <component
                                        :is="item.icon"
                                        class="size-4 shrink-0"
                                        :style="
                                            item.accentHex
                                                ? { color: item.accentHex }
                                                : undefined
                                        "
                                    />
                                    <span class="truncate">{{
                                        item.label
                                    }}</span>
                                </Link>

                                <div
                                    v-if="
                                        item.children &&
                                        item.children.length > 0
                                    "
                                    class="mt-0.5 ml-3 space-y-0.5 border-l border-zinc-200/80 pl-2.5 dark:border-zinc-800"
                                >
                                    <Link
                                        v-for="child in item.children"
                                        :key="child.key"
                                        :href="child.href"
                                        prefetch
                                        :class="[
                                            'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium transition-colors',
                                            isNavActive({ href: child.href })
                                                ? 'bg-zinc-100 text-zinc-950 dark:bg-zinc-800 dark:text-white'
                                                : 'text-zinc-500 hover:bg-zinc-100/70 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white',
                                        ]"
                                    >
                                        <component
                                            :is="child.icon"
                                            class="size-3.5 shrink-0"
                                            :style="
                                                child.accentHex
                                                    ? {
                                                          color: child.accentHex,
                                                      }
                                                    : undefined
                                            "
                                        />
                                        <span class="truncate">{{
                                            child.label
                                        }}</span>
                                    </Link>
                                </div>
                            </template>
                        </div>
                    </div>
                </nav>
            </div>

            <!-- Rail Footer -->
            <div class="mt-4 space-y-2.5">
                <div
                    class="rounded-lg border border-zinc-200 bg-[#f7f7f5] p-3 dark:border-zinc-800 dark:bg-zinc-900/60"
                    :title="
                        isSyncFailed && sync?.error_message
                            ? sync.error_message
                            : syncButtonTooltip
                    "
                >
                    <div
                        class="flex items-center justify-between font-mono text-[11px]"
                    >
                        <span
                            class="flex items-center gap-1.5 text-zinc-700 dark:text-zinc-300"
                        >
                            <span
                                :class="['size-1.5 rounded-full', syncDotClass]"
                            />
                            {{ syncLabel }}
                        </span>
                        <span class="text-zinc-400 dark:text-zinc-500">
                            {{ lastSyncedLabel }}
                        </span>
                    </div>
                    <div
                        v-if="hasActiveEnrichments && !isSyncing"
                        class="mt-1.5 flex items-center gap-1.5 font-mono text-[10px] text-sky-600 dark:text-sky-400"
                        :title="enrichmentTooltip"
                    >
                        <span
                            class="size-1.5 animate-pulse rounded-full bg-sky-500"
                        />
                        <span class="truncate tabular-nums">
                            Enriching · {{ activeEnrichmentJobs }}
                            {{ activeEnrichmentJobs === 1 ? 'job' : 'jobs' }}
                        </span>
                    </div>
                    <button
                        type="button"
                        :disabled="isSyncing || !isProviderConfigured"
                        :title="syncButtonTooltip"
                        class="mt-2.5 flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-md border border-zinc-200 bg-white py-1.5 text-xs font-medium text-zinc-900 transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                        @click="triggerSync"
                    >
                        <RefreshCw
                            :class="['size-3', isSyncing && 'animate-spin']"
                        />
                        {{ isSyncing ? 'Syncing...' : 'Sync Now' }}
                    </button>
                </div>

                <div class="flex items-center justify-between gap-1.5">
                    <Link
                        :href="settingsHref"
                        prefetch
                        :class="[
                            'flex flex-1 items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors',
                            isNavActive({ href: settingsHref })
                                ? 'bg-zinc-100 text-zinc-950 dark:bg-zinc-800 dark:text-white'
                                : 'text-zinc-500 hover:bg-zinc-100/70 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100',
                        ]"
                    >
                        <Settings class="size-3.5" />
                        <span>Settings</span>
                    </Link>
                    <button
                        type="button"
                        class="inline-flex size-7 cursor-pointer items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                        :aria-label="
                            isDarkMode
                                ? 'Switch to light mode'
                                : 'Switch to dark mode'
                        "
                        :title="
                            isDarkMode
                                ? 'Switch to light mode'
                                : 'Switch to dark mode'
                        "
                        @click="toggleTheme"
                    >
                        <Sun v-if="isDarkMode" class="size-3.5" />
                        <Moon v-else class="size-3.5" />
                    </button>
                </div>

                <div
                    class="flex items-center justify-between gap-2 px-2.5 pt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                >
                    <span>Version</span>
                    <span class="truncate" :title="appVersion">
                        {{ appVersion }}
                    </span>
                </div>
            </div>
        </aside>

        <!-- Main Workspace -->
        <main class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-10">
            <div class="mx-auto max-w-[1440px]">
                <slot />
            </div>
        </main>

        <Toaster />
    </div>
</template>
