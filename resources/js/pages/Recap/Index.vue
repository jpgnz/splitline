<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity as ActivityIcon,
    ArrowDownRight,
    ArrowUpRight,
    Award,
    Calendar,
    CloudRain,
    Download,
    Flame,
    Footprints,
    HeartPulse,
    Moon,
    Mountain,
    Route as RouteIcon,
    Snowflake,
    Sparkles,
    Sun,
    Timer,
    TrendingUp,
    Trophy,
    Wind,
    Zap,
} from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ActivityCalendarHeatmap from '@/components/charts/ActivityCalendarHeatmap.vue';
import { SPORT_METRIC_OPTIONS } from '@/components/charts/annualSummaryTypes';
import BaseChart from '@/components/charts/BaseChart.vue';
import RouteMiniMap from '@/components/routes/RouteMiniMap.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatDurationHuman,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import { useUnits } from '@/lib/units';
import { show as showActivity } from '@/routes/activities';
import { index as recapIndex } from '@/routes/recap';
import { show as routesShow } from '@/routes/routes';
import type { AnnualRecapPageProps, MonthlyMetricKey } from '@/types/recap';

const props = defineProps<AnnualRecapPageProps>();

const theme = useChartTheme();
const {
    distanceFromMeters,
    distanceUnit,
    elevationFromMeters,
    elevationUnit,
    formatDistanceCompact,
    formatElevation,
    formatPrecip,
    formatSpeedKmh,
    formatTemp,
} = useUnits();

const monthlyMetric = ref<MonthlyMetricKey>('distance');
const isExportingCard = ref<boolean>(false);

const MONTHLY_METRICS: Array<{ key: MonthlyMetricKey; label: string }> = [
    { key: 'distance', label: 'Distance' },
    { key: 'duration', label: 'Duration' },
    { key: 'elevation', label: 'Elevation' },
    { key: 'load', label: 'Load' },
];

const selectableYears = computed<number[]>(() => {
    const years = new Set<number>(props.available_years);
    years.add(props.selected_year);

    return Array.from(years).sort((a, b) => b - a);
});

const yoyCaption = computed<string>(() =>
    props.yoy.is_ytd_comparison
        ? `vs ${props.yoy.previous_year} YTD`
        : `vs ${props.yoy.previous_year}`,
);

function formatDeltaPct(pct: number | null): string {
    if (pct === null || !Number.isFinite(pct)) {
        return '—';
    }

    const sign = pct > 0 ? '+' : '';

    return `${sign}${pct.toFixed(1)}%`;
}

function deltaPillClass(pct: number | null): string {
    if (pct === null || !Number.isFinite(pct)) {
        return 'border-zinc-200 bg-zinc-100 text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400';
    }

    if (pct >= 0) {
        return 'border-emerald-500/25 bg-emerald-500/12 text-emerald-700 dark:text-emerald-300';
    }

    return 'border-rose-500/25 bg-rose-500/12 text-rose-700 dark:text-rose-300';
}

function formatSleepHours(hours: number | null): string {
    if (hours === null || !Number.isFinite(hours) || hours <= 0) {
        return '—';
    }

    const totalMinutes = Math.round(hours * 60);
    const wholeHours = Math.floor(totalMinutes / 60);
    const remainingMinutes = totalMinutes % 60;

    return `${wholeHours}h ${String(remainingMinutes).padStart(2, '0')}m`;
}

function formatClimbBadge(category: string | null): string | null {
    if (!category || category === 'uncategorized') {
        return null;
    }

    if (category === 'hc') {
        return 'HC';
    }

    return category.replace('cat', 'Cat ');
}

function monthlyValueFor(
    bucket: AnnualRecapPageProps['monthly'][number],
    metric: MonthlyMetricKey,
): number {
    switch (metric) {
        case 'distance':
            return Number(
                distanceFromMeters(bucket.distance_meters).toFixed(1),
            );
        case 'duration':
            return Number((bucket.duration_seconds / 3600).toFixed(2));
        case 'elevation':
            return Math.round(elevationFromMeters(bucket.elevation_gain));
        case 'load':
            return Math.round(bucket.training_load);
    }
}

const monthlyChartOption = computed<EChartsOption>(() => {
    const metric = monthlyMetric.value;
    const values = props.monthly.map((bucket) =>
        monthlyValueFor(bucket, metric),
    );
    const maxVal = Math.max(0, ...values);

    const accentByMetric: Record<MonthlyMetricKey, string> = {
        distance: '#10b981',
        duration: '#0ea5e9',
        elevation: '#f59e0b',
        load: '#8b5cf6',
    };

    const barColor = accentByMetric[metric];
    const mutedBarColor = theme.isDark.value
        ? 'rgba(161, 161, 170, 0.25)'
        : 'rgba(161, 161, 170, 0.35)';

    return {
        animationDuration: 450,
        grid: {
            top: 20,
            right: 16,
            bottom: 14,
            left: 12,
            containLabel: true,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'shadow',
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const idx = first?.dataIndex ?? 0;
                const bucket = props.monthly[idx];

                if (!bucket) {
                    return '';
                }

                const primaryLine =
                    metric === 'distance'
                        ? formatDistanceCompact(bucket.distance_meters, 1)
                        : metric === 'duration'
                          ? formatDurationHuman(bucket.duration_seconds)
                          : metric === 'elevation'
                            ? formatElevation(bucket.elevation_gain)
                            : `${formatNumber(Math.round(bucket.training_load))} load`;

                return [
                    `<div style="font-weight:600;margin-bottom:4px;">${bucket.full_label} ${props.selected_year}</div>`,
                    `<div>${primaryLine}</div>`,
                    `<div style="opacity:0.75;font-size:11px;margin-top:2px;">${bucket.activities} ${bucket.activities === 1 ? 'activity' : 'activities'} · ${formatDurationHuman(bucket.duration_seconds)}</div>`,
                ].join('');
            },
        },
        xAxis: {
            type: 'category',
            data: props.monthly.map((m) => m.label),
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: {
                color: theme.mutedColor.value,
                fontSize: 11,
                fontFamily: theme.fontFamilyMono,
            },
        },
        yAxis: {
            type: 'value',
            splitNumber: 4,
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: {
                lineStyle: {
                    color: theme.gridLineColor.value,
                    type: 'dashed',
                },
            },
            axisLabel: {
                color: theme.mutedColor.value,
                fontSize: 10,
                fontFamily: theme.fontFamilyMono,
                formatter: (val: number) => {
                    if (metric === 'distance') {
                        return `${formatNumber(Math.round(val))} ${distanceUnit.value}`;
                    }

                    if (metric === 'duration') {
                        return `${formatNumber(Math.round(val))}h`;
                    }

                    if (metric === 'elevation') {
                        return `${formatNumber(Math.round(val))} ${elevationUnit.value}`;
                    }

                    return formatNumber(Math.round(val));
                },
            },
        },
        series: [
            {
                type: 'bar',
                barMaxWidth: 34,
                data: values.map((v) => ({
                    value: v,
                    itemStyle: {
                        color:
                            v > 0
                                ? maxVal > 0 && v === maxVal
                                    ? barColor
                                    : `${barColor}bf`
                                : mutedBarColor,
                        borderRadius: [6, 6, 2, 2],
                    },
                })),
            },
        ],
    };
});

function drawRoundedRect(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    w: number,
    h: number,
    r: number,
): void {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

async function downloadYearCardPng(): Promise<void> {
    if (isExportingCard.value) {
        return;
    }

    isExportingCard.value = true;

    try {
        const width = 1200;
        const height = 630;
        const scale = 2;
        const canvas = document.createElement('canvas');
        canvas.width = width * scale;
        canvas.height = height * scale;

        const ctx = canvas.getContext('2d');

        if (!ctx) {
            throw new Error('Canvas 2D context unavailable');
        }

        ctx.scale(scale, scale);

        const monoFont =
            "'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, monospace";
        const sansFont =
            "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";

        // Background gradient
        const bgGrad = ctx.createLinearGradient(0, 0, width, height);
        bgGrad.addColorStop(0, '#0e0f12');
        bgGrad.addColorStop(0.55, '#13151a');
        bgGrad.addColorStop(1, '#0b1514');
        ctx.fillStyle = bgGrad;
        ctx.fillRect(0, 0, width, height);

        // Subtle emerald glow top-right
        const glow = ctx.createRadialGradient(
            width - 140,
            90,
            20,
            width - 140,
            90,
            420,
        );
        glow.addColorStop(0, 'rgba(16, 185, 129, 0.22)');
        glow.addColorStop(1, 'rgba(16, 185, 129, 0)');
        ctx.fillStyle = glow;
        ctx.fillRect(0, 0, width, height);

        // Subtle dot grid
        ctx.fillStyle = 'rgba(255, 255, 255, 0.04)';
        for (let gx = 40; gx < width; gx += 32) {
            for (let gy = 40; gy < height; gy += 32) {
                ctx.fillRect(gx, gy, 1.5, 1.5);
            }
        }

        // Outer subtle frame
        drawRoundedRect(ctx, 24, 24, width - 48, height - 48, 22);
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        // Brand emblem (top-left)
        drawRoundedRect(ctx, 64, 58, 38, 38, 9);
        ctx.fillStyle = '#f4f4f5';
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(71, 82);
        ctx.lineTo(77, 82);
        ctx.lineTo(81, 71);
        ctx.lineTo(85, 84);
        ctx.lineTo(89, 75);
        ctx.lineTo(95, 75);
        ctx.strokeStyle = '#10b981';
        ctx.lineWidth = 2.8;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.stroke();

        ctx.fillStyle = '#a1a1aa';
        ctx.font = `600 13px ${monoFont}`;
        ctx.fillText('SPLITLINE · ANNUAL RECAP', 116, 82);

        // YTD Pill (top-right)
        const badgeText = props.is_current_year
            ? `YTD · ${formatShortDate(props.today).toUpperCase()}`
            : `FULL YEAR · ${props.selected_year}`;
        ctx.font = `600 12px ${monoFont}`;
        const badgeWidth = ctx.measureText(badgeText).width + 28;
        drawRoundedRect(ctx, width - 64 - badgeWidth, 61, badgeWidth, 30, 15);
        ctx.fillStyle = 'rgba(16, 185, 129, 0.14)';
        ctx.fill();
        ctx.strokeStyle = 'rgba(16, 185, 129, 0.35)';
        ctx.lineWidth = 1;
        ctx.stroke();
        ctx.fillStyle = '#34d399';
        ctx.fillText(badgeText, width - 64 - badgeWidth + 14, 80);

        // Hero Title
        ctx.fillStyle = '#ffffff';
        ctx.font = `700 56px ${sansFont}`;
        ctx.fillText(`${props.selected_year} Year in Sport`, 64, 166);

        ctx.fillStyle = '#a1a1aa';
        ctx.font = `400 18px ${sansFont}`;
        ctx.fillText(
            `${formatNumber(props.totals.active_days)} active days · ${formatNumber(props.totals.calories)} kcal · ${formatNumber(Math.round(props.totals.training_load))} training load`,
            64,
            202,
        );

        // 4 Headline KPI Cards
        const kpis = [
            {
                label: 'TOTAL DISTANCE',
                value: formatDistanceCompact(props.totals.distance_meters, 0),
                delta: props.yoy.distance_pct,
                accent: '#10b981',
            },
            {
                label: 'MOVING TIME',
                value: formatDurationHuman(props.totals.duration_seconds),
                delta: props.yoy.duration_pct,
                accent: '#0ea5e9',
            },
            {
                label: 'ELEVATION GAIN',
                value: formatElevation(props.totals.elevation_gain),
                delta: props.yoy.elevation_pct,
                accent: '#f59e0b',
            },
            {
                label: 'ACTIVITIES',
                value: formatNumber(props.totals.activities),
                delta: props.yoy.activities_pct,
                accent: '#a78bfa',
            },
        ];

        const kpiGap = 18;
        const kpiWidth = (width - 128 - kpiGap * 3) / 4;
        const kpiY = 236;
        const kpiHeight = 138;

        kpis.forEach((kpi, index) => {
            const x = 64 + index * (kpiWidth + kpiGap);
            drawRoundedRect(ctx, x, kpiY, kpiWidth, kpiHeight, 16);
            ctx.fillStyle = 'rgba(255, 255, 255, 0.04)';
            ctx.fill();
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.09)';
            ctx.lineWidth = 1;
            ctx.stroke();

            // Top accent line
            drawRoundedRect(ctx, x + 20, kpiY + 18, 28, 3, 2);
            ctx.fillStyle = kpi.accent;
            ctx.fill();

            ctx.fillStyle = '#a1a1aa';
            ctx.font = `600 11px ${monoFont}`;
            ctx.fillText(kpi.label, x + 20, kpiY + 42);

            ctx.fillStyle = '#fafafa';
            ctx.font = `700 32px ${sansFont}`;
            ctx.fillText(kpi.value, x + 20, kpiY + 86, kpiWidth - 40);

            if (kpi.delta !== null) {
                const deltaStr = `${formatDeltaPct(kpi.delta)} ${yoyCaption.value}`;
                ctx.fillStyle = kpi.delta >= 0 ? '#34d399' : '#fb7185';
                ctx.font = `600 12px ${monoFont}`;
                ctx.fillText(deltaStr, x + 20, kpiY + 116);
            } else {
                ctx.fillStyle = '#71717a';
                ctx.font = `500 12px ${monoFont}`;
                ctx.fillText(
                    `${props.selected_year} baseline`,
                    x + 20,
                    kpiY + 116,
                );
            }
        });

        // Bottom Left: 12-Month Distance Sparkline Bar Chart
        const chartX = 64;
        const chartY = 402;
        const chartW = 630;
        const chartH = 166;

        drawRoundedRect(ctx, chartX, chartY, chartW, chartH, 16);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.03)';
        ctx.fill();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.stroke();

        ctx.fillStyle = '#a1a1aa';
        ctx.font = `600 11px ${monoFont}`;
        ctx.fillText('MONTHLY DISTANCE RHYTHM', chartX + 20, chartY + 28);

        const monthDistances = props.monthly.map((m) => m.distance_meters);
        const maxMonthDist = Math.max(1, ...monthDistances);
        const barAreaX = chartX + 22;
        const barAreaW = chartW - 44;
        const barBottom = chartY + chartH - 34;
        const barMaxH = 82;
        const slotW = barAreaW / 12;

        props.monthly.forEach((m, idx) => {
            const barW = Math.max(14, slotW - 14);
            const bx = barAreaX + idx * slotW + (slotW - barW) / 2;
            const ratio = m.distance_meters / maxMonthDist;
            const bh = m.distance_meters > 0 ? Math.max(8, ratio * barMaxH) : 4;
            const by = barBottom - bh;

            drawRoundedRect(ctx, bx, by, barW, bh, 4);
            ctx.fillStyle =
                m.distance_meters > 0
                    ? m.distance_meters === maxMonthDist
                        ? '#10b981'
                        : 'rgba(16, 185, 129, 0.55)'
                    : 'rgba(255, 255, 255, 0.08)';
            ctx.fill();

            ctx.fillStyle = '#71717a';
            ctx.font = `500 10px ${monoFont}`;
            ctx.textAlign = 'center';
            ctx.fillText(m.label, bx + barW / 2, barBottom + 18);
        });
        ctx.textAlign = 'left';

        // Bottom Right: Sport Mix & Highlights
        const rightX = 716;
        const rightW = width - 64 - rightX;

        drawRoundedRect(ctx, rightX, chartY, rightW, chartH, 16);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.03)';
        ctx.fill();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.stroke();

        ctx.fillStyle = '#a1a1aa';
        ctx.font = `600 11px ${monoFont}`;
        ctx.fillText('SPORT MIX & MILESTONES', rightX + 20, chartY + 28);

        // Sport proportion stacked bar
        const stackX = rightX + 20;
        const stackY = chartY + 44;
        const stackW = rightW - 40;
        const stackH = 12;
        drawRoundedRect(ctx, stackX, stackY, stackW, stackH, 6);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.fill();

        if (props.sport_breakdown.length > 0) {
            ctx.save();
            drawRoundedRect(ctx, stackX, stackY, stackW, stackH, 6);
            ctx.clip();
            let cursorX = stackX;
            for (const sport of props.sport_breakdown) {
                const segW = (sport.share_pct / 100) * stackW;
                ctx.fillStyle = sport.accent_hex;
                ctx.fillRect(cursorX, stackY, Math.max(2, segW), stackH);
                cursorX += segW;
            }
            ctx.restore();
        }

        // Top 3 sports legend
        const topSports = props.sport_breakdown.slice(0, 3);
        let legendX = stackX;
        const legendY = stackY + 34;
        const legendMaxX = rightX + rightW - 20;

        for (const sport of topSports) {
            ctx.font = `500 12px ${sansFont}`;
            const text = `${sport.label} ${sport.share_pct.toFixed(0)}%`;
            const itemW = 14 + ctx.measureText(text).width + 20;

            if (legendX + itemW - 20 > legendMaxX) {
                break;
            }

            ctx.fillStyle = sport.accent_hex;
            ctx.beginPath();
            ctx.arc(legendX + 4, legendY - 4, 4, 0, Math.PI * 2);
            ctx.fill();

            ctx.fillStyle = '#e4e4e7';
            ctx.fillText(text, legendX + 14, legendY);
            legendX += itemW;
        }

        // Highlight pills at bottom of right card
        const streakText = `${props.consistency.longest_daily_streak.days}d streak`;
        const prText = `${props.milestones.all_time_prs_count} All-Time PRs`;
        const segText = `${props.milestones.segment_prs_count} Segment PRs`;
        const pills = [streakText, prText, segText];

        let pillX = stackX;
        const pillY = chartY + 112;
        ctx.font = `600 12px ${monoFont}`;

        for (const label of pills) {
            const pw = ctx.measureText(label).width + 20;
            if (pillX + pw > rightX + rightW - 12) {
                break;
            }
            drawRoundedRect(ctx, pillX, pillY, pw, 30, 8);
            ctx.fillStyle = 'rgba(255, 255, 255, 0.06)';
            ctx.fill();
            ctx.fillStyle = '#d4d4d8';
            ctx.fillText(label, pillX + 10, pillY + 19);
            pillX += pw + 10;
        }

        const blob = await new Promise<Blob | null>((resolve) => {
            canvas.toBlob((result) => resolve(result), 'image/png');
        });

        if (!blob) {
            throw new Error('Failed to generate PNG');
        }

        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `splitline-${props.selected_year}-year-in-sport.png`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);

        toast.success(`Downloaded ${props.selected_year} Year in Sport card`);
    } catch {
        toast.error('Could not export Year Card PNG.');
    } finally {
        isExportingCard.value = false;
    }
}
</script>

<template>
    <div class="space-y-6 pb-8">
        <Head :title="`${selected_year} Year in Sport`" />

        <!-- Hero Banner -->
        <section
            class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-6 sm:p-8 dark:border-zinc-800/80 dark:bg-[#141417]"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-emerald-500/10 blur-3xl dark:bg-emerald-500/15"
                aria-hidden="true"
            />

            <div
                class="relative flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between"
            >
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 font-mono text-[11px] font-medium tracking-widest text-emerald-600 uppercase dark:text-emerald-400"
                        >
                            <Sparkles class="size-3.5" />
                            Annual Review
                        </span>
                        <span
                            v-if="is_current_year"
                            class="inline-flex items-center rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 font-mono text-[10px] font-medium text-emerald-700 dark:text-emerald-300"
                        >
                            YTD through {{ formatShortDate(today) }}
                        </span>
                    </div>

                    <h1
                        class="text-3xl font-bold tracking-tight text-zinc-950 sm:text-4xl dark:text-white"
                    >
                        {{ selected_year }} Year in Sport
                    </h1>

                    <p
                        v-if="has_data"
                        class="font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ formatNumber(totals.active_days) }} active
                        {{ totals.active_days === 1 ? 'day' : 'days' }} ·
                        {{ formatNumber(totals.calories) }} kcal ·
                        {{ formatNumber(Math.round(totals.training_load)) }}
                        training load
                    </p>
                </div>

                <!-- Year Pills + PNG Export -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <div
                        class="inline-flex flex-wrap items-center gap-1 rounded-xl border border-zinc-200/90 bg-zinc-50/90 p-1 dark:border-zinc-800 dark:bg-zinc-900/70"
                        role="navigation"
                        aria-label="Select recap year"
                    >
                        <Link
                            v-for="year in selectableYears"
                            :key="year"
                            :href="recapIndex.url({ query: { year } })"
                            prefetch
                            :class="[
                                'rounded-lg px-3 py-1 font-mono text-xs font-medium tabular-nums transition-colors',
                                year === selected_year
                                    ? 'bg-zinc-900 text-white shadow-xs dark:bg-white dark:text-zinc-950'
                                    : 'text-zinc-600 hover:bg-zinc-200/70 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100',
                            ]"
                        >
                            {{ year }}
                        </Link>
                    </div>

                    <button
                        v-if="has_data"
                        type="button"
                        :disabled="isExportingCard"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-xs transition-colors hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-60"
                        @click="downloadYearCardPng"
                    >
                        <Download class="size-3.5" />
                        <span>
                            {{
                                isExportingCard
                                    ? 'Rendering...'
                                    : 'Download Year Card (PNG)'
                            }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- 4 Headline KPIs -->
            <div
                v-if="has_data"
                class="relative mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
            >
                <!-- Distance -->
                <div
                    class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/80 p-4 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Total Distance
                        </span>
                        <Footprints class="size-3.5 text-emerald-500" />
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 tabular-nums sm:text-3xl dark:text-white"
                    >
                        {{ formatDistanceCompact(totals.distance_meters, 1) }}
                    </p>
                    <div class="mt-2 flex items-center gap-1.5">
                        <span
                            v-if="yoy.distance_pct !== null"
                            :class="[
                                'inline-flex items-center gap-0.5 rounded-full border px-2 py-0.5 font-mono text-[10px] font-semibold tabular-nums',
                                deltaPillClass(yoy.distance_pct),
                            ]"
                        >
                            <ArrowUpRight
                                v-if="yoy.distance_pct >= 0"
                                class="size-3"
                            />
                            <ArrowDownRight v-else class="size-3" />
                            {{ formatDeltaPct(yoy.distance_pct) }}
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{
                                yoy.distance_pct !== null
                                    ? yoyCaption
                                    : `No ${yoy.previous_year} data`
                            }}
                        </span>
                    </div>
                </div>

                <!-- Moving Time -->
                <div
                    class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/80 p-4 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Moving Time
                        </span>
                        <Timer class="size-3.5 text-sky-500" />
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 tabular-nums sm:text-3xl dark:text-white"
                    >
                        {{ formatDurationHuman(totals.duration_seconds) }}
                    </p>
                    <div class="mt-2 flex items-center gap-1.5">
                        <span
                            v-if="yoy.duration_pct !== null"
                            :class="[
                                'inline-flex items-center gap-0.5 rounded-full border px-2 py-0.5 font-mono text-[10px] font-semibold tabular-nums',
                                deltaPillClass(yoy.duration_pct),
                            ]"
                        >
                            <ArrowUpRight
                                v-if="yoy.duration_pct >= 0"
                                class="size-3"
                            />
                            <ArrowDownRight v-else class="size-3" />
                            {{ formatDeltaPct(yoy.duration_pct) }}
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{
                                yoy.duration_pct !== null
                                    ? yoyCaption
                                    : `No ${yoy.previous_year} data`
                            }}
                        </span>
                    </div>
                </div>

                <!-- Elevation Gain -->
                <div
                    class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/80 p-4 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Elevation Gain
                        </span>
                        <Mountain class="size-3.5 text-amber-500" />
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 tabular-nums sm:text-3xl dark:text-white"
                    >
                        {{ formatElevation(totals.elevation_gain) }}
                    </p>
                    <div class="mt-2 flex items-center gap-1.5">
                        <span
                            v-if="yoy.elevation_pct !== null"
                            :class="[
                                'inline-flex items-center gap-0.5 rounded-full border px-2 py-0.5 font-mono text-[10px] font-semibold tabular-nums',
                                deltaPillClass(yoy.elevation_pct),
                            ]"
                        >
                            <ArrowUpRight
                                v-if="yoy.elevation_pct >= 0"
                                class="size-3"
                            />
                            <ArrowDownRight v-else class="size-3" />
                            {{ formatDeltaPct(yoy.elevation_pct) }}
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{
                                yoy.elevation_pct !== null
                                    ? yoyCaption
                                    : `No ${yoy.previous_year} data`
                            }}
                        </span>
                    </div>
                </div>

                <!-- Activities -->
                <div
                    class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/80 p-4 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Activities
                        </span>
                        <ActivityIcon class="size-3.5 text-violet-500" />
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 tabular-nums sm:text-3xl dark:text-white"
                    >
                        {{ formatNumber(totals.activities) }}
                    </p>
                    <div class="mt-2 flex items-center gap-1.5">
                        <span
                            v-if="yoy.activities_pct !== null"
                            :class="[
                                'inline-flex items-center gap-0.5 rounded-full border px-2 py-0.5 font-mono text-[10px] font-semibold tabular-nums',
                                deltaPillClass(yoy.activities_pct),
                            ]"
                        >
                            <ArrowUpRight
                                v-if="yoy.activities_pct >= 0"
                                class="size-3"
                            />
                            <ArrowDownRight v-else class="size-3" />
                            {{ formatDeltaPct(yoy.activities_pct) }}
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{
                                yoy.activities_pct !== null
                                    ? yoyCaption
                                    : `No ${yoy.previous_year} data`
                            }}
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Empty State -->
        <section
            v-if="!has_data"
            class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300/90 bg-white px-6 py-16 text-center dark:border-zinc-800 dark:bg-[#141417]"
        >
            <Calendar class="size-8 text-zinc-400 dark:text-zinc-500" />
            <h2
                class="mt-3 text-base font-semibold text-zinc-900 dark:text-zinc-100"
            >
                No recorded activities in {{ selected_year }}
            </h2>
            <p class="mt-1 max-w-md text-xs text-zinc-500 dark:text-zinc-400">
                Sync activities or switch to another year above to explore your
                annual training highlights, personal records, and streaks.
            </p>
        </section>

        <template v-else>
            <!-- Monthly Momentum + Sport Mix -->
            <section class="grid gap-4 xl:grid-cols-12">
                <!-- Monthly Momentum -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 xl:col-span-8 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div
                        class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <h2
                                class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Monthly Momentum
                            </h2>
                            <p
                                class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                Jan – Dec {{ selected_year }} Progression
                            </p>
                        </div>

                        <div
                            class="inline-flex items-center gap-1 rounded-lg border border-zinc-200/90 bg-zinc-50 p-0.5 dark:border-zinc-800 dark:bg-zinc-900/70"
                            role="group"
                            aria-label="Monthly chart metric"
                        >
                            <button
                                v-for="item in MONTHLY_METRICS"
                                :key="item.key"
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors',
                                    monthlyMetric === item.key
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                                ]"
                                @click="monthlyMetric = item.key"
                            >
                                {{ item.label }}
                            </button>
                        </div>
                    </div>

                    <BaseChart :option="monthlyChartOption" :height="250" />
                </div>

                <!-- Sport Mix -->
                <div
                    class="flex flex-col rounded-xl border border-zinc-200/90 bg-white p-5 xl:col-span-4 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h2
                                class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Sport Mix
                            </h2>
                            <p
                                class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                Time & Volume Share
                            </p>
                        </div>
                        <span
                            class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            {{ sport_breakdown.length }}
                            {{
                                sport_breakdown.length === 1
                                    ? 'sport'
                                    : 'sports'
                            }}
                        </span>
                    </div>

                    <!-- Stacked Proportion Bar -->
                    <div
                        class="mt-4 flex h-2.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/90"
                    >
                        <div
                            v-for="sport in sport_breakdown"
                            :key="sport.key"
                            class="h-full transition-all"
                            :style="{
                                width: `${sport.share_pct}%`,
                                minWidth: sport.share_pct > 0 ? '4px' : '0px',
                                backgroundColor: sport.accent_hex,
                            }"
                            :title="`${sport.label}: ${sport.share_pct}%`"
                        />
                    </div>

                    <!-- Breakdown Rows -->
                    <div class="mt-4 flex-1 space-y-3">
                        <div
                            v-for="sport in sport_breakdown"
                            :key="sport.key"
                            class="space-y-1"
                        >
                            <div
                                class="flex items-center justify-between text-xs"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor: sport.accent_hex,
                                        }"
                                    />
                                    <span
                                        class="truncate font-medium text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{ sport.label }}
                                    </span>
                                    <span
                                        class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                    >
                                        {{ sport.count }}
                                    </span>
                                </div>
                                <span
                                    class="font-mono text-xs font-semibold text-zinc-700 tabular-nums dark:text-zinc-200"
                                >
                                    {{ sport.share_pct.toFixed(1) }}%
                                </span>
                            </div>

                            <div
                                class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :style="{
                                        width: `${Math.min(100, Math.max(2, sport.share_pct))}%`,
                                        backgroundColor: sport.accent_hex,
                                    }"
                                />
                            </div>

                            <div
                                class="flex items-center justify-between font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                            >
                                <span>{{
                                    sport.distance_meters > 0
                                        ? formatDistanceCompact(
                                              sport.distance_meters,
                                              1,
                                          )
                                        : '—'
                                }}</span>
                                <span>
                                    {{
                                        formatDurationHuman(
                                            sport.duration_seconds,
                                        )
                                    }}
                                    <template v-if="sport.elevation_gain > 0">
                                        ·
                                        {{
                                            formatElevation(
                                                sport.elevation_gain,
                                            )
                                        }}
                                    </template>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Calendar Activity Heatmap -->
            <section v-if="annual_summary.daily.length > 0">
                <ActivityCalendarHeatmap
                    :summary="annual_summary"
                    :selected-years="[selected_year]"
                    :metric-options="SPORT_METRIC_OPTIONS"
                    default-metric="distance"
                />
            </section>

            <!-- Consistency & Peak Moments -->
            <section class="space-y-3">
                <h2
                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Consistency & Peak Moments
                </h2>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <!-- Longest Streak -->
                    <div
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/80 dark:bg-[#141417]"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Longest Streak
                            </span>
                            <Flame class="size-4 text-orange-500" />
                        </div>
                        <p
                            class="mt-2 text-2xl font-bold text-zinc-950 tabular-nums dark:text-white"
                        >
                            {{ consistency.longest_daily_streak.days }}
                            {{
                                consistency.longest_daily_streak.days === 1
                                    ? 'day'
                                    : 'days'
                            }}
                        </p>
                        <p
                            v-if="
                                consistency.longest_daily_streak.start_date &&
                                consistency.longest_daily_streak.end_date
                            "
                            class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                formatShortDate(
                                    consistency.longest_daily_streak.start_date,
                                )
                            }}
                            –
                            {{
                                formatShortDate(
                                    consistency.longest_daily_streak.end_date,
                                )
                            }}
                        </p>
                        <div
                            class="mt-3 border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
                        >
                            Weekly streak:
                            <span
                                class="font-semibold text-zinc-800 dark:text-zinc-200"
                            >
                                {{ consistency.longest_weekly_streak.weeks }}
                                {{
                                    consistency.longest_weekly_streak.weeks ===
                                    1
                                        ? 'week'
                                        : 'weeks'
                                }}
                            </span>
                        </div>
                    </div>

                    <!-- Biggest Week -->
                    <div
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/80 dark:bg-[#141417]"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Biggest Week
                            </span>
                            <TrendingUp class="size-4 text-emerald-500" />
                        </div>
                        <template v-if="peaks.biggest_week">
                            <p
                                class="mt-2 text-2xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{
                                    formatDistanceCompact(
                                        peaks.biggest_week.distance_meters,
                                        1,
                                    )
                                }}
                            </p>
                            <p
                                class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{ peaks.biggest_week.label }}
                            </p>
                            <div
                                class="mt-3 border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
                            >
                                {{ peaks.biggest_week.count }}
                                {{
                                    peaks.biggest_week.count === 1
                                        ? 'activity'
                                        : 'activities'
                                }}
                                ·
                                {{
                                    formatDurationHuman(
                                        peaks.biggest_week.duration_seconds,
                                    )
                                }}
                            </div>
                        </template>
                        <p
                            v-else
                            class="mt-2 text-sm text-zinc-400 dark:text-zinc-500"
                        >
                            —
                        </p>
                    </div>

                    <!-- Biggest Month -->
                    <div
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/80 dark:bg-[#141417]"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Biggest Month
                            </span>
                            <Calendar class="size-4 text-sky-500" />
                        </div>
                        <template v-if="peaks.biggest_month">
                            <p
                                class="mt-2 text-2xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{ peaks.biggest_month.label }}
                            </p>
                            <p
                                class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                            >
                                {{
                                    formatDistanceCompact(
                                        peaks.biggest_month.distance_meters,
                                        1,
                                    )
                                }}
                                ·
                                {{
                                    formatElevation(
                                        peaks.biggest_month.elevation_gain,
                                    )
                                }}
                            </p>
                            <div
                                class="mt-3 border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
                            >
                                {{ peaks.biggest_month.count }}
                                {{
                                    peaks.biggest_month.count === 1
                                        ? 'activity'
                                        : 'activities'
                                }}
                                ·
                                {{
                                    formatDurationHuman(
                                        peaks.biggest_month.duration_seconds,
                                    )
                                }}
                            </div>
                        </template>
                        <p
                            v-else
                            class="mt-2 text-sm text-zinc-400 dark:text-zinc-500"
                        >
                            —
                        </p>
                    </div>

                    <!-- Longest Activity -->
                    <div
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/80 dark:bg-[#141417]"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Longest Distance
                            </span>
                            <Footprints class="size-4 text-emerald-500" />
                        </div>
                        <template v-if="peaks.longest_activity">
                            <p
                                class="mt-2 text-2xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{
                                    formatDistanceCompact(
                                        peaks.longest_activity.distance_meters,
                                        2,
                                    )
                                }}
                            </p>
                            <Link
                                :href="
                                    showActivity.url(peaks.longest_activity.id)
                                "
                                prefetch
                                class="mt-1 inline-flex max-w-full items-center gap-1 text-xs font-medium text-zinc-700 hover:text-emerald-600 dark:text-zinc-300 dark:hover:text-emerald-400"
                            >
                                <span class="truncate">{{
                                    peaks.longest_activity.name
                                }}</span>
                                <ArrowUpRight class="size-3 shrink-0" />
                            </Link>
                            <div
                                class="mt-3 flex items-center justify-between border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
                            >
                                <span>{{
                                    formatShortDate(peaks.longest_activity.date)
                                }}</span>
                                <span>{{
                                    formatDurationHuman(
                                        peaks.longest_activity.duration_seconds,
                                    )
                                }}</span>
                            </div>
                        </template>
                        <p
                            v-else
                            class="mt-2 text-sm text-zinc-400 dark:text-zinc-500"
                        >
                            —
                        </p>
                    </div>

                    <!-- Biggest Climb -->
                    <div
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/80 dark:bg-[#141417]"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Biggest Climb
                            </span>
                            <Mountain class="size-4 text-amber-500" />
                        </div>
                        <template v-if="peaks.biggest_climb_activity">
                            <p
                                class="mt-2 text-2xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{
                                    formatElevation(
                                        peaks.biggest_climb_activity
                                            .elevation_gain,
                                    )
                                }}
                            </p>
                            <Link
                                :href="
                                    showActivity.url(
                                        peaks.biggest_climb_activity.id,
                                    )
                                "
                                prefetch
                                class="mt-1 inline-flex max-w-full items-center gap-1 text-xs font-medium text-zinc-700 hover:text-emerald-600 dark:text-zinc-300 dark:hover:text-emerald-400"
                            >
                                <span class="truncate">{{
                                    peaks.biggest_climb_activity.name
                                }}</span>
                                <ArrowUpRight class="size-3 shrink-0" />
                            </Link>
                            <div
                                class="mt-3 flex items-center justify-between border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
                            >
                                <span>{{
                                    formatShortDate(
                                        peaks.biggest_climb_activity.date,
                                    )
                                }}</span>
                                <span>{{
                                    formatDistanceCompact(
                                        peaks.biggest_climb_activity
                                            .distance_meters,
                                        1,
                                    )
                                }}</span>
                            </div>
                        </template>
                        <p
                            v-else
                            class="mt-2 text-sm text-zinc-400 dark:text-zinc-500"
                        >
                            —
                        </p>
                    </div>

                    <!-- Favorite Day & Time -->
                    <div
                        class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/80 dark:bg-[#141417]"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Training Rhythm
                            </span>
                            <Zap class="size-4 text-violet-500" />
                        </div>
                        <p
                            class="mt-2 text-2xl font-bold text-zinc-950 dark:text-white"
                        >
                            {{ consistency.busiest_weekday?.day ?? '—' }}
                        </p>
                        <p
                            v-if="consistency.favorite_time_of_day"
                            class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            {{ consistency.favorite_time_of_day.label }} ·
                            {{
                                consistency.favorite_time_of_day.share_pct.toFixed(
                                    0,
                                )
                            }}%
                        </p>
                        <div
                            v-if="peaks.longest_duration_activity"
                            class="mt-3 flex items-center justify-between gap-2 border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 dark:border-zinc-800/80 dark:text-zinc-400"
                        >
                            <span class="truncate">Longest session:</span>
                            <Link
                                :href="
                                    showActivity.url(
                                        peaks.longest_duration_activity.id,
                                    )
                                "
                                prefetch
                                class="shrink-0 font-semibold text-zinc-800 hover:text-emerald-600 dark:text-zinc-200 dark:hover:text-emerald-400"
                            >
                                {{
                                    formatDurationHuman(
                                        peaks.longest_duration_activity
                                            .duration_seconds,
                                    )
                                }}
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PRs & Route Highlights -->
            <section class="grid gap-4 xl:grid-cols-12">
                <!-- Best Efforts -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 xl:col-span-7 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2
                                class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Personal Records & Benchmarks
                            </h2>
                            <p
                                class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                Fastest Efforts of {{ selected_year }}
                            </p>
                        </div>
                        <span
                            v-if="milestones.all_time_prs_count > 0"
                            class="inline-flex items-center gap-1 rounded-full border border-amber-500/30 bg-amber-500/12 px-2.5 py-0.5 font-mono text-[11px] font-semibold text-amber-700 dark:text-amber-300"
                        >
                            <Trophy class="size-3" />
                            {{ milestones.all_time_prs_count }} All-Time
                            {{
                                milestones.all_time_prs_count === 1
                                    ? 'PR'
                                    : 'PRs'
                            }}
                        </span>
                    </div>

                    <div
                        v-if="milestones.best_efforts.length === 0"
                        class="flex h-40 items-center justify-center rounded-xl border border-dashed border-zinc-200 font-mono text-xs text-zinc-400 dark:border-zinc-800 dark:text-zinc-500"
                    >
                        No benchmark best efforts recorded in
                        {{ selected_year }}.
                    </div>

                    <div v-else class="grid gap-2.5 sm:grid-cols-2">
                        <Link
                            v-for="effort in milestones.best_efforts"
                            :key="effort.id"
                            :href="showActivity.url(effort.activity_id)"
                            prefetch
                            class="group flex items-center justify-between gap-3 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-3.5 transition-colors hover:border-emerald-500/40 hover:bg-white dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:hover:border-emerald-500/40 dark:hover:bg-zinc-900"
                        >
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor: effort.accent_hex,
                                        }"
                                    />
                                    <span
                                        class="text-xs font-semibold text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{ effort.distance_label }}
                                    </span>
                                    <span
                                        v-if="effort.is_all_time_pr"
                                        class="rounded-sm bg-amber-500/15 px-1.5 py-0.5 font-mono text-[9px] font-bold tracking-wider text-amber-700 uppercase dark:text-amber-300"
                                    >
                                        PR
                                    </span>
                                </div>
                                <p
                                    class="mt-1 truncate text-[11px] text-zinc-500 group-hover:text-zinc-700 dark:text-zinc-400 dark:group-hover:text-zinc-200"
                                >
                                    {{ effort.activity_name }}
                                </p>
                                <p
                                    class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    {{ effort.sport_label }} ·
                                    {{ formatShortDate(effort.achieved_at) }}
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p
                                    class="font-mono text-base font-bold text-zinc-950 tabular-nums dark:text-white"
                                >
                                    {{
                                        formatDurationClock(
                                            effort.elapsed_seconds,
                                        )
                                    }}
                                </p>
                                <ArrowUpRight
                                    class="mt-0.5 ml-auto size-3.5 text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-emerald-500"
                                />
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Routes & Segments -->
                <div
                    class="flex flex-col gap-4 rounded-xl border border-zinc-200/90 bg-white p-5 xl:col-span-5 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h2
                                class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Routes & Segments
                            </h2>
                            <p
                                class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                Signature Loops & Climbs
                            </p>
                        </div>
                        <RouteIcon class="size-4 text-emerald-500" />
                    </div>

                    <div class="grid grid-cols-2 gap-2.5">
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/80 p-3 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Matched Routes
                            </span>
                            <p
                                class="mt-1 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{
                                    formatNumber(
                                        milestones.matched_routes_completed,
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/80 p-3 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Segment PRs
                            </span>
                            <p
                                class="mt-1 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{ formatNumber(milestones.segment_prs_count) }}
                            </p>
                        </div>
                    </div>

                    <!-- Most Repeated Route -->
                    <div
                        v-if="milestones.most_repeated_route"
                        class="space-y-2.5 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Most Repeated Route
                            </span>
                            <span
                                class="rounded-full bg-emerald-500/12 px-2 py-0.5 font-mono text-[10px] font-semibold text-emerald-700 tabular-nums dark:text-emerald-300"
                            >
                                {{
                                    milestones.most_repeated_route
                                        .visits_in_year
                                }}× in {{ selected_year }}
                            </span>
                        </div>

                        <RouteMiniMap
                            :encoded-polyline="
                                milestones.most_repeated_route.encoded_polyline
                            "
                            :accent-hex="
                                milestones.most_repeated_route.accent_hex
                            "
                            :is-loop="milestones.most_repeated_route.is_loop"
                        />

                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <Link
                                    :href="
                                        routesShow.url(
                                            milestones.most_repeated_route.id,
                                        )
                                    "
                                    prefetch
                                    class="inline-flex max-w-full items-center gap-1 text-sm font-semibold text-zinc-950 hover:text-emerald-600 dark:text-white dark:hover:text-emerald-400"
                                >
                                    <span class="truncate">{{
                                        milestones.most_repeated_route.name
                                    }}</span>
                                    <ArrowUpRight class="size-3.5 shrink-0" />
                                </Link>
                                <p
                                    v-if="
                                        milestones.most_repeated_route
                                            .place_label
                                    "
                                    class="truncate text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{
                                        milestones.most_repeated_route
                                            .place_label
                                    }}
                                </p>
                            </div>
                            <div
                                class="shrink-0 text-right font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                            >
                                <div>
                                    {{
                                        formatDistanceCompact(
                                            milestones.most_repeated_route
                                                .distance_meters,
                                            1,
                                        )
                                    }}
                                </div>
                                <div
                                    v-if="
                                        milestones.most_repeated_route
                                            .elevation_gain
                                    "
                                >
                                    {{
                                        formatElevation(
                                            milestones.most_repeated_route
                                                .elevation_gain,
                                        )
                                    }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Segment PRs -->
                    <div
                        v-if="milestones.segment_prs.length > 0"
                        class="space-y-1.5"
                    >
                        <p
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Top Segment PRs
                        </p>
                        <Link
                            v-for="seg in milestones.segment_prs.slice(0, 3)"
                            :key="seg.id"
                            :href="showActivity.url(seg.activity_id)"
                            prefetch
                            class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/70 px-3 py-2 text-xs transition-colors hover:border-emerald-500/40 dark:border-zinc-800/70"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <Award
                                    class="size-3.5 shrink-0 text-amber-500"
                                />
                                <span
                                    class="truncate font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    {{ seg.segment_name }}
                                </span>
                                <span
                                    v-if="formatClimbBadge(seg.climb_category)"
                                    class="shrink-0 rounded-sm bg-amber-500/12 px-1.5 py-0.5 font-mono text-[9px] font-semibold text-amber-700 dark:text-amber-300"
                                >
                                    {{ formatClimbBadge(seg.climb_category) }}
                                </span>
                            </div>
                            <span
                                class="shrink-0 font-mono text-xs font-semibold text-zinc-700 tabular-nums dark:text-zinc-200"
                            >
                                {{ formatDurationClock(seg.elapsed_seconds) }}
                            </span>
                        </Link>
                    </div>
                </div>
            </section>

            <!-- Weather Extremes & Health Snapshot -->
            <section class="grid gap-4 xl:grid-cols-12">
                <!-- Weather Extremes -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 xl:col-span-6 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2
                                class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Weather Extremes
                            </h2>
                            <p
                                class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                Toughest Conditions of {{ selected_year }}
                            </p>
                        </div>

                        <span
                            v-if="weather.sample_count > 0"
                            class="inline-flex items-center gap-1 rounded-full border border-sky-500/25 bg-sky-500/10 px-2.5 py-0.5 font-mono text-[10px] font-medium text-sky-700 tabular-nums dark:text-sky-300"
                        >
                            <CloudRain class="size-3" />
                            {{ weather.rainy_sessions_count }} rainy
                            {{
                                weather.rainy_sessions_count === 1
                                    ? 'session'
                                    : 'sessions'
                            }}
                        </span>
                    </div>

                    <div
                        v-if="weather.sample_count === 0"
                        class="flex h-40 items-center justify-center rounded-xl border border-dashed border-zinc-200 font-mono text-xs text-zinc-400 dark:border-zinc-800 dark:text-zinc-500"
                    >
                        No activity weather data recorded in
                        {{ selected_year }}.
                    </div>

                    <div v-else class="grid gap-3 sm:grid-cols-2">
                        <!-- Coldest -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Coldest
                                </span>
                                <Snowflake class="size-3.5 text-sky-500" />
                            </div>
                            <template v-if="weather.coldest_activity">
                                <p
                                    class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                                >
                                    {{
                                        formatTemp(
                                            weather.coldest_activity
                                                .temperature_c,
                                            1,
                                        )
                                    }}
                                </p>
                                <Link
                                    :href="
                                        showActivity.url(
                                            weather.coldest_activity
                                                .activity_id,
                                        )
                                    "
                                    prefetch
                                    class="mt-1 inline-flex max-w-full items-center gap-1 text-xs font-medium text-zinc-700 hover:text-emerald-600 dark:text-zinc-300 dark:hover:text-emerald-400"
                                >
                                    <span class="truncate">{{
                                        weather.coldest_activity.activity_name
                                    }}</span>
                                    <ArrowUpRight class="size-3 shrink-0" />
                                </Link>
                                <p
                                    class="mt-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    {{
                                        formatShortDate(
                                            weather.coldest_activity.date,
                                        )
                                    }}
                                    · {{ weather.coldest_activity.condition }}
                                </p>
                            </template>
                            <p v-else class="mt-2 text-sm text-zinc-400">—</p>
                        </div>

                        <!-- Hottest -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Hottest
                                </span>
                                <Sun class="size-3.5 text-amber-500" />
                            </div>
                            <template v-if="weather.hottest_activity">
                                <p
                                    class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                                >
                                    {{
                                        formatTemp(
                                            weather.hottest_activity
                                                .temperature_c,
                                            1,
                                        )
                                    }}
                                </p>
                                <Link
                                    :href="
                                        showActivity.url(
                                            weather.hottest_activity
                                                .activity_id,
                                        )
                                    "
                                    prefetch
                                    class="mt-1 inline-flex max-w-full items-center gap-1 text-xs font-medium text-zinc-700 hover:text-emerald-600 dark:text-zinc-300 dark:hover:text-emerald-400"
                                >
                                    <span class="truncate">{{
                                        weather.hottest_activity.activity_name
                                    }}</span>
                                    <ArrowUpRight class="size-3 shrink-0" />
                                </Link>
                                <p
                                    class="mt-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    {{
                                        formatShortDate(
                                            weather.hottest_activity.date,
                                        )
                                    }}
                                    · {{ weather.hottest_activity.condition }}
                                </p>
                            </template>
                            <p v-else class="mt-2 text-sm text-zinc-400">—</p>
                        </div>

                        <!-- Windiest -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Windiest
                                </span>
                                <Wind class="size-3.5 text-teal-500" />
                            </div>
                            <template v-if="weather.windiest_activity">
                                <p
                                    class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                                >
                                    {{
                                        formatSpeedKmh(
                                            weather.windiest_activity
                                                .wind_speed_kmh,
                                            1,
                                        )
                                    }}
                                </p>
                                <Link
                                    :href="
                                        showActivity.url(
                                            weather.windiest_activity
                                                .activity_id,
                                        )
                                    "
                                    prefetch
                                    class="mt-1 inline-flex max-w-full items-center gap-1 text-xs font-medium text-zinc-700 hover:text-emerald-600 dark:text-zinc-300 dark:hover:text-emerald-400"
                                >
                                    <span class="truncate">{{
                                        weather.windiest_activity.activity_name
                                    }}</span>
                                    <ArrowUpRight class="size-3 shrink-0" />
                                </Link>
                                <p
                                    class="mt-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    {{
                                        formatShortDate(
                                            weather.windiest_activity.date,
                                        )
                                    }}
                                    · {{ weather.windiest_activity.condition }}
                                </p>
                            </template>
                            <p v-else class="mt-2 text-sm text-zinc-400">—</p>
                        </div>

                        <!-- Wettest -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Wettest
                                </span>
                                <CloudRain class="size-3.5 text-indigo-500" />
                            </div>
                            <template v-if="weather.wettest_activity">
                                <p
                                    class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                                >
                                    {{
                                        formatPrecip(
                                            weather.wettest_activity
                                                .precipitation_mm,
                                        )
                                    }}
                                </p>
                                <Link
                                    :href="
                                        showActivity.url(
                                            weather.wettest_activity
                                                .activity_id,
                                        )
                                    "
                                    prefetch
                                    class="mt-1 inline-flex max-w-full items-center gap-1 text-xs font-medium text-zinc-700 hover:text-emerald-600 dark:text-zinc-300 dark:hover:text-emerald-400"
                                >
                                    <span class="truncate">{{
                                        weather.wettest_activity.activity_name
                                    }}</span>
                                    <ArrowUpRight class="size-3 shrink-0" />
                                </Link>
                                <p
                                    class="mt-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    {{
                                        formatShortDate(
                                            weather.wettest_activity.date,
                                        )
                                    }}
                                    · {{ weather.wettest_activity.condition }}
                                </p>
                            </template>
                            <p
                                v-else
                                class="mt-2 font-mono text-xs text-zinc-400 dark:text-zinc-500"
                            >
                                Dry year!
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Health Snapshot -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 xl:col-span-6 dark:border-zinc-800/80 dark:bg-[#141417]"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2
                                class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Health & Recovery Snapshot
                            </h2>
                            <p
                                class="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{ selected_year }} Daily Telemetry Averages
                            </p>
                        </div>
                        <HeartPulse class="size-4 text-rose-500" />
                    </div>

                    <div
                        v-if="!health.has_data"
                        class="flex h-40 items-center justify-center rounded-xl border border-dashed border-zinc-200 font-mono text-xs text-zinc-400 dark:border-zinc-800 dark:text-zinc-500"
                    >
                        No daily health metrics synced for
                        {{ selected_year }}.
                    </div>

                    <div
                        v-else
                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <!-- Avg Sleep -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Avg Sleep
                                </span>
                                <Moon class="size-3.5 text-indigo-500" />
                            </div>
                            <p
                                class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{ formatSleepHours(health.avg_sleep_hours) }}
                            </p>
                            <p
                                class="mt-0.5 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                            >
                                <template
                                    v-if="health.avg_sleep_score !== null"
                                >
                                    Score
                                    {{ health.avg_sleep_score.toFixed(0) }}
                                </template>
                                <template v-else-if="health.sleep_nights > 0">
                                    {{ formatNumber(health.sleep_nights) }}
                                    nights
                                </template>
                                <template v-else>—</template>
                            </p>
                        </div>

                        <!-- Resting HR -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Resting HR
                                </span>
                                <HeartPulse class="size-3.5 text-rose-500" />
                            </div>
                            <p
                                class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{
                                    health.avg_resting_hr !== null
                                        ? `${health.avg_resting_hr.toFixed(1)} bpm`
                                        : '—'
                                }}
                            </p>
                            <p
                                class="mt-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                            >
                                Annual mean
                            </p>
                        </div>

                        <!-- Overnight HRV -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Overnight HRV
                                </span>
                                <ActivityIcon
                                    class="size-3.5 text-violet-500"
                                />
                            </div>
                            <p
                                class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{
                                    health.avg_hrv !== null
                                        ? `${health.avg_hrv.toFixed(1)} ms`
                                        : '—'
                                }}
                            </p>
                            <p
                                class="mt-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                            >
                                Nightly avg
                            </p>
                        </div>

                        <!-- Total Steps -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 sm:col-span-2 lg:col-span-2 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Total Steps
                                </span>
                                <Footprints class="size-3.5 text-emerald-500" />
                            </div>
                            <p
                                class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{
                                    health.total_steps > 0
                                        ? formatNumber(health.total_steps)
                                        : '—'
                                }}
                            </p>
                            <p
                                class="mt-0.5 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                            >
                                {{
                                    health.avg_daily_steps !== null
                                        ? `${formatNumber(health.avg_daily_steps)} steps / day avg`
                                        : 'Daily wellness'
                                }}
                            </p>
                        </div>

                        <!-- Peak VO2 Max -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Peak VO2 Max
                                </span>
                                <Zap class="size-3.5 text-amber-500" />
                            </div>
                            <p
                                class="mt-1.5 text-xl font-bold text-zinc-950 tabular-nums dark:text-white"
                            >
                                {{
                                    health.peak_vo2_max !== null
                                        ? health.peak_vo2_max.toFixed(1)
                                        : '—'
                                }}
                            </p>
                            <p
                                class="mt-0.5 font-mono text-[10px] text-zinc-400 capitalize dark:text-zinc-500"
                            >
                                {{
                                    health.peak_vo2_max_sport
                                        ? `${health.peak_vo2_max_sport}${health.peak_vo2_max_date ? ` · ${formatShortDate(health.peak_vo2_max_date)}` : ''}`
                                        : 'mL/kg/min'
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
