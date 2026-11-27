<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import { formatDurationHuman, formatShortDate } from '@/lib/format';
import { useUnits } from '@/lib/units';
import { show as showActivity } from '@/routes/activities';

export type LatestActivityMetricMode =
    | 'pace'
    | 'speed'
    | 'pace_100m'
    | 'duration';

const props = withDefaults(
    defineProps<{
        activityId: number;
        name: string;
        accentHex: string;
        isToday?: boolean;
        date?: string | null;
        distanceMeters?: number | null;
        durationSeconds?: number | null;
        avgSpeed?: number | null;
        avgHr?: number | null;
        metricMode?: LatestActivityMetricMode;
        showDate?: boolean;
    }>(),
    {
        isToday: false,
        date: null,
        distanceMeters: null,
        durationSeconds: null,
        avgSpeed: null,
        avgHr: null,
        metricMode: 'pace',
        showDate: false,
    },
);

const units = useUnits();

const prefixLabel = computed<string>(() => {
    if (props.isToday) {
        return 'Today';
    }

    if (props.showDate && props.date) {
        return `Latest · ${formatShortDate(props.date)}`;
    }

    return 'Latest';
});

const secondaryMetric = computed<string | null>(() => {
    if (
        props.metricMode === 'duration' ||
        props.avgSpeed === null ||
        props.avgSpeed <= 0
    ) {
        if (
            (props.distanceMeters === null || props.distanceMeters <= 0) &&
            props.durationSeconds !== null &&
            props.durationSeconds > 0
        ) {
            return formatDurationHuman(props.durationSeconds);
        }

        return null;
    }

    if (props.metricMode === 'pace') {
        return units.formatPace(props.avgSpeed);
    }

    if (props.metricMode === 'pace_100m') {
        return units.formatPace100m(props.avgSpeed);
    }

    return units.formatSpeed(props.avgSpeed);
});
</script>

<template>
    <Link
        :href="showActivity.url(activityId)"
        prefetch
        class="group flex items-center gap-2.5 rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            :style="{ backgroundColor: accentHex }"
        />
        <span
            class="font-mono text-[10px] tracking-wider whitespace-nowrap text-zinc-400 uppercase dark:text-zinc-500"
        >
            {{ prefixLabel }}
        </span>
        <span
            class="max-w-[160px] truncate font-medium text-zinc-900 dark:text-zinc-100"
            :title="name"
        >
            {{ name }}
        </span>
        <span
            v-if="distanceMeters !== null && distanceMeters > 0"
            class="font-mono whitespace-nowrap text-zinc-500 tabular-nums dark:text-zinc-400"
        >
            {{ units.formatDistance(distanceMeters) }}
        </span>
        <span
            v-if="secondaryMetric"
            class="hidden font-mono whitespace-nowrap text-zinc-500 tabular-nums sm:inline dark:text-zinc-400"
        >
            {{ secondaryMetric }}
        </span>
        <span
            v-if="avgHr !== null"
            class="hidden font-mono whitespace-nowrap text-zinc-500 tabular-nums xl:inline dark:text-zinc-400"
        >
            {{ Math.round(avgHr) }} bpm
        </span>
        <ArrowUpRight
            class="size-3.5 shrink-0 text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-zinc-700 dark:text-zinc-500 dark:group-hover:text-zinc-200"
        />
    </Link>
</template>
