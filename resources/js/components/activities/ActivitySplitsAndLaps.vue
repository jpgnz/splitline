<script setup lang="ts">
import { computed } from 'vue';
import InfoPopover from '@/components/InfoPopover.vue';
import { formatDurationClock, formatNumber } from '@/lib/format';
import { computeSplitsFromSeries, KM_TO_MI, useUnits } from '@/lib/units';
import type { KmSplitItem, SplitItem, StreamPoint } from '@/types/activity';

const props = withDefaults(
    defineProps<{
        series: StreamPoint[];
        kmSplits: KmSplitItem[];
        laps: Array<Record<string, number | null>>;
        usesPace: boolean;
        isRunning: boolean;
        isSwimming?: boolean;
        accentHex: string;
        cadenceUnit: string;
    }>(),
    {
        isSwimming: false,
    },
);

const {
    units,
    isImperial,
    distanceUnit,
    elevationUnit,
    distanceFromMeters,
    elevationFromMeters,
    formatDistance,
    formatElevation,
    formatPace,
    formatPace100m,
    formatPaceSeconds,
    formatSpeed,
} = useUnits();

const splitsTab = defineModel<'splits' | 'laps'>('splitsTab', {
    default: 'splits',
});

const splits = computed<SplitItem[]>(() =>
    computeSplitsFromSeries(props.series, props.kmSplits, units.value),
);

const splitsAreKm = computed<boolean>(
    () =>
        !isImperial.value ||
        (splits.value.length > 0 && splits.value[0].label.startsWith('Km')),
);

const fullSplitThresholdMeters = computed<number>(() =>
    splitsAreKm.value ? 950 : 1528,
);

const splitsHavePower = computed<boolean>(() =>
    splits.value.some((s) => s.avg_power !== null && s.avg_power > 0),
);
const splitsHaveCadence = computed<boolean>(() =>
    splits.value.some((s) => s.avg_cadence !== null && s.avg_cadence > 0),
);
const splitsHaveGap = computed<boolean>(
    () => props.isRunning && splits.value.some((s) => s.gap_seconds !== null),
);
const lapsHaveGap = computed<boolean>(
    () =>
        props.isRunning &&
        props.laps.some(
            (l) => pickLapNumber(l, ['gap_seconds_per_km']) !== null,
        ),
);
const lapsHavePowerOrCadence = computed<boolean>(() =>
    props.laps.some(
        (l) =>
            pickLapNumber(l, ['avg_power', 'power']) !== null ||
            pickLapNumber(l, ['avg_cadence', 'cadence']) !== null,
    ),
);

const cardHeading = computed<string>(() => {
    if (splits.value.length > 0 && props.laps.length > 0) {
        return 'Splits & Laps';
    }

    if (splits.value.length > 0) {
        return 'Splits';
    }

    return 'Laps';
});

const splitSpeedBounds = computed<{ min: number; max: number }>(() => {
    const speeds = splits.value
        .map((s) => s.avg_speed)
        .filter((v) => Number.isFinite(v) && v > 0);

    if (speeds.length === 0) {
        return { min: 0, max: 1 };
    }

    return {
        min: Math.min(...speeds),
        max: Math.max(...speeds),
    };
});

function splitPaceBarWidthPct(speed: number): number {
    const { min, max } = splitSpeedBounds.value;

    if (max <= 0) {
        return 0;
    }

    if (max === min) {
        return 75;
    }

    const ratio = (speed - min) / (max - min);

    return Math.round(28 + ratio * 72);
}

function formatPrimaryPaceOrSpeed(speedMps: number | null): string {
    if (props.isSwimming) {
        return formatPace100m(speedMps);
    }

    return props.usesPace ? formatPace(speedMps) : formatSpeed(speedMps);
}

function formatLapGap(secondsPerKm: number | null): string {
    if (
        secondsPerKm === null ||
        !Number.isFinite(secondsPerKm) ||
        secondsPerKm <= 0
    ) {
        return '—';
    }

    const adjusted = isImperial.value ? secondsPerKm / KM_TO_MI : secondsPerKm;

    return formatPaceSeconds(adjusted);
}

function pickLapNumber(
    lap: Record<string, number | null>,
    keys: string[],
): number | null {
    for (const key of keys) {
        const val = lap[key];

        if (typeof val === 'number' && Number.isFinite(val)) {
            return val;
        }
    }

    return null;
}

const activeSplitsTab = computed<'splits' | 'laps'>(() => {
    if (splitsTab.value === 'laps' && props.laps.length > 0) {
        return 'laps';
    }

    if (splits.value.length > 0) {
        return 'splits';
    }

    return 'laps';
});
</script>

<template>
    <section
        v-if="splits.length > 0 || laps.length > 0"
        class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <div
            class="flex items-center justify-between gap-3 border-b border-zinc-200/80 px-4 py-3 sm:px-5 dark:border-zinc-800/80"
        >
            <span
                class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
            >
                {{ cardHeading }}
            </span>

            <div
                v-if="splits.length > 0 && laps.length > 0"
                class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
            >
                <button
                    type="button"
                    class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                    :class="
                        activeSplitsTab === 'splits'
                            ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                    "
                    @click="splitsTab = 'splits'"
                >
                    {{ splitsAreKm ? '1 km Splits' : '1 mi Splits' }} ({{
                        splits.length
                    }})
                </button>
                <button
                    type="button"
                    class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                    :class="
                        activeSplitsTab === 'laps'
                            ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                    "
                    @click="splitsTab = 'laps'"
                >
                    Laps ({{ laps.length }})
                </button>
            </div>
        </div>

        <!-- 1 km / 1 mi Splits Table -->
        <div
            v-if="activeSplitsTab === 'splits' && splits.length > 0"
            class="overflow-x-auto"
        >
            <table class="w-full text-left text-sm tabular-nums">
                <thead
                    class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium whitespace-nowrap">
                            {{ splitsAreKm ? 'Km' : 'Mile' }}
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            {{
                                isSwimming
                                    ? 'Pace /100m'
                                    : usesPace
                                      ? 'Pace'
                                      : 'Speed'
                            }}
                        </th>
                        <th
                            v-if="splitsHaveGap"
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            <span
                                class="inline-flex items-center justify-end gap-1"
                            >
                                <span>GAP</span>
                                <InfoPopover topic="gap" />
                            </span>
                        </th>
                        <th
                            class="hidden w-36 px-4 py-2.5 font-medium whitespace-nowrap sm:table-cell"
                        >
                            {{ isSwimming || usesPace ? 'Pace' : 'Speed' }}
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Grade
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Elev +/-
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Avg HR
                        </th>
                        <th
                            v-if="splitsHavePower"
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Power
                        </th>
                        <th
                            v-if="splitsHaveCadence"
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Cadence
                        </th>
                    </tr>
                </thead>

                <tbody
                    class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/80"
                >
                    <tr
                        v-for="split in splits"
                        :key="split.label"
                        class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                    >
                        <td
                            class="px-4 py-2.5 font-medium whitespace-nowrap text-zinc-900 dark:text-zinc-100"
                        >
                            {{ split.label }}
                            <span
                                v-if="
                                    split.distance_meters <
                                    fullSplitThresholdMeters
                                "
                                class="ml-1 text-[10px] text-zinc-400 dark:text-zinc-500"
                            >
                                ({{
                                    splitsAreKm
                                        ? `${formatNumber(split.distance_meters / 1000, 2)} km`
                                        : `${formatNumber(distanceFromMeters(split.distance_meters), 2)} ${distanceUnit}`
                                }})
                            </span>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                isSwimming
                                    ? formatPace100m(split.avg_speed)
                                    : usesPace
                                      ? formatPaceSeconds(split.pace_seconds)
                                      : formatSpeed(split.avg_speed)
                            }}
                        </td>
                        <td
                            v-if="splitsHaveGap"
                            class="px-4 py-2.5 text-right whitespace-nowrap text-amber-600 dark:text-amber-400"
                        >
                            {{ formatPaceSeconds(split.gap_seconds) }}
                        </td>
                        <td class="hidden px-4 py-2.5 sm:table-cell">
                            <div
                                class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :style="{
                                        width: `${splitPaceBarWidthPct(split.avg_speed)}%`,
                                        backgroundColor: accentHex,
                                    }"
                                />
                            </div>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                        >
                            {{
                                `${split.avg_grade > 0 ? '+' : ''}${split.avg_grade.toFixed(1)}%`
                            }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                class="text-emerald-600 dark:text-emerald-400"
                            >
                                +{{
                                    Math.round(
                                        elevationFromMeters(
                                            split.elevation_gain,
                                        ),
                                    )
                                }}
                            </span>
                            <span class="text-zinc-400"> / </span>
                            <span class="text-zinc-500 dark:text-zinc-400">
                                -{{
                                    Math.round(
                                        elevationFromMeters(
                                            split.elevation_loss,
                                        ),
                                    )
                                }}
                                {{ elevationUnit }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <span
                                v-if="split.avg_hr !== null"
                                class="text-rose-600 dark:text-rose-400"
                            >
                                {{ Math.round(split.avg_hr) }} bpm
                            </span>
                            <span
                                v-else
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                —
                            </span>
                        </td>
                        <td
                            v-if="splitsHavePower"
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                split.avg_power !== null
                                    ? `${Math.round(split.avg_power)} W`
                                    : '—'
                            }}
                        </td>
                        <td
                            v-if="splitsHaveCadence"
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                split.avg_cadence !== null
                                    ? `${Math.round(split.avg_cadence)} ${cadenceUnit}`
                                    : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Laps Table -->
        <div v-else-if="laps.length > 0" class="overflow-x-auto">
            <table class="w-full text-left text-sm tabular-nums">
                <thead
                    class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-medium whitespace-nowrap">
                            Lap
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Distance
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Time
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            {{
                                isSwimming
                                    ? 'Pace /100m'
                                    : usesPace
                                      ? 'Pace'
                                      : 'Speed'
                            }}
                        </th>
                        <th
                            v-if="lapsHaveGap"
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            <span
                                class="inline-flex items-center justify-end gap-1"
                            >
                                <span>GAP</span>
                                <InfoPopover topic="gap" />
                            </span>
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Elev Gain
                        </th>
                        <th
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Avg HR
                        </th>
                        <th
                            v-if="lapsHavePowerOrCadence"
                            class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                        >
                            Power / Cadence
                        </th>
                    </tr>
                </thead>

                <tbody
                    class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/80"
                >
                    <tr
                        v-for="(lap, index) in laps"
                        :key="index"
                        class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                    >
                        <td
                            class="px-4 py-2.5 font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            #{{ index + 1 }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                formatDistance(
                                    pickLapNumber(lap, [
                                        'distance_meters',
                                        'total_distance',
                                        'distance',
                                    ]),
                                    2,
                                )
                            }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                formatDurationClock(
                                    pickLapNumber(lap, [
                                        'duration_seconds',
                                        'total_timer_time',
                                        'total_elapsed_time',
                                        'duration',
                                    ]),
                                )
                            }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                formatPrimaryPaceOrSpeed(
                                    pickLapNumber(lap, [
                                        'avg_speed',
                                        'enhanced_avg_speed',
                                        'speed',
                                    ]),
                                )
                            }}
                        </td>
                        <td
                            v-if="lapsHaveGap"
                            class="px-4 py-2.5 text-right text-amber-600 dark:text-amber-400"
                        >
                            {{
                                formatLapGap(
                                    pickLapNumber(lap, ['gap_seconds_per_km']),
                                )
                            }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
                        >
                            {{
                                formatElevation(
                                    pickLapNumber(lap, [
                                        'elevation_gain',
                                        'total_ascent',
                                        'ascent',
                                    ]),
                                )
                            }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-rose-600 dark:text-rose-400"
                        >
                            {{
                                pickLapNumber(lap, [
                                    'avg_hr',
                                    'avg_heart_rate',
                                    'hr',
                                ]) !== null
                                    ? `${Math.round(
                                          pickLapNumber(lap, [
                                              'avg_hr',
                                              'avg_heart_rate',
                                              'hr',
                                          ])!,
                                      )} bpm`
                                    : '—'
                            }}
                        </td>
                        <td
                            v-if="lapsHavePowerOrCadence"
                            class="px-4 py-2.5 text-right whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                        >
                            <template
                                v-if="
                                    pickLapNumber(lap, [
                                        'avg_power',
                                        'power',
                                    ]) !== null
                                "
                            >
                                {{
                                    Math.round(
                                        pickLapNumber(lap, [
                                            'avg_power',
                                            'power',
                                        ])!,
                                    )
                                }}
                                W
                                <span
                                    v-if="
                                        pickLapNumber(lap, [
                                            'avg_cadence',
                                            'cadence',
                                        ]) !== null
                                    "
                                    class="text-zinc-400"
                                >
                                    ·
                                    {{
                                        Math.round(
                                            pickLapNumber(lap, [
                                                'avg_cadence',
                                                'cadence',
                                            ])!,
                                        )
                                    }}
                                    {{ cadenceUnit }}
                                </span>
                            </template>
                            <template
                                v-else-if="
                                    pickLapNumber(lap, [
                                        'avg_cadence',
                                        'cadence',
                                    ]) !== null
                                "
                            >
                                {{
                                    Math.round(
                                        pickLapNumber(lap, [
                                            'avg_cadence',
                                            'cadence',
                                        ])!,
                                    )
                                }}
                                {{ cadenceUnit }}
                            </template>
                            <template v-else>—</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
