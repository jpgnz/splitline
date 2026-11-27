<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RefreshCw, Star } from '@lucide/vue';
import { ref, watch } from 'vue';
import {
    syncStreams as syncStreamsAction,
    toggleFavorite as toggleFavoriteAction,
    updateDiscipline as updateDisciplineAction,
} from '@/actions/App/Http/Controllers/ActivityController';
import { assignToActivity } from '@/actions/App/Http/Controllers/GearController';
import type { ActivityDetail } from '@/types/activity';

export interface ActivityGearOption {
    id: number;
    name: string;
    type: string;
    default_sport: string | null;
    is_retired: boolean;
}

export interface ActivityGearPayload {
    assigned_id: number | null;
    assigned_name: string | null;
    available: ActivityGearOption[];
}

export interface DisciplineOption {
    value: string;
    label: string;
}

const props = defineProps<{
    activity: ActivityDetail;
    gear?: ActivityGearPayload;
    disciplineOptions?: DisciplineOption[];
}>();

const isSyncingStreams = defineModel<boolean>('syncingStreams', {
    default: false,
});

const isTogglingFavorite = ref<boolean>(false);
const isUpdatingDiscipline = ref<boolean>(false);
const isUpdatingGear = ref<boolean>(false);

const selectedGearId = ref<string>(
    props.gear?.assigned_id !== null && props.gear?.assigned_id !== undefined
        ? String(props.gear.assigned_id)
        : '',
);

watch(
    () => props.gear?.assigned_id,
    (assignedId) => {
        selectedGearId.value =
            assignedId !== null && assignedId !== undefined
                ? String(assignedId)
                : '';
    },
);

const selectedDiscipline = ref<string>(
    props.activity.effective_discipline ??
        props.activity.discipline_override ??
        props.activity.sport_discipline ??
        props.activity.biking_discipline ??
        props.disciplineOptions?.[0]?.value ??
        'other',
);

watch(
    () => [
        props.activity.effective_discipline,
        props.activity.discipline_override,
        props.activity.sport_discipline,
        props.activity.biking_discipline,
    ],
    ([effective, override, sportDisc, bikingDisc]) => {
        selectedDiscipline.value =
            effective ??
            override ??
            sportDisc ??
            bikingDisc ??
            props.disciplineOptions?.[0]?.value ??
            'other';
    },
);

function toggleFavorite(): void {
    if (isTogglingFavorite.value) {
        return;
    }

    router.post(
        toggleFavoriteAction.url(props.activity.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isTogglingFavorite.value = true;
            },
            onFinish: () => {
                isTogglingFavorite.value = false;
            },
        },
    );
}

function updateDiscipline(): void {
    if (isUpdatingDiscipline.value) {
        return;
    }

    router.patch(
        updateDisciplineAction.url(props.activity.id),
        { discipline_override: selectedDiscipline.value },
        {
            preserveScroll: true,
            onStart: () => {
                isUpdatingDiscipline.value = true;
            },
            onFinish: () => {
                isUpdatingDiscipline.value = false;
            },
        },
    );
}

function updateGear(): void {
    if (isUpdatingGear.value) {
        return;
    }

    const gearItemId =
        selectedGearId.value === '' ? null : Number(selectedGearId.value);

    router.patch(
        assignToActivity.url(props.activity.id),
        { gear_item_id: gearItemId },
        {
            preserveScroll: true,
            onStart: () => {
                isUpdatingGear.value = true;
            },
            onFinish: () => {
                isUpdatingGear.value = false;
            },
        },
    );
}

function syncStreams(): void {
    if (isSyncingStreams.value) {
        return;
    }

    router.post(
        syncStreamsAction.url(props.activity.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isSyncingStreams.value = true;
            },
            onFinish: () => {
                isSyncingStreams.value = false;
            },
        },
    );
}

defineExpose({
    syncStreams,
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <!-- Favorite Star Toggle -->
        <button
            type="button"
            :disabled="isTogglingFavorite"
            :aria-label="
                activity.is_favorite
                    ? 'Remove from favorites'
                    : 'Mark as favorite'
            "
            class="inline-flex min-w-[108px] cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-3 py-2 font-mono text-xs font-medium transition-colors disabled:opacity-60"
            :class="
                activity.is_favorite
                    ? 'border-amber-500/50 bg-amber-50 text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-300'
                    : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
            "
            @click="toggleFavorite"
        >
            <Star
                class="size-3.5"
                :class="
                    activity.is_favorite
                        ? 'fill-amber-500 text-amber-500'
                        : 'text-zinc-400'
                "
            />
            <span>{{ activity.is_favorite ? 'Favorited' : 'Favorite' }}</span>
        </button>

        <!-- Discipline Override Reclassification Select -->
        <label
            v-if="(disciplineOptions?.length ?? 0) > 0"
            class="inline-flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-500 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400"
        >
            <span class="text-[10px] tracking-wider uppercase">
                Discipline
            </span>
            <select
                v-model="selectedDiscipline"
                :disabled="isUpdatingDiscipline"
                aria-label="Activity discipline"
                class="cursor-pointer rounded bg-transparent pr-1 font-mono text-xs font-medium text-zinc-900 focus:outline-none dark:bg-[#141417] dark:text-zinc-100"
                @change="updateDiscipline"
            >
                <option
                    v-for="option in disciplineOptions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
        </label>

        <!-- Assigned Gear Selector -->
        <label
            v-if="gear && gear.available.length > 0"
            class="inline-flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-500 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400"
        >
            <span class="text-[10px] tracking-wider uppercase">Gear</span>
            <select
                v-model="selectedGearId"
                :disabled="isUpdatingGear"
                aria-label="Assigned gear"
                class="cursor-pointer rounded bg-transparent pr-1 font-mono text-xs font-medium text-zinc-900 focus:outline-none dark:bg-[#141417] dark:text-zinc-100"
                @change="updateGear"
            >
                <option value="">None</option>
                <option
                    v-for="item in gear.available"
                    :key="item.id"
                    :value="String(item.id)"
                >
                    {{ item.name }}{{ item.is_retired ? ' (Retired)' : '' }}
                </option>
            </select>
        </label>

        <!-- Sync FIT Streams Button -->
        <button
            type="button"
            :disabled="isSyncingStreams"
            class="inline-flex min-w-[178px] cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-3 py-2 font-mono text-xs font-medium text-zinc-800 transition-colors hover:border-zinc-300 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-100 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80"
            @click="syncStreams"
        >
            <RefreshCw
                :class="['size-3.5', isSyncingStreams && 'animate-spin']"
            />
            <span>
                {{
                    isSyncingStreams
                        ? 'Syncing FIT...'
                        : activity.streams_synced_at
                          ? 'Re-sync FIT Streams'
                          : 'Sync FIT Streams'
                }}
            </span>
        </button>
    </div>
</template>
