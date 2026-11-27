<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Archive,
    ArrowUpRight,
    BatteryCharging,
    Bike,
    Calendar,
    ChevronDown,
    ChevronUp,
    Compass,
    Footprints,
    Mountain,
    Pencil,
    Plus,
    RotateCcw,
    Trash,
    TriangleExclamationPoint,
    Wrench,
    X,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import {
    formatDurationClock,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import { show as activitiesShow } from '@/routes/activities';
import {
    destroy as gearDestroy,
    retire as gearRetire,
    store as gearStore,
    update as gearUpdate,
} from '@/routes/gear';

type GearType =
    | 'running_shoe'
    | 'trail_shoe'
    | 'road_bike'
    | 'gravel_bike'
    | 'mtb_bike'
    | 'ebike'
    | 'indoor_trainer'
    | 'other';
type AlertStatus = 'ok' | 'warning' | 'exceeded';

interface GearRecentActivity {
    id: number;
    name: string;
    started_at_local: string;
    distance_meters: number;
    duration_seconds: number;
    sport_category: string;
    effective_discipline: string | null;
}

interface GearItemRecord {
    id: number;
    name: string;
    brand: string | null;
    model: string | null;
    type: GearType | string;
    default_sport: string | null;
    initial_distance_meters: number;
    logged_distance_meters: number;
    total_distance_meters: number;
    max_distance_meters: number | null;
    usage_pct: number | null;
    alert_status: AlertStatus;
    activity_count: number;
    purchased_at: string | null;
    retired_at: string | null;
    is_retired: boolean;
    notes: string | null;
    recent_activities: GearRecentActivity[];
}

interface GearSummary {
    active_count: number;
    retired_count: number;
    alerts_count: number;
    total_distance_meters: number;
}

interface GearIndexProps {
    active: GearItemRecord[];
    retired: GearItemRecord[];
    summary: GearSummary;
}

const props = defineProps<GearIndexProps>();

const {
    getSportMeta,
    resolveActivitySportKey,
    sportAccentHex,
    sportBadgeClasses,
    sportLabel,
    sportShortLabel,
} = useSports();

const {
    isImperial,
    distanceUnit,
    distanceFromMeters,
    kmToInputDistance,
    inputDistanceToKm,
    formatDistanceCompact,
} = useUnits();

const activeTab = ref<'active' | 'retired'>('active');
const isFormOpen = ref<boolean>(false);
const editingItem = ref<GearItemRecord | null>(null);
const expandedIds = ref<number[]>([]);
const pendingDeleteId = ref<number | null>(null);
const busyItemId = ref<number | null>(null);

const LEGACY_SPORT_NORMALIZATION: Record<string, string> = {
    biking: 'cycling',
    road_biking: 'road_cycling',
    mtb_biking: 'mtb_cycling',
};

const GEAR_DEFAULT_SPORT_KEYS: string[] = [
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

const form = useForm<{
    name: string;
    brand: string;
    model: string;
    type: GearType;
    default_sport: string;
    initial_distance_km: string;
    max_distance_km: string;
    purchased_at: string;
    notes: string;
}>({
    name: '',
    brand: '',
    model: '',
    type: 'running_shoe',
    default_sport: '',
    initial_distance_km: '0',
    max_distance_km: '600',
    purchased_at: '',
    notes: '',
});

const typeOptions: Array<{
    value: GearType;
    label: string;
    suggestedMaxKm: number | null;
}> = [
    { value: 'running_shoe', label: 'Running Shoe', suggestedMaxKm: 600 },
    { value: 'trail_shoe', label: 'Trail Shoe', suggestedMaxKm: 500 },
    { value: 'road_bike', label: 'Road Bike', suggestedMaxKm: 3000 },
    { value: 'gravel_bike', label: 'Gravel Bike', suggestedMaxKm: 3000 },
    { value: 'mtb_bike', label: 'Mountain Bike', suggestedMaxKm: 2500 },
    { value: 'ebike', label: 'E-Bike', suggestedMaxKm: 3500 },
    { value: 'indoor_trainer', label: 'Indoor Trainer', suggestedMaxKm: 5000 },
    { value: 'other', label: 'Other', suggestedMaxKm: null },
];

const defaultSportOptions = computed<Array<{ value: string; label: string }>>(
    () => [
        { value: '', label: 'None (manual assignment)' },
        ...GEAR_DEFAULT_SPORT_KEYS.map((key) => ({
            value: key,
            label: `${sportLabel(key)} (auto-assign)`,
        })),
    ],
);

const visibleItems = computed<GearItemRecord[]>(() =>
    activeTab.value === 'active' ? props.active : props.retired,
);

const isEditing = computed<boolean>(() => editingItem.value !== null);

function formatSuggestedMaxInput(suggestedKm: number | null): string {
    if (suggestedKm === null) {
        return '';
    }

    if (isImperial.value) {
        return String(Math.round(kmToInputDistance(suggestedKm)));
    }

    return String(suggestedKm);
}

function formatDistanceInputFromMeters(meters: number): string {
    const converted = kmToInputDistance(meters / 1000);

    return Number.isInteger(converted)
        ? String(converted)
        : String(Number(converted.toFixed(1)));
}

function openCreateForm(): void {
    editingItem.value = null;
    form.clearErrors();
    form.name = '';
    form.brand = '';
    form.model = '';
    form.type = 'running_shoe';
    form.default_sport = '';
    form.initial_distance_km = '0';
    form.max_distance_km = formatSuggestedMaxInput(600);
    form.purchased_at = '';
    form.notes = '';
    isFormOpen.value = true;
}

function openEditForm(item: GearItemRecord): void {
    editingItem.value = item;
    form.clearErrors();
    form.name = item.name;
    form.brand = item.brand ?? '';
    form.model = item.model ?? '';
    form.type = isGearType(item.type) ? item.type : 'other';
    form.default_sport = item.default_sport
        ? (LEGACY_SPORT_NORMALIZATION[item.default_sport] ?? item.default_sport)
        : '';
    form.initial_distance_km = formatDistanceInputFromMeters(
        item.initial_distance_meters,
    );
    form.max_distance_km =
        item.max_distance_meters !== null
            ? formatDistanceInputFromMeters(item.max_distance_meters)
            : '';
    form.purchased_at = item.purchased_at ?? '';
    form.notes = item.notes ?? '';
    isFormOpen.value = true;

    if (typeof window !== 'undefined') {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function closeForm(): void {
    isFormOpen.value = false;
    editingItem.value = null;
    form.clearErrors();
}

function handleTypeChange(nextType: GearType): void {
    form.type = nextType;

    if (!isEditing.value) {
        const option = typeOptions.find((entry) => entry.value === nextType);

        if (option) {
            form.max_distance_km = formatSuggestedMaxInput(
                option.suggestedMaxKm,
            );
        }
    }
}

function submitForm(): void {
    const payload = {
        name: form.name.trim(),
        brand: form.brand.trim() || null,
        model: form.model.trim() || null,
        type: form.type,
        default_sport: form.default_sport || null,
        initial_distance_km:
            form.initial_distance_km !== ''
                ? inputDistanceToKm(Number(form.initial_distance_km))
                : 0,
        max_distance_km:
            form.max_distance_km !== ''
                ? inputDistanceToKm(Number(form.max_distance_km))
                : null,
        purchased_at: form.purchased_at || null,
        notes: form.notes.trim() || null,
    };

    const target = editingItem.value;

    if (target !== null) {
        form.transform(() => payload).patch(gearUpdate.url(target.id), {
            preserveScroll: true,
            onSuccess: () => {
                closeForm();
            },
        });

        return;
    }

    form.transform(() => payload).post(gearStore.url(), {
        preserveScroll: true,
        onSuccess: () => {
            closeForm();
            activeTab.value = 'active';
        },
    });
}

function toggleRetire(item: GearItemRecord): void {
    if (busyItemId.value !== null) {
        return;
    }

    pendingDeleteId.value = null;

    router.post(
        gearRetire(item.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busyItemId.value = item.id;
            },
            onFinish: () => {
                busyItemId.value = null;
            },
        },
    );
}

function requestDelete(itemId: number): void {
    pendingDeleteId.value = pendingDeleteId.value === itemId ? null : itemId;
}

function confirmDelete(item: GearItemRecord): void {
    if (busyItemId.value !== null) {
        return;
    }

    router.delete(gearDestroy(item.id), {
        preserveScroll: true,
        onStart: () => {
            busyItemId.value = item.id;
        },
        onSuccess: () => {
            pendingDeleteId.value = null;

            if (editingItem.value?.id === item.id) {
                closeForm();
            }
        },
        onFinish: () => {
            busyItemId.value = null;
        },
    });
}

function toggleExpanded(itemId: number): void {
    if (expandedIds.value.includes(itemId)) {
        expandedIds.value = expandedIds.value.filter((id) => id !== itemId);
    } else {
        expandedIds.value = [...expandedIds.value, itemId];
    }
}

function isExpanded(itemId: number): boolean {
    return expandedIds.value.includes(itemId);
}

function isGearType(value: string): value is GearType {
    return (
        value === 'running_shoe' ||
        value === 'trail_shoe' ||
        value === 'road_bike' ||
        value === 'gravel_bike' ||
        value === 'mtb_bike' ||
        value === 'ebike' ||
        value === 'indoor_trainer' ||
        value === 'other'
    );
}

function gearAccentBarClass(type: string): string {
    switch (type) {
        case 'running_shoe':
            return 'bg-amber-500';
        case 'trail_shoe':
            return 'bg-orange-500';
        case 'road_bike':
            return 'bg-sky-500';
        case 'gravel_bike':
            return 'bg-teal-500';
        case 'mtb_bike':
            return 'bg-emerald-500';
        case 'ebike':
            return 'bg-violet-500';
        case 'indoor_trainer':
            return 'bg-indigo-500';
        default:
            return 'bg-zinc-400';
    }
}

function gearIconBadgeClass(type: string): string {
    switch (type) {
        case 'running_shoe':
            return 'bg-amber-500/12 text-amber-600 dark:text-amber-400';
        case 'trail_shoe':
            return 'bg-orange-500/12 text-orange-600 dark:text-orange-400';
        case 'road_bike':
            return 'bg-sky-500/12 text-sky-600 dark:text-sky-400';
        case 'gravel_bike':
            return 'bg-teal-500/12 text-teal-600 dark:text-teal-400';
        case 'mtb_bike':
            return 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-400';
        case 'ebike':
            return 'bg-violet-500/12 text-violet-600 dark:text-violet-400';
        case 'indoor_trainer':
            return 'bg-indigo-500/12 text-indigo-600 dark:text-indigo-400';
        default:
            return 'bg-zinc-500/12 text-zinc-600 dark:text-zinc-400';
    }
}

function gearIconComponent(type: string): Component {
    switch (type) {
        case 'running_shoe':
            return Footprints;
        case 'trail_shoe':
            return Mountain;
        case 'road_bike':
            return Bike;
        case 'gravel_bike':
            return Compass;
        case 'mtb_bike':
            return Mountain;
        case 'ebike':
            return BatteryCharging;
        case 'indoor_trainer':
            return Zap;
        default:
            return Wrench;
    }
}

function gearTypeLabel(type: string): string {
    switch (type) {
        case 'running_shoe':
            return 'Running Shoe';
        case 'trail_shoe':
            return 'Trail Shoe';
        case 'road_bike':
            return 'Road Bike';
        case 'gravel_bike':
            return 'Gravel Bike';
        case 'mtb_bike':
            return 'Mountain Bike';
        case 'ebike':
            return 'E-Bike';
        case 'indoor_trainer':
            return 'Indoor Trainer';
        default:
            return 'Other Gear';
    }
}

function defaultSportLabel(sport: string | null): string | null {
    if (!sport) {
        return null;
    }

    return `Default · ${sportShortLabel(sport)}`;
}

function defaultSportBadgeClass(sport: string | null): string {
    if (!sport) {
        return 'border-zinc-200 bg-zinc-100 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
    }

    return sportBadgeClasses(getSportMeta(sport).color_token);
}

function progressBarClass(item: GearItemRecord): string {
    if (item.alert_status === 'exceeded') {
        return 'bg-rose-500';
    }

    if (item.alert_status === 'warning') {
        return 'bg-amber-500';
    }

    return gearAccentBarClass(item.type);
}

function progressWidthStyle(usagePct: number | null): string {
    if (usagePct === null || usagePct <= 0) {
        return '0%';
    }

    return `${Math.min(100, usagePct)}%`;
}

function brandModelSubtitle(item: GearItemRecord): string | null {
    const parts = [item.brand, item.model].filter(
        (value): value is string => value !== null && value.trim() !== '',
    );

    return parts.length > 0 ? parts.join(' · ') : null;
}

function remainingDistanceLabel(item: GearItemRecord): string | null {
    if (item.max_distance_meters === null || item.max_distance_meters <= 0) {
        return null;
    }

    const diffMeters = item.max_distance_meters - item.total_distance_meters;
    const absConverted = distanceFromMeters(Math.abs(diffMeters));

    if (diffMeters >= 0) {
        return `${formatNumber(absConverted, 1)} ${distanceUnit.value} remaining`;
    }

    return `${formatNumber(absConverted, 1)} ${distanceUnit.value} over threshold`;
}

function activitySportAccentHex(activity: GearRecentActivity): string {
    const sportKey = resolveActivitySportKey(
        activity.sport_category,
        activity.effective_discipline,
    );

    return sportAccentHex(sportKey);
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Gear Tracking" />

        <!-- Header -->
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    Gear Tracking
                </h1>
            </div>

            <button
                type="button"
                class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                @click="
                    isFormOpen && !isEditing ? closeForm() : openCreateForm()
                "
            >
                <X v-if="isFormOpen && !isEditing" class="size-3.5" />
                <Plus v-else class="size-3.5" />
                <span>{{
                    isFormOpen && !isEditing ? 'Close Form' : 'Add Gear'
                }}</span>
            </button>
        </header>

        <!-- 4-Card Summary Strip -->
        <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span class="absolute top-0 left-0 h-0.5 w-10 bg-emerald-500" />
                <p
                    class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Active Gear
                </p>
                <p
                    class="mt-2 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatNumber(summary.active_count, 0) }}
                </p>
            </div>

            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    :class="[
                        'absolute top-0 left-0 h-0.5 w-10',
                        summary.alerts_count > 0
                            ? 'bg-amber-500'
                            : 'bg-zinc-300 dark:bg-zinc-700',
                    ]"
                />
                <p
                    class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Maintenance Alerts
                </p>
                <p
                    :class="[
                        'mt-2 text-2xl font-semibold tracking-tight tabular-nums',
                        summary.alerts_count > 0
                            ? 'text-amber-600 dark:text-amber-400'
                            : 'text-zinc-900 dark:text-zinc-100',
                    ]"
                >
                    {{ formatNumber(summary.alerts_count, 0) }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                >
                    {{
                        summary.alerts_count > 0
                            ? 'At or above 85% limit'
                            : 'All within service limit'
                    }}
                </p>
            </div>

            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10 bg-zinc-400 dark:bg-zinc-600"
                />
                <p
                    class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Retired Gear
                </p>
                <p
                    class="mt-2 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatNumber(summary.retired_count, 0) }}
                </p>
            </div>

            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span class="absolute top-0 left-0 h-0.5 w-10 bg-sky-500" />
                <p
                    class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Total Logged {{ distanceUnit }}
                </p>
                <p
                    class="mt-2 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{
                        formatNumber(
                            distanceFromMeters(summary.total_distance_meters),
                            1,
                        )
                    }}
                    <span
                        class="font-mono text-xs font-normal text-zinc-500 dark:text-zinc-400"
                    >
                        {{ distanceUnit }}
                    </span>
                </p>
            </div>
        </section>

        <!-- Inline Collapsible Add / Edit Gear Form Card -->
        <section
            v-if="isFormOpen"
            class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <span
                :class="[
                    'absolute top-0 left-0 h-0.5 w-16',
                    gearAccentBarClass(form.type),
                ]"
            />

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2
                        class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        {{
                            isEditing
                                ? `Edit ${editingItem?.name}`
                                : 'Add Gear Item'
                        }}
                    </h2>
                </div>

                <button
                    type="button"
                    class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    aria-label="Close gear form"
                    @click="closeForm"
                >
                    <X class="size-4" />
                </button>
            </div>

            <form class="mt-4 space-y-4" @submit.prevent="submitForm">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <!-- Name -->
                    <div>
                        <label
                            for="gear-name"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Name *
                        </label>
                        <input
                            id="gear-name"
                            v-model="form.name"
                            type="text"
                            required
                            maxlength="120"
                            placeholder="e.g. ASICS Novablast 4"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 text-sm text-zinc-900 transition-colors outline-none placeholder:text-zinc-400 focus:border-zinc-400 focus:bg-white dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                        />
                        <p
                            v-if="form.errors.name"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <!-- Brand -->
                    <div>
                        <label
                            for="gear-brand"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Brand
                        </label>
                        <input
                            id="gear-brand"
                            v-model="form.brand"
                            type="text"
                            maxlength="80"
                            placeholder="e.g. ASICS, Canyon, Santa Cruz"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 text-sm text-zinc-900 transition-colors outline-none placeholder:text-zinc-400 focus:border-zinc-400 focus:bg-white dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                        />
                        <p
                            v-if="form.errors.brand"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.brand }}
                        </p>
                    </div>

                    <!-- Model -->
                    <div>
                        <label
                            for="gear-model"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Model
                        </label>
                        <input
                            id="gear-model"
                            v-model="form.model"
                            type="text"
                            maxlength="80"
                            placeholder="e.g. Novablast 4, Aeroad CF SLX"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 text-sm text-zinc-900 transition-colors outline-none placeholder:text-zinc-400 focus:border-zinc-400 focus:bg-white dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                        />
                        <p
                            v-if="form.errors.model"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.model }}
                        </p>
                    </div>

                    <!-- Type -->
                    <div>
                        <label
                            for="gear-type"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Gear Type *
                        </label>
                        <select
                            id="gear-type"
                            :value="form.type"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 text-sm text-zinc-900 transition-colors outline-none focus:border-zinc-400 focus:bg-white dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                            @change="
                                handleTypeChange(
                                    ($event.target as HTMLSelectElement)
                                        .value as GearType,
                                )
                            "
                        >
                            <option
                                v-for="option in typeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.type"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.type }}
                        </p>
                    </div>

                    <!-- Default Sport -->
                    <div>
                        <label
                            for="gear-default-sport"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Default Sport
                        </label>
                        <select
                            id="gear-default-sport"
                            v-model="form.default_sport"
                            :disabled="editingItem?.is_retired === true"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 text-sm text-zinc-900 transition-colors outline-none focus:border-zinc-400 focus:bg-white disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                        >
                            <option
                                v-for="option in defaultSportOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.default_sport"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.default_sport }}
                        </p>
                    </div>

                    <!-- Purchase Date -->
                    <div>
                        <label
                            for="gear-purchased-at"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Purchase Date
                        </label>
                        <input
                            id="gear-purchased-at"
                            v-model="form.purchased_at"
                            type="date"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 font-mono text-sm text-zinc-900 tabular-nums transition-colors outline-none focus:border-zinc-400 focus:bg-white dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                        />
                        <p
                            v-if="form.errors.purchased_at"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.purchased_at }}
                        </p>
                    </div>

                    <!-- Initial Distance Offset -->
                    <div>
                        <label
                            for="gear-initial-km"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Initial Offset ({{ distanceUnit }})
                        </label>
                        <input
                            id="gear-initial-km"
                            v-model="form.initial_distance_km"
                            type="number"
                            step="0.1"
                            min="0"
                            max="200000"
                            placeholder="0"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 font-mono text-sm text-zinc-900 tabular-nums transition-colors outline-none placeholder:text-zinc-400 focus:border-zinc-400 focus:bg-white dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                        />
                        <p
                            v-if="form.errors.initial_distance_km"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.initial_distance_km }}
                        </p>
                    </div>

                    <!-- Max / Service Threshold -->
                    <div>
                        <label
                            for="gear-max-km"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Max / Service Threshold ({{ distanceUnit }})
                        </label>
                        <input
                            id="gear-max-km"
                            v-model="form.max_distance_km"
                            type="number"
                            step="1"
                            min="1"
                            max="500000"
                            :placeholder="isImperial ? 'e.g. 375' : 'e.g. 600'"
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 font-mono text-sm text-zinc-900 tabular-nums transition-colors outline-none placeholder:text-zinc-400 focus:border-zinc-400 focus:bg-white dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                        />
                        <p
                            v-if="form.errors.max_distance_km"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.max_distance_km }}
                        </p>
                    </div>

                    <!-- Notes -->
                    <div class="sm:col-span-2 lg:col-span-1">
                        <label
                            for="gear-notes"
                            class="block font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Notes
                        </label>
                        <input
                            id="gear-notes"
                            v-model="form.notes"
                            type="text"
                            maxlength="1000"
                            placeholder="Chain wax interval, race day pair, tyre setup..."
                            class="mt-1.5 w-full rounded-lg border border-zinc-200 bg-[#f7f7f5] px-3 py-2 text-sm text-zinc-900 transition-colors outline-none placeholder:text-zinc-400 focus:border-zinc-400 focus:bg-white dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:border-zinc-600 dark:focus:bg-[#141417]"
                        />
                        <p
                            v-if="form.errors.notes"
                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                        >
                            {{ form.errors.notes }}
                        </p>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center justify-end gap-2 border-t border-zinc-200/80 pt-4 dark:border-zinc-800/80"
                >
                    <button
                        type="button"
                        class="cursor-pointer rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="closeForm"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="cursor-pointer rounded-lg bg-zinc-900 px-4 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                    >
                        {{
                            form.processing
                                ? 'Saving...'
                                : isEditing
                                  ? 'Save Changes'
                                  : 'Add Gear'
                        }}
                    </button>
                </div>
            </form>
        </section>

        <!-- Active / Retired Tab Switcher -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200/90 pb-3 dark:border-zinc-800/90"
        >
            <div
                class="inline-flex rounded-lg border border-zinc-200/90 bg-white p-0.5 dark:border-zinc-800/90 dark:bg-[#141417]"
                role="tablist"
                aria-label="Gear status"
            >
                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'active'"
                    :class="[
                        'inline-flex cursor-pointer items-center gap-2 rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                        activeTab === 'active'
                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                    ]"
                    @click="activeTab = 'active'"
                >
                    <span>Active</span>
                    <span
                        :class="[
                            'rounded px-1.5 py-0.5 font-mono text-[10px] tabular-nums',
                            activeTab === 'active'
                                ? 'bg-white/20 text-white dark:bg-zinc-900/15 dark:text-zinc-950'
                                : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                        ]"
                    >
                        {{ summary.active_count }}
                    </span>
                </button>

                <button
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'retired'"
                    :class="[
                        'inline-flex cursor-pointer items-center gap-2 rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                        activeTab === 'retired'
                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                    ]"
                    @click="activeTab = 'retired'"
                >
                    <span>Retired</span>
                    <span
                        :class="[
                            'rounded px-1.5 py-0.5 font-mono text-[10px] tabular-nums',
                            activeTab === 'retired'
                                ? 'bg-white/20 text-white dark:bg-zinc-900/15 dark:text-zinc-950'
                                : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                        ]"
                    >
                        {{ summary.retired_count }}
                    </span>
                </button>
            </div>

            <p class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400">
                Default gear auto-assigns to new Garmin activities on sync
            </p>
        </div>

        <!-- Empty State -->
        <section
            v-if="visibleItems.length === 0"
            class="rounded-xl border border-dashed border-zinc-300 bg-white/80 px-6 py-12 text-center dark:border-zinc-800 dark:bg-[#141417]/60"
        >
            <div
                class="mx-auto flex size-10 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
            >
                <Wrench class="size-5" />
            </div>
            <h2
                class="mt-3 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
            >
                {{
                    activeTab === 'active'
                        ? 'No active gear tracked yet'
                        : 'No retired gear yet'
                }}
            </h2>
            <p
                class="mx-auto mt-1 max-w-md text-xs text-zinc-500 dark:text-zinc-400"
            >
                {{
                    activeTab === 'active'
                        ? 'Add shoes or bikes to track mileage and replacement alerts.'
                        : 'Retired shoes and bikes will appear here with their full lifetime mileage and activity history preserved.'
                }}
            </p>
            <button
                v-if="activeTab === 'active'"
                type="button"
                class="mt-4 inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                @click="openCreateForm"
            >
                <Plus class="size-3.5" />
                <span>Add First Gear</span>
            </button>
        </section>

        <!-- Gear Cards Grid -->
        <section v-else class="grid gap-4 lg:grid-cols-2">
            <article
                v-for="item in visibleItems"
                :key="item.id"
                class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    :class="[
                        'absolute top-0 left-0 h-0.5 w-16',
                        gearAccentBarClass(item.type),
                    ]"
                />

                <div class="space-y-4">
                    <!-- Top Header Row -->
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                :class="[
                                    'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg',
                                    gearIconBadgeClass(item.type),
                                ]"
                            >
                                <component
                                    :is="gearIconComponent(item.type)"
                                    class="size-4"
                                />
                            </span>

                            <div class="min-w-0">
                                <p
                                    class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    {{ gearTypeLabel(item.type) }}
                                </p>
                                <h2
                                    class="truncate text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                                >
                                    {{ item.name }}
                                </h2>
                                <p
                                    v-if="brandModelSubtitle(item)"
                                    class="truncate text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ brandModelSubtitle(item) }}
                                </p>
                            </div>
                        </div>

                        <!-- Status Badges -->
                        <div
                            class="flex shrink-0 flex-wrap justify-end gap-1.5"
                        >
                            <span
                                v-if="
                                    !item.is_retired &&
                                    defaultSportLabel(item.default_sport)
                                "
                                :class="[
                                    'inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-[10px] font-medium',
                                    defaultSportBadgeClass(item.default_sport),
                                ]"
                            >
                                {{ defaultSportLabel(item.default_sport) }}
                            </span>

                            <span
                                v-if="
                                    !item.is_retired &&
                                    item.alert_status === 'exceeded'
                                "
                                class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-500/12 px-2 py-0.5 font-mono text-[10px] font-medium text-rose-700 dark:border-rose-500/30 dark:text-rose-300"
                            >
                                <TriangleExclamationPoint class="size-3" />
                                <span>Service Due</span>
                            </span>

                            <span
                                v-else-if="
                                    !item.is_retired &&
                                    item.alert_status === 'warning'
                                "
                                class="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-500/12 px-2 py-0.5 font-mono text-[10px] font-medium text-amber-700 dark:border-amber-500/30 dark:text-amber-300"
                            >
                                <TriangleExclamationPoint class="size-3" />
                                <span>Service Soon</span>
                            </span>

                            <span
                                v-if="item.is_retired"
                                class="inline-flex items-center rounded-md border border-zinc-200 bg-zinc-100 px-2 py-0.5 font-mono text-[10px] font-medium text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                            >
                                Retired
                                <template v-if="item.retired_at">
                                    · {{ formatShortDate(item.retired_at) }}
                                </template>
                            </span>
                        </div>
                    </div>

                    <!-- Mileage & Service Threshold Bar -->
                    <div
                        class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <div class="flex items-baseline gap-1.5">
                                <span
                                    class="text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                                >
                                    {{
                                        formatNumber(
                                            distanceFromMeters(
                                                item.total_distance_meters,
                                            ),
                                            1,
                                        )
                                    }}
                                </span>
                                <span
                                    class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ distanceUnit }}
                                    <template
                                        v-if="item.max_distance_meters !== null"
                                    >
                                        /
                                        {{
                                            formatNumber(
                                                distanceFromMeters(
                                                    item.max_distance_meters,
                                                ),
                                                0,
                                            )
                                        }}
                                        {{ distanceUnit }}
                                    </template>
                                </span>
                            </div>

                            <span
                                v-if="item.usage_pct !== null"
                                :class="[
                                    'font-mono text-xs font-medium tabular-nums',
                                    item.alert_status === 'exceeded'
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : item.alert_status === 'warning'
                                          ? 'text-amber-600 dark:text-amber-400'
                                          : 'text-zinc-600 dark:text-zinc-300',
                                ]"
                            >
                                {{ formatNumber(item.usage_pct, 1) }}%
                            </span>
                            <span
                                v-else
                                class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                            >
                                No limit set
                            </span>
                        </div>

                        <div
                            v-if="item.max_distance_meters !== null"
                            class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200/90 dark:bg-zinc-800"
                        >
                            <div
                                :class="[
                                    'h-full rounded-full transition-all',
                                    progressBarClass(item),
                                ]"
                                :style="{
                                    width: progressWidthStyle(item.usage_pct),
                                }"
                            />
                        </div>

                        <div
                            class="mt-2 flex flex-wrap items-center justify-between gap-2 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            <span>
                                {{ formatNumber(item.activity_count, 0) }}
                                {{
                                    item.activity_count === 1
                                        ? 'session'
                                        : 'sessions'
                                }}
                                <template
                                    v-if="item.initial_distance_meters > 0"
                                >
                                    ·
                                    {{
                                        formatNumber(
                                            distanceFromMeters(
                                                item.logged_distance_meters,
                                            ),
                                            1,
                                        )
                                    }}
                                    logged +
                                    {{
                                        formatNumber(
                                            distanceFromMeters(
                                                item.initial_distance_meters,
                                            ),
                                            1,
                                        )
                                    }}
                                    initial
                                </template>
                            </span>

                            <span v-if="remainingDistanceLabel(item)">
                                {{ remainingDistanceLabel(item) }}
                            </span>
                        </div>
                    </div>

                    <!-- Purchase Date & Notes -->
                    <div
                        v-if="item.purchased_at || item.notes"
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        <span
                            v-if="item.purchased_at"
                            class="inline-flex items-center gap-1 font-mono text-[11px] tabular-nums"
                        >
                            <Calendar class="size-3 text-zinc-400" />
                            Purchased {{ item.purchased_at }}
                        </span>
                        <span v-if="item.notes" class="truncate">
                            {{ item.notes }}
                        </span>
                    </div>
                </div>

                <!-- Actions & Expandable Recent Activities -->
                <div
                    class="mt-4 space-y-3 border-t border-zinc-200/80 pt-3.5 dark:border-zinc-800/80"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1 font-mono text-[11px] text-zinc-600 transition-colors hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100"
                            @click="toggleExpanded(item.id)"
                        >
                            <ChevronUp
                                v-if="isExpanded(item.id)"
                                class="size-3.5"
                            />
                            <ChevronDown v-else class="size-3.5" />
                            <span>
                                Recent Activities ({{
                                    item.recent_activities.length
                                }})
                            </span>
                        </button>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <template v-if="pendingDeleteId === item.id">
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                    @click="pendingDeleteId = null"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="busyItemId === item.id"
                                    class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-transparent bg-rose-600 px-2.5 py-1 text-[11px] font-medium text-white transition-colors hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="confirmDelete(item)"
                                >
                                    Confirm Delete
                                </button>
                            </template>
                            <template v-else>
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                    @click="openEditForm(item)"
                                >
                                    <Pencil class="size-3" />
                                    <span>Edit</span>
                                </button>

                                <button
                                    type="button"
                                    :disabled="busyItemId === item.id"
                                    class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-700 transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                    @click="toggleRetire(item)"
                                >
                                    <RotateCcw
                                        v-if="item.is_retired"
                                        class="size-3"
                                    />
                                    <Archive v-else class="size-3" />
                                    <span>{{
                                        item.is_retired
                                            ? 'Reactivate'
                                            : 'Retire'
                                    }}</span>
                                </button>

                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-600 transition-colors hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-rose-500/30 dark:hover:bg-rose-500/10 dark:hover:text-rose-300"
                                    @click="requestDelete(item.id)"
                                >
                                    <Trash class="size-3" />
                                    <span>Delete</span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Expandable Recent Activities List -->
                    <div
                        v-if="isExpanded(item.id)"
                        class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/70 p-2 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                    >
                        <p
                            v-if="item.recent_activities.length === 0"
                            class="px-2 py-3 text-center font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            No activities assigned to this gear yet.
                        </p>

                        <ul
                            v-else
                            class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70"
                        >
                            <li
                                v-for="activity in item.recent_activities"
                                :key="activity.id"
                            >
                                <Link
                                    :href="activitiesShow(activity.id)"
                                    prefetch
                                    class="group flex items-center justify-between gap-3 rounded-md px-2 py-2 text-xs transition-colors hover:bg-white dark:hover:bg-[#141417]"
                                >
                                    <div
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <span
                                            class="size-1.5 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    activitySportAccentHex(
                                                        activity,
                                                    ),
                                            }"
                                        />
                                        <span
                                            class="truncate font-medium text-zinc-800 group-hover:text-zinc-950 dark:text-zinc-200 dark:group-hover:text-white"
                                        >
                                            {{ activity.name }}
                                        </span>
                                    </div>

                                    <div
                                        class="flex shrink-0 items-center gap-3 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                    >
                                        <span>{{
                                            formatShortDate(
                                                activity.started_at_local,
                                            )
                                        }}</span>
                                        <span
                                            class="font-medium text-zinc-700 dark:text-zinc-300"
                                        >
                                            {{
                                                formatDistanceCompact(
                                                    activity.distance_meters,
                                                    1,
                                                )
                                            }}
                                        </span>
                                        <span>{{
                                            formatDurationClock(
                                                activity.duration_seconds,
                                            )
                                        }}</span>
                                        <ArrowUpRight
                                            class="size-3 text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-zinc-700 dark:group-hover:text-zinc-200"
                                        />
                                    </div>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </article>
        </section>
    </div>
</template>
