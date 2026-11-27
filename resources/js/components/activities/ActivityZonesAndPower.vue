<script setup lang="ts">
import type { EChartsOption } from 'echarts';
import { computed } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatDurationClock } from '@/lib/format';
import type { HrZoneItem, PowerZoneItem } from '@/types/activity';

const props = defineProps<{
    hrZones: HrZoneItem[];
    powerZones: PowerZoneItem[];
    powerCurve: Record<string, number> | null;
    isRunning: boolean;
    thresholdPower: number | null;
}>();

const theme = useChartTheme();

// HR & Power Zones
const hasHrZones = computed<boolean>(() =>
    props.hrZones.some((z) => z.seconds > 0),
);
const hasPowerZones = computed<boolean>(() =>
    props.powerZones.some((z) => z.seconds > 0),
);

function hrZoneBarClass(zone: number): string {
    const map: Record<number, string> = {
        1: 'bg-zinc-400 dark:bg-zinc-500',
        2: 'bg-sky-500',
        3: 'bg-emerald-500',
        4: 'bg-amber-500',
        5: 'bg-rose-500',
    };

    return map[zone] ?? 'bg-zinc-400';
}

function powerZoneBarClass(zone: number): string {
    const map: Record<number, string> = {
        1: 'bg-zinc-400 dark:bg-zinc-500',
        2: 'bg-sky-500',
        3: 'bg-emerald-500',
        4: 'bg-amber-500',
        5: 'bg-orange-500',
        6: 'bg-rose-500',
        7: 'bg-indigo-500',
    };

    return map[zone] ?? 'bg-sky-500';
}

function formatZoneBpmRange(zone: HrZoneItem): string {
    if (zone.min_hr !== null && zone.max_hr !== null) {
        return `${zone.min_hr}–${zone.max_hr} bpm`;
    }

    if (zone.min_hr !== null) {
        return `${zone.min_hr}+ bpm`;
    }

    if (zone.max_hr !== null) {
        return `<${zone.max_hr} bpm`;
    }

    return '';
}

function formatZoneWattRange(zone: PowerZoneItem): string {
    if (zone.min_watts !== null && zone.max_watts !== null) {
        return `${zone.min_watts}–${zone.max_watts} W`;
    }

    if (zone.min_watts !== null) {
        return `${zone.min_watts}+ W`;
    }

    if (zone.max_watts !== null) {
        return `<${zone.max_watts} W`;
    }

    return '';
}

function formatZonePct(pct: number, seconds: number): string {
    if (seconds > 0 && pct > 0 && pct < 0.5) {
        return '<1%';
    }

    return `${Math.round(pct)}%`;
}

// Road / Running Power Curve
const POWER_CURVE_LABELS: Record<number, string> = {
    1: '1s',
    5: '5s',
    10: '10s',
    15: '15s',
    30: '30s',
    60: '1m',
    120: '2m',
    300: '5m',
    600: '10m',
    1200: '20m',
    1800: '30m',
    3600: '1h',
    7200: '2h',
};

function formatDurationBucket(seconds: number): string {
    if (POWER_CURVE_LABELS[seconds]) {
        return POWER_CURVE_LABELS[seconds];
    }

    if (seconds >= 3600) {
        return `${(seconds / 3600).toFixed(1)}h`;
    }

    if (seconds >= 60) {
        return `${Math.round(seconds / 60)}m`;
    }

    return `${seconds}s`;
}

const powerCurvePoints = computed<
    Array<{ seconds: number; label: string; watts: number }>
>(() => {
    const raw = props.powerCurve;

    if (!raw || typeof raw !== 'object') {
        return [];
    }

    return Object.entries(raw)
        .map(([k, v]) => ({
            seconds: Number(k),
            watts: Number(v),
        }))
        .filter(
            (item) =>
                Number.isFinite(item.seconds) &&
                Number.isFinite(item.watts) &&
                item.watts > 0,
        )
        .sort((a, b) => a.seconds - b.seconds)
        .map((item) => ({
            seconds: item.seconds,
            label: formatDurationBucket(item.seconds),
            watts: Math.round(item.watts),
        }));
});

const powerCurveOption = computed<EChartsOption>(() => {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };
    const curveColor = props.isRunning ? '#8b5cf6' : '#0ea5e9';
    const curveAreaColor = props.isRunning
        ? theme.isDark.value
            ? 'rgba(139, 92, 246, 0.18)'
            : 'rgba(139, 92, 246, 0.15)'
        : theme.isDark.value
          ? 'rgba(14, 165, 233, 0.16)'
          : 'rgba(14, 165, 233, 0.14)';

    return {
        animation: false,
        grid: {
            left: 16,
            right: 16,
            top: 32,
            bottom: 12,
            containLabel: true,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: {
                    color: theme.mutedColor.value,
                    type: 'dashed',
                },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as
                    | { name?: string; value?: number }
                    | undefined;

                if (!first || typeof first.value !== 'number') {
                    return '';
                }

                return `<strong>${first.name ?? ''}</strong><br/>${first.value} W`;
            },
        },
        xAxis: {
            type: 'category',
            data: powerCurvePoints.value.map((p) => p.label),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                ...axisLabelStyle,
                hideOverlap: true,
            },
        },
        yAxis: {
            type: 'value',
            name: 'W',
            nameTextStyle: axisLabelStyle,
            axisLabel: axisLabelStyle,
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'Max Power',
                type: 'line',
                smooth: 0.25,
                symbolSize: 6,
                lineStyle: { color: curveColor, width: 2.25 },
                itemStyle: { color: curveColor },
                areaStyle: {
                    color: curveAreaColor,
                },
                data: powerCurvePoints.value.map((p) => p.watts),
            },
        ],
    };
});
</script>

<template>
    <div
        v-if="hasHrZones || hasPowerZones || powerCurvePoints.length > 0"
        class="space-y-6"
    >
        <!-- HR Zones & Power Zones -->
        <section
            v-if="hasHrZones || hasPowerZones"
            class="grid grid-cols-1 gap-4"
            :class="hasHrZones && hasPowerZones ? 'lg:grid-cols-2' : ''"
        >
            <!-- HR Zones (Z1-Z5) -->
            <div
                v-if="hasHrZones"
                class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="mb-4 flex items-center justify-between">
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Heart Rate Zones
                    </span>
                    <span
                        class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                    >
                        Z1 – Z5
                    </span>
                </div>

                <div class="space-y-3">
                    <div
                        v-for="zone in hrZones"
                        :key="zone.zone"
                        class="space-y-1"
                    >
                        <div
                            class="flex items-center justify-between gap-2 font-mono text-xs tabular-nums"
                        >
                            <div
                                class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5"
                            >
                                <span
                                    class="inline-flex w-7 shrink-0 justify-center rounded bg-zinc-100 py-0.5 text-[10px] font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                >
                                    Z{{ zone.zone }}
                                </span>
                                <span
                                    class="truncate font-sans text-xs font-medium text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ zone.label }}
                                </span>
                                <span
                                    v-if="formatZoneBpmRange(zone)"
                                    class="text-[10px] text-zinc-500 sm:text-[11px] dark:text-zinc-400"
                                >
                                    {{ formatZoneBpmRange(zone) }}
                                </span>
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <span class="text-zinc-700 dark:text-zinc-300">
                                    {{ formatDurationClock(zone.seconds) }}
                                </span>
                                <span
                                    class="w-10 text-right text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ formatZonePct(zone.pct, zone.seconds) }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :class="hrZoneBarClass(zone.zone)"
                                :style="{
                                    width: `${Math.max(0, Math.min(100, zone.pct))}%`,
                                }"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Power Zones (Z1-Z5 Running / Z1-Z7 Cycling) -->
            <div
                v-if="hasPowerZones"
                class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="mb-4 flex items-center justify-between">
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{ isRunning ? 'Running Power Zones' : 'Power Zones' }}
                    </span>
                    <span
                        class="font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Z1 – Z{{ powerZones.length }}
                        <template v-if="thresholdPower">
                            · Threshold {{ Math.round(thresholdPower) }} W
                        </template>
                    </span>
                </div>

                <div class="space-y-3">
                    <div
                        v-for="zone in powerZones"
                        :key="zone.zone"
                        class="space-y-1"
                    >
                        <div
                            class="flex items-center justify-between gap-2 font-mono text-xs tabular-nums"
                        >
                            <div
                                class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5"
                            >
                                <span
                                    class="inline-flex w-7 shrink-0 justify-center rounded bg-zinc-100 py-0.5 text-[10px] font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                >
                                    Z{{ zone.zone }}
                                </span>
                                <span
                                    class="truncate font-sans text-xs font-medium text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ zone.label }}
                                </span>
                                <span
                                    v-if="formatZoneWattRange(zone)"
                                    class="text-[10px] text-zinc-500 sm:text-[11px] dark:text-zinc-400"
                                >
                                    {{ formatZoneWattRange(zone) }}
                                </span>
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <span class="text-zinc-700 dark:text-zinc-300">
                                    {{ formatDurationClock(zone.seconds) }}
                                </span>
                                <span
                                    class="w-10 text-right text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ formatZonePct(zone.pct, zone.seconds) }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :class="powerZoneBarClass(zone.zone)"
                                :style="{
                                    width: `${Math.max(0, Math.min(100, zone.pct))}%`,
                                }"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Road Power Curve -->
        <section
            v-if="powerCurvePoints.length > 0"
            class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <span
                    class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Mean Maximal Power Curve
                </span>
                <div
                    class="flex flex-wrap items-center gap-2 font-mono text-[11px] tabular-nums"
                >
                    <span
                        v-for="pt in powerCurvePoints.filter((p) =>
                            [5, 60, 300, 1200].includes(p.seconds),
                        )"
                        :key="pt.seconds"
                        class="rounded-md border border-zinc-200/80 bg-[#f7f7f5] px-2 py-0.5 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300"
                    >
                        <span class="text-zinc-400 dark:text-zinc-500">
                            {{ pt.label }}:
                        </span>
                        <span
                            class="font-semibold"
                            :class="
                                isRunning
                                    ? 'text-violet-600 dark:text-violet-400'
                                    : 'text-sky-600 dark:text-sky-400'
                            "
                        >
                            {{ pt.watts }} W
                        </span>
                    </span>
                </div>
            </div>

            <BaseChart :option="powerCurveOption" :height="240" />
        </section>
    </div>
</template>
