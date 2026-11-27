<script setup lang="ts">
import {
    Activity,
    Gauge,
    HeartPulse,
    Minus,
    TrendingDown,
    TrendingUp,
} from '@lucide/vue';
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import type { ActivityDerivedMetricsPayload } from '@/types/activity';

const props = defineProps<{
    derivedMetrics: ActivityDerivedMetricsPayload | null;
}>();

const hasDecoupling = computed<boolean>(
    () =>
        props.derivedMetrics !== null &&
        props.derivedMetrics.decoupling_pct !== null &&
        Number.isFinite(props.derivedMetrics.decoupling_pct),
);

const hasEf = computed<boolean>(
    () =>
        props.derivedMetrics !== null &&
        props.derivedMetrics.efficiency_factor !== null &&
        Number.isFinite(props.derivedMetrics.efficiency_factor),
);

const hasPacing = computed<boolean>(
    () =>
        props.derivedMetrics !== null &&
        props.derivedMetrics.pacing_strategy !== null,
);

const hasHrSplit = computed<boolean>(
    () =>
        props.derivedMetrics !== null &&
        props.derivedMetrics.hr_low_pct !== null &&
        props.derivedMetrics.hr_moderate_pct !== null &&
        props.derivedMetrics.hr_high_pct !== null,
);

const isVisible = computed<boolean>(
    () => hasDecoupling.value || hasEf.value || hasPacing.value,
);

const metricMeta = computed<{ code: string; efUnit: string; subtitle: string }>(
    () => {
        const metric = props.derivedMetrics?.decoupling_metric;

        if (metric === 'power') {
            return {
                code: 'Pw:Hr',
                efUnit: 'W/bpm',
                subtitle: 'Power vs Heart Rate',
            };
        }

        if (metric === 'gap') {
            return {
                code: 'Pa:Hr',
                efUnit: 'm/min/bpm',
                subtitle: 'Grade-Adjusted Pace vs HR',
            };
        }

        return {
            code: 'Spd:Hr',
            efUnit: 'm/min/bpm',
            subtitle: 'Speed vs Heart Rate',
        };
    },
);

const decouplingBadge = computed<{
    label: string;
    thresholdHint: string;
    badgeClass: string;
    dotClass: string;
    valueClass: string;
} | null>(() => {
    const rating = props.derivedMetrics?.decoupling_rating;

    if (!hasDecoupling.value || !rating) {
        return null;
    }

    const pct = props.derivedMetrics?.decoupling_pct ?? 0;

    // Strongly negative drift (HR falling vs output) usually means a skewed warm-up or HR data issue.
    if (pct < -5) {
        return {
            label: 'Negative Drift',
            thresholdHint: 'check warm-up / HR data',
            badgeClass:
                'border-sky-500/35 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-300',
            dotClass: 'bg-sky-500',
            valueClass: 'text-sky-700 dark:text-sky-400',
        };
    }

    if (rating === 'well_coupled') {
        return {
            label: 'Well Coupled',
            thresholdHint: '<5%',
            badgeClass:
                'border-emerald-500/35 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300',
            dotClass: 'bg-emerald-500',
            valueClass: 'text-emerald-700 dark:text-emerald-400',
        };
    }

    if (rating === 'moderate_drift') {
        return {
            label: 'Moderate Drift',
            thresholdHint: '5–10%',
            badgeClass:
                'border-amber-500/35 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300',
            dotClass: 'bg-amber-500',
            valueClass: 'text-amber-700 dark:text-amber-400',
        };
    }

    return {
        label: 'High Drift',
        thresholdHint: '>10%',
        badgeClass:
            'border-rose-500/35 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/15 dark:text-rose-300',
        dotClass: 'bg-rose-500',
        valueClass: 'text-rose-700 dark:text-rose-400',
    };
});

const formattedDecoupling = computed<string>(() => {
    const val = props.derivedMetrics?.decoupling_pct;

    if (val === null || val === undefined || !Number.isFinite(val)) {
        return '—';
    }

    const prefix = val > 0 ? '+' : '';

    return `${prefix}${formatNumber(val, 1)}%`;
});

const pacingBadge = computed<{
    label: string;
    pillClass: string;
    icon: 'up' | 'even' | 'down';
} | null>(() => {
    const strategy = props.derivedMetrics?.pacing_strategy;

    if (!strategy) {
        return null;
    }

    if (strategy === 'negative_split') {
        return {
            label: 'Negative Split',
            pillClass:
                'border-emerald-500/35 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300',
            icon: 'up',
        };
    }

    if (strategy === 'positive_split') {
        return {
            label: 'Positive Split',
            pillClass:
                'border-amber-500/35 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300',
            icon: 'down',
        };
    }

    return {
        label: 'Even Split',
        pillClass:
            'border-sky-500/35 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-300',
        icon: 'even',
    };
});

const hrSegments = computed<{ low: number; moderate: number; high: number }>(
    () => {
        const d = props.derivedMetrics;

        if (
            !d ||
            d.hr_low_pct === null ||
            d.hr_moderate_pct === null ||
            d.hr_high_pct === null
        ) {
            return { low: 0, moderate: 0, high: 0 };
        }

        const low = Math.max(0, Math.min(100, d.hr_low_pct));
        const moderate = Math.max(0, Math.min(100, d.hr_moderate_pct));
        const high = Math.max(0, Math.min(100, d.hr_high_pct));

        return { low, moderate, high };
    },
);
</script>

<template>
    <section
        v-if="isVisible && derivedMetrics"
        class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <!-- Header -->
        <div
            class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200/70 pb-3 dark:border-zinc-800/70"
        >
            <div class="flex items-center gap-2.5">
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:bg-emerald-400/10 dark:text-emerald-400"
                >
                    <Activity class="size-4" />
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <h2
                            class="text-sm font-semibold tracking-tight text-zinc-950 dark:text-zinc-50"
                        >
                            Aerobic Efficiency &amp; Pacing
                        </h2>
                        <span
                            v-if="hasDecoupling"
                            class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{ metricMeta.code }} · {{ metricMeta.subtitle }}
                        </span>
                    </div>
                    <p
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Cardiac drift, efficiency factor, split consistency, and
                        intensity distribution
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span
                    v-if="decouplingBadge"
                    class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 font-mono text-[11px] font-medium"
                    :class="decouplingBadge.badgeClass"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="decouplingBadge.dotClass"
                    />
                    <span>{{ decouplingBadge.label }}</span>
                    <span class="opacity-70">{{
                        decouplingBadge.thresholdHint
                    }}</span>
                </span>

                <span
                    v-if="pacingBadge"
                    class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[11px] font-medium"
                    :class="pacingBadge.pillClass"
                >
                    <TrendingUp
                        v-if="pacingBadge.icon === 'up'"
                        class="size-3"
                    />
                    <TrendingDown
                        v-else-if="pacingBadge.icon === 'down'"
                        class="size-3"
                    />
                    <Minus v-else class="size-3" />
                    <span>{{ pacingBadge.label }}</span>
                </span>
            </div>
        </div>

        <!-- Core Metrics Grid -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <!-- 1. Aerobic Decoupling -->
            <div
                v-if="hasDecoupling"
                class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-3 dark:border-zinc-800/70 dark:bg-zinc-900/40"
            >
                <div class="flex items-center justify-between gap-2">
                    <span
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Aerobic Decoupling
                    </span>
                    <span
                        class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                    >
                        {{ metricMeta.code }}
                    </span>
                </div>
                <div
                    class="mt-1.5 font-mono text-xl font-semibold tracking-tight tabular-nums"
                    :class="
                        decouplingBadge?.valueClass ??
                        'text-zinc-900 dark:text-zinc-100'
                    "
                >
                    {{ formattedDecoupling }}
                </div>
                <div
                    v-if="
                        derivedMetrics.first_half_ef !== null &&
                        derivedMetrics.second_half_ef !== null
                    "
                    class="mt-1 font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    1st {{ formatNumber(derivedMetrics.first_half_ef, 2) }} →
                    2nd {{ formatNumber(derivedMetrics.second_half_ef, 2) }} EF
                </div>
            </div>

            <!-- 2. Efficiency Factor -->
            <div
                v-if="hasEf"
                class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-3 dark:border-zinc-800/70 dark:bg-zinc-900/40"
            >
                <div class="flex items-center justify-between gap-2">
                    <span
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Efficiency Factor
                    </span>
                    <Gauge class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                </div>
                <div class="mt-1.5 flex items-baseline gap-1.5 tabular-nums">
                    <span
                        class="font-mono text-xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        {{ formatNumber(derivedMetrics.efficiency_factor, 2) }}
                    </span>
                    <span
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        {{ metricMeta.efUnit }}
                    </span>
                </div>
                <div
                    class="mt-1 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                >
                    Higher EF = more output per heartbeat
                </div>
            </div>

            <!-- 3. Pacing Strategy & Consistency -->
            <div
                v-if="hasPacing"
                class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-3 dark:border-zinc-800/70 dark:bg-zinc-900/40"
            >
                <div
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Split Pacing
                </div>
                <div
                    class="mt-1.5 font-mono text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{
                        derivedMetrics.split_ratio !== null
                            ? `${formatNumber(derivedMetrics.split_ratio, 3)}×`
                            : '—'
                    }}
                </div>
                <div
                    class="mt-1 flex flex-wrap items-center gap-x-2 font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    <span>{{ pacingBadge?.label }}</span>
                    <span v-if="derivedMetrics.pace_cv_pct !== null">
                        · {{ formatNumber(derivedMetrics.pace_cv_pct, 1) }}% CV
                    </span>
                </div>
            </div>

            <!-- 4. Polarized HR Split -->
            <div
                v-if="hasHrSplit"
                class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-3 dark:border-zinc-800/70 dark:bg-zinc-900/40"
            >
                <div class="flex items-center justify-between gap-2">
                    <span
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Polarized HR Split
                    </span>
                    <HeartPulse
                        class="size-3.5 text-rose-500/80 dark:text-rose-400/80"
                    />
                </div>

                <div
                    class="mt-2.5 flex h-2.5 w-full overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-800"
                >
                    <div
                        v-if="hrSegments.low > 0"
                        class="h-full bg-emerald-500"
                        :style="{ width: `${hrSegments.low}%` }"
                        :title="`Easy Z1–Z2: ${formatNumber(hrSegments.low, 1)}%`"
                    />
                    <div
                        v-if="hrSegments.moderate > 0"
                        class="h-full bg-amber-500"
                        :style="{ width: `${hrSegments.moderate}%` }"
                        :title="`Moderate Z3: ${formatNumber(hrSegments.moderate, 1)}%`"
                    />
                    <div
                        v-if="hrSegments.high > 0"
                        class="h-full bg-rose-500"
                        :style="{ width: `${hrSegments.high}%` }"
                        :title="`Hard Z4–Z5: ${formatNumber(hrSegments.high, 1)}%`"
                    />
                </div>

                <div
                    class="mt-2 flex items-center justify-between gap-1 font-mono text-[10px] tabular-nums"
                >
                    <span class="text-emerald-700 dark:text-emerald-400">
                        Z1–2 {{ Math.round(hrSegments.low) }}%
                    </span>
                    <span class="text-amber-700 dark:text-amber-400">
                        Z3 {{ Math.round(hrSegments.moderate) }}%
                    </span>
                    <span class="text-rose-700 dark:text-rose-400">
                        Z4–5 {{ Math.round(hrSegments.high) }}%
                    </span>
                </div>
            </div>
        </div>
    </section>
</template>
