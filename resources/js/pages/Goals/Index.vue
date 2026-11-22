<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    CalendarRange,
    Check,
    ChevronDown,
    ChevronRight,
    Flame,
    Pencil,
    Plus,
    Target,
    Trash2,
    Trophy,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { formatNumber } from '@/lib/format';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import { index as activitiesIndex } from '@/routes/activities';
import {
    discipline as cyclingDiscipline,
    index as cyclingIndex,
} from '@/routes/cycling';
import {
    archive as goalsArchive,
    destroy as goalsDestroy,
    store as goalsStore,
    update as goalsUpdate,
} from '@/routes/goals';
import {
    discipline as runningDiscipline,
    index as runningIndex,
} from '@/routes/running';
import { show as sportsShow } from '@/routes/sports';
import type { RouteDefinition } from '@/wayfinder';

type GoalSport = string;
type GoalMetric =
    | 'distance_km'
    | 'elevation_m'
    | 'duration_hours'
    | 'activity_count';
type GoalPeriod = 'weekly' | 'monthly' | 'yearly';
type PaceStatus = 'completed' | 'ahead' | 'on_track' | 'behind';

interface GoalEvaluation {
    id: number;
    title: string;
    sport: GoalSport;
    metric: GoalMetric;
    period: GoalPeriod;
    target_value: number;
    current_value: number;
    remaining_value: number;
    expected_value: number;
    expected_pct: number;
    delta: number;
    pct: number;
    unit: string;
    pace_status: PaceStatus;
    pace_label: string;
    period_label: string;
    days_remaining: number;
    year: number | null;
    is_active: boolean;
}

interface SportWeeklyStreak {
    current_weekly_streak: number;
    longest_weekly_streak: number;
}

interface SportWeeklyStreakItem extends SportWeeklyStreak {
    key: string;
    label: string;
    short_label: string;
    accent_hex: string;
}

interface StreaksPayload {
    current_daily_streak: number;
    longest_daily_streak: number;
    current_weekly_streak: number;
    longest_weekly_streak: number;
    by_sport: Record<string, SportWeeklyStreak>;
    by_sport_list?: SportWeeklyStreakItem[];
}

interface GoalsIndexProps {
    active: GoalEvaluation[];
    archived: GoalEvaluation[];
    streaks: StreaksPayload;
}

const props = defineProps<GoalsIndexProps>();

const {
    catalog,
    sportAccentHex: resolveSportAccentHex,
    sportLabel: resolveSportLabel,
} = useSports();

const {
    isImperial,
    distanceUnit,
    elevationUnit,
    distanceFromMeters,
    elevationFromMeters,
    kmToInputDistance,
    inputDistanceToKm,
    metersToInputElevation,
    inputElevationToMeters,
} = useUnits();

const LEGACY_SPORT_NORMALIZATION: Record<string, string> = {
    biking: 'cycling',
    road_biking: 'road_cycling',
    mtb_biking: 'mtb_cycling',
};

const GOAL_SPORT_ORDER: string[] = [
    'running',
    'road_running',
    'trail_running',
    'track_running',
    'treadmill_running',
    'cycling',
    'road_cycling',
    'gravel_cycling',
    'mtb_cycling',
    'indoor_cycling',
    'ebike_cycling',
    'commute_cycling',
    'hiking',
    'walking',
    'swimming',
    'cardio',
];

const sportOptions = computed<Array<{ value: GoalSport; label: string }>>(
    () => {
        const entries = catalog.value;
        const options: Array<{ value: GoalSport; label: string }> = [
            { value: 'all', label: 'All Sports' },
        ];
        const added = new Set<string>(['all', 'other']);

        for (const key of GOAL_SPORT_ORDER) {
            const meta = entries[key];

            if (meta && !added.has(key)) {
                options.push({ value: key, label: meta.label });
                added.add(key);
            }
        }

        for (const [key, meta] of Object.entries(entries)) {
            if (!added.has(key)) {
                options.push({ value: key, label: meta.label });
                added.add(key);
            }
        }

        return options;
    },
);

const metricOptions = computed<
    Array<{
        value: GoalMetric;
        label: string;
        unit: string;
        step: string;
        placeholder: string;
    }>
>(() => [
    {
        value: 'distance_km',
        label: `Distance (${distanceUnit.value})`,
        unit: distanceUnit.value,
        step: '0.1',
        placeholder: isImperial.value ? '30' : '50',
    },
    {
        value: 'elevation_m',
        label: `Elevation Gain (${elevationUnit.value})`,
        unit: elevationUnit.value,
        step: '1',
        placeholder: isImperial.value ? '5000' : '1500',
    },
    {
        value: 'duration_hours',
        label: 'Duration (hours)',
        unit: 'h',
        step: '0.1',
        placeholder: '6',
    },
    {
        value: 'activity_count',
        label: 'Activity Sessions',
        unit: 'sessions',
        step: '1',
        placeholder: '5',
    },
]);

const PERIOD_OPTIONS: Array<{ value: GoalPeriod; label: string }> = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'yearly', label: 'Yearly' },
];

const isFormOpen = ref<boolean>(false);
const editingGoalId = ref<number | null>(null);
const archivedOpen = ref<boolean>(false);
const busyGoalId = ref<number | null>(null);
const confirmDeleteId = ref<number | null>(null);

const form = useForm({
    title: '',
    sport: 'running' as GoalSport,
    metric: 'distance_km' as GoalMetric,
    period: 'weekly' as GoalPeriod,
    target_value: '',
    year: '',
});

const selectedMetricMeta = computed(() => {
    return (
        metricOptions.value.find((option) => option.value === form.metric) ??
        metricOptions.value[0]
    );
});

const groupedActiveGoals = computed<
    Array<{ period: GoalPeriod; heading: string; goals: GoalEvaluation[] }>
>(() => {
    const groups: Array<{
        period: GoalPeriod;
        heading: string;
        goals: GoalEvaluation[];
    }> = [
        {
            period: 'weekly',
            heading: 'Weekly Targets',
            goals: props.active.filter((goal) => goal.period === 'weekly'),
        },
        {
            period: 'monthly',
            heading: 'Monthly Targets',
            goals: props.active.filter((goal) => goal.period === 'monthly'),
        },
        {
            period: 'yearly',
            heading: 'Yearly Targets',
            goals: props.active.filter((goal) => goal.period === 'yearly'),
        },
    ];

    return groups.filter((group) => group.goals.length > 0);
});

function normalizeGoalSportKey(sport: string): string {
    return LEGACY_SPORT_NORMALIZATION[sport] ?? sport;
}

function sportHref(sportKey: string): RouteDefinition<'get'> {
    const normalized = normalizeGoalSportKey(sportKey);

    switch (normalized) {
        case 'running':
            return runningIndex();
        case 'road_running':
            return runningDiscipline('road');
        case 'trail_running':
            return runningDiscipline('trail');
        case 'track_running':
            return runningDiscipline('track');
        case 'treadmill_running':
            return runningDiscipline('treadmill');
        case 'cycling':
            return cyclingIndex();
        case 'road_cycling':
            return cyclingDiscipline('road');
        case 'gravel_cycling':
            return cyclingDiscipline('gravel');
        case 'mtb_cycling':
            return cyclingDiscipline('mtb');
        case 'indoor_cycling':
            return cyclingDiscipline('indoor');
        case 'ebike_cycling':
            return cyclingDiscipline('ebike');
        case 'commute_cycling':
            return cyclingDiscipline('commute');
        case 'hiking':
        case 'walking':
        case 'swimming':
        case 'cardio':
            return sportsShow(normalized);
        default:
            return activitiesIndex();
    }
}

const sportStreakPills = computed(() => {
    if (props.streaks.by_sport_list && props.streaks.by_sport_list.length > 0) {
        return props.streaks.by_sport_list.map((entry) => ({
            key: entry.key,
            label: entry.label,
            href: sportHref(entry.key),
            accentHex: entry.accent_hex || resolveSportAccentHex(entry.key),
            streak: {
                current_weekly_streak: entry.current_weekly_streak,
                longest_weekly_streak: entry.longest_weekly_streak,
            },
        }));
    }

    const fallbackKeys = ['running', 'road_cycling', 'mtb_cycling'];
    const legacyFallbacks: Record<string, string> = {
        road_cycling: 'road_biking',
        mtb_cycling: 'mtb_biking',
    };

    return fallbackKeys
        .map((key) => {
            const streak =
                props.streaks.by_sport[key] ??
                (legacyFallbacks[key]
                    ? props.streaks.by_sport[legacyFallbacks[key]]
                    : undefined);

            if (!streak) {
                return null;
            }

            return {
                key,
                label: resolveSportLabel(key),
                href: sportHref(key),
                accentHex: resolveSportAccentHex(key),
                streak,
            };
        })
        .filter((item): item is NonNullable<typeof item> => item !== null);
});

function openCreateForm(): void {
    if (isFormOpen.value && editingGoalId.value === null) {
        closeForm();

        return;
    }

    editingGoalId.value = null;
    form.clearErrors();
    form.title = '';
    form.sport = 'running';
    form.metric = 'distance_km';
    form.period = 'weekly';
    form.target_value = '';
    form.year = '';
    isFormOpen.value = true;
}

function formatTargetForInput(value: number, metric: GoalMetric): string {
    if (metric === 'distance_km') {
        return String(kmToInputDistance(value));
    }

    if (metric === 'elevation_m') {
        return String(metersToInputElevation(value));
    }

    return String(value);
}

function openEditForm(goal: GoalEvaluation): void {
    editingGoalId.value = goal.id;
    confirmDeleteId.value = null;
    form.clearErrors();
    form.title = goal.title;
    form.sport = normalizeGoalSportKey(goal.sport);
    form.metric = goal.metric;
    form.period = goal.period;
    form.target_value = formatTargetForInput(goal.target_value, goal.metric);
    form.year = goal.year !== null ? String(goal.year) : '';
    isFormOpen.value = true;
}

function closeForm(): void {
    isFormOpen.value = false;
    editingGoalId.value = null;
    form.clearErrors();
    form.reset();
}

function convertInputTargetToMetric(
    rawValue: string,
    metric: GoalMetric,
): number | null {
    if (rawValue === '') {
        return null;
    }

    const numeric = Number(rawValue);

    if (!Number.isFinite(numeric)) {
        return null;
    }

    if (metric === 'distance_km') {
        return inputDistanceToKm(numeric);
    }

    if (metric === 'elevation_m') {
        return inputElevationToMeters(numeric);
    }

    return numeric;
}

function submitForm(): void {
    const trimmedYear = form.year.trim();

    form.transform((data) => ({
        title: data.title.trim(),
        sport: data.sport,
        metric: data.metric,
        period: data.period,
        target_value: convertInputTargetToMetric(
            data.target_value,
            data.metric,
        ),
        year:
            data.period === 'yearly' && trimmedYear !== ''
                ? Number(trimmedYear)
                : null,
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeForm();
        },
    };

    if (editingGoalId.value !== null) {
        form.patch(goalsUpdate.url(editingGoalId.value), options);

        return;
    }

    form.post(goalsStore.url(), options);
}

function toggleArchive(goal: GoalEvaluation): void {
    if (busyGoalId.value !== null) {
        return;
    }

    confirmDeleteId.value = null;

    router.post(
        goalsArchive(goal.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busyGoalId.value = goal.id;
            },
            onFinish: () => {
                busyGoalId.value = null;
            },
        },
    );
}

function requestDelete(goalId: number): void {
    if (confirmDeleteId.value === goalId) {
        executeDelete(goalId);

        return;
    }

    confirmDeleteId.value = goalId;
}

function executeDelete(goalId: number): void {
    if (busyGoalId.value !== null) {
        return;
    }

    router.delete(goalsDestroy(goalId), {
        preserveScroll: true,
        onStart: () => {
            busyGoalId.value = goalId;
        },
        onSuccess: () => {
            if (editingGoalId.value === goalId) {
                closeForm();
            }
        },
        onFinish: () => {
            busyGoalId.value = null;
            confirmDeleteId.value = null;
        },
    });
}

function goalAccentHex(sport: GoalSport): string {
    if (sport === 'all') {
        return '#6366f1';
    }

    return resolveSportAccentHex(sport);
}

function goalProgressBarColor(goal: GoalEvaluation): string {
    if (goal.pace_status === 'completed') {
        return '#10b981';
    }

    return goalAccentHex(goal.sport);
}

function sportLabel(sport: GoalSport): string {
    if (sport === 'all') {
        return 'All Sports';
    }

    return resolveSportLabel(sport);
}

function paceBadgeClass(status: PaceStatus): string {
    if (status === 'completed' || status === 'ahead') {
        return 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300';
    }

    if (status === 'on_track') {
        return 'bg-sky-500/12 text-sky-700 dark:text-sky-300';
    }

    return 'bg-amber-500/12 text-amber-700 dark:text-amber-300';
}

function goalUnit(goal: GoalEvaluation): string {
    if (goal.metric === 'distance_km') {
        return distanceUnit.value;
    }

    if (goal.metric === 'elevation_m') {
        return elevationUnit.value;
    }

    if (goal.metric === 'duration_hours') {
        return 'h';
    }

    if (goal.metric === 'activity_count') {
        return 'sessions';
    }

    return goal.unit;
}

function formatGoalValue(value: number, metric: GoalMetric): string {
    if (metric === 'distance_km') {
        const converted = isImperial.value
            ? distanceFromMeters(value * 1000)
            : value;

        return Number.isInteger(converted)
            ? formatNumber(converted, 0)
            : formatNumber(converted, 1);
    }

    if (metric === 'elevation_m') {
        const converted = isImperial.value ? elevationFromMeters(value) : value;

        return formatNumber(Math.round(converted), 0);
    }

    if (metric === 'activity_count') {
        return formatNumber(Math.round(value), 0);
    }

    return Number.isInteger(value)
        ? formatNumber(value, 0)
        : formatNumber(value, 1);
}

function formatGoalPaceLabel(goal: GoalEvaluation): string {
    if (
        !isImperial.value ||
        (goal.metric !== 'distance_km' && goal.metric !== 'elevation_m')
    ) {
        return goal.pace_label;
    }

    if (goal.pace_status === 'completed' || goal.pace_status === 'on_track') {
        return goal.pace_label;
    }

    const absDelta = Math.abs(goal.delta);
    const formattedDelta =
        goal.metric === 'distance_km'
            ? formatNumber(distanceFromMeters(absDelta * 1000), 1)
            : formatNumber(Math.round(elevationFromMeters(absDelta)), 0);
    const unit = goalUnit(goal);

    if (goal.pace_status === 'ahead') {
        return `On track · +${formattedDelta} ${unit} ahead`;
    }

    return `Behind · ${formattedDelta} ${unit} behind`;
}

function clampedPct(value: number): number {
    return Math.min(100, Math.max(0, value));
}

function formatDaysRemaining(days: number): string {
    if (days <= 0) {
        return 'Final day';
    }

    return days === 1 ? '1d left' : `${days}d left`;
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Goals & Streaks" />

        <!-- Studio Telemetry Header -->
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <p
                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Planning &amp; Equipment
                </p>
                <h1
                    class="mt-1 text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    Goals &amp; Streaks
                </h1>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                    @click="openCreateForm"
                >
                    <X
                        v-if="isFormOpen && editingGoalId === null"
                        class="size-3.5"
                    />
                    <Plus v-else class="size-3.5" />
                    <span>{{
                        isFormOpen && editingGoalId === null
                            ? 'Close Form'
                            : 'New Goal'
                    }}</span>
                </button>
            </div>
        </header>

        <!-- Active Streaks Hero Strip -->
        <section class="space-y-3">
            <div class="grid gap-4 md:grid-cols-3">
                <!-- Current Daily Streak -->
                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-12 bg-amber-500"
                    />
                    <div class="flex items-start justify-between gap-2">
                        <p
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Current Daily Streak
                        </p>
                        <span
                            class="flex size-7 items-center justify-center rounded-lg bg-amber-500/12 text-amber-600 dark:text-amber-400"
                        >
                            <Flame class="size-3.5" />
                        </span>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span
                            class="text-3xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatNumber(streaks.current_daily_streak, 0) }}
                        </span>
                        <span
                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                streaks.current_daily_streak === 1
                                    ? 'day'
                                    : 'days'
                            }}
                        </span>
                    </div>
                    <p
                        v-if="streaks.current_daily_streak === 0"
                        class="mt-2 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Log an activity to start a streak
                    </p>
                </div>

                <!-- Longest Daily Streak -->
                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-12 bg-indigo-500"
                    />
                    <div class="flex items-start justify-between gap-2">
                        <p
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Longest Daily Streak
                        </p>
                        <span
                            class="flex size-7 items-center justify-center rounded-lg bg-indigo-500/12 text-indigo-600 dark:text-indigo-400"
                        >
                            <Trophy class="size-3.5" />
                        </span>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span
                            class="text-3xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatNumber(streaks.longest_daily_streak, 0) }}
                        </span>
                        <span
                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                streaks.longest_daily_streak === 1
                                    ? 'day'
                                    : 'days'
                            }}
                        </span>
                    </div>
                </div>

                <!-- Current Weekly Streak -->
                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-12 bg-emerald-500"
                    />
                    <div class="flex items-start justify-between gap-2">
                        <p
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Current Weekly Streak
                        </p>
                        <span
                            class="flex size-7 items-center justify-center rounded-lg bg-emerald-500/12 text-emerald-600 dark:text-emerald-400"
                        >
                            <CalendarRange class="size-3.5" />
                        </span>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span
                            class="text-3xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatNumber(streaks.current_weekly_streak, 0) }}
                        </span>
                        <span
                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                streaks.current_weekly_streak === 1
                                    ? 'week'
                                    : 'weeks'
                            }}
                        </span>
                    </div>
                    <p
                        class="mt-2 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        Best:
                        {{ formatNumber(streaks.longest_weekly_streak, 0) }}
                        {{ streaks.longest_weekly_streak === 1 ? 'wk' : 'wks' }}
                        · Mon–Sun
                    </p>
                </div>
            </div>

            <!-- Per-Sport Weekly Streak Pills -->
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    v-for="pill in sportStreakPills"
                    :key="pill.key"
                    :href="pill.href"
                    prefetch
                    class="inline-flex items-center gap-2.5 rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs transition-colors hover:border-zinc-400 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-600"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{ backgroundColor: pill.accentHex }"
                    />
                    <span class="font-medium text-zinc-800 dark:text-zinc-200">
                        {{ pill.label }}
                    </span>
                    <span
                        class="font-mono text-[11px] font-medium text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ pill.streak.current_weekly_streak }}w
                    </span>
                    <span
                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        best {{ pill.streak.longest_weekly_streak }}w
                    </span>
                </Link>
            </div>
        </section>

        <!-- Inline Collapsible Create / Edit Goal Form -->
        <section
            v-if="isFormOpen"
            class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <span
                class="absolute top-0 left-0 h-0.5 w-16"
                :style="{ backgroundColor: goalAccentHex(form.sport) }"
            />

            <div class="flex items-center justify-between gap-3">
                <div>
                    <p
                        class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{
                            editingGoalId !== null
                                ? 'Edit Target'
                                : 'New Target'
                        }}
                    </p>
                    <h2
                        class="mt-0.5 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        {{
                            editingGoalId !== null
                                ? 'Update Custom Goal'
                                : 'Create Custom Goal'
                        }}
                    </h2>
                </div>

                <button
                    type="button"
                    class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    aria-label="Close goal form"
                    @click="closeForm"
                >
                    <X class="size-4" />
                </button>
            </div>

            <form
                class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-6"
                @submit.prevent="submitForm"
            >
                <!-- Title -->
                <div class="sm:col-span-2 lg:col-span-2">
                    <label
                        for="goal-title"
                        class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Title
                    </label>
                    <input
                        id="goal-title"
                        v-model="form.title"
                        type="text"
                        maxlength="120"
                        required
                        placeholder="e.g. Weekly Running Distance"
                        class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-500"
                    />
                    <p
                        v-if="form.errors.title"
                        class="mt-1 font-mono text-[11px] text-rose-600 dark:text-rose-400"
                    >
                        {{ form.errors.title }}
                    </p>
                </div>

                <!-- Sport -->
                <div>
                    <label
                        for="goal-sport"
                        class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Sport
                    </label>
                    <select
                        id="goal-sport"
                        v-model="form.sport"
                        class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900/70 dark:text-zinc-100 dark:focus:border-zinc-500"
                    >
                        <option
                            v-for="option in sportOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.sport"
                        class="mt-1 font-mono text-[11px] text-rose-600 dark:text-rose-400"
                    >
                        {{ form.errors.sport }}
                    </p>
                </div>

                <!-- Metric -->
                <div>
                    <label
                        for="goal-metric"
                        class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Metric
                    </label>
                    <select
                        id="goal-metric"
                        v-model="form.metric"
                        class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900/70 dark:text-zinc-100 dark:focus:border-zinc-500"
                    >
                        <option
                            v-for="option in metricOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.metric"
                        class="mt-1 font-mono text-[11px] text-rose-600 dark:text-rose-400"
                    >
                        {{ form.errors.metric }}
                    </p>
                </div>

                <!-- Period -->
                <div>
                    <label
                        for="goal-period"
                        class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Period
                    </label>
                    <select
                        id="goal-period"
                        v-model="form.period"
                        class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900/70 dark:text-zinc-100 dark:focus:border-zinc-500"
                    >
                        <option
                            v-for="option in PERIOD_OPTIONS"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <p
                        v-if="form.errors.period"
                        class="mt-1 font-mono text-[11px] text-rose-600 dark:text-rose-400"
                    >
                        {{ form.errors.period }}
                    </p>
                </div>

                <!-- Target Value -->
                <div>
                    <label
                        for="goal-target"
                        class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Target ({{ selectedMetricMeta.unit }})
                    </label>
                    <div class="relative mt-1.5">
                        <input
                            id="goal-target"
                            v-model="form.target_value"
                            type="number"
                            :step="selectedMetricMeta.step"
                            min="0.1"
                            max="1000000"
                            required
                            :placeholder="selectedMetricMeta.placeholder"
                            class="w-full rounded-lg border border-zinc-200 bg-white py-2 pr-14 pl-3 font-mono text-sm text-zinc-900 tabular-nums placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-500"
                        />
                        <span
                            class="pointer-events-none absolute inset-y-0 right-3 flex items-center font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{ selectedMetricMeta.unit }}
                        </span>
                    </div>
                    <p
                        v-if="form.errors.target_value"
                        class="mt-1 font-mono text-[11px] text-rose-600 dark:text-rose-400"
                    >
                        {{ form.errors.target_value }}
                    </p>
                </div>

                <!-- Optional Year (Yearly Period Only) -->
                <div v-if="form.period === 'yearly'" class="sm:col-span-1">
                    <label
                        for="goal-year"
                        class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Year (Optional)
                    </label>
                    <input
                        id="goal-year"
                        v-model="form.year"
                        type="number"
                        min="2000"
                        max="2100"
                        placeholder="Auto (current)"
                        class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 font-mono text-sm text-zinc-900 tabular-nums placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-500"
                    />
                    <p
                        v-if="form.errors.year"
                        class="mt-1 font-mono text-[11px] text-rose-600 dark:text-rose-400"
                    >
                        {{ form.errors.year }}
                    </p>
                </div>

                <!-- Form Actions -->
                <div
                    :class="[
                        'flex items-end justify-end gap-2',
                        form.period === 'yearly'
                            ? 'sm:col-span-1 lg:col-span-5'
                            : 'sm:col-span-2 lg:col-span-6',
                    ]"
                >
                    <button
                        type="button"
                        class="cursor-pointer rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="closeForm"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                    >
                        <Check class="size-3.5" />
                        <span>
                            {{
                                form.processing
                                    ? 'Saving...'
                                    : editingGoalId !== null
                                      ? 'Save Changes'
                                      : 'Create Goal'
                            }}
                        </span>
                    </button>
                </div>
            </form>
        </section>

        <!-- Active Goals Grouped by Period -->
        <section v-if="groupedActiveGoals.length > 0" class="space-y-6">
            <div
                v-for="group in groupedActiveGoals"
                :key="group.period"
                class="space-y-3"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <h2
                            class="font-mono text-[11px] font-medium tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            {{ group.heading }}
                        </h2>
                        <span
                            class="rounded-md bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[10px] font-medium text-zinc-600 tabular-nums dark:bg-zinc-800 dark:text-zinc-400"
                        >
                            {{ group.goals.length }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="goal in group.goals"
                        :key="goal.id"
                        class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    >
                        <!-- Sport Accent Bar -->
                        <span
                            class="absolute top-0 left-0 h-0.5 w-14"
                            :style="{
                                backgroundColor: goalAccentHex(goal.sport),
                            }"
                        />

                        <div>
                            <!-- Card Meta & Controls -->
                            <div class="flex items-start justify-between gap-3">
                                <div
                                    class="flex flex-wrap items-center gap-2 font-mono text-[10px] tracking-wider uppercase"
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"
                                    >
                                        <span
                                            class="size-1.5 rounded-full"
                                            :style="{
                                                backgroundColor: goalAccentHex(
                                                    goal.sport,
                                                ),
                                            }"
                                        />
                                        {{ sportLabel(goal.sport) }}
                                    </span>
                                    <span
                                        class="text-zinc-300 dark:text-zinc-700"
                                        >·</span
                                    >
                                    <span
                                        class="text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{ goal.period_label }}
                                    </span>
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    <template
                                        v-if="confirmDeleteId === goal.id"
                                    >
                                        <button
                                            type="button"
                                            class="inline-flex h-7 cursor-pointer items-center justify-center rounded-md border border-zinc-200 bg-white px-2 font-mono text-[10px] font-medium text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                            @click="confirmDeleteId = null"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="busyGoalId === goal.id"
                                            class="inline-flex h-7 cursor-pointer items-center justify-center rounded-md bg-rose-500/15 px-2 font-mono text-[10px] font-medium text-rose-600 transition-colors hover:bg-rose-500/25 disabled:opacity-50 dark:text-rose-400"
                                            aria-label="Confirm delete goal"
                                            title="Click to confirm delete"
                                            @click="requestDelete(goal.id)"
                                        >
                                            Delete?
                                        </button>
                                    </template>
                                    <template v-else>
                                        <button
                                            type="button"
                                            class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                            aria-label="Edit goal"
                                            title="Edit goal"
                                            @click="openEditForm(goal)"
                                        >
                                            <Pencil class="size-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="busyGoalId === goal.id"
                                            class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 disabled:opacity-50 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                            aria-label="Archive goal"
                                            title="Archive goal"
                                            @click="toggleArchive(goal)"
                                        >
                                            <Archive class="size-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="busyGoalId === goal.id"
                                            class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-rose-500/10 hover:text-rose-600 disabled:opacity-50 dark:hover:text-rose-400"
                                            aria-label="Delete goal"
                                            title="Delete goal"
                                            @click="requestDelete(goal.id)"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <!-- Title -->
                            <h3
                                class="mt-2 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                {{ goal.title }}
                            </h3>

                            <!-- Big Readout -->
                            <div
                                class="mt-4 flex items-baseline justify-between gap-3"
                            >
                                <div class="flex items-baseline gap-1.5">
                                    <span
                                        class="text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums sm:text-3xl dark:text-zinc-100"
                                    >
                                        {{
                                            formatGoalValue(
                                                goal.current_value,
                                                goal.metric,
                                            )
                                        }}
                                    </span>
                                    <span
                                        class="font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                                    >
                                        /
                                        {{
                                            formatGoalValue(
                                                goal.target_value,
                                                goal.metric,
                                            )
                                        }}
                                        {{ goalUnit(goal) }}
                                    </span>
                                </div>

                                <span
                                    class="font-mono text-xs font-medium text-zinc-700 tabular-nums dark:text-zinc-300"
                                >
                                    {{ formatNumber(goal.pct, 0) }}%
                                </span>
                            </div>

                            <!-- Progress Bar + Expected Schedule Tick Mark -->
                            <div class="mt-3">
                                <div
                                    class="relative h-2 w-full rounded-full bg-zinc-100 dark:bg-zinc-800/90"
                                >
                                    <div
                                        class="h-full rounded-full transition-all duration-300"
                                        :style="{
                                            width: `${clampedPct(goal.pct)}%`,
                                            backgroundColor:
                                                goalProgressBarColor(goal),
                                        }"
                                    />
                                    <!-- Expected schedule today tick -->
                                    <div
                                        v-if="goal.expected_pct > 0"
                                        class="pointer-events-none absolute -top-1 -bottom-1 w-0.5 -translate-x-1/2 rounded-full bg-zinc-900/80 dark:bg-zinc-100/85"
                                        :style="{
                                            left: `${clampedPct(goal.expected_pct)}%`,
                                        }"
                                        :title="`Expected today: ${formatGoalValue(goal.expected_value, goal.metric)} ${goalUnit(goal)} (${goal.expected_pct}%)`"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer: Pace Pill & Days Remaining -->
                        <div
                            class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800/80"
                        >
                            <span
                                :class="[
                                    'inline-flex items-center rounded-md px-2 py-0.5 font-mono text-[11px] font-medium tabular-nums',
                                    paceBadgeClass(goal.pace_status),
                                ]"
                            >
                                {{ formatGoalPaceLabel(goal) }}
                            </span>

                            <span
                                class="font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                            >
                                {{ formatDaysRemaining(goal.days_remaining) }}
                                <template v-if="goal.remaining_value > 0">
                                    ·
                                    {{
                                        formatGoalValue(
                                            goal.remaining_value,
                                            goal.metric,
                                        )
                                    }}
                                    {{ goalUnit(goal) }} left
                                </template>
                            </span>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <!-- Empty State -->
        <section
            v-else
            class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 bg-white px-6 py-12 text-center dark:border-zinc-800 dark:bg-[#141417]"
        >
            <span
                class="flex size-10 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
            >
                <Target class="size-5" />
            </span>
            <h2
                class="mt-3 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
            >
                No active training goals yet
            </h2>
            <p class="mt-1 max-w-md text-xs text-zinc-500 dark:text-zinc-400">
                Add a weekly, monthly, or yearly goal to track your progress.
            </p>
            <button
                type="button"
                class="mt-4 inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                @click="openCreateForm"
            >
                <Plus class="size-3.5" />
                <span>Create First Goal</span>
            </button>
        </section>

        <!-- Collapsible Archived Goals Section -->
        <section
            v-if="archived.length > 0"
            class="rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <button
                type="button"
                class="flex w-full cursor-pointer items-center justify-between px-5 py-3.5 text-left transition-colors hover:bg-zinc-50/70 dark:hover:bg-zinc-900/40"
                @click="archivedOpen = !archivedOpen"
            >
                <div class="flex items-center gap-2">
                    <ChevronDown
                        v-if="archivedOpen"
                        class="size-4 text-zinc-400"
                    />
                    <ChevronRight v-else class="size-4 text-zinc-400" />
                    <span
                        class="font-mono text-[11px] font-medium tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Archived Goals
                    </span>
                    <span
                        class="rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] font-medium text-zinc-500 tabular-nums dark:bg-zinc-800 dark:text-zinc-400"
                    >
                        {{ archived.length }}
                    </span>
                </div>
                <span
                    class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                >
                    {{ archivedOpen ? 'Hide' : 'Show' }}
                </span>
            </button>

            <div
                v-if="archivedOpen"
                class="divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800/80 dark:border-zinc-800/80"
            >
                <div
                    v-for="goal in archived"
                    :key="goal.id"
                    class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="size-1.5 rounded-full opacity-70"
                                :style="{
                                    backgroundColor: goalAccentHex(goal.sport),
                                }"
                            />
                            <span
                                class="truncate text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                {{ goal.title }}
                            </span>
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                            >
                                {{ sportLabel(goal.sport) }} ·
                                {{ goal.period_label }}
                            </span>
                        </div>
                        <p
                            class="mt-1 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{
                                formatGoalValue(goal.current_value, goal.metric)
                            }}
                            /
                            {{
                                formatGoalValue(goal.target_value, goal.metric)
                            }}
                            {{ goalUnit(goal) }} ({{
                                formatNumber(goal.pct, 0)
                            }}%)
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <template v-if="confirmDeleteId === goal.id">
                            <button
                                type="button"
                                class="inline-flex cursor-pointer items-center rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                @click="confirmDeleteId = null"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                :disabled="busyGoalId === goal.id"
                                class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-transparent bg-rose-500/15 px-2.5 py-1 text-xs font-medium text-rose-600 transition-colors hover:bg-rose-500/25 disabled:opacity-50 dark:text-rose-400"
                                @click="requestDelete(goal.id)"
                            >
                                <Trash2 class="size-3.5" />
                                <span>Confirm Delete</span>
                            </button>
                        </template>
                        <template v-else>
                            <button
                                type="button"
                                :disabled="busyGoalId === goal.id"
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                @click="toggleArchive(goal)"
                            >
                                <ArchiveRestore class="size-3.5" />
                                <span>Restore</span>
                            </button>
                            <button
                                type="button"
                                :disabled="busyGoalId === goal.id"
                                class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-500 transition-colors hover:border-rose-300 hover:text-rose-600 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:border-rose-500/40 dark:hover:text-rose-400"
                                @click="requestDelete(goal.id)"
                            >
                                <Trash2 class="size-3.5" />
                                <span>Delete</span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
