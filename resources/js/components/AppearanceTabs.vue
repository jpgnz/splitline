<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
        role="group"
        aria-label="Color appearance"
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :aria-pressed="appearance === value"
            @click="updateAppearance(value)"
            :class="[
                'flex cursor-pointer items-center gap-1.5 rounded-md px-3 py-1.5 font-mono text-xs font-medium transition-colors',
                appearance === value
                    ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                    : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
            ]"
        >
            <component :is="Icon" class="size-3.5" />
            <span>{{ label }}</span>
        </button>
    </div>
</template>
