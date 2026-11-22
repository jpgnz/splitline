<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, Target } from '@lucide/vue';
import { formatNumber } from '@/lib/format';
import { useUnits } from '@/lib/units';
import { index as goalsIndex } from '@/routes/goals';

export interface CompactGoalProgress {
    id: number;
    title: string;
    sport: string;
    metric: string;
    period: string;
    target_value: number;
    current_value: number;
    expected_pct: number;
    pct: number;
    unit: string;
    pace_status: string;
    pace_label: string;
    period_label: string;
    days_remaining: number;
    delta?: number;
}

withDefaults(
    defineProps<{
        goals: CompactGoalProgress[];
        accent?: 'amber' | 'sky' | 'emerald' | 'indigo';
        title?: string;
    }>(),
    {
        accent: 'indigo',
        title: 'Active Goals',
    },
);

const {
    isImperial,
    distanceUnit,
    elevationUnit,
    distanceFromMeters,
    elevationFromMeters,
} = useUnits();

const accentBarMap: Record<string, string> = {
    amber: 'bg-amber-500',
    sky: 'bg-sky-500',
    emerald: 'bg-emerald-500',
    indigo: 'bg-indigo-500',
};

function paceBadgeClass(status: string): string {
    if (status === 'completed' || status === 'ahead') {
        return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400';
    }

    if (status === 'on_track') {
        return 'bg-sky-500/15 text-sky-600 dark:text-sky-400';
    }

    return 'bg-amber-500/15 text-amber-600 dark:text-amber-400';
}

function goalUnit(goal: CompactGoalProgress): string {
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

function formatGoalValue(value: number, metric: string): string {
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

function formatPaceLabel(goal: CompactGoalProgress): string {
    if (
        !isImperial.value ||
        (goal.metric !== 'distance_km' && goal.metric !== 'elevation_m')
    ) {
        return goal.pace_label;
    }

    if (goal.pace_status === 'completed' || goal.pace_status === 'on_track') {
        return goal.pace_label;
    }

    let rawDelta: number | null = null;

    if (typeof goal.delta === 'number' && Number.isFinite(goal.delta)) {
        rawDelta = Math.abs(goal.delta);
    } else {
        const match = /([0-9]+(?:\.[0-9]+)?)\s*(?:km|m)\b/i.exec(
            goal.pace_label,
        );

        if (match) {
            rawDelta = Number(match[1]);
        } else {
            const expectedValue = (goal.target_value * goal.expected_pct) / 100;
            rawDelta = Math.abs(goal.current_value - expectedValue);
        }
    }

    if (rawDelta === null || !Number.isFinite(rawDelta)) {
        return goal.pace_label;
    }

    const formattedDelta =
        goal.metric === 'distance_km'
            ? formatNumber(distanceFromMeters(rawDelta * 1000), 1)
            : formatNumber(Math.round(elevationFromMeters(rawDelta)), 0);
    const unit = goalUnit(goal);

    if (goal.pace_status === 'ahead') {
        return `On track · +${formattedDelta} ${unit} ahead`;
    }

    return `Behind · ${formattedDelta} ${unit} behind`;
}
</script>

<template>
    <section
        v-if="goals.length > 0"
        class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <div class="mb-3 flex items-center justify-between gap-3">
            <span
                class="flex items-center gap-1.5 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
            >
                <Target class="size-3.5" />
                {{ title }}
            </span>
            <Link
                :href="goalsIndex()"
                class="inline-flex items-center gap-1 font-mono text-[11px] text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            >
                Manage
                <ArrowUpRight class="size-3" />
            </Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="goal in goals"
                :key="goal.id"
                class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5] p-3 dark:border-zinc-800/80 dark:bg-zinc-900/40"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p
                            class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            {{ goal.title }}
                        </p>
                        <p
                            class="font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            {{ goal.period }} · {{ goal.days_remaining }}d left
                        </p>
                    </div>
                    <span
                        :class="[
                            'shrink-0 rounded-full px-2 py-0.5 font-mono text-[10px] font-medium tabular-nums',
                            paceBadgeClass(goal.pace_status),
                        ]"
                    >
                        {{ Math.round(goal.pct) }}%
                    </span>
                </div>

                <div
                    class="relative mt-2.5 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800"
                >
                    <div
                        :class="[
                            'h-full rounded-full transition-all',
                            accentBarMap[accent] ?? 'bg-indigo-500',
                        ]"
                        :style="{ width: `${Math.min(100, goal.pct)}%` }"
                    />
                    <span
                        class="absolute top-0 bottom-0 w-0.5 bg-zinc-900/50 dark:bg-zinc-100/60"
                        :style="{
                            left: `${Math.min(100, Math.max(0, goal.expected_pct))}%`,
                        }"
                    />
                </div>

                <div
                    class="mt-2 flex items-center justify-between gap-2 font-mono text-[11px] tabular-nums"
                >
                    <span class="text-zinc-700 dark:text-zinc-300">
                        {{ formatGoalValue(goal.current_value, goal.metric) }} /
                        {{ formatGoalValue(goal.target_value, goal.metric) }}
                        {{ goalUnit(goal) }}
                    </span>
                    <span class="truncate text-zinc-500 dark:text-zinc-400">
                        {{ formatPaceLabel(goal) }}
                    </span>
                </div>
            </div>
        </div>
    </section>
</template>
