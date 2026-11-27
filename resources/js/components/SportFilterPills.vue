<script setup lang="ts">
import type { SportFilterOption } from '@/types/sports';

defineProps<{
    options: SportFilterOption[];
    selected: string | null;
}>();

const emit = defineEmits<{
    select: [sport: string | null];
}>();

const activeClasses =
    'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950';
const inactiveClasses =
    'border-zinc-200/80 text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100';
</script>

<template>
    <div class="flex flex-wrap items-center gap-1">
        <button
            type="button"
            class="cursor-pointer rounded-md border px-2 py-1 font-mono text-[11px] font-medium transition-colors"
            :class="selected === null ? activeClasses : inactiveClasses"
            @click="emit('select', null)"
        >
            All
        </button>
        <button
            v-for="option in options"
            :key="option.key"
            type="button"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 font-mono text-[11px] font-medium tabular-nums transition-colors"
            :class="selected === option.key ? activeClasses : inactiveClasses"
            @click="emit('select', option.key)"
        >
            <span
                class="size-1.5 rounded-full"
                :style="{ backgroundColor: option.accent_hex }"
            />
            <span>{{ option.short_label }}</span>
            <span class="opacity-65">{{ option.count }}</span>
        </button>
    </div>
</template>
