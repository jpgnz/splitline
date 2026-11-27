<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CloudSun, Ellipsis, RefreshCw } from '@lucide/vue';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import {
    syncStreams as syncStreamsAction,
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

const props = withDefaults(
    defineProps<{
        activity: ActivityDetail;
        gear?: ActivityGearPayload;
        disciplineOptions?: DisciplineOption[];
        canSyncWeather?: boolean;
        hasWeather?: boolean;
        syncingWeather?: boolean;
    }>(),
    {
        canSyncWeather: false,
        hasWeather: false,
        syncingWeather: false,
    },
);

const emit = defineEmits<{
    syncWeather: [];
}>();

const isSyncingStreams = defineModel<boolean>('syncingStreams', {
    default: false,
});

const isOpen = ref<boolean>(false);
const containerRef = ref<HTMLElement | null>(null);
const triggerButtonRef = ref<HTMLButtonElement | null>(null);
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

watch(
    () => props.activity.id,
    () => {
        isOpen.value = false;
    },
);

function toggleMenu(): void {
    isOpen.value = !isOpen.value;
}

function closeMenu(): void {
    isOpen.value = false;
}

function handlePointerDown(event: PointerEvent): void {
    if (!isOpen.value) {
        return;
    }

    const target = event.target;

    if (
        containerRef.value &&
        target instanceof Node &&
        !containerRef.value.contains(target)
    ) {
        closeMenu();
    }
}

function handleKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && isOpen.value) {
        closeMenu();
        triggerButtonRef.value?.focus();
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    document.removeEventListener('pointerdown', handlePointerDown);
    document.removeEventListener('keydown', handleKeyDown);
});

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
    <div ref="containerRef" class="relative inline-block">
        <button
            ref="triggerButtonRef"
            type="button"
            :aria-expanded="isOpen"
            aria-haspopup="dialog"
            class="inline-flex h-8 cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs font-medium whitespace-nowrap text-zinc-700 transition-colors hover:border-zinc-300 hover:bg-zinc-50 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80 dark:hover:text-zinc-100"
            @click="toggleMenu"
        >
            <Ellipsis class="size-3.5" />
            <span>Manage</span>
        </button>

        <div
            v-if="isOpen"
            role="dialog"
            aria-label="Manage activity"
            class="absolute right-0 z-40 mt-1.5 w-64 space-y-3 rounded-xl border border-zinc-200/90 bg-white p-3.5 shadow-lg dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <!-- Discipline Override -->
            <label v-if="(disciplineOptions?.length ?? 0) > 0" class="block">
                <span
                    class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Discipline
                </span>
                <select
                    v-model="selectedDiscipline"
                    :disabled="isUpdatingDiscipline"
                    aria-label="Activity discipline"
                    class="mt-1 w-full cursor-pointer rounded-lg border border-zinc-200/90 bg-[#f7f7f5] px-2.5 py-1.5 font-mono text-xs font-medium text-zinc-900 focus:border-zinc-400 focus:outline-none disabled:opacity-60 dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:focus:border-zinc-600"
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

            <!-- Assigned Gear -->
            <label v-if="gear && gear.available.length > 0" class="block">
                <span
                    class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Assigned Gear
                </span>
                <select
                    v-model="selectedGearId"
                    :disabled="isUpdatingGear"
                    aria-label="Assigned gear"
                    class="mt-1 w-full cursor-pointer rounded-lg border border-zinc-200/90 bg-[#f7f7f5] px-2.5 py-1.5 font-mono text-xs font-medium text-zinc-900 focus:border-zinc-400 focus:outline-none disabled:opacity-60 dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-100 dark:focus:border-zinc-600"
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

            <!-- Data Sync Actions -->
            <div
                class="space-y-1 border-t border-zinc-200/80 pt-2.5 dark:border-zinc-800/80"
            >
                <button
                    type="button"
                    :disabled="isSyncingStreams"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5 text-left font-mono text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-100 hover:text-zinc-950 disabled:cursor-not-allowed disabled:opacity-60 dark:text-zinc-300 dark:hover:bg-zinc-800/80 dark:hover:text-zinc-100"
                    @click="syncStreams"
                >
                    <RefreshCw
                        :class="[
                            'size-3.5',
                            isSyncingStreams && 'animate-spin',
                        ]"
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

                <button
                    v-if="canSyncWeather"
                    type="button"
                    :disabled="syncingWeather"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5 text-left font-mono text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-100 hover:text-zinc-950 disabled:cursor-not-allowed disabled:opacity-60 dark:text-zinc-300 dark:hover:bg-zinc-800/80 dark:hover:text-zinc-100"
                    @click="emit('syncWeather')"
                >
                    <CloudSun
                        :class="[
                            'size-3.5 text-sky-500',
                            syncingWeather && 'animate-pulse',
                        ]"
                    />
                    <span>
                        {{
                            syncingWeather
                                ? 'Refreshing Weather...'
                                : hasWeather
                                  ? 'Refresh Weather'
                                  : 'Fetch Weather'
                        }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
