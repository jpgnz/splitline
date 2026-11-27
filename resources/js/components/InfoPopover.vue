<script lang="ts">
import { ref } from 'vue';

let nextPopoverSequence = 0;
const activePopoverId = ref<string | null>(null);
</script>

<script setup lang="ts">
import { Info, X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref as localRef,
    watch,
} from 'vue';
import {
    METRIC_GLOSSARY,
    type MetricGlossaryDetail,
    type MetricGlossaryEntry,
    type MetricGlossaryTopic,
} from '@/lib/metricGlossary';

const props = withDefaults(
    defineProps<{
        topic?: MetricGlossaryTopic;
        title?: string;
        summary?: string;
        formula?: string;
        details?: ReadonlyArray<MetricGlossaryDetail | string>;
        note?: string;
        size?: 'xs' | 'sm';
        label?: string;
    }>(),
    {
        size: 'xs',
    },
);

const popoverId = `info-popover-${++nextPopoverSequence}`;
const triggerRef = localRef<HTMLButtonElement | null>(null);
const panelRef = localRef<HTMLDivElement | null>(null);
const panelStyle = localRef<Record<string, string>>({
    top: '-9999px',
    left: '-9999px',
    visibility: 'hidden',
});

const glossaryEntry = computed<MetricGlossaryEntry | null>(() =>
    props.topic ? METRIC_GLOSSARY[props.topic] : null,
);

const resolvedTitle = computed<string>(
    () => props.title ?? glossaryEntry.value?.title ?? '',
);

const resolvedSummary = computed<string>(
    () => props.summary ?? glossaryEntry.value?.summary ?? '',
);

const resolvedFormula = computed<string | undefined>(
    () => props.formula ?? glossaryEntry.value?.formula,
);

const resolvedDetails = computed<ReadonlyArray<MetricGlossaryDetail>>(() => {
    const raw = props.details ?? glossaryEntry.value?.details ?? [];

    return raw.map((item) =>
        typeof item === 'string' ? { text: item } : item,
    );
});

const resolvedNote = computed<string | undefined>(
    () => props.note ?? glossaryEntry.value?.note,
);

const resolvedAriaLabel = computed<string>(() => {
    if (props.label) {
        return props.label;
    }

    return resolvedTitle.value
        ? `More info about ${resolvedTitle.value}`
        : 'More information';
});

const isOpen = computed<boolean>(() => activePopoverId.value === popoverId);

const buttonClasses = computed<string>(() => {
    const sizeClasses = props.size === 'sm' ? 'h-5 w-5' : 'h-4 w-4';
    const stateClasses = isOpen.value
        ? 'bg-zinc-200/80 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100'
        : 'text-zinc-400 hover:bg-zinc-200/60 hover:text-zinc-700 dark:text-zinc-500 dark:hover:bg-zinc-800/80 dark:hover:text-zinc-200';

    return `inline-flex shrink-0 cursor-pointer items-center justify-center rounded-full leading-none transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400/70 dark:focus-visible:ring-zinc-500/70 ${sizeClasses} ${stateClasses}`;
});

const iconClasses = computed<string>(() =>
    props.size === 'sm' ? 'h-3.5 w-3.5' : 'h-3 w-3',
);

function close(restoreFocus = false): void {
    if (activePopoverId.value === popoverId) {
        activePopoverId.value = null;
    }

    if (restoreFocus) {
        triggerRef.value?.focus();
    }
}

function toggle(): void {
    if (isOpen.value) {
        close();
    } else {
        activePopoverId.value = popoverId;
    }
}

function updatePosition(): void {
    const trigger = triggerRef.value;
    const panel = panelRef.value;

    if (!trigger || !panel) {
        return;
    }

    const rect = trigger.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const padding = 12;
    const gap = 8;

    // Close automatically if the trigger scrolled out of the visible viewport.
    if (
        rect.bottom < 0 ||
        rect.top > viewportHeight ||
        rect.right < 0 ||
        rect.left > viewportWidth
    ) {
        close();

        return;
    }

    const panelWidth = panel.offsetWidth || 304;
    const panelHeight = panel.offsetHeight || 160;

    const spaceBelow = viewportHeight - rect.bottom - padding;
    const spaceAbove = rect.top - padding;
    const placeAbove =
        spaceBelow < panelHeight + gap && spaceAbove > spaceBelow;

    let top = placeAbove ? rect.top - panelHeight - gap : rect.bottom + gap;
    top = Math.max(
        padding,
        Math.min(top, viewportHeight - panelHeight - padding),
    );

    let left = rect.left + rect.width / 2 - panelWidth / 2;
    left = Math.max(
        padding,
        Math.min(left, viewportWidth - panelWidth - padding),
    );

    panelStyle.value = {
        top: `${Math.round(top)}px`,
        left: `${Math.round(left)}px`,
        visibility: 'visible',
    };
}

let rafId: number | null = null;

function schedulePositionUpdate(): void {
    if (rafId !== null) {
        return;
    }

    rafId = window.requestAnimationFrame(() => {
        rafId = null;

        if (isOpen.value) {
            updatePosition();
        }
    });
}

function onDocumentPointerDown(event: PointerEvent): void {
    const target = event.target as Node | null;

    if (!target) {
        return;
    }

    if (
        triggerRef.value?.contains(target) ||
        panelRef.value?.contains(target)
    ) {
        return;
    }

    close();
}

function onDocumentKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close(true);
    }
}

function onWindowScroll(event: Event): void {
    const target = event.target as Node | null;

    if (
        target &&
        panelRef.value &&
        (target === panelRef.value || panelRef.value.contains(target))
    ) {
        return;
    }

    schedulePositionUpdate();
}

function attachListeners(): void {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);
    document.addEventListener('keydown', onDocumentKeyDown, true);
    window.addEventListener('resize', schedulePositionUpdate, {
        passive: true,
    });
    window.addEventListener('scroll', onWindowScroll, {
        capture: true,
        passive: true,
    });
}

function detachListeners(): void {
    document.removeEventListener('pointerdown', onDocumentPointerDown, true);
    document.removeEventListener('keydown', onDocumentKeyDown, true);
    window.removeEventListener('resize', schedulePositionUpdate);
    window.removeEventListener('scroll', onWindowScroll, true);

    if (rafId !== null) {
        window.cancelAnimationFrame(rafId);
        rafId = null;
    }
}

watch(isOpen, async (open) => {
    if (open) {
        panelStyle.value = {
            top: '-9999px',
            left: '-9999px',
            visibility: 'hidden',
        };
        attachListeners();
        await nextTick();
        updatePosition();
    } else {
        detachListeners();
    }
});

onBeforeUnmount(() => {
    detachListeners();

    if (activePopoverId.value === popoverId) {
        activePopoverId.value = null;
    }
});
</script>

<template>
    <span class="inline-flex shrink-0 items-center align-middle">
        <button
            ref="triggerRef"
            type="button"
            :class="buttonClasses"
            :aria-label="resolvedAriaLabel"
            :aria-expanded="isOpen"
            :aria-controls="isOpen ? popoverId : undefined"
            aria-haspopup="dialog"
            @click.stop.prevent="toggle"
        >
            <Info :class="iconClasses" aria-hidden="true" />
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                :id="popoverId"
                ref="panelRef"
                role="dialog"
                :aria-label="resolvedTitle || resolvedAriaLabel"
                :style="panelStyle"
                class="fixed z-[700] max-h-[min(78vh,460px)] w-72 max-w-[calc(100vw-24px)] overflow-y-auto rounded-xl border border-zinc-200/90 bg-white p-3.5 text-left text-zinc-900 shadow-lg shadow-zinc-950/10 sm:w-80 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-100 dark:shadow-black/50"
                @click.stop
            >
                <div class="flex items-start justify-between gap-2">
                    <h4
                        v-if="resolvedTitle"
                        class="text-xs leading-snug font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        {{ resolvedTitle }}
                    </h4>
                    <button
                        type="button"
                        aria-label="Close explanation"
                        class="-mt-0.5 -mr-1 inline-flex h-5 w-5 shrink-0 cursor-pointer items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 focus-visible:ring-2 focus-visible:ring-zinc-400/70 focus-visible:outline-none dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                        @click.stop="close(true)"
                    >
                        <X class="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                </div>

                <p
                    v-if="resolvedSummary"
                    class="mt-1.5 text-[12px] leading-relaxed font-normal tracking-normal text-zinc-600 normal-case dark:text-zinc-300"
                >
                    {{ resolvedSummary }}
                </p>

                <div
                    v-if="$slots.default"
                    class="mt-2 text-[11.5px] leading-relaxed font-normal tracking-normal text-zinc-700 normal-case dark:text-zinc-300"
                >
                    <slot />
                </div>

                <div
                    v-if="resolvedFormula"
                    class="mt-2 rounded-lg border border-zinc-200/70 bg-zinc-50 px-2.5 py-1.5 font-mono text-[11px] leading-snug font-normal tracking-normal text-zinc-700 normal-case dark:border-zinc-800 dark:bg-zinc-900/80 dark:text-zinc-300"
                >
                    {{ resolvedFormula }}
                </div>

                <ul
                    v-if="resolvedDetails.length > 0"
                    class="mt-2.5 space-y-1.5 text-[11.5px] leading-snug font-normal tracking-normal text-zinc-600 normal-case dark:text-zinc-300"
                >
                    <li
                        v-for="(item, idx) in resolvedDetails"
                        :key="idx"
                        class="flex items-start gap-1.5"
                    >
                        <span
                            class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-zinc-400 dark:bg-zinc-500"
                            aria-hidden="true"
                        />
                        <span>
                            <span
                                v-if="item.label"
                                class="font-medium text-zinc-800 dark:text-zinc-100"
                            >
                                {{ item.label }}:
                            </span>
                            {{ item.text }}
                        </span>
                    </li>
                </ul>

                <p
                    v-if="resolvedNote"
                    class="mt-2.5 border-t border-zinc-100 pt-2 text-[11px] leading-relaxed font-normal tracking-normal text-zinc-500 normal-case dark:border-zinc-800/80 dark:text-zinc-400"
                >
                    <span class="font-medium text-zinc-600 dark:text-zinc-300">
                        Good to know:
                    </span>
                    {{ resolvedNote }}
                </p>
            </div>
        </Teleport>
    </span>
</template>
