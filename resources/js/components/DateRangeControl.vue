<script setup lang="ts">
import { Calendar, ChevronLeft, ChevronRight, RotateCcw, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export interface DateRangePreset {
    value: string;
    label: string;
}

export interface DateWindowMeta {
    range: string;
    start: string | null;
    end: string;
    from: string | null;
    to: string | null;
    prevEnd: string | null;
    nextEnd: string | null;
    isLatest: boolean;
    label: string;
}

const props = withDefaults(
    defineProps<{
        presets: DateRangePreset[];
        window: DateWindowMeta;
        surface?: 'card' | 'page';
        popoverAlign?: 'left' | 'right';
        showCustom?: boolean;
        maxDate?: string;
        ariaLabel?: string;
    }>(),
    {
        surface: 'card',
        popoverAlign: 'left',
        showCustom: true,
        maxDate: undefined,
        ariaLabel: 'Date range',
    },
);

const emit = defineEmits<{
    selectPreset: [range: string];
    step: [end: string | null];
    applyCustom: [payload: { from: string | null; to: string | null }];
}>();

const rootRef = ref<HTMLElement | null>(null);
const popoverOpen = ref<boolean>(false);
const draftFrom = ref<string>(props.window.from ?? props.window.start ?? '');
const draftTo = ref<string>(props.window.to ?? props.window.end ?? '');

watch(
    () => [
        props.window.range,
        props.window.from,
        props.window.to,
        props.window.start,
        props.window.end,
    ],
    ([range, from, to, start, end]) => {
        if (range === 'custom') {
            draftFrom.value = from ?? '';
            draftTo.value = to ?? '';
        } else {
            draftFrom.value = start ?? '';
            draftTo.value = end ?? '';
        }
    },
);

const allPresets = computed<DateRangePreset[]>(() => {
    if (
        !props.showCustom ||
        props.presets.some((preset) => preset.value === 'custom')
    ) {
        return props.presets;
    }

    return [...props.presets, { value: 'custom', label: 'Custom' }];
});

const isPageSurface = computed<boolean>(() => props.surface === 'page');

const canStepPrev = computed<boolean>(() => props.window.prevEnd !== null);
const canStepNext = computed<boolean>(() => props.window.nextEnd !== null);
const canJumpLatest = computed<boolean>(
    () =>
        !props.window.isLatest &&
        props.window.range !== 'all' &&
        props.window.range !== 'custom',
);

const hasDraftBounds = computed<boolean>(
    () => draftFrom.value.trim() !== '' || draftTo.value.trim() !== '',
);

function isPresetActive(value: string): boolean {
    if (value === 'custom') {
        return popoverOpen.value || props.window.range === 'custom';
    }

    return (
        !popoverOpen.value &&
        props.window.range.toLowerCase() === value.toLowerCase()
    );
}

function syncDraftsFromWindow(): void {
    if (props.window.range === 'custom') {
        draftFrom.value = props.window.from ?? '';
        draftTo.value = props.window.to ?? '';
    } else if (props.window.start !== null) {
        draftFrom.value = props.window.start;
        draftTo.value = props.window.end;
    } else {
        draftFrom.value = '';
        draftTo.value = '';
    }
}

function toggleCustomPopover(): void {
    if (popoverOpen.value) {
        popoverOpen.value = false;

        return;
    }

    syncDraftsFromWindow();
    popoverOpen.value = true;
}

function closePopover(): void {
    popoverOpen.value = false;
}

function onPresetClick(value: string): void {
    if (value === 'custom') {
        toggleCustomPopover();

        return;
    }

    popoverOpen.value = false;

    if (
        props.window.range.toLowerCase() === value.toLowerCase() &&
        props.window.isLatest
    ) {
        return;
    }

    emit('selectPreset', value);
}

function stepBackward(): void {
    if (props.window.prevEnd) {
        popoverOpen.value = false;
        emit('step', props.window.prevEnd);
    }
}

function stepForward(): void {
    if (props.window.nextEnd) {
        popoverOpen.value = false;
        emit('step', props.window.nextEnd);
    }
}

function jumpToLatest(): void {
    if (!canJumpLatest.value) {
        return;
    }

    popoverOpen.value = false;
    emit('step', null);
}

function submitCustomRange(): void {
    let from = draftFrom.value.trim();
    let to = draftTo.value.trim();

    if (from === '' && to === '') {
        return;
    }

    if (from !== '' && to !== '' && from > to) {
        const swappedFrom = to;
        const swappedTo = from;
        from = swappedFrom;
        to = swappedTo;
        draftFrom.value = from;
        draftTo.value = to;
    }

    popoverOpen.value = false;
    emit('applyCustom', {
        from: from !== '' ? from : null,
        to: to !== '' ? to : null,
    });
}

function onDocumentPointerDown(event: PointerEvent): void {
    if (!popoverOpen.value || !rootRef.value) {
        return;
    }

    if (!rootRef.value.contains(event.target as Node)) {
        popoverOpen.value = false;
    }
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && popoverOpen.value) {
        popoverOpen.value = false;
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
</script>

<template>
    <div
        ref="rootRef"
        class="relative inline-flex flex-wrap items-center gap-2"
    >
        <!-- Preset Pills -->
        <div
            :class="[
                'inline-flex shrink-0 rounded-lg border',
                isPageSurface
                    ? 'border-zinc-200/90 bg-white p-1 dark:border-zinc-800/90 dark:bg-[#141417]'
                    : 'border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60',
            ]"
            role="group"
            :aria-label="ariaLabel"
        >
            <button
                v-for="preset in allPresets"
                :key="preset.value"
                type="button"
                :class="[
                    'cursor-pointer rounded-md font-mono font-medium whitespace-nowrap transition-colors',
                    isPageSurface
                        ? 'px-2.5 py-1.5 text-xs'
                        : 'px-2.5 py-1 text-[11px]',
                    isPresetActive(preset.value)
                        ? isPageSurface
                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                            : 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                        : isPageSurface
                          ? 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                          : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                ]"
                @click="onPresetClick(preset.value)"
            >
                {{ preset.label }}
            </button>
        </div>

        <!-- Fixed-Width Period Stepper (stable geometry across all states) -->
        <div
            :class="[
                'inline-flex shrink-0 items-center rounded-lg border border-zinc-200/90 dark:border-zinc-800/90',
                isPageSurface
                    ? 'bg-white dark:bg-[#141417]'
                    : 'bg-white dark:bg-zinc-900/70',
            ]"
        >
            <button
                type="button"
                aria-label="Previous period"
                title="Previous period"
                :disabled="!canStepPrev"
                :class="[
                    'flex shrink-0 items-center justify-center rounded-l-lg text-zinc-500 transition-colors disabled:cursor-not-allowed disabled:opacity-30 dark:text-zinc-400',
                    isPageSurface ? 'size-8' : 'size-7',
                    canStepPrev
                        ? 'cursor-pointer hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100'
                        : '',
                ]"
                @click="stepBackward"
            >
                <ChevronLeft class="size-3.5" />
            </button>

            <button
                type="button"
                title="Click to select a custom date range"
                :class="[
                    'flex shrink-0 cursor-pointer items-center justify-center gap-1.5 border-x border-zinc-200/80 px-2 font-mono whitespace-nowrap text-zinc-700 tabular-nums transition-colors hover:bg-zinc-50 hover:text-zinc-950 dark:border-zinc-800/80 dark:text-zinc-300 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-100',
                    isPageSurface
                        ? 'h-8 w-[232px] text-xs'
                        : 'h-7 w-[218px] text-[11px]',
                ]"
                @click="toggleCustomPopover"
            >
                <Calendar
                    class="size-3 shrink-0 text-zinc-400 dark:text-zinc-500"
                />
                <span class="truncate">{{ window.label }}</span>
            </button>

            <button
                type="button"
                aria-label="Next period"
                title="Next period"
                :disabled="!canStepNext"
                :class="[
                    'flex shrink-0 items-center justify-center text-zinc-500 transition-colors disabled:cursor-not-allowed disabled:opacity-30 dark:text-zinc-400',
                    isPageSurface ? 'size-8' : 'size-7',
                    canStepNext
                        ? 'cursor-pointer hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100'
                        : '',
                ]"
                @click="stepForward"
            >
                <ChevronRight class="size-3.5" />
            </button>

            <button
                type="button"
                aria-label="Jump to latest period"
                title="Jump to latest period"
                :disabled="!canJumpLatest"
                :class="[
                    'flex shrink-0 items-center justify-center rounded-r-lg border-l border-zinc-200/80 transition-colors disabled:cursor-not-allowed disabled:opacity-30 dark:border-zinc-800/80',
                    isPageSurface ? 'size-8' : 'size-7',
                    canJumpLatest
                        ? 'cursor-pointer text-zinc-700 hover:bg-zinc-100 hover:text-zinc-950 dark:text-zinc-200 dark:hover:bg-zinc-800 dark:hover:text-white'
                        : 'text-zinc-400 dark:text-zinc-500',
                ]"
                @click="jumpToLatest"
            >
                <RotateCcw class="size-3" />
            </button>
        </div>

        <!-- Floating Custom Date Range Popover -->
        <form
            v-if="popoverOpen"
            :class="[
                'absolute top-full z-40 mt-1.5 w-[296px] rounded-xl border border-zinc-200/90 bg-white p-3.5 shadow-lg dark:border-zinc-800 dark:bg-[#141417]',
                popoverAlign === 'right' ? 'right-0' : 'left-0',
            ]"
            @submit.prevent="submitCustomRange"
        >
            <div class="flex items-center justify-between gap-2">
                <span
                    class="font-mono text-[10px] font-medium tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Custom Date Range
                </span>
                <button
                    type="button"
                    aria-label="Close custom date range"
                    class="inline-flex size-6 cursor-pointer items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                    @click="closePopover"
                >
                    <X class="size-3.5" />
                </button>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2.5">
                <label class="block space-y-1">
                    <span
                        class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        From
                    </span>
                    <input
                        v-model="draftFrom"
                        type="date"
                        :max="maxDate"
                        aria-label="From date"
                        class="h-8 w-full rounded-lg border border-zinc-200/90 bg-[#f7f7f5] px-2 font-mono text-xs text-zinc-900 tabular-nums focus:border-zinc-400 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100 dark:focus:border-zinc-600"
                    />
                </label>

                <label class="block space-y-1">
                    <span
                        class="block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        To
                    </span>
                    <input
                        v-model="draftTo"
                        type="date"
                        :max="maxDate"
                        aria-label="To date"
                        class="h-8 w-full rounded-lg border border-zinc-200/90 bg-[#f7f7f5] px-2 font-mono text-xs text-zinc-900 tabular-nums focus:border-zinc-400 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100 dark:focus:border-zinc-600"
                    />
                </label>
            </div>

            <div class="mt-3 flex items-center justify-end gap-1.5">
                <button
                    type="button"
                    class="cursor-pointer rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-[11px] font-medium text-zinc-600 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                    @click="closePopover"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="!hasDraftBounds"
                    class="cursor-pointer rounded-lg border border-zinc-900 bg-zinc-900 px-3 py-1.5 font-mono text-[11px] font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                >
                    Apply
                </button>
            </div>
        </form>
    </div>
</template>
