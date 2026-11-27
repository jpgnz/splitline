<script setup lang="ts">
import { computed, ref } from 'vue';
import InfoPopover from '@/components/InfoPopover.vue';
import { formatDurationClock } from '@/lib/format';
import { KM_TO_MI, useUnits } from '@/lib/units';
import type { PaceDistributionPayload, PaceZoneBucket } from '@/types/activity';

const props = defineProps<{
    paceDistribution: PaceDistributionPayload;
}>();

const { isImperial, paceUnit } = useUnits();
const mode = ref<'gap' | 'pace'>('gap');

const activeZones = computed<PaceZoneBucket[]>(() =>
    mode.value === 'gap'
        ? props.paceDistribution.gap_zones
        : props.paceDistribution.pace_zones,
);

function formatSecPerUnit(secPerKm: number): string {
    const adjusted = isImperial.value
        ? Math.round(secPerKm / KM_TO_MI)
        : Math.round(secPerKm);
    const mins = Math.floor(adjusted / 60);
    const rem = adjusted % 60;

    return `${mins}:${String(rem).padStart(2, '0')}`;
}

function formatRange(zone: PaceZoneBucket): string {
    if (zone.min_pace_sec === null && zone.max_pace_sec !== null) {
        return `< ${formatSecPerUnit(zone.max_pace_sec)}`;
    }

    if (zone.min_pace_sec !== null && zone.max_pace_sec === null) {
        return `> ${formatSecPerUnit(zone.min_pace_sec)}`;
    }

    if (zone.min_pace_sec !== null && zone.max_pace_sec !== null) {
        return `${formatSecPerUnit(zone.min_pace_sec)} – ${formatSecPerUnit(zone.max_pace_sec)}`;
    }

    return '';
}

function barClass(zone: number): string {
    const map: Record<number, string> = {
        6: 'bg-sky-900 dark:bg-sky-400',
        5: 'bg-sky-700 dark:bg-sky-500',
        4: 'bg-sky-600 dark:bg-sky-500/85',
        3: 'bg-sky-500 dark:bg-sky-600',
        2: 'bg-sky-400 dark:bg-sky-700',
        1: 'bg-zinc-400 dark:bg-zinc-600',
    };

    return map[zone] ?? 'bg-sky-500';
}

const formattedAnchor5k = computed<string>(() =>
    formatDurationClock(props.paceDistribution.anchor_5k_seconds),
);
</script>

<template>
    <div
        class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <span
                    class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Pace Distribution
                </span>
                <p
                    class="mt-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                >
                    Z1 – Z6 · Calibrated to {{ formattedAnchor5k }} eq. 5K ({{
                        paceDistribution.anchor_label
                    }})
                </p>
            </div>

            <div class="inline-flex items-center gap-1.5">
                <div
                    class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                >
                    <button
                        type="button"
                        class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            mode === 'gap'
                                ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="mode = 'gap'"
                    >
                        GAP
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            mode === 'pace'
                                ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="mode = 'pace'"
                    >
                        Raw Pace
                    </button>
                </div>
                <InfoPopover topic="gap" />
            </div>
        </div>

        <div class="space-y-3">
            <div v-for="zone in activeZones" :key="zone.zone" class="space-y-1">
                <div
                    class="flex items-center justify-between gap-2 font-mono text-xs tabular-nums"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            class="inline-flex w-7 justify-center rounded bg-zinc-100 py-0.5 text-[10px] font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                        >
                            Z{{ zone.zone }}
                        </span>
                        <span
                            class="truncate font-sans text-xs font-medium text-zinc-800 dark:text-zinc-200"
                        >
                            {{ zone.label }}
                        </span>
                        <span
                            v-if="formatRange(zone)"
                            class="hidden text-[11px] text-zinc-400 sm:inline dark:text-zinc-500"
                        >
                            {{ formatRange(zone) }} {{ paceUnit }}
                        </span>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-zinc-700 dark:text-zinc-300">
                            {{ formatDurationClock(zone.seconds) }}
                        </span>
                        <span
                            class="w-10 text-right text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{ Math.round(zone.pct) }}%
                        </span>
                    </div>
                </div>

                <div
                    class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                >
                    <div
                        class="h-full rounded-full transition-all"
                        :class="barClass(zone.zone)"
                        :style="{
                            width: `${Math.max(0, Math.min(100, zone.pct))}%`,
                        }"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
