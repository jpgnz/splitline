<script setup lang="ts">
import {
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
import InfoPopover from '@/components/InfoPopover.vue';
import { useUnits } from '@/lib/units';
import { formatCompass } from '@/lib/wind';
import type { ActivityWeatherPayload } from '@/types/activity';

const props = withDefaults(
    defineProps<{
        weather: ActivityWeatherPayload | null;
        isIndoor: boolean;
        hasCoordinates: boolean;
        weatherSyncEnabled?: boolean;
        syncing?: boolean;
    }>(),
    {
        weatherSyncEnabled: true,
        syncing: false,
    },
);

const emit = defineEmits<{
    sync: [];
}>();

const { formatPrecip, formatSpeedKmh, formatTemp, tempFromC } = useUnits();

const isVisible = computed<boolean>(
    () =>
        !props.isIndoor &&
        (props.weather !== null ||
            (props.weatherSyncEnabled && props.hasCoordinates)),
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

const WEATHER_ICON_CLASSES: Record<string, string> = {
    wind: 'bg-sky-500/10 text-sky-600 dark:bg-sky-400/10 dark:text-sky-400',
    sun: 'bg-amber-500/10 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400',
    'cloud-sun':
        'bg-amber-500/10 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400',
    cloud: 'bg-zinc-500/10 text-zinc-600 dark:bg-zinc-400/10 dark:text-zinc-300',
    'cloud-rain':
        'bg-blue-500/10 text-blue-600 dark:bg-blue-400/10 dark:text-blue-400',
    'cloud-drizzle':
        'bg-sky-500/10 text-sky-600 dark:bg-sky-400/10 dark:text-sky-400',
    'cloud-fog':
        'bg-zinc-500/10 text-zinc-500 dark:bg-zinc-400/10 dark:text-zinc-400',
    snowflake:
        'bg-cyan-500/10 text-cyan-600 dark:bg-cyan-400/10 dark:text-cyan-400',
    'cloud-lightning':
        'bg-violet-500/10 text-violet-600 dark:bg-violet-400/10 dark:text-violet-400',
};

const weatherIconComponent = computed<Component>(() => {
    const key = props.weather?.icon ?? 'cloud';

    return WEATHER_ICONS[key] ?? Cloud;
});

const weatherIconBadgeClass = computed<string>(() => {
    const key = props.weather?.icon ?? 'cloud';

    return (
        WEATHER_ICON_CLASSES[key] ??
        'bg-zinc-500/10 text-zinc-600 dark:bg-zinc-400/10 dark:text-zinc-300'
    );
});

const samplingMeta = computed<string>(() => {
    const w = props.weather;

    if (!w) {
        return '';
    }

    if (w.sampling_mode === 'route') {
        const count = w.waypoint_count;

        return `${count} route ${count === 1 ? 'waypoint' : 'waypoints'}`;
    }

    if (w.sampling_mode === 'start_point') {
        return 'Start point';
    }

    return `${w.waypoint_count} ${w.sampling_mode.replace(/_/g, ' ')}`;
});

const temperatureRangeSub = computed<string | null>(() => {
    const w = props.weather;

    if (
        !w ||
        w.min_temperature_c === null ||
        w.max_temperature_c === null ||
        w.max_temperature_c - w.min_temperature_c < 2
    ) {
        return null;
    }

    const minVal = Math.round(tempFromC(w.min_temperature_c));

    return `${minVal}–${formatTemp(w.max_temperature_c, 0)}`;
});

const humidityValue = computed<string>(() => {
    const pct = props.weather?.humidity_pct;

    return pct !== null && pct !== undefined ? `${Math.round(pct)}%` : '—';
});

const dewPointSub = computed<string | null>(() => {
    const dew = props.weather?.dew_point_c;

    if (dew === null || dew === undefined) {
        return null;
    }

    return `Dew ${formatTemp(dew, 0)}`;
});

const windGustsSub = computed<string | null>(() => {
    const w = props.weather;

    if (
        !w ||
        w.wind_gusts_kmh === null ||
        w.wind_speed_kmh === null ||
        w.wind_gusts_kmh - w.wind_speed_kmh < 5
    ) {
        return null;
    }

    return `Gusts ${formatSpeedKmh(w.wind_gusts_kmh, 0)}`;
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

const windDirectionSub = computed<string | null>(() => {
    const deg = props.weather?.wind_direction_deg;

    if (deg === null || deg === undefined || !Number.isFinite(deg)) {
        return null;
    }

    return `${Math.round(deg)}°`;
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

    return {
        head: headRounded,
        cross: crossRounded,
        tail: tailRounded,
    };
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

const cloudCoverValue = computed<string>(() => {
    const pct = props.weather?.cloud_cover_pct;

    return pct !== null && pct !== undefined ? `${Math.round(pct)}%` : '—';
});

const precipitationSub = computed<string | null>(() => {
    const mm = props.weather?.precipitation_mm;

    if (mm === null || mm === undefined) {
        return null;
    }

    return `Precip ${formatPrecip(mm)}`;
});
</script>

<template>
    <section
        v-if="isVisible"
        :class="
            weather
                ? 'rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]'
                : 'flex flex-col items-center justify-between gap-4 rounded-xl border border-dashed border-zinc-300 bg-white/70 p-4 text-center sm:flex-row sm:px-5 sm:text-left dark:border-zinc-700 dark:bg-[#141417]/70'
        "
    >
        <!-- Not-yet-synced state -->
        <template v-if="!weather">
            <div class="flex items-center gap-3">
                <div
                    class="hidden size-8 shrink-0 items-center justify-center rounded-lg border border-zinc-200/80 bg-[#f7f7f5] text-zinc-500 sm:flex dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400"
                >
                    <CloudSun class="size-4" />
                </div>
                <div>
                    <p
                        class="font-mono text-xs font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Historical weather not synced yet
                    </p>
                    <p
                        class="mt-0.5 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Fetch historical weather and wind for this route from
                        Open-Meteo.
                    </p>
                </div>
            </div>

            <button
                type="button"
                :disabled="syncing"
                class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 font-mono text-xs font-medium text-zinc-800 transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                @click="emit('sync')"
            >
                <RefreshCw :class="['size-3.5', syncing && 'animate-spin']" />
                {{ syncing ? 'Fetching Weather...' : 'Fetch Weather' }}
            </button>
        </template>

        <!-- Synced weather state -->
        <template v-else>
            <!-- Header row -->
            <div
                class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200/70 pb-3 dark:border-zinc-800/70"
            >
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                        :class="weatherIconBadgeClass"
                    >
                        <component :is="weatherIconComponent" class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <h2
                                class="text-sm font-semibold tracking-tight text-zinc-950 dark:text-zinc-50"
                            >
                                {{ weather.condition }}
                            </h2>
                            <span
                                v-if="
                                    weather.base_condition &&
                                    weather.base_condition !== weather.condition
                                "
                                class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                            >
                                {{ weather.base_condition }}
                            </span>
                        </div>
                        <p
                            class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            {{ samplingMeta }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <a
                        href="https://open-meteo.com/"
                        target="_blank"
                        rel="noopener noreferrer"
                        title="Weather data by Open-Meteo.com (CC BY 4.0)"
                        class="rounded-md border border-zinc-200/80 bg-[#f7f7f5] px-2 py-1 font-mono text-[10px] text-zinc-500 transition-colors hover:border-zinc-300 hover:text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-200"
                    >
                        Open-Meteo · CC BY 4.0
                    </a>
                    <button
                        v-if="weatherSyncEnabled"
                        type="button"
                        :disabled="syncing"
                        title="Refresh weather data"
                        class="inline-flex min-w-[96px] cursor-pointer items-center justify-center gap-1.5 rounded-md border border-zinc-200/90 bg-white px-2.5 py-1 font-mono text-[11px] font-medium text-zinc-700 transition-colors hover:border-zinc-300 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                        @click="emit('sync')"
                    >
                        <RefreshCw
                            :class="['size-3', syncing && 'animate-spin']"
                        />
                        <span>{{ syncing ? 'Refreshing' : 'Refresh' }}</span>
                    </button>
                </div>
            </div>

            <!-- 6-stat primary grid -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <!-- 1. Temperature -->
                <div class="min-w-0">
                    <div
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Temperature
                    </div>
                    <div
                        class="mt-1 font-mono text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatTemp(weather.temperature_c, 0) }}
                    </div>
                    <div
                        v-if="temperatureRangeSub"
                        class="mt-0.5 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ temperatureRangeSub }}
                    </div>
                </div>

                <!-- 2. Humidity -->
                <div class="min-w-0">
                    <div
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Humidity
                    </div>
                    <div
                        class="mt-1 font-mono text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ humidityValue }}
                    </div>
                    <div
                        v-if="dewPointSub"
                        class="mt-0.5 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ dewPointSub }}
                    </div>
                </div>

                <!-- 3. Feels like -->
                <div class="min-w-0">
                    <div
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Feels like
                    </div>
                    <div
                        class="mt-1 font-mono text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatTemp(weather.apparent_temperature_c, 0) }}
                    </div>
                </div>

                <!-- 4. Wind Speed -->
                <div class="min-w-0">
                    <div
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Wind Speed
                    </div>
                    <div
                        class="mt-1 font-mono text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatSpeedKmh(weather.wind_speed_kmh, 1) }}
                    </div>
                    <div
                        v-if="windGustsSub"
                        class="mt-0.5 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ windGustsSub }}
                    </div>
                </div>

                <!-- 5. Wind Direction -->
                <div class="min-w-0">
                    <div
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Wind Direction
                    </div>
                    <div
                        class="mt-1 flex items-center gap-1.5 font-mono text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        <span>{{ windCompass }}</span>
                        <svg
                            v-if="windBlowingToDeg !== null"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                            class="size-4 shrink-0 text-sky-600 dark:text-sky-400"
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
                    <div
                        v-if="windDirectionSub"
                        class="mt-0.5 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ windDirectionSub }}
                    </div>
                </div>

                <!-- 6. Wind Impact (or Cloud Cover & Precipitation fallback) -->
                <div v-if="hasWindImpact" class="min-w-0">
                    <div class="flex items-center justify-between gap-1">
                        <span
                            class="inline-flex items-center gap-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            <span>Wind Impact</span>
                            <InfoPopover topic="wind_impact" />
                        </span>
                        <span
                            v-if="netEffectiveWind"
                            class="font-mono text-[10px] font-medium tabular-nums"
                            :class="netEffectiveWind.toneClass"
                        >
                            {{ netEffectiveWind.text }}
                        </span>
                    </div>

                    <div
                        class="mt-2 flex h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
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
                        class="mt-1.5 font-mono text-[10px] leading-tight text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ windImpactSegments.head }}% Head ·
                        {{ windImpactSegments.cross }}% Cross ·
                        {{ windImpactSegments.tail }}% Tail
                    </div>
                </div>

                <div v-else class="min-w-0">
                    <div
                        class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        Cloud &amp; Precip
                    </div>
                    <div
                        class="mt-1 font-mono text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ cloudCoverValue }}
                    </div>
                    <div
                        v-if="precipitationSub"
                        class="mt-0.5 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ precipitationSub }}
                    </div>
                </div>
            </div>
        </template>
    </section>
</template>
