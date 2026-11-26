<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    Check,
    ChevronDown,
    Clock,
    Database,
    ExternalLink,
    HeartPulse,
    History,
    Layers,
    MapPin,
    Mountain,
    RefreshCw,
    Sparkles,
    Tag,
} from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    updateHealthSources,
    updateSegments,
    updateSports,
    updateUnits,
} from '@/actions/App/Http/Controllers/SettingsController';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { useEnrichmentQueueFeed } from '@/composables/useEnrichmentQueueFeed';
import { formatNumber, formatRelativeTime } from '@/lib/format';
import { backfill as syncBackfill, status as syncStatus } from '@/routes/sync';
import type {
    EnrichmentQueueJobs,
    EnrichmentQueueSummary,
    UnitSystem,
} from '@/types/global';

type FamilyMode = 'off' | 'unified' | 'split';

interface SportsConfig {
    mode: 'auto' | 'custom';
    pin_sub_disciplines: boolean;
    order: string[];
    families: {
        running: {
            mode: FamilyMode;
            disciplines: string[];
        };
        cycling: {
            mode: FamilyMode;
            disciplines: string[];
        };
    };
    secondary: string[];
    rollups: {
        trail_running: 'own' | 'running';
        track_running: 'road_running' | 'own';
        gravel_cycling: 'own' | 'road_cycling' | 'mtb_cycling';
        indoor_cycling: 'road_cycling' | 'own';
        ebike_cycling: 'own' | 'mtb_cycling' | 'exclude';
    };
    best_efforts: {
        trail_in_running_prs: boolean;
        cycling_disciplines: string[];
    };
}

interface DiscoveryHint {
    key: string;
    label: string;
    count: number;
}

interface BackfillSummary {
    last_completed_at: string | null;
    can_resume: boolean;
    resume_cursor_date: string | null;
    resume_until_date: string | null;
    last_error: string | null;
}

interface HealthSourceProvider {
    id: string;
    label: string;
    count: number;
}

interface HealthSourceDomain {
    key: string;
    label: string;
    description: string;
    configured: string;
    resolved: string | null;
    resolved_label: string | null;
    has_multiple_providers: boolean;
    providers: HealthSourceProvider[];
}

interface HealthSourcesPayload {
    domains: HealthSourceDomain[];
    has_multiple_providers: boolean;
}

interface GeocodingConfigSummary {
    enabled: boolean;
    auto_sync: boolean;
    host: string;
    is_public_endpoint: boolean;
    rate_limit_ms: number;
}

interface SegmentsSettingsPayload {
    min_efforts: number;
    default_min_efforts: number;
    visible_climbs_count: number;
    tracked_climbs_count: number;
}

interface SettingsProps {
    unit_system: UnitSystem;
    timezone: string;
    provider: {
        name: string;
        configured: boolean;
    };
    backfill: BackfillSummary;
    enrichment_queue: EnrichmentQueueSummary;
    geocoding: GeocodingConfigSummary;
    sports_settings: {
        config: SportsConfig;
        counts: Record<string, number>;
        hints: DiscoveryHint[];
    };
    health_sources: HealthSourcesPayload;
    segments_settings: SegmentsSettingsPayload;
}

const props = defineProps<SettingsProps>();
const page = usePage();

const appName = computed<string>(() => page.props.name || 'Splitline');
const appVersion = computed<string>(() => page.props.appVersion);
const releaseNotesUrl = computed<string | null>(() => {
    const tag = page.props.releaseTag;

    if (!tag) {
        return null;
    }

    return `https://github.com/splitline-php/splitline/releases/tag/${encodeURIComponent(tag)}`;
});

type BackfillRange = '90d' | '1y' | '3y' | 'all';

const BACKFILL_RANGE_OPTIONS: Array<{
    value: BackfillRange;
    label: string;
}> = [
    { value: '90d', label: 'Last 90 days' },
    { value: '1y', label: 'Last 1 year' },
    { value: '3y', label: 'Last 3 years' },
    { value: 'all', label: 'All history' },
];

const selectedBackfillRange = ref<BackfillRange>('1y');
const isBackfillPosting = ref(false);

interface LiveSyncSnapshot {
    id: number | null;
    status: string;
    type: string | null;
    cursor_date: string | null;
    until_date: string | null;
    items_synced: number;
    error_message: string | null;
}

const liveSync = ref<LiveSyncSnapshot>({
    id: page.props.sync?.id ?? null,
    status: page.props.sync?.status ?? 'idle',
    type: page.props.sync?.type ?? null,
    cursor_date: page.props.sync?.cursor_date ?? null,
    until_date: page.props.sync?.until_date ?? null,
    items_synced: page.props.sync?.items_synced ?? 0,
    error_message: page.props.sync?.error_message ?? null,
});

watch(
    () => page.props.sync,
    (nextSync) => {
        if (!nextSync) {
            return;
        }

        liveSync.value = {
            id: nextSync.id ?? null,
            status: nextSync.status,
            type: nextSync.type,
            cursor_date: nextSync.cursor_date ?? null,
            until_date: nextSync.until_date ?? null,
            items_synced: nextSync.items_synced,
            error_message: nextSync.error_message,
        };
    },
    { deep: true },
);

const liveEnrichmentQueue = ref<EnrichmentQueueSummary>(props.enrichment_queue);

watch(
    () => props.enrichment_queue,
    (nextQueue) => {
        liveEnrichmentQueue.value = nextQueue;
    },
    { deep: true },
);

function applyPolledEnrichmentJobs(next: EnrichmentQueueJobs): void {
    liveEnrichmentQueue.value = {
        ...liveEnrichmentQueue.value,
        jobs: next.jobs,
        has_active_work: next.has_active_work,
    };
}

// The layout already polls queued-job counts while enrichment runs; reuse its snapshot
// instead of running a second poller from this page.
const { latestPolledEnrichments } = useEnrichmentQueueFeed();

watch(latestPolledEnrichments, (next) => {
    if (next) {
        applyPolledEnrichmentJobs(next);
    }
});

const isAnySyncRunning = computed<boolean>(
    () =>
        isBackfillPosting.value ||
        liveSync.value.status === 'running' ||
        liveSync.value.status === 'queued',
);

const isEnrichmentQueueActive = computed<boolean>(
    () => liveEnrichmentQueue.value.jobs.total > 0,
);

const isBackfillActive = computed<boolean>(
    () =>
        (liveSync.value.status === 'running' ||
            liveSync.value.status === 'queued') &&
        liveSync.value.type === 'backfill',
);

const lastBackfillLabel = computed<string>(() =>
    props.backfill.last_completed_at
        ? formatRelativeTime(props.backfill.last_completed_at)
        : 'Never',
);

let backfillPollTimer: ReturnType<typeof setInterval> | null = null;
let backfillPollInFlight = false;

function stopBackfillPolling(): void {
    if (backfillPollTimer !== null) {
        clearInterval(backfillPollTimer);
        backfillPollTimer = null;
    }
}

async function pollBackfillStatus(): Promise<void> {
    if (backfillPollInFlight) {
        return;
    }

    backfillPollInFlight = true;

    try {
        const trackedRunId =
            liveSync.value.type === 'backfill' && liveSync.value.id !== null
                ? liveSync.value.id
                : null;
        const pollUrl =
            trackedRunId !== null
                ? syncStatus.url({ query: { run_id: trackedRunId } })
                : syncStatus.url();

        const response = await fetch(pollUrl, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            return;
        }

        const data = (await response.json()) as Partial<LiveSyncSnapshot> & {
            enrichments?: EnrichmentQueueJobs;
        };
        const nextStatus = data.status ?? 'idle';

        liveSync.value = {
            id: data.id ?? null,
            status: nextStatus,
            type: data.type ?? null,
            cursor_date: data.cursor_date ?? null,
            until_date: data.until_date ?? null,
            items_synced: data.items_synced ?? 0,
            error_message: data.error_message ?? null,
        };

        if (data.enrichments) {
            applyPolledEnrichmentJobs(data.enrichments);
        }

        if (nextStatus !== 'running' && nextStatus !== 'queued') {
            stopBackfillPolling();

            if (data.type === 'backfill') {
                if (nextStatus === 'completed') {
                    toast.success('Historical backfill completed.');
                } else if (nextStatus === 'failed') {
                    toast.error(
                        data.error_message || 'Historical backfill failed.',
                    );
                } else if (nextStatus === 'interrupted') {
                    toast.warning(
                        'Historical backfill stopped before completing. You can resume it below.',
                    );
                }
            }

            router.reload({ only: ['backfill', 'sync', 'enrichment_queue'] });
        }
    } catch {
        // Transient network error while polling; retry on next tick.
    } finally {
        backfillPollInFlight = false;
    }
}

watch(
    () =>
        liveSync.value.status === 'running' ||
        liveSync.value.status === 'queued',
    (running) => {
        if (running) {
            if (backfillPollTimer === null) {
                backfillPollTimer = setInterval(() => {
                    void pollBackfillStatus();
                }, 2500);
            }
        } else {
            stopBackfillPolling();
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    stopBackfillPolling();
});

function triggerBackfill(range: BackfillRange | 'resume'): void {
    if (isAnySyncRunning.value || !props.provider.configured) {
        return;
    }

    router.post(
        syncBackfill.url(),
        { range },
        {
            preserveScroll: true,
            onStart: () => {
                isBackfillPosting.value = true;
            },
            onSuccess: () => {
                toast.success(
                    range === 'resume'
                        ? 'Resuming historical backfill in the background.'
                        : 'Historical backfill started in the background.',
                );
            },
            onError: () => {
                toast.error('Unable to start historical backfill.');
            },
            onFinish: () => {
                isBackfillPosting.value = false;
            },
        },
    );
}

const UNIT_OPTIONS: Array<{
    value: UnitSystem;
    label: string;
    summary: string;
    detail: string;
}> = [
    {
        value: 'metric',
        label: 'Metric',
        summary: 'km, m, kg, min/km, km/h, °C, ml',
        detail: 'Kilometers, meters of climb, kilograms, Celsius, and milliliters.',
    },
    {
        value: 'imperial',
        label: 'Imperial',
        summary: 'mi, ft, lb, min/mi, mph, °F, fl oz',
        detail: 'Miles, feet of climb, pounds, Fahrenheit, and fluid ounces.',
    },
];

const FAMILY_MODE_OPTIONS: Array<{ value: FamilyMode; label: string }> = [
    { value: 'off', label: 'Off' },
    { value: 'unified', label: 'Unified' },
    { value: 'split', label: 'Split by Discipline' },
];

const RUNNING_DISCIPLINES: Array<{
    key: string;
    label: string;
    dotClass: string;
}> = [
    { key: 'road_running', label: 'Road Running', dotClass: 'bg-emerald-500' },
    { key: 'trail_running', label: 'Trail Running', dotClass: 'bg-teal-500' },
    { key: 'track_running', label: 'Track Running', dotClass: 'bg-lime-500' },
    {
        key: 'treadmill_running',
        label: 'Treadmill',
        dotClass: 'bg-cyan-500',
    },
];

const CYCLING_DISCIPLINES: Array<{
    key: string;
    label: string;
    dotClass: string;
}> = [
    { key: 'road_cycling', label: 'Road Cycling', dotClass: 'bg-sky-500' },
    {
        key: 'gravel_cycling',
        label: 'Gravel / CX',
        dotClass: 'bg-orange-500',
    },
    {
        key: 'mtb_cycling',
        label: 'Mountain Biking',
        dotClass: 'bg-amber-500',
    },
    {
        key: 'indoor_cycling',
        label: 'Indoor Cycling',
        dotClass: 'bg-indigo-500',
    },
    { key: 'ebike_cycling', label: 'E-Bike', dotClass: 'bg-violet-500' },
    {
        key: 'commute_cycling',
        label: 'Commute',
        dotClass: 'bg-slate-500',
    },
];

const SECONDARY_SPORTS: Array<{
    key: string;
    label: string;
    dotClass: string;
}> = [
    { key: 'hiking', label: 'Hiking', dotClass: 'bg-emerald-600' },
    { key: 'walking', label: 'Walking', dotClass: 'bg-teal-600' },
    { key: 'swimming', label: 'Swimming', dotClass: 'bg-blue-600' },
    { key: 'cardio', label: 'Strength & Cardio', dotClass: 'bg-rose-500' },
];

const TOP_LEVEL_SPORT_LABELS: Record<
    string,
    { label: string; dotClass: string }
> = {
    running: { label: 'Running', dotClass: 'bg-emerald-500' },
    cycling: { label: 'Cycling', dotClass: 'bg-sky-500' },
    hiking: { label: 'Hiking', dotClass: 'bg-emerald-600' },
    walking: { label: 'Walking', dotClass: 'bg-teal-600' },
    swimming: { label: 'Swimming', dotClass: 'bg-blue-600' },
    cardio: { label: 'Strength & Cardio', dotClass: 'bg-rose-500' },
};

const BEST_EFFORT_CYCLING_OPTIONS: Array<{ value: string; label: string }> = [
    { value: 'road', label: 'Road' },
    { value: 'gravel', label: 'Gravel' },
    { value: 'mtb', label: 'MTB' },
    { value: 'ebike', label: 'E-Bike' },
];

const isUpdatingUnits = ref(false);
const pendingUnit = ref<UnitSystem | null>(null);
const rulesExpanded = ref(true);

function cloneSportsConfig(config: SportsConfig): SportsConfig {
    return {
        mode: config.mode,
        pin_sub_disciplines: Boolean(config.pin_sub_disciplines),
        order: [...config.order],
        families: {
            running: {
                mode: config.families.running.mode,
                disciplines: [...config.families.running.disciplines],
            },
            cycling: {
                mode: config.families.cycling.mode,
                disciplines: [...config.families.cycling.disciplines],
            },
        },
        secondary: [...config.secondary],
        rollups: { ...config.rollups },
        best_efforts: {
            trail_in_running_prs: Boolean(
                config.best_efforts.trail_in_running_prs,
            ),
            cycling_disciplines: [...config.best_efforts.cycling_disciplines],
        },
    };
}

const sportsForm = useForm<SportsConfig>(
    cloneSportsConfig(props.sports_settings.config),
);

watch(
    () => props.sports_settings.config,
    (nextConfig) => {
        const cloned = cloneSportsConfig(nextConfig);
        sportsForm.mode = cloned.mode;
        sportsForm.pin_sub_disciplines = cloned.pin_sub_disciplines;
        sportsForm.order = cloned.order;
        sportsForm.families = cloned.families;
        sportsForm.secondary = cloned.secondary;
        sportsForm.rollups = cloned.rollups;
        sportsForm.best_efforts = cloned.best_efforts;
        sportsForm.defaults(cloned);
    },
    { deep: true },
);

const isCustomMode = computed<boolean>(() => sportsForm.mode === 'custom');

const runningTotalCount = computed<number>(
    () =>
        (props.sports_settings.counts.road_running ?? 0) +
        (props.sports_settings.counts.trail_running ?? 0) +
        (props.sports_settings.counts.track_running ?? 0) +
        (props.sports_settings.counts.treadmill_running ?? 0),
);

const cyclingTotalCount = computed<number>(
    () =>
        (props.sports_settings.counts.road_cycling ?? 0) +
        (props.sports_settings.counts.gravel_cycling ?? 0) +
        (props.sports_settings.counts.mtb_cycling ?? 0) +
        (props.sports_settings.counts.indoor_cycling ?? 0) +
        (props.sports_settings.counts.ebike_cycling ?? 0) +
        (props.sports_settings.counts.commute_cycling ?? 0) +
        (props.sports_settings.counts.other_cycling ?? 0),
);

function selectUnitSystem(nextUnit: UnitSystem): void {
    if (isUpdatingUnits.value || nextUnit === props.unit_system) {
        return;
    }

    router.patch(
        updateUnits.url(),
        { unit_system: nextUnit },
        {
            preserveScroll: true,
            onStart: () => {
                isUpdatingUnits.value = true;
                pendingUnit.value = nextUnit;
            },
            onSuccess: () => {
                toast.success(
                    `Switched to ${nextUnit === 'imperial' ? 'Imperial' : 'Metric'} units.`,
                );
            },
            onError: () => {
                toast.error('Unable to update unit preferences.');
            },
            onFinish: () => {
                isUpdatingUnits.value = false;
                pendingUnit.value = null;
            },
        },
    );
}

function setFamilyMode(family: 'running' | 'cycling', mode: FamilyMode): void {
    if (!isCustomMode.value) {
        return;
    }

    sportsForm.families[family].mode = mode;

    if (
        mode === 'split' &&
        sportsForm.families[family].disciplines.length === 0
    ) {
        sportsForm.families[family].disciplines = [
            family === 'running' ? 'road_running' : 'road_cycling',
        ];
    }
}

function toggleFamilyDiscipline(
    family: 'running' | 'cycling',
    disciplineKey: string,
): void {
    if (!isCustomMode.value) {
        return;
    }

    const list = sportsForm.families[family].disciplines;
    const exists = list.includes(disciplineKey);

    if (exists) {
        if (sportsForm.families[family].mode === 'split' && list.length <= 1) {
            return;
        }

        sportsForm.families[family].disciplines = list.filter(
            (key) => key !== disciplineKey,
        );
    } else {
        sportsForm.families[family].disciplines = [...list, disciplineKey];
    }
}

function toggleSecondarySport(sportKey: string): void {
    if (!isCustomMode.value) {
        return;
    }

    if (sportsForm.secondary.includes(sportKey)) {
        sportsForm.secondary = sportsForm.secondary.filter(
            (key) => key !== sportKey,
        );
    } else {
        sportsForm.secondary = [...sportsForm.secondary, sportKey];
    }
}

function toggleCyclingBestEffortDiscipline(value: string): void {
    if (!isCustomMode.value) {
        return;
    }

    const current = sportsForm.best_efforts.cycling_disciplines;

    if (current.includes(value)) {
        sportsForm.best_efforts.cycling_disciplines = current.filter(
            (item) => item !== value,
        );
    } else {
        sportsForm.best_efforts.cycling_disciplines = [...current, value];
    }
}

function moveOrderItem(index: number, direction: -1 | 1): void {
    if (!isCustomMode.value) {
        return;
    }

    const targetIndex = index + direction;

    if (targetIndex < 0 || targetIndex >= sportsForm.order.length) {
        return;
    }

    const next = [...sportsForm.order];
    const [moved] = next.splice(index, 1);
    next.splice(targetIndex, 0, moved);
    sportsForm.order = next;
}

function isOrderSportEnabled(sportKey: string): boolean {
    if (sportKey === 'running' || sportKey === 'cycling') {
        return sportsForm.families[sportKey].mode !== 'off';
    }

    return sportsForm.secondary.includes(sportKey);
}

function enableDiscoveryHint(hint: DiscoveryHint): void {
    sportsForm.mode = 'custom';

    const runKeys = RUNNING_DISCIPLINES.map((item) => item.key);
    const cycleKeys = CYCLING_DISCIPLINES.map((item) => item.key);

    if (runKeys.includes(hint.key)) {
        sportsForm.families.running.mode = 'split';
        const next = new Set(sportsForm.families.running.disciplines);

        if ((props.sports_settings.counts.road_running ?? 0) > 0) {
            next.add('road_running');
        }

        next.add(hint.key);
        sportsForm.families.running.disciplines = Array.from(next);

        if (hint.key === 'trail_running') {
            sportsForm.rollups.trail_running = 'own';
        } else if (hint.key === 'track_running') {
            sportsForm.rollups.track_running = 'own';
        }
    } else if (cycleKeys.includes(hint.key)) {
        sportsForm.families.cycling.mode = 'split';
        const next = new Set(sportsForm.families.cycling.disciplines);

        if ((props.sports_settings.counts.road_cycling ?? 0) > 0) {
            next.add('road_cycling');
        }

        next.add(hint.key);
        sportsForm.families.cycling.disciplines = Array.from(next);

        if (hint.key === 'gravel_cycling') {
            sportsForm.rollups.gravel_cycling = 'own';
        } else if (hint.key === 'indoor_cycling') {
            sportsForm.rollups.indoor_cycling = 'own';
        } else if (hint.key === 'ebike_cycling') {
            sportsForm.rollups.ebike_cycling = 'own';
        }
    } else if (!sportsForm.secondary.includes(hint.key)) {
        sportsForm.secondary = [...sportsForm.secondary, hint.key];
    }

    submitSports(`Enabled ${hint.label} in custom sports configuration.`);
}

function submitSports(successMessage = 'Sports configuration updated.'): void {
    sportsForm.patch(updateSports.url(), {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(successMessage);
        },
        onError: () => {
            toast.error('Please review your sports configuration.');
        },
    });
}

function buildHealthSourcesMap(
    payload: HealthSourcesPayload,
): Record<string, string> {
    const map: Record<string, string> = {};

    for (const domain of payload.domains) {
        map[domain.key] = domain.configured;
    }

    return map;
}

const healthSourcesForm = useForm<{ sources: Record<string, string> }>({
    sources: buildHealthSourcesMap(props.health_sources),
});

watch(
    () => props.health_sources,
    (nextPayload) => {
        const nextSources = buildHealthSourcesMap(nextPayload);
        healthSourcesForm.sources = nextSources;
        healthSourcesForm.defaults({ sources: { ...nextSources } });
    },
    { deep: true },
);

function submitHealthSources(): void {
    healthSourcesForm.patch(updateHealthSources.url(), {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Health data source preferences updated.');
        },
        onError: () => {
            toast.error('Please review your health data source selections.');
        },
    });
}

const SEGMENTS_MIN_EFFORTS_PRESETS = [1, 3, 5, 10] as const;

const segmentsForm = useForm<{ min_efforts: number }>({
    min_efforts: props.segments_settings.min_efforts,
});

watch(
    () => props.segments_settings.min_efforts,
    (nextMinEfforts) => {
        segmentsForm.min_efforts = nextMinEfforts;
        segmentsForm.defaults({ min_efforts: nextMinEfforts });
    },
);

function submitSegmentsSettings(): void {
    segmentsForm.patch(updateSegments.url(), {
        preserveScroll: true,
        onSuccess: () => {
            const count = segmentsForm.min_efforts;
            toast.success(
                `Auto-detected climb threshold set to ${count} ${count === 1 ? 'effort' : 'efforts'}.`,
            );
        },
        onError: () => {
            toast.error('Minimum efforts must be between 1 and 50.');
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Settings" />

        <!-- Page Header -->
        <div class="flex flex-wrap items-end justify-between gap-4">
            <h1
                class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
            >
                Settings
            </h1>
        </div>

        <div class="max-w-4xl space-y-6">
            <!-- 1. Appearance -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <h2
                    class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                >
                    Appearance
                </h2>

                <div class="mt-4">
                    <AppearanceTabs />
                </div>
            </section>

            <!-- 2. Measurement Units -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <h2
                            class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            Measurement Units
                        </h2>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Applied immediately across dashboards, splits,
                            charts, weather, and equipment mileage.
                        </p>
                    </div>
                    <span
                        v-if="isUpdatingUnits"
                        class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                    >
                        Saving...
                    </span>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <button
                        v-for="option in UNIT_OPTIONS"
                        :key="option.value"
                        type="button"
                        :disabled="isUpdatingUnits"
                        :aria-pressed="unit_system === option.value"
                        class="group flex cursor-pointer flex-col justify-between rounded-xl border p-4 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-70"
                        :class="
                            unit_system === option.value
                                ? 'border-emerald-500/70 bg-emerald-500/5 dark:border-emerald-500/60 dark:bg-emerald-500/10'
                                : 'border-zinc-200/90 bg-[#f7f7f5]/60 hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900/40 dark:hover:border-zinc-700'
                        "
                        @click="selectUnitSystem(option.value)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{ option.label }}
                            </span>
                            <span
                                v-if="unit_system === option.value"
                                class="inline-flex size-5 items-center justify-center rounded-full bg-emerald-500 text-white"
                            >
                                <Check class="size-3" />
                            </span>
                            <span
                                v-else-if="pendingUnit === option.value"
                                class="font-mono text-[10px] text-zinc-400"
                            >
                                ...
                            </span>
                        </div>
                        <p
                            class="mt-2 font-mono text-xs text-zinc-700 dark:text-zinc-300"
                        >
                            {{ option.summary }}
                        </p>
                        <p
                            class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            {{ option.detail }}
                        </p>
                    </button>
                </div>
            </section>

            <!-- 3. Sports & Disciplines -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <form class="space-y-6" @submit.prevent="submitSports()">
                    <div
                        class="flex flex-wrap items-start justify-between gap-4"
                    >
                        <div class="space-y-1">
                            <h2
                                class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                Sports &amp; Disciplines
                            </h2>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Choose which sports appear in your sidebar, how
                                sub-disciplines roll up, and which efforts count
                                toward PRs.
                            </p>
                        </div>

                        <!-- Mode Switch -->
                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-1 dark:border-zinc-800 dark:bg-zinc-900/70"
                        >
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                                :class="
                                    sportsForm.mode === 'auto'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="sportsForm.mode = 'auto'"
                            >
                                Auto-detect from activities
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                                :class="
                                    sportsForm.mode === 'custom'
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="sportsForm.mode = 'custom'"
                            >
                                Custom configuration
                            </button>
                        </div>
                    </div>

                    <!-- Discovery Hints Banner -->
                    <div
                        v-if="sports_settings.hints.length > 0"
                        class="rounded-xl border border-emerald-500/25 bg-emerald-500/5 p-4 dark:border-emerald-500/25 dark:bg-emerald-500/10"
                    >
                        <div class="flex items-center gap-2">
                            <Sparkles
                                class="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                            />
                            <p
                                class="font-mono text-[11px] font-medium tracking-wider text-emerald-700 uppercase dark:text-emerald-300"
                            >
                                Additional Activities Detected
                            </p>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <div
                                v-for="hint in sports_settings.hints"
                                :key="hint.key"
                                class="flex items-center gap-2.5 rounded-lg border border-emerald-500/20 bg-white px-3 py-1.5 text-xs dark:border-emerald-500/20 dark:bg-[#141417]"
                            >
                                <span
                                    class="text-zinc-700 tabular-nums dark:text-zinc-200"
                                >
                                    <strong class="font-mono">{{
                                        formatNumber(hint.count)
                                    }}</strong>
                                    {{ hint.label }}
                                    {{
                                        hint.count === 1
                                            ? 'activity'
                                            : 'activities'
                                    }}
                                    detected
                                </span>
                                <button
                                    type="button"
                                    :disabled="sportsForm.processing"
                                    class="cursor-pointer rounded-md bg-emerald-600 px-2 py-0.5 font-mono text-[11px] font-medium text-white transition-colors hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="enableDiscoveryHint(hint)"
                                >
                                    Enable?
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Presentation -->
                    <div
                        class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <div class="space-y-0.5">
                                <h3
                                    class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    Sidebar Presentation
                                </h3>
                                <p
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Keep the sidebar compact with in-page
                                    discipline tabs, or pin nested
                                    sub-disciplines directly under Running &amp;
                                    Cycling.
                                </p>
                            </div>

                            <label
                                class="inline-flex cursor-pointer items-center gap-2.5 text-xs font-medium text-zinc-800 dark:text-zinc-200"
                            >
                                <input
                                    v-model="sportsForm.pin_sub_disciplines"
                                    type="checkbox"
                                    class="size-4 cursor-pointer rounded border-zinc-300 text-emerald-600 accent-emerald-600 focus:ring-emerald-500 dark:border-zinc-700"
                                />
                                <span>Pin sub-disciplines to sidebar</span>
                            </label>
                        </div>

                        <!-- Top-Level Order -->
                        <div
                            class="mt-4 border-t border-zinc-200/80 pt-4 dark:border-zinc-800/80"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Top-Level Sport Order
                                </span>
                                <span
                                    v-if="!isCustomMode"
                                    class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    Switch to Custom to reorder
                                </span>
                            </div>

                            <div class="mt-2.5 grid gap-1.5 sm:grid-cols-2">
                                <div
                                    v-for="(
                                        sportKey, index
                                    ) in sportsForm.order"
                                    :key="sportKey"
                                    class="flex items-center justify-between rounded-lg border border-zinc-200/80 bg-white px-3 py-1.5 text-xs dark:border-zinc-800 dark:bg-[#141417]"
                                    :class="
                                        !isOrderSportEnabled(sportKey) &&
                                        'opacity-55'
                                    "
                                >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                        >
                                            {{ index + 1 }}
                                        </span>
                                        <span
                                            class="size-2 rounded-full"
                                            :class="
                                                TOP_LEVEL_SPORT_LABELS[sportKey]
                                                    ?.dotClass ?? 'bg-zinc-400'
                                            "
                                        />
                                        <span
                                            class="font-medium text-zinc-800 dark:text-zinc-200"
                                        >
                                            {{
                                                TOP_LEVEL_SPORT_LABELS[sportKey]
                                                    ?.label ?? sportKey
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                !isOrderSportEnabled(sportKey)
                                            "
                                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                        >
                                            hidden
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-1">
                                        <button
                                            type="button"
                                            :disabled="
                                                !isCustomMode || index === 0
                                            "
                                            aria-label="Move sport up"
                                            class="inline-flex size-6 cursor-pointer items-center justify-center rounded border border-zinc-200 text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 disabled:cursor-not-allowed disabled:opacity-35 dark:border-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                                            @click="moveOrderItem(index, -1)"
                                        >
                                            <ArrowUp class="size-3" />
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="
                                                !isCustomMode ||
                                                index ===
                                                    sportsForm.order.length - 1
                                            "
                                            aria-label="Move sport down"
                                            class="inline-flex size-6 cursor-pointer items-center justify-center rounded border border-zinc-200 text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 disabled:cursor-not-allowed disabled:opacity-35 dark:border-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                                            @click="moveOrderItem(index, 1)"
                                        >
                                            <ArrowDown class="size-3" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Auto-mode preview notice -->
                    <div
                        v-if="!isCustomMode"
                        class="flex items-center justify-between rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-3.5 py-2.5 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900/50 dark:text-zinc-400"
                    >
                        <span>
                            Previewing your auto-detected sports layout based on
                            recorded activities.
                        </span>
                        <button
                            type="button"
                            class="shrink-0 cursor-pointer font-mono text-[11px] font-medium text-emerald-600 hover:underline dark:text-emerald-400"
                            @click="sportsForm.mode = 'custom'"
                        >
                            Customize →
                        </button>
                    </div>

                    <!-- Running & Cycling Family Cards -->
                    <div class="grid gap-4 md:grid-cols-2">
                        <!-- Running Family -->
                        <div
                            class="rounded-xl border border-zinc-200/80 p-4 dark:border-zinc-800/80"
                            :class="!isCustomMode && 'opacity-80'"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="size-2.5 rounded-full bg-emerald-500"
                                    />
                                    <h3
                                        class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                    >
                                        Running
                                    </h3>
                                </div>
                                <span
                                    class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                >
                                    {{ formatNumber(runningTotalCount) }}
                                    recorded
                                </span>
                            </div>

                            <div
                                class="mt-3 grid grid-cols-3 gap-1 rounded-lg bg-[#f7f7f5] p-1 dark:bg-zinc-900/70"
                            >
                                <button
                                    v-for="opt in FAMILY_MODE_OPTIONS"
                                    :key="opt.value"
                                    type="button"
                                    :disabled="!isCustomMode"
                                    class="cursor-pointer rounded-md px-2 py-1 text-[11px] font-medium transition-colors disabled:cursor-not-allowed"
                                    :class="
                                        sportsForm.families.running.mode ===
                                        opt.value
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-white'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="setFamilyMode('running', opt.value)"
                                >
                                    {{ opt.label }}
                                </button>
                            </div>

                            <div class="mt-3.5 space-y-1.5">
                                <label
                                    v-for="disc in RUNNING_DISCIPLINES"
                                    :key="disc.key"
                                    class="flex items-center justify-between rounded-lg border border-zinc-200/70 px-3 py-2 text-xs transition-colors dark:border-zinc-800/70"
                                    :class="[
                                        isCustomMode &&
                                        sportsForm.families.running.mode ===
                                            'split'
                                            ? 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-900/50'
                                            : 'cursor-not-allowed opacity-60',
                                    ]"
                                >
                                    <span class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            :checked="
                                                sportsForm.families.running.disciplines.includes(
                                                    disc.key,
                                                )
                                            "
                                            :disabled="
                                                !isCustomMode ||
                                                sportsForm.families.running
                                                    .mode !== 'split'
                                            "
                                            class="size-3.5 rounded border-zinc-300 accent-emerald-600 dark:border-zinc-700"
                                            @change="
                                                toggleFamilyDiscipline(
                                                    'running',
                                                    disc.key,
                                                )
                                            "
                                        />
                                        <span
                                            class="size-1.5 rounded-full"
                                            :class="disc.dotClass"
                                        />
                                        <span
                                            class="font-medium text-zinc-800 dark:text-zinc-200"
                                        >
                                            {{ disc.label }}
                                        </span>
                                    </span>
                                    <span
                                        class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                    >
                                        {{
                                            formatNumber(
                                                sports_settings.counts[
                                                    disc.key
                                                ] ?? 0,
                                            )
                                        }}
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Cycling Family -->
                        <div
                            class="rounded-xl border border-zinc-200/80 p-4 dark:border-zinc-800/80"
                            :class="!isCustomMode && 'opacity-80'"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="size-2.5 rounded-full bg-sky-500"
                                    />
                                    <h3
                                        class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                    >
                                        Cycling
                                    </h3>
                                </div>
                                <span
                                    class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                >
                                    {{ formatNumber(cyclingTotalCount) }}
                                    recorded
                                </span>
                            </div>

                            <div
                                class="mt-3 grid grid-cols-3 gap-1 rounded-lg bg-[#f7f7f5] p-1 dark:bg-zinc-900/70"
                            >
                                <button
                                    v-for="opt in FAMILY_MODE_OPTIONS"
                                    :key="opt.value"
                                    type="button"
                                    :disabled="!isCustomMode"
                                    class="cursor-pointer rounded-md px-2 py-1 text-[11px] font-medium transition-colors disabled:cursor-not-allowed"
                                    :class="
                                        sportsForm.families.cycling.mode ===
                                        opt.value
                                            ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-white'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="setFamilyMode('cycling', opt.value)"
                                >
                                    {{ opt.label }}
                                </button>
                            </div>

                            <div class="mt-3.5 space-y-1.5">
                                <label
                                    v-for="disc in CYCLING_DISCIPLINES"
                                    :key="disc.key"
                                    class="flex items-center justify-between rounded-lg border border-zinc-200/70 px-3 py-2 text-xs transition-colors dark:border-zinc-800/70"
                                    :class="[
                                        isCustomMode &&
                                        sportsForm.families.cycling.mode ===
                                            'split'
                                            ? 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-900/50'
                                            : 'cursor-not-allowed opacity-60',
                                    ]"
                                >
                                    <span class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            :checked="
                                                sportsForm.families.cycling.disciplines.includes(
                                                    disc.key,
                                                )
                                            "
                                            :disabled="
                                                !isCustomMode ||
                                                sportsForm.families.cycling
                                                    .mode !== 'split'
                                            "
                                            class="size-3.5 rounded border-zinc-300 accent-emerald-600 dark:border-zinc-700"
                                            @change="
                                                toggleFamilyDiscipline(
                                                    'cycling',
                                                    disc.key,
                                                )
                                            "
                                        />
                                        <span
                                            class="size-1.5 rounded-full"
                                            :class="disc.dotClass"
                                        />
                                        <span
                                            class="font-medium text-zinc-800 dark:text-zinc-200"
                                        >
                                            {{ disc.label }}
                                        </span>
                                    </span>
                                    <span
                                        class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                    >
                                        {{
                                            formatNumber(
                                                sports_settings.counts[
                                                    disc.key
                                                ] ?? 0,
                                            )
                                        }}
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Cross-Training Sports -->
                    <div
                        class="rounded-xl border border-zinc-200/80 p-4 dark:border-zinc-800/80"
                        :class="!isCustomMode && 'opacity-80'"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <h3
                                    class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    Cross-Training Sports
                                </h3>
                                <p
                                    class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Enable dedicated pages for secondary sports
                                    in your sidebar and 7-day dashboard
                                    breakdown.
                                </p>
                            </div>
                        </div>

                        <div class="mt-3.5 grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="sport in SECONDARY_SPORTS"
                                :key="sport.key"
                                class="flex items-center justify-between rounded-lg border border-zinc-200/70 px-3 py-2.5 text-xs transition-colors dark:border-zinc-800/70"
                                :class="
                                    isCustomMode
                                        ? 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-900/50'
                                        : 'cursor-not-allowed opacity-70'
                                "
                            >
                                <span class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        :checked="
                                            sportsForm.secondary.includes(
                                                sport.key,
                                            )
                                        "
                                        :disabled="!isCustomMode"
                                        class="size-3.5 rounded border-zinc-300 accent-emerald-600 dark:border-zinc-700"
                                        @change="
                                            toggleSecondarySport(sport.key)
                                        "
                                    />
                                    <span
                                        class="size-2 rounded-full"
                                        :class="sport.dotClass"
                                    />
                                    <span
                                        class="font-medium text-zinc-800 dark:text-zinc-200"
                                    >
                                        {{ sport.label }}
                                    </span>
                                </span>
                                <span
                                    class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                >
                                    {{
                                        formatNumber(
                                            sports_settings.counts[sport.key] ??
                                                0,
                                        )
                                    }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Roll-Up & Best Efforts Rules (Custom Mode) -->
                    <div
                        v-if="isCustomMode"
                        class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/50 dark:border-zinc-800/80 dark:bg-zinc-900/30"
                    >
                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-center justify-between px-4 py-3.5 text-left"
                            @click="rulesExpanded = !rulesExpanded"
                        >
                            <div>
                                <h3
                                    class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    Roll-Up &amp; Best Efforts Rules
                                </h3>
                                <p
                                    class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Control how overlapping disciplines
                                    aggregate and which activities feed personal
                                    records.
                                </p>
                            </div>
                            <ChevronDown
                                class="size-4 shrink-0 text-zinc-400 transition-transform"
                                :class="rulesExpanded && 'rotate-180'"
                            />
                        </button>

                        <div
                            v-if="rulesExpanded"
                            class="space-y-5 border-t border-zinc-200/80 p-4 dark:border-zinc-800/80"
                        >
                            <!-- Roll-Up Selects -->
                            <div class="grid gap-3.5 sm:grid-cols-2">
                                <label class="space-y-1.5">
                                    <span
                                        class="block font-mono text-[11px] text-zinc-600 dark:text-zinc-400"
                                    >
                                        Trail Running Roll-Up
                                    </span>
                                    <select
                                        v-model="
                                            sportsForm.rollups.trail_running
                                        "
                                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-100"
                                    >
                                        <option value="own">
                                            Keep in Trail Running
                                        </option>
                                        <option value="running">
                                            Roll into Road / Running
                                        </option>
                                    </select>
                                </label>

                                <label class="space-y-1.5">
                                    <span
                                        class="block font-mono text-[11px] text-zinc-600 dark:text-zinc-400"
                                    >
                                        Track Running Roll-Up
                                    </span>
                                    <select
                                        v-model="
                                            sportsForm.rollups.track_running
                                        "
                                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-100"
                                    >
                                        <option value="road_running">
                                            Roll into Road Running
                                        </option>
                                        <option value="own">
                                            Keep in Track Running
                                        </option>
                                    </select>
                                </label>

                                <label class="space-y-1.5">
                                    <span
                                        class="block font-mono text-[11px] text-zinc-600 dark:text-zinc-400"
                                    >
                                        Gravel / CX Roll-Up
                                    </span>
                                    <select
                                        v-model="
                                            sportsForm.rollups.gravel_cycling
                                        "
                                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-100"
                                    >
                                        <option value="own">
                                            Keep in Gravel / CX
                                        </option>
                                        <option value="road_cycling">
                                            Roll into Road Cycling
                                        </option>
                                        <option value="mtb_cycling">
                                            Roll into Mountain Biking
                                        </option>
                                    </select>
                                </label>

                                <label class="space-y-1.5">
                                    <span
                                        class="block font-mono text-[11px] text-zinc-600 dark:text-zinc-400"
                                    >
                                        Indoor Cycling Roll-Up
                                    </span>
                                    <select
                                        v-model="
                                            sportsForm.rollups.indoor_cycling
                                        "
                                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-100"
                                    >
                                        <option value="road_cycling">
                                            Roll into Road Cycling
                                        </option>
                                        <option value="own">
                                            Keep in Indoor Cycling
                                        </option>
                                    </select>
                                </label>

                                <label class="space-y-1.5 sm:col-span-2">
                                    <span
                                        class="block font-mono text-[11px] text-zinc-600 dark:text-zinc-400"
                                    >
                                        E-Bike Roll-Up
                                    </span>
                                    <select
                                        v-model="
                                            sportsForm.rollups.ebike_cycling
                                        "
                                        class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-100"
                                    >
                                        <option value="own">
                                            Keep in E-Bike
                                        </option>
                                        <option value="mtb_cycling">
                                            Roll into Mountain Biking
                                        </option>
                                        <option value="exclude">
                                            Exclude from Cycling overview totals
                                        </option>
                                    </select>
                                </label>
                            </div>

                            <!-- Best Efforts Controls -->
                            <div
                                class="space-y-3 border-t border-zinc-200/80 pt-4 dark:border-zinc-800/80"
                            >
                                <label
                                    class="flex cursor-pointer items-center gap-2.5 text-xs text-zinc-800 dark:text-zinc-200"
                                >
                                    <input
                                        v-model="
                                            sportsForm.best_efforts
                                                .trail_in_running_prs
                                        "
                                        type="checkbox"
                                        class="size-4 cursor-pointer rounded border-zinc-300 accent-emerald-600 dark:border-zinc-700"
                                    />
                                    <span>
                                        Include Trail Running in flat Running
                                        PRs &amp; Race Predictor
                                    </span>
                                </label>

                                <div class="space-y-2">
                                    <span
                                        class="block font-mono text-[11px] text-zinc-600 dark:text-zinc-400"
                                    >
                                        Cycling Distance Best Effort Disciplines
                                    </span>
                                    <div class="flex flex-wrap gap-2">
                                        <label
                                            v-for="item in BEST_EFFORT_CYCLING_OPTIONS"
                                            :key="item.value"
                                            class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-800 transition-colors hover:border-zinc-300 dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-200 dark:hover:border-zinc-600"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="
                                                    sportsForm.best_efforts.cycling_disciplines.includes(
                                                        item.value,
                                                    )
                                                "
                                                class="size-3.5 cursor-pointer rounded border-zinc-300 accent-emerald-600 dark:border-zinc-700"
                                                @change="
                                                    toggleCyclingBestEffortDiscipline(
                                                        item.value,
                                                    )
                                                "
                                            />
                                            <span>{{ item.label }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Validation Errors -->
                    <div
                        v-if="Object.keys(sportsForm.errors).length > 0"
                        class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3.5 py-2.5 text-xs text-rose-700 dark:text-rose-300"
                    >
                        <p
                            v-for="(message, field) in sportsForm.errors"
                            :key="field"
                        >
                            {{ message }}
                        </p>
                    </div>

                    <!-- Form Footer -->
                    <div
                        class="flex items-center justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800/80"
                    >
                        <span
                            class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{
                                sportsForm.isDirty
                                    ? 'Unsaved changes'
                                    : 'Up to date'
                            }}
                        </span>
                        <button
                            type="submit"
                            :disabled="sportsForm.processing"
                            class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                        >
                            {{
                                sportsForm.processing
                                    ? 'Saving...'
                                    : 'Save Sports Settings'
                            }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- 4. Health Data Sources -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <form class="space-y-5" @submit.prevent="submitHealthSources">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <HeartPulse
                                    class="size-4 text-emerald-600 dark:text-emerald-400"
                                />
                                <h2
                                    class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                                >
                                    Health Data Sources
                                </h2>
                            </div>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Choose the primary telemetry source per health
                                domain when data arrives from multiple
                                providers. Auto prefers the most recently
                                updated provider and fills in days it has no
                                data for from your other providers.
                            </p>
                        </div>

                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 font-mono text-[10px] font-medium"
                            :class="
                                health_sources.has_multiple_providers
                                    ? 'bg-sky-500/12 text-sky-700 dark:text-sky-300'
                                    : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'
                            "
                        >
                            {{
                                health_sources.has_multiple_providers
                                    ? 'Multi-provider detected'
                                    : 'Single-provider mode'
                            }}
                        </span>
                    </div>

                    <div
                        class="divide-y divide-zinc-200/80 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/50 dark:divide-zinc-800/80 dark:border-zinc-800 dark:bg-zinc-900/35"
                    >
                        <div
                            v-for="domain in health_sources.domains"
                            :key="domain.key"
                            class="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{ domain.label }}
                                    </span>
                                    <span
                                        v-if="domain.resolved_label"
                                        class="inline-flex items-center rounded-full bg-emerald-500/12 px-2 py-0.5 font-mono text-[10px] font-medium text-emerald-700 dark:text-emerald-400"
                                    >
                                        Active: {{ domain.resolved_label }}
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center rounded-full bg-zinc-200/70 px-2 py-0.5 font-mono text-[10px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
                                    >
                                        No data yet
                                    </span>
                                </div>
                                <p
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ domain.description }}
                                </p>
                                <div
                                    v-if="domain.providers.length > 0"
                                    class="flex flex-wrap gap-1.5 pt-0.5"
                                >
                                    <span
                                        v-for="source in domain.providers"
                                        :key="source.id"
                                        class="inline-flex items-center gap-1 rounded-md border border-zinc-200/80 bg-white px-2 py-0.5 font-mono text-[10px] text-zinc-600 tabular-nums dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-300"
                                    >
                                        <span>{{ source.label }}</span>
                                        <span
                                            class="text-zinc-400 dark:text-zinc-500"
                                        >
                                            ({{ formatNumber(source.count) }})
                                        </span>
                                    </span>
                                </div>
                            </div>

                            <div class="shrink-0 sm:w-52">
                                <select
                                    v-model="
                                        healthSourcesForm.sources[domain.key]
                                    "
                                    :aria-label="`${domain.label} primary source`"
                                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-100"
                                >
                                    <option value="auto">
                                        Auto{{
                                            domain.resolved_label
                                                ? ` (${domain.resolved_label})`
                                                : ' (Most recent)'
                                        }}
                                    </option>
                                    <option
                                        v-for="source in domain.providers"
                                        :key="source.id"
                                        :value="source.id"
                                    >
                                        {{ source.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="Object.keys(healthSourcesForm.errors).length > 0"
                        class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3.5 py-2.5 text-xs text-rose-700 dark:text-rose-300"
                    >
                        <p
                            v-for="(message, field) in healthSourcesForm.errors"
                            :key="field"
                        >
                            {{ message }}
                        </p>
                    </div>

                    <div
                        class="flex items-center justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800/80"
                    >
                        <span
                            class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{
                                healthSourcesForm.isDirty
                                    ? 'Unsaved changes'
                                    : 'Up to date'
                            }}
                        </span>
                        <button
                            type="submit"
                            :disabled="healthSourcesForm.processing"
                            class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                        >
                            {{
                                healthSourcesForm.processing
                                    ? 'Saving...'
                                    : 'Save Health Sources'
                            }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- 4A. Segments & Auto-Detected Climbs -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <form class="space-y-5" @submit.prevent="submitSegmentsSettings">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <Mountain
                                    class="size-4 text-amber-600 dark:text-amber-400"
                                />
                                <h2
                                    class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                                >
                                    Segments &amp; Auto-Detected Climbs
                                </h2>
                            </div>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Minimum number of climbs required before an
                                auto-detected climb appears in your Segments
                                directory and activity breakdowns. Custom and
                                starred segments are always shown.
                            </p>
                        </div>

                        <span
                            class="inline-flex items-center rounded-full bg-amber-500/12 px-2.5 py-0.5 font-mono text-[10px] font-medium text-amber-700 tabular-nums dark:text-amber-300"
                        >
                            Showing
                            {{
                                formatNumber(
                                    segments_settings.visible_climbs_count,
                                )
                            }}
                            of
                            {{
                                formatNumber(
                                    segments_settings.tracked_climbs_count,
                                )
                            }}
                            climbs
                        </span>
                    </div>

                    <div
                        class="flex flex-col gap-4 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-900/35"
                    >
                        <div class="space-y-1">
                            <label
                                for="segments-min-efforts"
                                class="text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >
                                Minimum Efforts per Auto-Climb
                            </label>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Default is
                                {{ segments_settings.default_min_efforts }}.
                                Changes apply immediately without rescanning
                                your activity history.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <div
                                class="inline-flex rounded-lg border border-zinc-200/90 bg-white p-0.5 dark:border-zinc-800 dark:bg-[#141417]"
                            >
                                <button
                                    v-for="preset in SEGMENTS_MIN_EFFORTS_PRESETS"
                                    :key="preset"
                                    type="button"
                                    class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-xs font-medium tabular-nums transition-colors"
                                    :class="
                                        segmentsForm.min_efforts === preset
                                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="segmentsForm.min_efforts = preset"
                                >
                                    {{ preset }}
                                </button>
                            </div>

                            <input
                                id="segments-min-efforts"
                                v-model.number="segmentsForm.min_efforts"
                                type="number"
                                min="1"
                                max="50"
                                step="1"
                                class="w-20 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 font-mono text-xs text-zinc-900 tabular-nums focus:border-emerald-500 focus:outline-none dark:border-zinc-700 dark:bg-[#141417] dark:text-zinc-100"
                            />
                        </div>
                    </div>

                    <div
                        v-if="segmentsForm.errors.min_efforts"
                        class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3.5 py-2.5 text-xs text-rose-700 dark:text-rose-300"
                    >
                        {{ segmentsForm.errors.min_efforts }}
                    </div>

                    <div
                        class="flex items-center justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800/80"
                    >
                        <span
                            class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{
                                segmentsForm.isDirty
                                    ? 'Unsaved changes'
                                    : 'Up to date'
                            }}
                        </span>
                        <button
                            type="submit"
                            :disabled="segmentsForm.processing"
                            class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                        >
                            {{
                                segmentsForm.processing
                                    ? 'Saving...'
                                    : 'Save Segment Settings'
                            }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- 5. Data Provider & Environment -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="space-y-1">
                    <h2
                        class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        Data Provider &amp; Environment
                    </h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Managed via environment variables on the server.
                    </p>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div
                        class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40"
                    >
                        <div
                            class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <Clock class="size-3" />
                            Display Timezone
                        </div>
                        <p
                            class="mt-2 font-mono text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            {{ timezone }}
                        </p>
                        <p
                            class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Used for daily rollups, sleep boundaries, and local
                            activity timestamps.
                        </p>
                    </div>

                    <div
                        class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40"
                    >
                        <div
                            class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <Database class="size-3" />
                            Data Provider
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span
                                class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{ provider.name }}
                            </span>
                            <span
                                v-if="provider.configured"
                                class="inline-flex items-center rounded-full bg-emerald-500/12 px-2 py-0.5 font-mono text-[10px] font-medium text-emerald-700 dark:text-emerald-400"
                            >
                                Configured
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center rounded-full bg-amber-500/15 px-2 py-0.5 font-mono text-[10px] font-medium text-amber-700 dark:text-amber-400"
                            >
                                Not Configured — Set GARMIN_KEY in environment
                            </span>
                        </div>
                        <p
                            class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Background sync imports activities, wellness
                            metrics, and route data.
                        </p>
                    </div>
                </div>

                <!-- Historical Backfill -->
                <div
                    class="mt-4 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="space-y-1">
                            <div
                                class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                <History class="size-3" />
                                Historical Backfill
                            </div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Re-fetch historical health metrics (including
                                weekly Fitness Age, VO2 Max, sleep, HRV, and
                                weight) in resumable background chunks, and
                                queue any missing FIT streams and weather.
                            </p>
                        </div>

                        <span
                            class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            Last completed: {{ lastBackfillLabel }}
                        </span>
                    </div>

                    <!-- Live Progress Banner -->
                    <div
                        v-if="isAnySyncRunning"
                        class="mt-3.5 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-500/30 bg-amber-500/8 px-3.5 py-2.5 text-xs dark:border-amber-500/25 dark:bg-amber-500/10"
                    >
                        <span
                            class="flex items-center gap-2 font-medium text-amber-800 dark:text-amber-300"
                        >
                            <span
                                class="size-2 animate-pulse rounded-full bg-amber-500"
                            />
                            {{
                                isBackfillActive
                                    ? 'Historical backfill running in background...'
                                    : 'Garmin sync in progress...'
                            }}
                        </span>
                        <span
                            class="font-mono text-[11px] text-amber-700/90 tabular-nums dark:text-amber-300/90"
                        >
                            <template v-if="liveSync.cursor_date">
                                Cursor {{ liveSync.cursor_date }} ·
                            </template>
                            {{ formatNumber(liveSync.items_synced) }} items
                        </span>
                    </div>

                    <!-- Previous Backfill Error -->
                    <div
                        v-else-if="backfill.last_error"
                        class="mt-3.5 flex items-start gap-2 rounded-lg border border-rose-500/30 bg-rose-500/8 px-3.5 py-2.5 text-xs text-rose-700 dark:border-rose-500/25 dark:bg-rose-500/10 dark:text-rose-300"
                    >
                        <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                        <span class="break-words">{{
                            backfill.last_error
                        }}</span>
                    </div>

                    <div
                        class="mt-4 flex flex-wrap items-center justify-between gap-3"
                    >
                        <!-- Range Selector -->
                        <div
                            class="inline-flex flex-wrap rounded-lg border border-zinc-200/90 bg-white p-1 dark:border-zinc-800 dark:bg-[#141417]"
                        >
                            <button
                                v-for="option in BACKFILL_RANGE_OPTIONS"
                                :key="option.value"
                                type="button"
                                :disabled="isAnySyncRunning"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                :class="
                                    selectedBackfillRange === option.value
                                        ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                        : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="selectedBackfillRange = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-if="
                                    backfill.can_resume &&
                                    backfill.resume_cursor_date &&
                                    !isAnySyncRunning
                                "
                                type="button"
                                :disabled="!provider.configured"
                                :title="
                                    backfill.resume_until_date
                                        ? `Resumes back to ${backfill.resume_until_date}`
                                        : 'Resumes all-time history backfill'
                                "
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-medium text-zinc-900 transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                                @click="triggerBackfill('resume')"
                            >
                                <History class="size-3.5" />
                                Resume from {{ backfill.resume_cursor_date }}
                            </button>

                            <button
                                type="button"
                                :disabled="
                                    isAnySyncRunning || !provider.configured
                                "
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                                @click="triggerBackfill(selectedBackfillRange)"
                            >
                                <RefreshCw
                                    :class="[
                                        'size-3.5',
                                        isAnySyncRunning && 'animate-spin',
                                    ]"
                                />
                                {{
                                    isBackfillActive
                                        ? 'Backfilling...'
                                        : 'Start Backfill'
                                }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Background Enrichment Queue -->
                <div
                    class="mt-4 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div class="space-y-0.5">
                            <div
                                class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                <Layers class="size-3" />
                                Background Enrichment Queue
                            </div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Queued worker jobs and remaining activities
                                awaiting stream, weather, route matching, or
                                place naming.
                            </p>
                        </div>

                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                            :class="
                                isEnrichmentQueueActive
                                    ? 'bg-sky-500/12 text-sky-700 dark:text-sky-300'
                                    : 'bg-zinc-200/70 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'
                            "
                        >
                            <span
                                v-if="isEnrichmentQueueActive"
                                class="size-1.5 animate-pulse rounded-full bg-sky-500"
                            />
                            {{
                                isEnrichmentQueueActive
                                    ? `Active · ${formatNumber(liveEnrichmentQueue.jobs.total)} queued`
                                    : 'Queue Idle'
                            }}
                        </span>
                    </div>

                    <div class="mt-3.5 grid gap-3 sm:grid-cols-2">
                        <!-- Queued Jobs -->
                        <div
                            class="rounded-lg border border-zinc-200/80 bg-white p-3 dark:border-zinc-800 dark:bg-[#141417]"
                        >
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                            >
                                Queued Jobs
                            </p>
                            <div
                                class="mt-2.5 grid grid-cols-2 gap-2 text-center sm:grid-cols-4"
                            >
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.jobs
                                                    .streams,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Streams
                                    </p>
                                </div>
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.jobs
                                                    .weather,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Weather
                                    </p>
                                </div>
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.jobs
                                                    .geocode,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Geocode
                                    </p>
                                </div>
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.jobs
                                                    .default,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Default
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Pending Activity Enrichments -->
                        <div
                            class="rounded-lg border border-zinc-200/80 bg-white p-3 dark:border-zinc-800 dark:bg-[#141417]"
                        >
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                            >
                                Pending Enrichments
                            </p>
                            <div
                                class="mt-2.5 grid grid-cols-2 gap-2 text-center sm:grid-cols-5"
                            >
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.pending
                                                    .streams,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        FIT Streams
                                    </p>
                                </div>
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.pending
                                                    .weather,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Weather
                                    </p>
                                </div>
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.pending
                                                    .routes,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Routes
                                    </p>
                                </div>
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.pending
                                                    .segments,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Segments
                                    </p>
                                </div>
                                <div
                                    class="rounded-md bg-[#f7f7f5] px-2 py-1.5 dark:bg-zinc-900/70"
                                >
                                    <p
                                        class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                liveEnrichmentQueue.pending
                                                    .geocoding,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        Place Names
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reverse Geocoding Provider & ODbL Attribution -->
                    <div
                        class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200/80 bg-white px-3 py-2.5 text-xs dark:border-zinc-800 dark:bg-[#141417]"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                <MapPin class="size-3" />
                                Nominatim
                            </span>
                            <span
                                class="font-mono text-[11px] text-zinc-700 dark:text-zinc-300"
                            >
                                {{ geocoding.host }}
                            </span>
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 font-mono text-[10px] font-medium"
                                :class="
                                    geocoding.enabled
                                        ? 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-400'
                                        : 'bg-zinc-200/70 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'
                                "
                            >
                                {{
                                    geocoding.enabled
                                        ? geocoding.is_public_endpoint
                                            ? 'Enabled · Public OSMF'
                                            : 'Enabled · Self-hosted'
                                        : 'Disabled'
                                }}
                            </span>
                        </div>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
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
                        </span>
                    </div>
                </div>
            </section>

            <!-- 6. About -->
            <section
                class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h2
                            class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            About {{ appName }}
                        </h2>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Local-first, self-hosted endurance and health
                            telemetry.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-1 font-mono text-xs font-medium text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300"
                        >
                            <Tag
                                class="size-3 text-zinc-400 dark:text-zinc-500"
                            />
                            {{ appVersion }}
                        </span>

                        <a
                            v-if="releaseNotesUrl"
                            :href="releaseNotesUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                        >
                            <span>Release Notes</span>
                            <ExternalLink class="size-3" />
                        </a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
