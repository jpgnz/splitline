<script setup lang="ts">
import {
    Gauge,
    HeartPulse,
    Minus,
    TrendingDown,
    TrendingUp,
} from '@lucide/vue';
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { useUnits } from '@/lib/units';
import type { ActivityDerivedMetricsPayload } from '@/types/activity';

const props = defineProps<{
    derivedMetrics: ActivityDerivedMetricsPayload | null;
}>();

const { efficiencyFactorFromMetric, efficiencyFactorUnit } = useUnits();

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
    () =>
        hasDecoupling.value ||
        hasEf.value ||
        hasPacing.value ||
        hasHrSplit.value,
);

const visibleTileCount = computed<number>(
    () =>
        Number(hasDecoupling.value) +
        Number(hasEf.value) +
        Number(hasPacing.value) +
        Number(hasHrSplit.value),
);

const gridColsClass = computed<string>(() => {
    if (visibleTileCount.value <= 1) {
        return 'grid-cols-1';
    }

    if (visibleTileCount.value === 2) {
        return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-2';
    }

    if (visibleTileCount.value === 3) {
        return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
    }

    return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4';
});

const metricMeta = computed<{
    code: string;
    efUnit: string;
    subtitle: string;
    tooltip: string;
}>(() => {
    const metric = props.derivedMetrics?.decoupling_metric;

    if (metric === 'power') {
        return {
            code: 'Pw:Hr',
            efUnit: 'W/bpm',
            subtitle: 'Power vs Heart Rate',
            tooltip:
                'Power-to-Heart-Rate aerobic decoupling: compares watts per heartbeat in the 1st vs 2nd half (<5% = well coupled)',
        };
    }

    const paceEfUnit = efficiencyFactorUnit(metric);

    if (metric === 'gap') {
        return {
            code: 'Pa:Hr',
            efUnit: paceEfUnit,
            subtitle: 'Grade-Adjusted Pace vs HR',
            tooltip:
                'Grade-Adjusted Pace-to-Heart-Rate aerobic decoupling: compares output per heartbeat in the 1st vs 2nd half (<5% = well coupled)',
        };
    }

    return {
        code: 'Spd:Hr',
        efUnit: paceEfUnit,
        subtitle: 'Speed vs Heart Rate',
        tooltip:
            'Speed-to-Heart-Rate aerobic decoupling: compares speed per heartbeat in the 1st vs 2nd half (<5% = well coupled)',
    };
});

function convertEfValue(value: number | null | undefined): number | null {
    return efficiencyFactorFromMetric(
        value,
        props.derivedMetrics?.decoupling_metric,
    );
}

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
    toneClass: string;
    icon: 'up' | 'even' | 'down';
} | null>(() => {
    const strategy = props.derivedMetrics?.pacing_strategy;

    if (!strategy) {
        return null;
    }

    if (strategy === 'negative_split') {
        return {
            label: 'Negative Split',
            toneClass: 'text-emerald-700 dark:text-emerald-400',
            icon: 'up',
        };
    }

    if (strategy === 'positive_split') {
        return {
            label: 'Positive Split',
            toneClass: 'text-amber-700 dark:text-amber-400',
            icon: 'down',
        };
    }

    return {
        label: 'Even Split',
        toneClass: 'text-sky-700 dark:text-sky-400',
        icon: 'even',
    };
});

const splitDirectionText = computed<string | null>(() => {
    const ratio = props.derivedMetrics?.split_ratio;

    if (ratio === null || ratio === undefined || !Number.isFinite(ratio)) {
        return null;
    }

    const diffPct = (ratio - 1) * 100;

    if (Math.abs(diffPct) < 0.1) {
        return 'Even halves';
    }

    if (diffPct > 0) {
        return `+${formatNumber(diffPct, 1)}% faster 2nd half`;
    }

    return `−${formatNumber(Math.abs(diffPct), 1)}% slower 2nd half`;
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
        <!-- Header (aligned with sibling telemetry cards) -->
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <span class="size-1.5 rounded-full bg-emerald-500" />
                <h2
                    class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Aerobic Efficiency &amp; Pacing
                </h2>
            </div>

            <span
                v-if="hasDecoupling"
                class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
            >
                {{ metricMeta.subtitle }}
            </span>
        </div>

        <!-- Core Metrics Grid -->
        <div class="grid gap-3" :class="gridColsClass">
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
                        :title="metricMeta.tooltip"
                        class="cursor-help rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[10px] font-medium text-zinc-600 underline decoration-zinc-400/70 decoration-dotted underline-offset-2 dark:bg-zinc-800 dark:text-zinc-300"
                    >
                        {{ metricMeta.code }}
                    </span>
                </div>
                <div class="mt-1.5 flex flex-wrap items-baseline gap-2">
                    <span
                        class="font-mono text-xl font-semibold tracking-tight tabular-nums"
                        :class="
                            decouplingBadge?.valueClass ??
                            'text-zinc-900 dark:text-zinc-100'
                        "
                    >
                        {{ formattedDecoupling }}
                    </span>
                    <span
                        v-if="decouplingBadge"
                        class="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 font-mono text-[10px] font-medium"
                        :class="decouplingBadge.badgeClass"
                    >
                        <span
                            class="size-1.5 rounded-full"
                            :class="decouplingBadge.dotClass"
                        />
                        <span>{{ decouplingBadge.label }}</span>
                    </span>
                </div>
                <div
                    v-if="
                        derivedMetrics.first_half_ef !== null &&
                        derivedMetrics.second_half_ef !== null
                    "
                    class="mt-1 font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    1st
                    {{
                        formatNumber(
                            convertEfValue(derivedMetrics.first_half_ef),
                            2,
                        )
                    }}
                    → 2nd
                    {{
                        formatNumber(
                            convertEfValue(derivedMetrics.second_half_ef),
                            2,
                        )
                    }}
                    EF
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
                        {{
                            formatNumber(
                                convertEfValue(
                                    derivedMetrics.efficiency_factor,
                                ),
                                2,
                            )
                        }}
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
                <div class="flex items-center justify-between gap-2">
                    <span
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Split Pacing
                    </span>
                    <span
                        v-if="pacingBadge"
                        class="inline-flex items-center gap-1 font-mono text-[10px] font-medium"
                        :class="pacingBadge.toneClass"
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
                <div
                    class="mt-1.5 flex flex-wrap items-baseline gap-x-2 tabular-nums"
                >
                    <span
                        title="Ratio of 2nd-half speed to 1st-half speed (>1.000× = negative split, faster 2nd half)"
                        class="cursor-help font-mono text-xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        {{
                            derivedMetrics.split_ratio !== null
                                ? `${formatNumber(derivedMetrics.split_ratio, 3)}×`
                                : '—'
                        }}
                    </span>
                    <span
                        v-if="splitDirectionText"
                        class="font-mono text-[11px] font-medium"
                        :class="
                            pacingBadge?.toneClass ??
                            'text-zinc-500 dark:text-zinc-400'
                        "
                    >
                        {{ splitDirectionText }}
                    </span>
                </div>
                <div
                    v-if="derivedMetrics.pace_cv_pct !== null"
                    class="mt-1 font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    <span
                        title="Coefficient of Variation of split paces — lower means steadier, more even pacing"
                        class="cursor-help underline decoration-zinc-400/70 decoration-dotted underline-offset-2"
                    >
                        {{ formatNumber(derivedMetrics.pace_cv_pct, 1) }}% CV
                    </span>
                    <span> · Split pace variability</span>
                </div>
            </div>

            <!-- 4. HR Intensity Split -->
            <div
                v-if="hasHrSplit"
                class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-3 dark:border-zinc-800/70 dark:bg-zinc-900/40"
            >
                <div class="flex items-center justify-between gap-2">
                    <span
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        HR Intensity Split
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
