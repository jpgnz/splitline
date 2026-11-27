<script setup lang="ts">
import {
    ArrowRight,
    Cloud,
    CloudDrizzle,
    CloudFog,
    CloudLightning,
    CloudRain,
    CloudSun,
    RefreshCw,
    Snowflake,
    Sun,
    Wind,
} from '@lucide/vue';
import { type Component, computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { useUnits } from '@/lib/units';
import { formatCompass } from '@/lib/wind';
import type {
    ActivityDerivedMetricsPayload,
    ActivityWeatherPayload,
} from '@/types/activity';

export interface SnapshotTelemetryCell {
    label: string;
    value: string;
    sub?: string | null;
}

const props = withDefaults(
    defineProps<{
        derivedMetrics: ActivityDerivedMetricsPayload | null;
        weather: ActivityWeatherPayload | null;
        isIndoor: boolean;
        hasCoordinates: boolean;
        syncingWeather?: boolean;
        disciplineTitle: string | null;
        disciplineCells: SnapshotTelemetryCell[];
        accentHex: string;
    }>(),
    {
        syncingWeather: false,
    },
);

const emit = defineEmits<{
    syncWeather: [];
    viewAllStats: [];
}>();

const {
    efficiencyFactorFromMetric,
    efficiencyFactorUnit,
    formatPrecip,
    formatSpeedKmh,
    formatTemp,
} = useUnits();

// --- Derived Metrics ---
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

const showDerivedCard = computed<boolean>(
    () =>
        hasDecoupling.value ||
        hasEf.value ||
        hasPacing.value ||
        hasHrSplit.value,
);

const metricMeta = computed<{
    code: string;
    efUnit: string;
    tooltip: string;
}>(() => {
    const metric = props.derivedMetrics?.decoupling_metric;

    if (metric === 'power') {
        return {
            code: 'Pw:Hr',
            efUnit: 'W/bpm',
            tooltip:
                'Power-to-Heart-Rate aerobic decoupling: compares watts per heartbeat in the 1st vs 2nd half (<5% = well coupled)',
        };
    }

    const paceEfUnit = efficiencyFactorUnit(metric);

    if (metric === 'gap') {
        return {
            code: 'Pa:Hr',
            efUnit: paceEfUnit,
            tooltip:
                'Grade-Adjusted Pace-to-Heart-Rate aerobic decoupling: compares output per heartbeat in the 1st vs 2nd half (<5% = well coupled)',
        };
    }

    return {
        code: 'Spd:Hr',
        efUnit: paceEfUnit,
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
    badgeClass: string;
    valueClass: string;
} | null>(() => {
    const rating = props.derivedMetrics?.decoupling_rating;

    if (!hasDecoupling.value || !rating) {
        return null;
    }

    if (rating === 'well_coupled') {
        return {
            label: 'Well Coupled',
            badgeClass:
                'border-emerald-500/35 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300',
            valueClass: 'text-emerald-700 dark:text-emerald-400',
        };
    }

    if (rating === 'moderate_drift') {
        return {
            label: 'Moderate Drift',
            badgeClass:
                'border-amber-500/35 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300',
            valueClass: 'text-amber-700 dark:text-amber-400',
        };
    }

    return {
        label: 'High Drift',
        badgeClass:
            'border-rose-500/35 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/15 dark:text-rose-300',
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

const pacingMeta = computed<{ label: string; toneClass: string } | null>(() => {
    const strategy = props.derivedMetrics?.pacing_strategy;

    if (!strategy) {
        return null;
    }

    if (strategy === 'negative_split') {
        return {
            label: 'Negative Split',
            toneClass: 'text-emerald-700 dark:text-emerald-400',
        };
    }

    if (strategy === 'positive_split') {
        return {
            label: 'Positive Split',
            toneClass: 'text-amber-700 dark:text-amber-400',
        };
    }

    return {
        label: 'Even Split',
        toneClass: 'text-sky-700 dark:text-sky-400',
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

        return {
            low: Math.max(0, Math.min(100, d.hr_low_pct)),
            moderate: Math.max(0, Math.min(100, d.hr_moderate_pct)),
            high: Math.max(0, Math.min(100, d.hr_high_pct)),
        };
    },
);

// --- Weather ---
const showWeatherCard = computed<boolean>(
    () => !props.isIndoor && (props.weather !== null || props.hasCoordinates),
);

const WEATHER_ICONS: Record<string, Component> = {
    wind: Wind,
    sun: Sun,
    'cloud-sun': CloudSun,
    cloud: Cloud,
    'cloud-rain': CloudRain,
    'cloud-drizzle': CloudDrizzle,
    'cloud-fog': CloudFog,
    snowflake: Snowflake,
    'cloud-lightning': CloudLightning,
};

const weatherIconComponent = computed<Component>(() => {
    const key = props.weather?.icon ?? 'cloud';

    return WEATHER_ICONS[key] ?? Cloud;
});

const samplingMeta = computed<string>(() => {
    const w = props.weather;

    if (!w) {
        return '';
    }

    if (w.sampling_mode === 'route') {
        const count = w.waypoint_count;

        return `${count} ${count === 1 ? 'waypoint' : 'waypoints'}`;
    }

    if (w.sampling_mode === 'start_point') {
        return 'Start point';
    }

    return `${w.waypoint_count} ${w.sampling_mode.replace(/_/g, ' ')}`;
});

const windCompass = computed<string>(() => {
    const w = props.weather;

    if (!w) {
        return '—';
    }

    if (w.wind_direction_compass) {
        return w.wind_direction_compass;
    }

    if (w.wind_direction_deg !== null) {
        return formatCompass(w.wind_direction_deg);
    }

    return '—';
});

const windBlowingToDeg = computed<number | null>(() => {
    const deg = props.weather?.wind_direction_deg;

    if (deg === null || deg === undefined || !Number.isFinite(deg)) {
        return null;
    }

    return (((deg + 180) % 360) + 360) % 360;
});

const hasWindImpact = computed<boolean>(
    () =>
        props.weather !== null &&
        props.weather.headwind_pct !== null &&
        Number.isFinite(props.weather.headwind_pct),
);

const windImpactSegments = computed<{
    head: number;
    cross: number;
    tail: number;
}>(() => {
    const w = props.weather;
    const head = Math.max(0, w?.headwind_pct ?? 0);
    const cross = Math.max(0, w?.crosswind_pct ?? 0);
    const tail = Math.max(0, w?.tailwind_pct ?? 0);
    const sum = head + cross + tail;

    if (sum <= 0) {
        return { head: 0, cross: 0, tail: 0 };
    }

    const headRounded = Math.round((head / sum) * 100);
    const crossRounded = Math.round((cross / sum) * 100);
    const tailRounded = Math.max(0, 100 - headRounded - crossRounded);

    return { head: headRounded, cross: crossRounded, tail: tailRounded };
});

const netEffectiveWind = computed<{
    text: string;
    toneClass: string;
} | null>(() => {
    const kmh = props.weather?.avg_effective_wind_kmh;

    if (kmh === null || kmh === undefined || !Number.isFinite(kmh)) {
        return null;
    }

    const normalized = Math.abs(kmh) < 0.05 ? 0 : kmh;
    const formatted = formatSpeedKmh(normalized, 1);
    const text = normalized > 0 ? `+${formatted}` : formatted;

    const toneClass =
        normalized <= -1
            ? 'text-rose-600 dark:text-rose-400'
            : normalized >= 1
              ? 'text-emerald-600 dark:text-emerald-400'
              : 'text-zinc-500 dark:text-zinc-400';

    return { text, toneClass };
});

// --- Top Discipline Highlights ---
const showDisciplineCard = computed<boolean>(
    () => Boolean(props.disciplineTitle) && props.disciplineCells.length > 0,
);

const highlightedDisciplineCells = computed<SnapshotTelemetryCell[]>(() => {
    const limit = showDerivedCard.value && showWeatherCard.value ? 4 : 6;

    return props.disciplineCells.slice(0, limit);
});
</script>

<template>
    <div class="flex h-full flex-col gap-4">
        <!-- 1. Aerobic Efficiency & Pacing Snapshot -->
        <section
            v-if="showDerivedCard && derivedMetrics"
            class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="mb-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="size-1.5 rounded-full bg-emerald-500" />
                    <h2
                        class="font-mono text-[11px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Session Snapshot
                    </h2>
                </div>

                <span
                    v-if="decouplingBadge"
                    :title="metricMeta.tooltip"
                    class="cursor-help rounded border px-1.5 py-0.5 font-mono text-[10px] font-medium"
                    :class="decouplingBadge.badgeClass"
                >
                    {{ decouplingBadge.label }}
                </span>
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <div
                    v-if="hasDecoupling"
                    class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-2.5 dark:border-zinc-800/70 dark:bg-zinc-900/40"
                >
                    <div
                        class="flex items-center justify-between gap-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        <span class="truncate">Decoupling</span>
                        <span
                            :title="metricMeta.tooltip"
                            class="shrink-0 cursor-help text-zinc-400 dark:text-zinc-500"
                        >
                            {{ metricMeta.code }}
                        </span>
                    </div>
                    <div
                        class="mt-1 font-mono text-base font-semibold tracking-tight tabular-nums"
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
                        class="mt-0.5 truncate font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
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
                    </div>
                </div>

                <div
                    v-if="hasPacing"
                    class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-2.5 dark:border-zinc-800/70 dark:bg-zinc-900/40"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Split Pacing
                    </div>
                    <div
                        class="mt-1 font-mono text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{
                            derivedMetrics.split_ratio !== null
                                ? `${formatNumber(derivedMetrics.split_ratio, 3)}×`
                                : '—'
                        }}
                    </div>
                    <div
                        v-if="pacingMeta"
                        class="mt-0.5 truncate font-mono text-[10px] font-medium"
                        :class="pacingMeta.toneClass"
                    >
                        {{ pacingMeta.label }}
                    </div>
                </div>

                <div
                    v-if="hasEf"
                    class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-2.5 dark:border-zinc-800/70 dark:bg-zinc-900/40"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Efficiency Factor
                    </div>
                    <div
                        class="mt-1 flex items-baseline gap-1 font-mono tabular-nums"
                    >
                        <span
                            class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
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
                            class="text-[10px] text-zinc-500 dark:text-zinc-400"
                        >
                            {{ metricMeta.efUnit }}
                        </span>
                    </div>
                    <div
                        v-if="derivedMetrics.pace_cv_pct !== null"
                        class="mt-0.5 truncate font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ formatNumber(derivedMetrics.pace_cv_pct, 1) }}% split
                        CV
                    </div>
                </div>

                <div
                    v-if="hasHrSplit"
                    class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-2.5 dark:border-zinc-800/70 dark:bg-zinc-900/40"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        HR Intensity
                    </div>
                    <div
                        class="mt-2.5 flex h-2 w-full overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-800"
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
                        class="mt-1.5 flex items-center justify-between gap-1 font-mono text-[10px] tabular-nums"
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

        <!-- 2. Compact Weather & Wind Card -->
        <section
            v-if="showWeatherCard"
            :class="
                weather
                    ? 'rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]'
                    : 'flex items-center justify-between gap-3 rounded-xl border border-dashed border-zinc-300 bg-white/70 p-4 dark:border-zinc-700 dark:bg-[#141417]/70'
            "
        >
            <template v-if="!weather">
                <div class="min-w-0">
                    <p
                        class="font-mono text-xs font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Historical weather not synced
                    </p>
                    <p
                        class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                    >
                        Fetch route weather &amp; wind from Open-Meteo.
                    </p>
                </div>
                <button
                    type="button"
                    :disabled="syncingWeather"
                    class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-[11px] font-medium text-zinc-800 transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                    @click="emit('syncWeather')"
                >
                    <RefreshCw
                        :class="['size-3', syncingWeather && 'animate-spin']"
                    />
                    {{ syncingWeather ? 'Fetching...' : 'Fetch Weather' }}
                </button>
            </template>

            <template v-else>
                <div class="mb-2.5 flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <component
                            :is="weatherIconComponent"
                            class="size-3.5 shrink-0 text-sky-500 dark:text-sky-400"
                        />
                        <h2
                            class="truncate font-mono text-[11px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Weather · {{ weather.condition }}
                        </h2>
                    </div>

                    <a
                        href="https://open-meteo.com/"
                        target="_blank"
                        rel="noopener noreferrer"
                        title="Weather data by Open-Meteo.com (CC BY 4.0)"
                        class="shrink-0 font-mono text-[10px] text-zinc-400 transition-colors hover:text-zinc-700 dark:text-zinc-500 dark:hover:text-zinc-200"
                    >
                        {{ samplingMeta || 'Open-Meteo' }}
                    </a>
                </div>

                <div class="grid grid-cols-3 gap-2 font-mono tabular-nums">
                    <div class="min-w-0">
                        <div
                            class="text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Temp
                        </div>
                        <div
                            class="mt-0.5 flex flex-wrap items-baseline gap-x-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <span>{{
                                formatTemp(weather.temperature_c, 0)
                            }}</span>
                            <span
                                v-if="weather.apparent_temperature_c !== null"
                                class="text-[10px] font-normal text-zinc-400 dark:text-zinc-500"
                            >
                                feels
                                {{
                                    formatTemp(
                                        weather.apparent_temperature_c,
                                        0,
                                    )
                                }}
                            </span>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div
                            class="text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Wind
                        </div>
                        <div
                            class="mt-0.5 flex items-center gap-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <span class="truncate">{{
                                formatSpeedKmh(weather.wind_speed_kmh, 0)
                            }}</span>
                            <span
                                v-if="windCompass !== '—'"
                                class="text-[10px] font-medium text-sky-600 dark:text-sky-400"
                            >
                                {{ windCompass }}
                            </span>
                            <svg
                                v-if="windBlowingToDeg !== null"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                                class="size-3 shrink-0 text-sky-500 dark:text-sky-400"
                                :style="{
                                    transform: `rotate(${windBlowingToDeg}deg)`,
                                }"
                            >
                                <path
                                    d="M12 3L17.5 12.5L13.5 11.5V20.5H10.5V11.5L6.5 12.5L12 3Z"
                                    fill="currentColor"
                                />
                            </svg>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div
                            class="text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Humidity
                        </div>
                        <div
                            class="mt-0.5 flex flex-wrap items-baseline gap-x-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            <span>{{
                                weather.humidity_pct !== null
                                    ? `${Math.round(weather.humidity_pct)}%`
                                    : '—'
                            }}</span>
                            <span
                                v-if="weather.dew_point_c !== null"
                                class="text-[10px] font-normal text-zinc-400 dark:text-zinc-500"
                            >
                                dew {{ formatTemp(weather.dew_point_c, 0) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Wind Impact Bar (or Precipitation/Cloud fallback) -->
                <div v-if="hasWindImpact" class="mt-3">
                    <div
                        class="flex h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                        role="img"
                        :aria-label="`${windImpactSegments.head}% headwind, ${windImpactSegments.cross}% crosswind, ${windImpactSegments.tail}% tailwind`"
                    >
                        <div
                            v-if="windImpactSegments.head > 0"
                            class="h-full bg-rose-500"
                            :style="{ width: `${windImpactSegments.head}%` }"
                        />
                        <div
                            v-if="windImpactSegments.cross > 0"
                            class="h-full bg-amber-500"
                            :style="{ width: `${windImpactSegments.cross}%` }"
                        />
                        <div
                            v-if="windImpactSegments.tail > 0"
                            class="h-full bg-emerald-500"
                            :style="{ width: `${windImpactSegments.tail}%` }"
                        />
                    </div>
                    <div
                        class="mt-1.5 flex items-center justify-between gap-2 font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        <span>
                            {{ windImpactSegments.head }}% Head ·
                            {{ windImpactSegments.cross }}% Cross ·
                            {{ windImpactSegments.tail }}% Tail
                        </span>
                        <span
                            v-if="netEffectiveWind"
                            class="font-medium"
                            :class="netEffectiveWind.toneClass"
                        >
                            Net {{ netEffectiveWind.text }}
                        </span>
                    </div>
                </div>
                <div
                    v-else-if="
                        weather.cloud_cover_pct !== null ||
                        weather.precipitation_mm !== null
                    "
                    class="mt-2.5 flex items-center justify-between font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    <span v-if="weather.cloud_cover_pct !== null">
                        Cloud {{ Math.round(weather.cloud_cover_pct) }}%
                    </span>
                    <span v-if="weather.precipitation_mm !== null">
                        Precip {{ formatPrecip(weather.precipitation_mm) }}
                    </span>
                </div>
            </template>
        </section>

        <!-- 3. Top Discipline Highlights -->
        <section
            v-if="showDisciplineCard"
            class="flex-1 rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="mb-2.5 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span
                        class="size-1.5 rounded-full"
                        :style="{ backgroundColor: accentHex }"
                    />
                    <h2
                        class="font-mono text-[11px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{ disciplineTitle }}
                    </h2>
                </div>

                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 font-mono text-[10px] text-zinc-400 transition-colors hover:text-zinc-900 dark:text-zinc-500 dark:hover:text-zinc-100"
                    @click="emit('viewAllStats')"
                >
                    <span>All stats</span>
                    <ArrowRight class="size-2.5" />
                </button>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div
                    v-for="cell in highlightedDisciplineCells"
                    :key="cell.label"
                    class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 px-2.5 py-2 dark:border-zinc-800/70 dark:bg-zinc-900/40"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{ cell.label }}
                    </div>
                    <div
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ cell.value }}
                    </div>
                    <div
                        v-if="cell.sub"
                        class="mt-0.5 truncate font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ cell.sub }}
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
