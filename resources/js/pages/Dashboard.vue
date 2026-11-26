<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowUpRight,
    Calendar,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    RefreshCw,
    Settings,
} from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed, ref } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import ActivitySummaryCharts from '@/components/charts/ActivitySummaryCharts.vue';
import type { AnnualActivitySummaryPayload } from '@/components/charts/annualSummaryTypes';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatDurationHuman,
    formatNumber,
    formatShortDate,
    formatTimeOfDay,
} from '@/lib/format';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import { dashboard } from '@/routes';
import {
    index as activitiesIndex,
    show as activitiesShow,
} from '@/routes/activities';
import {
    discipline as cyclingDiscipline,
    index as cyclingIndex,
} from '@/routes/cycling';
import { index as goalsIndex } from '@/routes/goals';
import { show as healthShow } from '@/routes/health';
import {
    discipline as runningDiscipline,
    index as runningIndex,
} from '@/routes/running';
import { index as settingsIndex } from '@/routes/settings';
import { show as sportsShow } from '@/routes/sports';
import { store as syncStore } from '@/routes/sync';

interface DashboardProps {
    date: string;
    dateLabel: string;
    prevDate: string;
    nextDate: string | null;
    isToday: boolean;
    todayDate: string;
    latestActivity: {
        id: number;
        name: string;
        sport_category: string;
        effective_discipline: string | null;
        distance_meters: number;
        duration_seconds: number;
        avg_speed: number | null;
        avg_hr: number | null;
        started_at_local: string;
        is_today: boolean;
    } | null;
    bodyBattery: {
        current: number | null;
        high: number | null;
        low: number | null;
        charged: number | null;
        drained: number | null;
        sleep: number | null;
        dayStartMs: number;
        dayEndMs: number;
        wakeTimestampMs: number | null;
        wakeTimeLabel: string | null;
        series: [number, number][];
    };
    sleep: {
        totalSeconds: number | null;
        deepSeconds: number | null;
        lightSeconds: number | null;
        remSeconds: number | null;
        awakeSeconds: number | null;
        napSeconds: number | null;
        score: number | null;
        quality: string | null;
        needMinutes: number | null;
        nextNeedMinutes: number | null;
        startLocal: string | null;
        endLocal: string | null;
        trend7d: Array<{
            date: string;
            totalHours: number;
            deepHours: number;
            lightHours: number;
            remHours: number;
            score: number | null;
        }>;
    };
    hrv: {
        lastNightAvg: number | null;
        weeklyAvg: number | null;
        lastNight5MinHigh: number | null;
        status: string | null;
        baselineLow: number | null;
        baselineBalancedLow: number | null;
        baselineBalancedUpper: number | null;
        feedbackPhrase: string | null;
        trend28d: Array<{
            date: string;
            lastNight: number | null;
            weeklyAvg: number | null;
        }>;
    };
    trainingReadiness: {
        score: number | null;
        level: string | null;
        subtitle: string | null;
        tone: string;
        recoveryHours: number | null;
        factors: {
            sleep: { label: string; tone: string; score: number | null };
            recovery: {
                label: string;
                tone: string;
                score: number | null;
                hours: number | null;
            };
            hrvStatus: { label: string; tone: string; score: number | null };
            acuteLoad: {
                label: string;
                tone: string;
                score: number | null;
                ratio: number | null;
            };
            recentSleep: { label: string; tone: string; score: number | null };
            recentStress: {
                label: string;
                tone: string;
                score: number | null;
            };
        };
    };
    weeklyActivity: {
        weekStart: string;
        weekEnd: string;
        weekLabel: string;
        totalDurationSeconds: number;
        days: Array<{
            date: string;
            label: string;
            durationSeconds: number;
            activityCount: number;
            isSelected: boolean;
            isFuture: boolean;
        }>;
        last4w: Array<{
            date: string;
            durationSeconds: number;
            activityCount: number;
            level: number;
            isFuture: boolean;
        }>;
    };
    training7d: {
        total_load: number;
        total_duration_seconds: number;
        total_distance_meters: number;
        activity_count: number;
        by_sport: {
            running: {
                count: number;
                distance_meters: number;
                duration_seconds: number;
                load: number;
            };
            road: {
                count: number;
                distance_meters: number;
                duration_seconds: number;
                load: number;
            };
            mtb: {
                count: number;
                distance_meters: number;
                elevation_gain: number;
                duration_seconds: number;
                load: number;
            };
            other: {
                count: number;
                distance_meters: number;
                duration_seconds: number;
                load: number;
            };
        };
        by_sport_list?: Array<{
            key: string;
            label: string;
            short_label: string;
            accent_hex: string;
            count: number;
            distance_meters: number;
            elevation_gain: number;
            duration_seconds: number;
            load: number;
        }>;
        goals: Array<{
            id: number;
            title: string;
            sport: string;
            current_value: number;
            target_value: number;
            delta?: number;
            unit: string;
            pct: number;
            pace_label: string;
        }>;
    };
    atAGlance: {
        steps: {
            count: number | null;
            goal: number | null;
            distanceMeters: number | null;
        };
        heartRate: {
            resting: number | null;
            sevenDayAvg: number | null;
            min: number | null;
            max: number | null;
            series: [number, number][];
        };
        stress: {
            avg: number | null;
            max: number | null;
            qualifier: string | null;
            restSeconds: number | null;
            lowSeconds: number | null;
            mediumSeconds: number | null;
            highSeconds: number | null;
            restPct: number | null;
        };
        intensityMinutes: {
            moderate: number | null;
            vigorous: number | null;
            total: number | null;
            goal: number | null;
            dailyTotal: number | null;
            weekStart: string;
        };
        weight: {
            weightKg: number | null;
            goalKg: number | null;
            deltaLastKg: number | null;
            delta30dKg: number | null;
            bmi: number | null;
            bodyFatPct: number | null;
            bodyWaterPct: number | null;
            muscleMassKg: number | null;
            boneMassKg: number | null;
            measuredDate: string | null;
        };
        vo2Fitness: {
            runningVo2: number | null;
            cyclingVo2: number | null;
            fitnessAge: number | null;
            chronologicalAge: number | null;
            delta: number | null;
            fitnessAgeDate: string | null;
        };
        healthStatus: {
            status: string | null;
            tone: string;
            summary: string;
            inRangeCount: number;
            checkedCount: number;
            metrics: Array<{
                key: string;
                label: string;
                inRange: boolean;
                detail: string;
            }>;
        };
        calories: {
            total: number | null;
            active: number | null;
            bmr: number | null;
            rawTotal: number | null;
            rawActive: number | null;
            rawBmr: number | null;
            dayNote: string | null;
        };
        floors: {
            ascended: number | null;
            descended: number | null;
            goal: number | null;
        };
        respirationSpo2: {
            avgRespiration: number | null;
            minRespiration: number | null;
            maxRespiration: number | null;
            avgSpo2: number | null;
            lowestSpo2: number | null;
            latestSpo2: number | null;
        };
        hydration: {
            ml: number | null;
        };
    };
    annualActivitySummary: AnnualActivitySummaryPayload;
}

const props = defineProps<DashboardProps>();
const page = usePage();
const theme = useChartTheme();
const units = useUnits();
const sports = useSports();
const dateInputRef = ref<HTMLInputElement | null>(null);
const isSyncStarting = ref(false);

const sync = computed(() => page.props.sync);
const isProviderConfigured = computed<boolean>(
    () => sync.value?.provider_configured !== false,
);
const isSyncing = computed<boolean>(
    () =>
        isSyncStarting.value ||
        sync.value?.status === 'running' ||
        sync.value?.status === 'queued',
);

const isEmptyState = computed<boolean>(() => {
    const hasActivity =
        props.latestActivity !== null ||
        props.training7d.activity_count > 0 ||
        props.weeklyActivity.totalDurationSeconds > 0;
    const hasRecovery =
        props.bodyBattery.current !== null ||
        props.bodyBattery.high !== null ||
        props.bodyBattery.series.length > 0 ||
        props.sleep.totalSeconds !== null ||
        props.sleep.score !== null ||
        props.sleep.trend7d.length > 0 ||
        props.hrv.lastNightAvg !== null ||
        props.hrv.trend28d.length > 0;
    const hasGlance =
        props.atAGlance.steps.count !== null ||
        props.atAGlance.heartRate.resting !== null ||
        props.atAGlance.stress.avg !== null ||
        props.atAGlance.calories.total !== null ||
        props.atAGlance.weight.weightKg !== null ||
        props.atAGlance.vo2Fitness.runningVo2 !== null ||
        props.atAGlance.vo2Fitness.cyclingVo2 !== null;

    return !hasActivity && !hasRecovery && !hasGlance;
});

function triggerEmptyStateSync(): void {
    if (isSyncing.value || !isProviderConfigured.value) {
        return;
    }

    router.post(
        syncStore.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isSyncStarting.value = true;
            },
            onFinish: () => {
                isSyncStarting.value = false;
            },
        },
    );
}

function goToDate(targetDate: string): void {
    if (!targetDate) {
        return;
    }

    const url =
        targetDate === props.todayDate
            ? dashboard.url()
            : dashboard.url({ query: { date: targetDate } });

    router.get(
        url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function openDatePicker(): void {
    const input = dateInputRef.value;

    if (!input) {
        return;
    }

    if (typeof input.showPicker === 'function') {
        input.showPicker();
    } else {
        input.focus();
        input.click();
    }
}

function onDateChange(event: Event): void {
    const target = event.target as HTMLInputElement | null;

    if (target?.value) {
        goToDate(target.value);
    }
}

function formatClockFromTimestamp(
    timestampMs: number,
    includeDate = false,
): string {
    const date = new Date(timestampMs);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const time = date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    if (!includeDate) {
        return time;
    }

    const day = date.toLocaleDateString([], {
        day: 'numeric',
        month: 'short',
    });

    return `${day} ${time}`;
}

function seriesSpansMultipleDays(points: [number, number][]): boolean {
    if (points.length < 2) {
        return false;
    }

    return points[points.length - 1][0] - points[0][0] > 24 * 60 * 60 * 1000;
}

function formatQualifier(qualifier: string | null): string {
    if (!qualifier) {
        return '—';
    }

    return qualifier
        .toLowerCase()
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function clampPct(value: number): number {
    if (!Number.isFinite(value)) {
        return 0;
    }

    return Math.max(0, Math.min(100, value));
}

function sportHref(sportKey: string): string {
    switch (sportKey) {
        case 'running':
            return runningIndex.url();
        case 'road_running':
            return runningDiscipline.url('road');
        case 'trail_running':
            return runningDiscipline.url('trail');
        case 'track_running':
            return runningDiscipline.url('track');
        case 'treadmill_running':
            return runningDiscipline.url('treadmill');
        case 'cycling':
        case 'biking':
            return cyclingIndex.url();
        case 'road_cycling':
        case 'road_biking':
        case 'road':
            return cyclingDiscipline.url('road');
        case 'gravel_cycling':
        case 'gravel':
            return cyclingDiscipline.url('gravel');
        case 'mtb_cycling':
        case 'mtb_biking':
        case 'mtb':
            return cyclingDiscipline.url('mtb');
        case 'indoor_cycling':
        case 'indoor':
            return cyclingDiscipline.url('indoor');
        case 'ebike_cycling':
        case 'ebike':
            return cyclingDiscipline.url('ebike');
        case 'commute_cycling':
        case 'commute':
            return cyclingDiscipline.url('commute');
        case 'hiking':
        case 'walking':
        case 'swimming':
        case 'cardio':
            return sportsShow.url(sportKey);
        default:
            return activitiesIndex.url();
    }
}

function resolveActivityAccent(
    sportCategory: string,
    effectiveDiscipline: string | null = null,
): string {
    const key = sports.resolveActivitySportKey(
        sportCategory,
        effectiveDiscipline,
    );

    return sports.sportAccentHex(key);
}

function goalAccentHex(sport: string): string {
    return sports.sportAccentHex(sport);
}

type SportBreakdownItem = {
    key: string;
    label: string;
    short_label: string;
    accent_hex: string;
    count: number;
    distance_meters: number;
    elevation_gain: number;
    duration_seconds: number;
    load: number;
};

const sportBreakdownList = computed<SportBreakdownItem[]>(() => {
    const dynamicList = props.training7d.by_sport_list;

    if (Array.isArray(dynamicList) && dynamicList.length > 0) {
        return dynamicList;
    }

    const legacy = props.training7d.by_sport;
    const fallback: SportBreakdownItem[] = [
        {
            key: 'running',
            label: sports.sportLabel('running'),
            short_label: sports.sportShortLabel('running'),
            accent_hex: sports.sportAccentHex('running'),
            count: legacy.running.count,
            distance_meters: legacy.running.distance_meters,
            elevation_gain: 0,
            duration_seconds: legacy.running.duration_seconds,
            load: legacy.running.load,
        },
        {
            key: 'road_cycling',
            label: sports.sportLabel('road_cycling'),
            short_label: sports.sportShortLabel('road_cycling'),
            accent_hex: sports.sportAccentHex('road_cycling'),
            count: legacy.road.count,
            distance_meters: legacy.road.distance_meters,
            elevation_gain: 0,
            duration_seconds: legacy.road.duration_seconds,
            load: legacy.road.load,
        },
        {
            key: 'mtb_cycling',
            label: sports.sportLabel('mtb_cycling'),
            short_label: sports.sportShortLabel('mtb_cycling'),
            accent_hex: sports.sportAccentHex('mtb_cycling'),
            count: legacy.mtb.count,
            distance_meters: legacy.mtb.distance_meters,
            elevation_gain: legacy.mtb.elevation_gain,
            duration_seconds: legacy.mtb.duration_seconds,
            load: legacy.mtb.load,
        },
    ];

    if (legacy.other.count > 0) {
        fallback.push({
            key: 'other',
            label: 'Other',
            short_label: 'Other',
            accent_hex: '#71717a',
            count: legacy.other.count,
            distance_meters: legacy.other.distance_meters,
            elevation_gain: 0,
            duration_seconds: legacy.other.duration_seconds,
            load: legacy.other.load,
        });
    }

    return fallback;
});

function formatSportChipVolume(item: SportBreakdownItem): string {
    const meta = sports.getSportMeta(item.key);

    if (item.key === 'other' || meta.metric_mode === 'duration') {
        return formatDurationHuman(item.duration_seconds);
    }

    if (item.distance_meters > 0) {
        const dist = units.formatDistanceCompact(item.distance_meters, 1);

        if (
            (item.key === 'mtb_cycling' ||
                item.key === 'trail_running' ||
                item.key === 'hiking') &&
            item.elevation_gain > 0
        ) {
            return `${dist} · ${units.formatElevation(item.elevation_gain)}`;
        }

        return dist;
    }

    if (item.duration_seconds > 0) {
        return formatDurationHuman(item.duration_seconds);
    }

    return units.formatDistanceCompact(0, 1);
}

function formatGoalProgress(goal: {
    current_value: number;
    target_value: number;
    unit: string;
}): string {
    if (goal.unit === 'km') {
        return `${formatNumber(units.kmToInputDistance(goal.current_value), 1)} / ${formatNumber(units.kmToInputDistance(goal.target_value), 1)} ${units.distanceUnit.value}`;
    }

    if (goal.unit === 'm') {
        return `${formatNumber(units.metersToInputElevation(goal.current_value), 0)} / ${formatNumber(units.metersToInputElevation(goal.target_value), 0)} ${units.elevationUnit.value}`;
    }

    return `${formatNumber(goal.current_value)} / ${formatNumber(goal.target_value)} ${goal.unit}`;
}

function formatGoalPaceLabel(goal: {
    unit: string;
    delta?: number;
    pct: number;
    pace_label: string;
}): string {
    if (!units.isImperial.value || goal.delta === undefined) {
        return goal.pace_label;
    }

    if (goal.pct >= 100 || goal.pace_label === 'On pace') {
        return goal.pace_label;
    }

    if (goal.unit === 'km') {
        const converted = units.kmToInputDistance(Math.abs(goal.delta));

        return goal.delta >= 0
            ? `On track · +${converted.toFixed(1)} mi ahead`
            : `Behind · -${converted.toFixed(1)} mi`;
    }

    if (goal.unit === 'm') {
        const converted = units.metersToInputElevation(Math.abs(goal.delta));

        return goal.delta >= 0
            ? `On track · +${formatNumber(converted, 0)} ft ahead`
            : `Behind · -${formatNumber(converted, 0)} ft`;
    }

    return goal.pace_label;
}

function formatWeightDelta(deltaKg: number): string {
    const converted = units.weightFromKg(deltaKg);

    return `${converted > 0 ? '+' : ''}${converted.toFixed(1)} ${units.weightUnit.value}`;
}

const latestActivityMetric = computed(() => {
    const activity = props.latestActivity;

    if (!activity || activity.avg_speed === null || activity.avg_speed <= 0) {
        return null;
    }

    const sportKey = sports.resolveActivitySportKey(
        activity.sport_category,
        activity.effective_discipline,
    );
    const mode = sports.getSportMeta(sportKey).metric_mode;

    if (mode === 'pace') {
        return units.formatPace(activity.avg_speed);
    }

    if (mode === 'pace_100m') {
        return units.formatPace100m(activity.avg_speed);
    }

    if (mode === 'duration') {
        return null;
    }

    return units.formatSpeed(activity.avg_speed);
});

const sleepStages = computed(() => {
    const deep = Math.max(0, props.sleep.deepSeconds ?? 0);
    const light = Math.max(0, props.sleep.lightSeconds ?? 0);
    const rem = Math.max(0, props.sleep.remSeconds ?? 0);
    const awake = Math.max(0, props.sleep.awakeSeconds ?? 0);
    const total = deep + light + rem + awake;

    if (total <= 0) {
        return {
            hasData: false,
            deepPct: 0,
            lightPct: 0,
            remPct: 0,
            awakePct: 0,
            deepMin: 0,
            lightMin: 0,
            remMin: 0,
            awakeMin: 0,
        };
    }

    return {
        hasData: true,
        deepPct: (deep / total) * 100,
        lightPct: (light / total) * 100,
        remPct: (rem / total) * 100,
        awakePct: (awake / total) * 100,
        deepMin: Math.round(deep / 60),
        lightMin: Math.round(light / 60),
        remMin: Math.round(rem / 60),
        awakeMin: Math.round(awake / 60),
    };
});

const hrvStatusClass = computed(() => {
    const status = (props.hrv.status ?? '').toUpperCase();

    if (status === 'BALANCED') {
        return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400';
    }

    if (status === 'UNBALANCED') {
        return 'bg-amber-500/15 text-amber-600 dark:text-amber-400';
    }

    if (status === 'LOW' || status === 'POOR') {
        return 'bg-rose-500/15 text-rose-600 dark:text-rose-400';
    }

    return 'bg-zinc-500/12 text-zinc-600 dark:text-zinc-400';
});

const stepsPct = computed(() => {
    const count = props.atAGlance.steps.count;
    const goal = props.atAGlance.steps.goal;

    if (count === null || goal === null || goal <= 0) {
        return 0;
    }

    return clampPct((count / goal) * 100);
});

const intensityPct = computed(() => {
    const total = props.atAGlance.intensityMinutes.total;
    const goal = props.atAGlance.intensityMinutes.goal;

    if (total === null || goal === null || goal <= 0) {
        return 0;
    }

    return clampPct((total / goal) * 100);
});

const weightMeta = computed(() => {
    const w = props.atAGlance.weight;
    const parts: string[] = [];

    if (w.bodyFatPct !== null) {
        parts.push(`BF ${w.bodyFatPct.toFixed(1)}%`);
    }

    if (w.muscleMassKg !== null) {
        parts.push(`Muscle ${units.formatWeight(w.muscleMassKg, 1)}`);
    }

    if (w.boneMassKg !== null) {
        parts.push(`Bone ${units.formatWeight(w.boneMassKg, 1)}`);
    }

    if (w.bmi !== null) {
        parts.push(`BMI ${w.bmi.toFixed(1)}`);
    }

    return parts.length > 0 ? parts.join(' · ') : 'No body composition data';
});

const hydrationSubLabel = computed(() => {
    const ml = props.atAGlance.hydration.ml ?? 0;

    if (units.isImperial.value) {
        const gallons = units.volumeFromMl(ml) / 128;

        return `${gallons.toFixed(2)} gal logged`;
    }

    return `${(ml / 1000).toFixed(2)} L logged`;
});

const vo2Display = computed(() => {
    const { runningVo2, cyclingVo2 } = props.atAGlance.vo2Fitness;

    if (runningVo2 !== null && cyclingVo2 !== null) {
        return `${runningVo2.toFixed(0)} / ${cyclingVo2.toFixed(0)}`;
    }

    if (runningVo2 !== null) {
        return runningVo2.toFixed(0);
    }

    if (cyclingVo2 !== null) {
        return cyclingVo2.toFixed(0);
    }

    return '—';
});

const vo2Meta = computed(() => {
    const { runningVo2, cyclingVo2 } = props.atAGlance.vo2Fitness;

    if (runningVo2 !== null && cyclingVo2 !== null) {
        return 'Run / Cycle · mL/kg/min';
    }

    if (runningVo2 !== null) {
        return 'Running · mL/kg/min';
    }

    if (cyclingVo2 !== null) {
        return 'Cycling · mL/kg/min';
    }

    return 'No VO2 estimates yet';
});

const fitnessAgeDisplay = computed(() => {
    const age = props.atAGlance.vo2Fitness.fitnessAge;

    if (age === null) {
        return '—';
    }

    return Math.round(age).toString();
});

const sleepQualityClass = computed(() => {
    const quality = (props.sleep.quality ?? '').toUpperCase();

    if (quality === 'EXCELLENT' || quality === 'GOOD') {
        return 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-400';
    }

    if (quality === 'FAIR') {
        return 'bg-amber-500/12 text-amber-600 dark:text-amber-400';
    }

    if (quality === 'POOR') {
        return 'bg-rose-500/12 text-rose-600 dark:text-rose-400';
    }

    return 'bg-zinc-500/12 text-zinc-600 dark:text-zinc-400';
});

function factorToneDotClass(tone: string): string {
    if (tone === 'positive') {
        return 'bg-emerald-500';
    }

    if (tone === 'moderate') {
        return 'bg-amber-500';
    }

    if (tone === 'negative') {
        return 'bg-rose-500';
    }

    return 'bg-zinc-300 dark:bg-zinc-700';
}

const readinessToneColor = computed(() => {
    const tone = props.trainingReadiness.tone;

    if (tone === 'prime') {
        return '#8b5cf6';
    }

    if (tone === 'high') {
        return '#0ea5e9';
    }

    if (tone === 'moderate') {
        return '#10b981';
    }

    if (tone === 'low') {
        return '#f59e0b';
    }

    if (tone === 'poor') {
        return '#f43f5e';
    }

    return '#71717a';
});

const readinessGaugeArcs = computed(() => {
    const radius = 38;
    const cx = 50;
    const cy = 50;
    const startDeg = 135;
    const totalSweep = 270;
    const gapDeg = 5;

    const bands = [
        { from: 0, to: 24, color: '#f43f5e' },
        { from: 25, to: 49, color: '#f59e0b' },
        { from: 50, to: 74, color: '#10b981' },
        { from: 75, to: 94, color: '#0ea5e9' },
        { from: 95, to: 100, color: '#8b5cf6' },
    ];

    const polarToCartesian = (angleDeg: number) => {
        const rad = (angleDeg * Math.PI) / 180;

        return {
            x: cx + radius * Math.cos(rad),
            y: cy + radius * Math.sin(rad),
        };
    };

    const segments = bands.map((band, index) => {
        const a0 =
            startDeg +
            (band.from / 100) * totalSweep +
            (index === 0 ? 0 : gapDeg / 2);
        const a1 =
            startDeg +
            (band.to / 100) * totalSweep -
            (index === bands.length - 1 ? 0 : gapDeg / 2);
        const p0 = polarToCartesian(a0);
        const p1 = polarToCartesian(a1);
        const largeArc = a1 - a0 > 180 ? 1 : 0;

        return {
            d: `M ${p0.x.toFixed(2)} ${p0.y.toFixed(2)} A ${radius} ${radius} 0 ${largeArc} 1 ${p1.x.toFixed(2)} ${p1.y.toFixed(2)}`,
            color: band.color,
        };
    });

    const score = props.trainingReadiness.score;
    const marker =
        score !== null
            ? polarToCartesian(
                  startDeg +
                      (Math.max(0, Math.min(100, score)) / 100) * totalSweep,
              )
            : null;

    return { segments, marker };
});

const readinessFactorRows = computed(() => {
    const f = props.trainingReadiness.factors;

    return [
        {
            key: 'sleep',
            title: 'Sleep',
            label: f.sleep.label,
            tone: f.sleep.tone,
            hint: f.sleep.score !== null ? `Score ${f.sleep.score}` : null,
        },
        {
            key: 'recovery',
            title: 'Recovery',
            label: f.recovery.label,
            tone: f.recovery.tone,
            hint:
                f.recovery.hours !== null && f.recovery.hours > 0
                    ? `~${f.recovery.hours}h`
                    : null,
        },
        {
            key: 'hrvStatus',
            title: 'HRV Status',
            label: f.hrvStatus.label,
            tone: f.hrvStatus.tone,
            hint: null,
        },
        {
            key: 'acuteLoad',
            title: 'Acute Load',
            label: f.acuteLoad.label,
            tone: f.acuteLoad.tone,
            hint:
                f.acuteLoad.ratio !== null
                    ? `${f.acuteLoad.ratio.toFixed(2)}x`
                    : null,
        },
        {
            key: 'recentSleep',
            title: 'Recent Sleep',
            label: f.recentSleep.label,
            tone: f.recentSleep.tone,
            hint: null,
        },
        {
            key: 'recentStress',
            title: 'Recent Stress',
            label: f.recentStress.label,
            tone: f.recentStress.tone,
            hint: null,
        },
    ];
});

const weeklyMaxDurationSeconds = computed(() => {
    const maxVal = Math.max(
        ...props.weeklyActivity.days.map((d) => d.durationSeconds),
        0,
    );

    return maxVal > 0 ? maxVal : 1;
});

const last4wWeeks = computed(() => {
    const items = props.weeklyActivity.last4w;
    const chunks = [];

    for (let i = 0; i < items.length; i += 7) {
        chunks.push(items.slice(i, i + 7));
    }

    return chunks;
});

function activityDotClass(level: number, isFuture: boolean): string {
    if (isFuture) {
        return 'size-1 bg-zinc-200/70 dark:bg-zinc-800/70';
    }

    if (level === 0) {
        return 'size-1 bg-zinc-300 dark:bg-zinc-700';
    }

    if (level === 1) {
        return 'size-2 bg-sky-400/80 dark:bg-sky-500/75';
    }

    if (level === 2) {
        return 'size-2.5 bg-sky-500 dark:bg-sky-400';
    }

    return 'size-3 bg-emerald-500 dark:bg-emerald-400';
}

const stressRingSegments = computed(() => {
    const s = props.atAGlance.stress;
    const rest = Math.max(0, s.restSeconds ?? 0);
    const low = Math.max(0, s.lowSeconds ?? 0);
    const med = Math.max(0, s.mediumSeconds ?? 0);
    const high = Math.max(0, s.highSeconds ?? 0);
    const total = rest + low + med + high;
    const circumference = 2 * Math.PI * 20;

    if (total <= 0) {
        return {
            hasData: false,
            circumference,
            segments: [] as Array<{
                color: string;
                dashArray: string;
                dashOffset: number;
            }>,
        };
    }

    const parts = [
        { value: rest, color: '#0ea5e9' },
        { value: low, color: '#fbbf24' },
        { value: med, color: '#f97316' },
        { value: high, color: '#f43f5e' },
    ].filter((part) => part.value > 0);

    let offset = 0;
    const segments = parts.map((part) => {
        const length = (part.value / total) * circumference;
        const gap = parts.length > 1 ? Math.min(2, length * 0.25) : 0;
        const segment = {
            color: part.color,
            dashArray: `${Math.max(0.5, length - gap).toFixed(2)} ${circumference.toFixed(2)}`,
            dashOffset: -offset,
        };
        offset += length;

        return segment;
    });

    return { hasData: true, circumference, segments };
});

const caloriesSplit = computed(() => {
    const active = Math.max(0, props.atAGlance.calories.active ?? 0);
    const bmr = Math.max(0, props.atAGlance.calories.bmr ?? 0);
    const sum = active + bmr;

    if (sum <= 0) {
        return { hasData: false, activePct: 0, bmrPct: 0 };
    }

    const rawActivePct = (active / sum) * 100;
    const activePct = active > 0 ? Math.max(3, rawActivePct) : 0;

    return {
        hasData: true,
        activePct,
        bmrPct: Math.max(0, 100 - activePct),
    };
});

const healthStatusAccentClass = computed(() => {
    const tone = props.atAGlance.healthStatus.tone;

    if (tone === 'positive') {
        return 'bg-emerald-500';
    }

    if (tone === 'moderate') {
        return 'bg-amber-500';
    }

    if (tone === 'negative') {
        return 'bg-rose-500';
    }

    return 'bg-zinc-400';
});

const showHydrationCard = computed(
    () =>
        props.atAGlance.hydration.ml !== null &&
        props.atAGlance.hydration.ml > 0,
);

const bodyBatteryChartOption = computed<EChartsOption>(() => {
    const points = props.bodyBattery.series.filter(
        (item): item is [number, number] =>
            Array.isArray(item) &&
            item.length >= 2 &&
            Number.isFinite(item[0]) &&
            Number.isFinite(item[1]),
    );
    const includeDate = seriesSpansMultipleDays(points);
    const dayStart = props.bodyBattery.dayStartMs;
    const dayEnd = props.bodyBattery.dayEndMs;
    const bufferMs = 60 * 60 * 1000;
    const fitsSingleDay =
        !includeDate &&
        points.length > 0 &&
        points[0][0] >= dayStart - bufferMs &&
        points[points.length - 1][0] <= dayEnd + bufferMs;

    const wakeMs = props.bodyBattery.wakeTimestampMs;
    const showWakeMarker =
        fitsSingleDay &&
        wakeMs !== null &&
        wakeMs >= dayStart &&
        wakeMs <= dayEnd;

    let peakPoint: [number, number] | null = null;

    for (const point of points) {
        if (peakPoint === null || point[1] >= peakPoint[1]) {
            peakPoint = point;
        }
    }

    return {
        animation: false,
        grid: { left: 4, right: 6, top: 20, bottom: 4, containLabel: false },
        tooltip: {
            trigger: 'axis',
            backgroundColor: theme.tooltipBg.value,
            borderColor: theme.tooltipBorder.value,
            borderWidth: 1,
            textStyle: {
                color: theme.tooltipText.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 11,
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as
                    | { value?: [number, number] }
                    | undefined;

                if (!first?.value) {
                    return '';
                }

                const [ts, val] = first.value;

                return `${formatClockFromTimestamp(ts, includeDate)} · <strong>${Math.round(val)}</strong>`;
            },
        },
        xAxis: {
            type: 'time',
            min: fitsSingleDay ? dayStart : undefined,
            max: fitsSingleDay ? dayEnd : undefined,
            show: false,
        },
        yAxis: {
            type: 'value',
            min: 0,
            max: 100,
            show: false,
        },
        series: [
            {
                type: 'line',
                smooth: 0.35,
                showSymbol: false,
                symbolSize: 5,
                lineStyle: { color: '#0ea5e9', width: 2 },
                itemStyle: { color: '#0ea5e9' },
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0,
                        y: 0,
                        x2: 0,
                        y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(14, 165, 233, 0.24)' },
                            { offset: 1, color: 'rgba(14, 165, 233, 0.01)' },
                        ],
                    },
                },
                markPoint: peakPoint
                    ? {
                          symbol: 'circle',
                          symbolSize: 6,
                          itemStyle: { color: '#0ea5e9' },
                          label: {
                              show: true,
                              position: 'top',
                              distance: 4,
                              formatter: () => `${Math.round(peakPoint![1])}`,
                              color: theme.textColor.value,
                              fontFamily: theme.fontFamilyMono,
                              fontSize: 10,
                              fontWeight: 600,
                          },
                          data: [{ name: 'Peak', coord: peakPoint }],
                      }
                    : undefined,
                markLine: showWakeMarker
                    ? {
                          silent: true,
                          symbol: ['none', 'none'],
                          lineStyle: {
                              color: theme.mutedColor.value,
                              type: 'dashed',
                              width: 1,
                          },
                          label: {
                              show: false,
                          },
                          data: [{ xAxis: wakeMs }],
                      }
                    : undefined,
                data: points,
            },
        ],
    };
});

const sleepTrendChartOption = computed<EChartsOption>(() => {
    const items = props.sleep.trend7d;

    return {
        animation: false,
        grid: { left: 2, right: 2, top: 6, bottom: 2, containLabel: false },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            backgroundColor: theme.tooltipBg.value,
            borderColor: theme.tooltipBorder.value,
            borderWidth: 1,
            textStyle: {
                color: theme.tooltipText.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 11,
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const item =
                    first?.dataIndex !== undefined
                        ? items[first.dataIndex]
                        : undefined;

                if (!item) {
                    return '';
                }

                const scoreText =
                    item.score !== null ? ` · Score ${item.score}` : '';

                return `${formatShortDate(item.date)}<br/><strong>${item.totalHours.toFixed(1)}h</strong>${scoreText}`;
            },
        },
        xAxis: {
            type: 'category',
            show: false,
            data: items.map((d) => d.date),
        },
        yAxis: {
            type: 'value',
            show: false,
        },
        series: [
            {
                name: 'Deep',
                type: 'bar',
                stack: 'sleep',
                barMaxWidth: 18,
                itemStyle: { color: '#4f46e5' },
                data: items.map((d) => d.deepHours),
            },
            {
                name: 'Light',
                type: 'bar',
                stack: 'sleep',
                barMaxWidth: 18,
                itemStyle: { color: '#818cf8' },
                data: items.map((d) => d.lightHours),
            },
            {
                name: 'REM',
                type: 'bar',
                stack: 'sleep',
                barMaxWidth: 18,
                itemStyle: { color: '#10b981', borderRadius: [3, 3, 0, 0] },
                data: items.map((d) => d.remHours),
            },
        ],
    };
});

const hasHrvTrend = computed(() =>
    props.hrv.trend28d.some(
        (point) => point.lastNight !== null || point.weeklyAvg !== null,
    ),
);

const hrvChartOption = computed<EChartsOption>(() => {
    const items = props.hrv.trend28d;
    const bandLow = props.hrv.baselineBalancedLow;
    const bandHigh = props.hrv.baselineBalancedUpper;

    const markAreaData:
        | Array<[{ yAxis: number }, { yAxis: number }]>
        | undefined =
        bandLow !== null && bandHigh !== null
            ? [[{ yAxis: bandLow }, { yAxis: bandHigh }]]
            : undefined;

    return {
        animation: false,
        grid: { left: 2, right: 2, top: 8, bottom: 4, containLabel: false },
        tooltip: {
            trigger: 'axis',
            backgroundColor: theme.tooltipBg.value,
            borderColor: theme.tooltipBorder.value,
            borderWidth: 1,
            textStyle: {
                color: theme.tooltipText.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 11,
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const item =
                    first?.dataIndex !== undefined
                        ? items[first.dataIndex]
                        : undefined;

                if (!item) {
                    return '';
                }

                const nightly =
                    item.lastNight !== null ? `${item.lastNight} ms` : '—';
                const avg =
                    item.weeklyAvg !== null ? ` · 7d ${item.weeklyAvg} ms` : '';

                return `${formatShortDate(item.date)}<br/><strong>${nightly}</strong>${avg}`;
            },
        },
        xAxis: {
            type: 'category',
            show: false,
            data: items.map((d) => d.date),
        },
        yAxis: {
            type: 'value',
            show: false,
            scale: true,
        },
        series: [
            {
                name: 'Nightly HRV',
                type: 'line',
                smooth: 0.3,
                connectNulls: true,
                showSymbol: false,
                symbolSize: 5,
                lineStyle: { color: '#10b981', width: 2 },
                itemStyle: { color: '#10b981' },
                markArea: markAreaData
                    ? {
                          silent: true,
                          itemStyle: { color: 'rgba(16, 185, 129, 0.12)' },
                          data: markAreaData,
                      }
                    : undefined,
                data: items.map((d) => d.lastNight),
            },
            {
                name: '7d Avg',
                type: 'line',
                smooth: 0.3,
                connectNulls: true,
                showSymbol: false,
                lineStyle: {
                    color: theme.mutedColor.value,
                    width: 1.5,
                    type: 'dashed',
                },
                itemStyle: { color: theme.mutedColor.value },
                data: items.map((d) => d.weeklyAvg),
            },
        ],
    };
});

const hrSparklineOption = computed<EChartsOption>(() => {
    const points = props.atAGlance.heartRate.series.filter(
        (item): item is [number, number] =>
            Array.isArray(item) &&
            item.length >= 2 &&
            Number.isFinite(item[0]) &&
            Number.isFinite(item[1]),
    );
    const includeDate = seriesSpansMultipleDays(points);

    return {
        animation: false,
        grid: { left: 1, right: 1, top: 4, bottom: 2, containLabel: false },
        tooltip: {
            trigger: 'axis',
            backgroundColor: theme.tooltipBg.value,
            borderColor: theme.tooltipBorder.value,
            borderWidth: 1,
            textStyle: {
                color: theme.tooltipText.value,
                fontFamily: theme.fontFamilyMono,
                fontSize: 10,
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as
                    | { value?: [number, number] }
                    | undefined;

                if (!first?.value) {
                    return '';
                }

                const [ts, val] = first.value;

                return `${formatClockFromTimestamp(ts, includeDate)} · <strong>${Math.round(val)} bpm</strong>`;
            },
        },
        xAxis: {
            type: 'time',
            show: false,
        },
        yAxis: {
            type: 'value',
            show: false,
            scale: true,
        },
        series: [
            {
                type: 'line',
                smooth: 0.3,
                showSymbol: false,
                lineStyle: { color: '#f43f5e', width: 1.5 },
                itemStyle: { color: '#f43f5e' },
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0,
                        y: 0,
                        x2: 0,
                        y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(244, 63, 94, 0.2)' },
                            { offset: 1, color: 'rgba(244, 63, 94, 0.01)' },
                        ],
                    },
                },
                data: points,
            },
        ],
    };
});
</script>

<template>
    <div class="space-y-6">
        <Head
            :title="
                isToday ? 'Health Overview' : `Health Overview · ${dateLabel}`
            "
        />

        <!-- Header Row -->
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <p
                    class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ dateLabel }}
                </p>
                <h1
                    class="mt-1 text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    Health Overview
                </h1>
            </div>

            <div
                class="flex flex-wrap items-center justify-between gap-2.5 lg:justify-end"
            >
                <!-- Today's / Latest Activity Telemetry Pill -->
                <Link
                    v-if="latestActivity"
                    :href="activitiesShow.url(latestActivity.id)"
                    class="group flex items-center gap-2.5 rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :style="{
                            backgroundColor: resolveActivityAccent(
                                latestActivity.sport_category,
                                latestActivity.effective_discipline,
                            ),
                        }"
                    />
                    <span
                        class="font-mono text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                    >
                        {{ latestActivity.is_today ? 'Today' : 'Latest' }}
                    </span>
                    <span
                        class="max-w-[160px] truncate font-medium text-zinc-900 dark:text-zinc-100"
                    >
                        {{ latestActivity.name }}
                    </span>
                    <span
                        v-if="latestActivity.distance_meters > 0"
                        class="font-mono text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{
                            units.formatDistance(latestActivity.distance_meters)
                        }}
                    </span>
                    <span
                        v-if="latestActivityMetric"
                        class="hidden font-mono text-zinc-500 tabular-nums sm:inline dark:text-zinc-400"
                    >
                        {{ latestActivityMetric }}
                    </span>
                    <span
                        v-if="latestActivity.avg_hr !== null"
                        class="hidden font-mono text-zinc-500 tabular-nums xl:inline dark:text-zinc-400"
                    >
                        {{ Math.round(latestActivity.avg_hr) }} bpm
                    </span>
                    <ArrowUpRight
                        class="size-3.5 text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-zinc-700 dark:text-zinc-500 dark:group-hover:text-zinc-200"
                    />
                </Link>

                <!-- Date Navigation Controls (anchored far right) -->
                <div class="ml-auto flex shrink-0 items-center gap-1.5">
                    <button
                        v-if="!isToday"
                        type="button"
                        class="h-8 cursor-pointer rounded-lg border border-zinc-200/90 bg-white px-2.5 font-mono text-[11px] font-medium text-zinc-700 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                        @click="goToDate(todayDate)"
                    >
                        Today
                    </button>

                    <div
                        class="flex items-center rounded-lg border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
                    >
                        <button
                            type="button"
                            aria-label="Previous day"
                            class="flex size-8 cursor-pointer items-center justify-center rounded-l-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                            @click="goToDate(prevDate)"
                        >
                            <ChevronLeft class="size-4" />
                        </button>

                        <div
                            class="relative border-x border-zinc-200/80 dark:border-zinc-800/80"
                        >
                            <button
                                type="button"
                                class="flex h-8 w-[164px] cursor-pointer items-center justify-center gap-1.5 px-2.5 font-mono text-xs whitespace-nowrap text-zinc-800 tabular-nums transition-colors hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800/60"
                                @click="openDatePicker"
                            >
                                <Calendar
                                    class="size-3.5 shrink-0 text-zinc-400 dark:text-zinc-500"
                                />
                                <span>{{ dateLabel }}</span>
                            </button>
                            <input
                                ref="dateInputRef"
                                type="date"
                                :value="date"
                                :max="todayDate"
                                aria-label="Select date"
                                class="sr-only"
                                @change="onDateChange"
                            />
                        </div>

                        <button
                            type="button"
                            aria-label="Next day"
                            :disabled="!nextDate"
                            class="flex size-8 items-center justify-center rounded-r-lg text-zinc-500 transition-colors disabled:cursor-not-allowed disabled:opacity-35 dark:text-zinc-400"
                            :class="
                                nextDate
                                    ? 'cursor-pointer hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100'
                                    : ''
                            "
                            @click="nextDate && goToDate(nextDate)"
                        >
                            <ChevronRight class="size-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- First-Run Empty State Banner -->
        <section
            v-if="isEmptyState"
            class="flex flex-col gap-4 rounded-xl border border-emerald-500/25 bg-white p-5 sm:flex-row sm:items-center sm:justify-between dark:border-emerald-500/25 dark:bg-[#141417]"
        >
            <div class="space-y-1">
                <p
                    class="font-mono text-[10px] tracking-widest text-emerald-600 uppercase dark:text-emerald-400"
                >
                    Getting Started
                </p>
                <h2
                    class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                >
                    No data synced yet
                </h2>
                <p
                    v-if="!isProviderConfigured"
                    class="text-sm text-zinc-500 dark:text-zinc-400"
                >
                    Set
                    <code
                        class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-xs text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
                    >
                        GARMIN_KEY
                    </code>
                    in your environment to enable syncing activities, sleep,
                    HRV, and route data.
                </p>
                <p v-else class="text-sm text-zinc-500 dark:text-zinc-400">
                    Click
                    <strong class="font-medium text-zinc-800 dark:text-zinc-200"
                        >Sync Now</strong
                    >
                    to import your latest health data and activities.
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button
                    v-if="isProviderConfigured"
                    type="button"
                    :disabled="isSyncing"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                    @click="triggerEmptyStateSync"
                >
                    <RefreshCw
                        :class="['size-3.5', isSyncing && 'animate-spin']"
                    />
                    {{ isSyncing ? 'Syncing...' : 'Sync Now' }}
                </button>
                <Link
                    :href="settingsIndex.url()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-50 hover:text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900/60 dark:text-zinc-200 dark:hover:bg-zinc-800"
                >
                    <Settings class="size-3.5" />
                    Settings
                </Link>
            </div>
        </section>

        <!-- In Focus Bento Grid (2x2) -->
        <div class="grid gap-4 md:grid-cols-2">
            <!-- Body Battery -->
            <Link
                :href="healthShow.url('body-battery')"
                class="group flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
            >
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Body Battery
                        </span>
                        <span
                            v-if="
                                bodyBattery.charged !== null ||
                                bodyBattery.drained !== null
                            "
                            class="font-mono text-xs tabular-nums"
                        >
                            <span class="text-emerald-500"
                                >+{{ bodyBattery.charged ?? 0 }}</span
                            >
                            <span class="mx-1 text-zinc-300 dark:text-zinc-700"
                                >/</span
                            >
                            <span class="text-zinc-500 dark:text-zinc-400"
                                >-{{ bodyBattery.drained ?? 0 }}</span
                            >
                        </span>
                    </div>

                    <div
                        class="mt-2 flex flex-wrap items-baseline gap-x-2.5 gap-y-1 tabular-nums"
                    >
                        <span
                            class="text-4xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            {{ bodyBattery.current ?? '—' }}
                        </span>
                        <span
                            v-if="
                                bodyBattery.high !== null ||
                                bodyBattery.low !== null
                            "
                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            peak {{ bodyBattery.high ?? '—' }} · low
                            {{ bodyBattery.low ?? '—' }}
                        </span>
                    </div>
                </div>

                <div class="mt-4">
                    <template v-if="bodyBattery.series.length > 0">
                        <BaseChart
                            :option="bodyBatteryChartOption"
                            :height="92"
                        />
                        <div
                            class="mt-1 flex items-center justify-between font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            <span>0:00</span>
                            <span v-if="bodyBattery.wakeTimeLabel">
                                wake {{ bodyBattery.wakeTimeLabel }}
                            </span>
                            <span>0:00</span>
                        </div>
                    </template>
                    <div
                        v-else
                        class="flex h-[92px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-[11px] text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                    >
                        No intraday curve recorded
                    </div>
                </div>
            </Link>

            <!-- Sleep -->
            <Link
                :href="healthShow.url('sleep')"
                class="group flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
            >
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Sleep
                        </span>
                        <div class="flex items-center gap-1.5">
                            <span
                                v-if="sleep.quality"
                                class="rounded-full px-2 py-0.5 font-mono text-[10px] font-medium uppercase"
                                :class="sleepQualityClass"
                            >
                                {{ formatQualifier(sleep.quality) }}
                            </span>
                            <span
                                v-if="sleep.score !== null"
                                class="rounded-full bg-indigo-500/12 px-2 py-0.5 font-mono text-[10px] font-medium text-indigo-600 tabular-nums dark:text-indigo-400"
                            >
                                Score {{ sleep.score }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="mt-2 flex flex-wrap items-baseline gap-x-2.5 gap-y-1 tabular-nums"
                    >
                        <span
                            class="text-4xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                sleep.totalSeconds !== null
                                    ? formatDurationHuman(sleep.totalSeconds)
                                    : '—'
                            }}
                        </span>
                        <span
                            v-if="sleep.needMinutes !== null"
                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            need
                            {{ formatDurationHuman(sleep.needMinutes * 60) }}
                        </span>
                    </div>

                    <!-- 4-Segment Stage Bar -->
                    <div
                        class="mt-4 flex h-2.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                    >
                        <template v-if="sleepStages.hasData">
                            <div
                                class="bg-indigo-600"
                                :style="{ width: `${sleepStages.deepPct}%` }"
                            />
                            <div
                                class="bg-indigo-400"
                                :style="{ width: `${sleepStages.lightPct}%` }"
                            />
                            <div
                                class="bg-emerald-500"
                                :style="{ width: `${sleepStages.remPct}%` }"
                            />
                            <div
                                class="bg-amber-400"
                                :style="{ width: `${sleepStages.awakePct}%` }"
                            />
                        </template>
                    </div>

                    <div
                        class="mt-2 flex flex-wrap items-center justify-between gap-2 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5"
                        >
                            <span>Deep {{ sleepStages.deepMin }}m</span>
                            <span>Light {{ sleepStages.lightMin }}m</span>
                            <span>REM {{ sleepStages.remMin }}m</span>
                            <span v-if="sleepStages.awakeMin > 0"
                                >Awake {{ sleepStages.awakeMin }}m</span
                            >
                        </div>
                        <span
                            v-if="sleep.startLocal && sleep.endLocal"
                            class="text-zinc-400 dark:text-zinc-500"
                        >
                            {{ formatTimeOfDay(sleep.startLocal) }} →
                            {{ formatTimeOfDay(sleep.endLocal) }}
                        </span>
                    </div>
                </div>

                <!-- 7-Day Mini Sleep Trend -->
                <div class="mt-3">
                    <BaseChart
                        v-if="sleep.trend7d.length > 0"
                        :option="sleepTrendChartOption"
                        :height="48"
                    />
                </div>
            </Link>

            <!-- HRV Status -->
            <Link
                :href="healthShow.url('hrv')"
                class="group flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
            >
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            HRV Status
                        </span>
                        <span
                            v-if="hrv.status"
                            class="rounded-full px-2 py-0.5 font-mono text-[10px] font-medium uppercase"
                            :class="hrvStatusClass"
                        >
                            {{ formatQualifier(hrv.status) }}
                        </span>
                    </div>

                    <div
                        class="mt-2 flex flex-wrap items-baseline gap-x-2.5 gap-y-1 tabular-nums"
                    >
                        <span
                            class="text-4xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                hrv.lastNightAvg !== null
                                    ? `${hrv.lastNightAvg} ms`
                                    : '—'
                            }}
                        </span>
                        <span
                            v-if="hrv.weeklyAvg !== null"
                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            7d {{ hrv.weeklyAvg }} ms
                        </span>
                        <span
                            v-if="
                                hrv.baselineBalancedLow !== null &&
                                hrv.baselineBalancedUpper !== null
                            "
                            class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                        >
                            base {{ hrv.baselineBalancedLow }}–{{
                                hrv.baselineBalancedUpper
                            }}
                        </span>
                    </div>
                </div>

                <div class="mt-4">
                    <BaseChart
                        v-if="hasHrvTrend"
                        :option="hrvChartOption"
                        :height="92"
                    />
                    <div
                        v-else
                        class="flex h-[92px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-[11px] text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                    >
                        No 28-day HRV trend recorded
                    </div>
                </div>
            </Link>

            <!-- Training Readiness (Estimated locally from Garmin's 6 factors) -->
            <div
                class="flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="flex items-center justify-between gap-2">
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Training Readiness
                    </span>
                    <span
                        title="Estimated locally from Sleep, Recovery Time, HRV Status, 10d Acute Load, 3-night Sleep History, and 3-day Stress History"
                        class="rounded-full bg-zinc-500/10 px-2 py-0.5 font-mono text-[10px] font-medium text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Est.
                    </span>
                </div>

                <div class="mt-3 flex items-center gap-4">
                    <div class="relative size-20 shrink-0">
                        <svg
                            viewBox="0 0 100 100"
                            class="size-full overflow-visible"
                            aria-hidden="true"
                        >
                            <path
                                v-for="(
                                    seg, idx
                                ) in readinessGaugeArcs.segments"
                                :key="idx"
                                :d="seg.d"
                                fill="none"
                                :stroke="seg.color"
                                stroke-width="7"
                                stroke-linecap="round"
                                class="opacity-85"
                            />
                            <circle
                                v-if="readinessGaugeArcs.marker"
                                :cx="readinessGaugeArcs.marker.x"
                                :cy="readinessGaugeArcs.marker.y"
                                r="5.5"
                                :fill="readinessToneColor"
                                stroke="white"
                                stroke-width="2"
                                class="dark:stroke-[#141417]"
                            />
                        </svg>
                        <div
                            class="absolute inset-0 flex items-center justify-center"
                        >
                            <span
                                class="text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                            >
                                {{ trainingReadiness.score ?? '—' }}
                            </span>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div
                            class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            {{ trainingReadiness.level ?? 'No data' }}
                        </div>
                        <p
                            class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                trainingReadiness.subtitle ??
                                'Sync sleep, HRV, and activities to estimate readiness'
                            }}
                        </p>
                    </div>
                </div>

                <div
                    class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 border-t border-zinc-100 pt-3.5 dark:border-zinc-800/80"
                >
                    <div
                        v-for="factor in readinessFactorRows"
                        :key="factor.key"
                        class="flex items-baseline justify-between gap-2"
                    >
                        <div class="flex min-w-0 items-center gap-1.5">
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :class="factorToneDotClass(factor.tone)"
                            />
                            <span
                                class="truncate text-xs font-medium text-zinc-800 dark:text-zinc-200"
                            >
                                {{ factor.label }}
                            </span>
                        </div>
                        <span
                            class="shrink-0 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            {{ factor.title }}
                            <template v-if="factor.hint">
                                · {{ factor.hint }}
                            </template>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 7-Day Training & Goals Strip -->
        <section
            class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                    <div>
                        <p
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            7-Day Training Load
                        </p>
                        <div
                            class="mt-0.5 flex items-baseline gap-3 tabular-nums"
                        >
                            <span
                                class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                {{ formatNumber(training7d.total_load) }}
                            </span>
                            <span
                                class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    formatDurationHuman(
                                        training7d.total_duration_seconds,
                                    )
                                }}
                                · {{ training7d.activity_count }}
                                {{
                                    training7d.activity_count === 1
                                        ? 'session'
                                        : 'sessions'
                                }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Sport Volume Chips -->
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-for="item in sportBreakdownList"
                        :key="item.key"
                        :href="sportHref(item.key)"
                        class="flex items-center gap-2 rounded-lg border border-zinc-200/80 bg-[#f7f7f5] px-3 py-1.5 font-mono text-xs tabular-nums transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900/60 dark:hover:border-zinc-700"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{ backgroundColor: item.accent_hex }"
                        />
                        <span
                            class="font-sans font-medium text-zinc-800 dark:text-zinc-200"
                        >
                            {{ item.short_label }}
                        </span>
                        <span class="text-zinc-600 dark:text-zinc-400">
                            {{ formatSportChipVolume(item) }}
                        </span>
                        <span
                            class="text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            ({{ item.count }})
                        </span>
                    </Link>
                </div>
            </div>

            <!-- Weekly Activities Mon-Sun Bars & Last 4w Consistency Dots -->
            <div
                class="mt-4 grid gap-4 border-t border-zinc-100 pt-4 md:grid-cols-12 md:items-end dark:border-zinc-800/80"
            >
                <div class="md:col-span-6">
                    <div class="flex items-baseline justify-between gap-2">
                        <Link
                            :href="activitiesIndex.url()"
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
                        >
                            All Activities · {{ weeklyActivity.weekLabel }}
                        </Link>
                        <span
                            class="font-mono text-xs font-medium text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{
                                formatDurationClock(
                                    weeklyActivity.totalDurationSeconds,
                                )
                            }}
                        </span>
                    </div>

                    <div class="mt-2.5 grid grid-cols-7 gap-2">
                        <div
                            v-for="day in weeklyActivity.days"
                            :key="day.date"
                            :title="`${formatShortDate(day.date)}: ${day.activityCount > 0 ? `${formatDurationClock(day.durationSeconds)} (${day.activityCount})` : 'Rest'}`"
                            class="flex flex-col items-center gap-1"
                        >
                            <div
                                class="flex h-11 w-full items-end justify-center"
                            >
                                <div
                                    v-if="day.durationSeconds > 0"
                                    class="w-3.5 rounded-t-full bg-sky-500 transition-all dark:bg-sky-400"
                                    :style="{
                                        height: `${Math.max(18, Math.round((day.durationSeconds / weeklyMaxDurationSeconds) * 100))}%`,
                                    }"
                                />
                                <div
                                    v-else
                                    class="h-1 w-3.5 rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                                />
                            </div>
                            <span
                                class="font-mono text-[10px] tabular-nums"
                                :class="
                                    day.isSelected
                                        ? 'font-semibold text-zinc-900 dark:text-zinc-100'
                                        : 'text-zinc-400 dark:text-zinc-500'
                                "
                            >
                                {{ day.label }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    class="md:col-span-6 md:border-l md:border-zinc-100 md:pl-5 dark:md:border-zinc-800/80"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Last 4 Weeks
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            {{
                                weeklyActivity.last4w.filter(
                                    (d) => d.activityCount > 0,
                                ).length
                            }}
                            active days
                        </span>
                    </div>

                    <div
                        class="mt-3 flex flex-wrap items-center justify-between gap-2"
                    >
                        <div
                            v-for="(week, weekIndex) in last4wWeeks"
                            :key="weekIndex"
                            class="flex items-center gap-1.5"
                        >
                            <div
                                v-for="dot in week"
                                :key="dot.date"
                                :title="`${formatShortDate(dot.date)}: ${dot.activityCount > 0 ? `${formatDurationHuman(dot.durationSeconds)} (${dot.activityCount})` : 'Rest'}`"
                                class="flex size-3.5 items-center justify-center"
                            >
                                <span
                                    class="rounded-full"
                                    :class="
                                        activityDotClass(
                                            dot.level,
                                            dot.isFuture,
                                        )
                                    "
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Weekly Goals Progress Bars -->
            <div
                v-if="training7d.goals.length > 0"
                class="mt-4 grid gap-3 border-t border-zinc-100 pt-4 sm:grid-cols-2 lg:grid-cols-3 dark:border-zinc-800/80"
            >
                <Link
                    v-for="goal in training7d.goals"
                    :key="goal.id"
                    :href="goalsIndex.url()"
                    class="group rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 px-3 py-2.5 transition-colors hover:border-zinc-300 dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:hover:border-zinc-700"
                >
                    <div
                        class="flex items-center justify-between gap-2 text-xs"
                    >
                        <span
                            class="flex min-w-0 items-center gap-1.5 font-medium text-zinc-800 dark:text-zinc-200"
                        >
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: goalAccentHex(goal.sport),
                                }"
                            />
                            <span class="truncate">{{ goal.title }}</span>
                        </span>
                        <span
                            class="shrink-0 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{ formatGoalProgress(goal) }}
                        </span>
                    </div>
                    <div
                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-800"
                    >
                        <div
                            class="h-full rounded-full transition-all"
                            :style="{
                                width: `${clampPct(goal.pct)}%`,
                                backgroundColor: goalAccentHex(goal.sport),
                            }"
                        />
                    </div>
                    <div
                        class="mt-1.5 flex items-center justify-between font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        <span>{{ formatGoalPaceLabel(goal) }}</span>
                        <span>{{ Math.round(goal.pct) }}%</span>
                    </div>
                </Link>
            </div>
        </section>

        <!-- At a Glance Section -->
        <section class="space-y-3">
            <h2
                class="font-mono text-[11px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
            >
                At a Glance
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <!-- 1. Steps -->
                <Link
                    :href="healthShow.url('steps')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span class="absolute top-0 left-0 h-0.5 w-10 bg-sky-500" />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Steps
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{
                                atAGlance.steps.count !== null
                                    ? formatNumber(atAGlance.steps.count)
                                    : '—'
                            }}
                        </div>
                    </div>

                    <div class="mt-3 space-y-1.5">
                        <div
                            v-if="atAGlance.steps.goal !== null"
                            class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                        >
                            <div
                                class="h-full rounded-full bg-sky-500"
                                :style="{ width: `${stepsPct}%` }"
                            />
                        </div>
                        <div
                            class="truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{
                                atAGlance.steps.distanceMeters !== null
                                    ? units.formatDistance(
                                          atAGlance.steps.distanceMeters,
                                      )
                                    : '—'
                            }}
                            · Goal
                            {{
                                atAGlance.steps.goal !== null
                                    ? formatNumber(atAGlance.steps.goal)
                                    : '—'
                            }}
                        </div>
                    </div>
                </Link>

                <!-- 2. Resting HR -->
                <Link
                    :href="healthShow.url('heart-rate')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-rose-500"
                    />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Resting HR
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 flex items-baseline gap-2 tabular-nums"
                        >
                            <span
                                class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    atAGlance.heartRate.resting !== null
                                        ? `${atAGlance.heartRate.resting} bpm`
                                        : '—'
                                }}
                            </span>
                            <span
                                v-if="atAGlance.heartRate.sevenDayAvg !== null"
                                class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                            >
                                7d {{ atAGlance.heartRate.sevenDayAvg }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-2 space-y-1">
                        <BaseChart
                            v-if="atAGlance.heartRate.series.length > 0"
                            :option="hrSparklineOption"
                            :height="36"
                        />
                        <div
                            class="truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            Min {{ atAGlance.heartRate.min ?? '—' }} · Max
                            {{ atAGlance.heartRate.max ?? '—' }} bpm
                        </div>
                    </div>
                </Link>

                <!-- 3. Stress -->
                <Link
                    :href="healthShow.url('stress')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-amber-500"
                    />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Stress
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div class="mt-2 flex items-center gap-3">
                            <div class="relative size-12 shrink-0">
                                <svg
                                    viewBox="0 0 52 52"
                                    class="size-full -rotate-90"
                                    aria-hidden="true"
                                >
                                    <circle
                                        cx="26"
                                        cy="26"
                                        r="20"
                                        fill="none"
                                        stroke-width="4.5"
                                        class="stroke-zinc-100 dark:stroke-zinc-800"
                                    />
                                    <circle
                                        v-for="(
                                            seg, idx
                                        ) in stressRingSegments.segments"
                                        :key="idx"
                                        cx="26"
                                        cy="26"
                                        r="20"
                                        fill="none"
                                        :stroke="seg.color"
                                        stroke-width="4.5"
                                        stroke-linecap="round"
                                        :stroke-dasharray="seg.dashArray"
                                        :stroke-dashoffset="seg.dashOffset"
                                    />
                                </svg>
                                <div
                                    class="absolute inset-0 flex items-center justify-center"
                                >
                                    <span
                                        class="text-base font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{ atAGlance.stress.avg ?? '—' }}
                                    </span>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <div
                                    v-if="atAGlance.stress.qualifier"
                                    class="font-mono text-xs font-medium text-amber-600 dark:text-amber-400"
                                >
                                    {{
                                        formatQualifier(
                                            atAGlance.stress.qualifier,
                                        )
                                    }}
                                </div>
                                <div
                                    class="font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                >
                                    Rest
                                    {{
                                        atAGlance.stress.restPct !== null
                                            ? `${Math.round(atAGlance.stress.restPct)}%`
                                            : '—'
                                    }}
                                </div>
                                <div
                                    class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                                >
                                    Max {{ atAGlance.stress.max ?? '—' }}
                                </div>
                            </div>
                        </div>
                    </div>
                </Link>

                <!-- 4. Intensity Minutes -->
                <Link
                    :href="healthShow.url('intensity-minutes')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-amber-500"
                    />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Intensity Min · Wk
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            <template
                                v-if="atAGlance.intensityMinutes.total !== null"
                            >
                                {{ atAGlance.intensityMinutes.total }}
                                <span
                                    v-if="
                                        atAGlance.intensityMinutes.goal !== null
                                    "
                                    class="font-mono text-sm font-normal text-zinc-400 dark:text-zinc-500"
                                >
                                    / {{ atAGlance.intensityMinutes.goal }}
                                </span>
                            </template>
                            <template v-else>—</template>
                        </div>
                    </div>

                    <div class="mt-3 space-y-1.5">
                        <div
                            v-if="atAGlance.intensityMinutes.goal !== null"
                            class="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                        >
                            <div
                                class="h-full rounded-full bg-amber-500"
                                :style="{ width: `${intensityPct}%` }"
                            />
                        </div>
                        <div
                            class="truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            Mod {{ atAGlance.intensityMinutes.moderate ?? 0 }} ·
                            Vig {{ atAGlance.intensityMinutes.vigorous ?? 0 }}
                            <template
                                v-if="
                                    atAGlance.intensityMinutes.dailyTotal !==
                                        null &&
                                    atAGlance.intensityMinutes.dailyTotal > 0
                                "
                            >
                                · +{{ atAGlance.intensityMinutes.dailyTotal }}
                                {{ isToday ? 'today' : 'day' }}
                            </template>
                        </div>
                    </div>
                </Link>

                <!-- 5. Weight & Comp -->
                <Link
                    :href="healthShow.url('weight')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-emerald-500"
                    />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Weight &amp; Comp
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 flex flex-wrap items-baseline gap-x-2 gap-y-0.5 tabular-nums"
                        >
                            <span
                                class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    units.formatWeight(
                                        atAGlance.weight.weightKg,
                                        1,
                                    )
                                }}
                            </span>
                            <span
                                v-if="atAGlance.weight.goalKg !== null"
                                class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                            >
                                Goal
                                {{
                                    units.formatWeight(
                                        atAGlance.weight.goalKg,
                                        1,
                                    )
                                }}
                            </span>
                        </div>
                        <div
                            v-if="
                                atAGlance.weight.deltaLastKg !== null ||
                                atAGlance.weight.delta30dKg !== null
                            "
                            class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 font-mono text-[11px] tabular-nums"
                        >
                            <span
                                v-if="atAGlance.weight.deltaLastKg !== null"
                                :class="
                                    atAGlance.weight.deltaLastKg <= 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-amber-600 dark:text-amber-400'
                                "
                            >
                                {{
                                    formatWeightDelta(
                                        atAGlance.weight.deltaLastKg,
                                    )
                                }}
                                last
                            </span>
                            <span
                                v-if="atAGlance.weight.delta30dKg !== null"
                                :class="
                                    atAGlance.weight.delta30dKg <= 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-amber-600 dark:text-amber-400'
                                "
                            >
                                {{
                                    formatWeightDelta(
                                        atAGlance.weight.delta30dKg,
                                    )
                                }}
                                30d
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 space-y-0.5">
                        <div
                            class="truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            {{ weightMeta }}
                        </div>
                        <div
                            v-if="atAGlance.weight.measuredDate"
                            class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            Updated
                            {{ formatShortDate(atAGlance.weight.measuredDate) }}
                        </div>
                    </div>
                </Link>

                <!-- 6. VO2 Max -->
                <Link
                    :href="healthShow.url('vo2-max')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-indigo-500"
                    />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                VO2 Max
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ vo2Display }}
                        </div>
                    </div>

                    <div
                        class="mt-3 truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ vo2Meta }}
                    </div>
                </Link>

                <!-- 7. Fitness Age (Dedicated) -->
                <Link
                    :href="healthShow.url('fitness-age')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-violet-500"
                    />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Fitness Age
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 flex items-baseline gap-2 tabular-nums"
                        >
                            <span
                                class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                {{ fitnessAgeDisplay }}
                            </span>
                            <span
                                v-if="atAGlance.vo2Fitness.delta !== null"
                                class="font-mono text-[11px]"
                                :class="
                                    atAGlance.vo2Fitness.delta <= 0
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-amber-600 dark:text-amber-400'
                                "
                            >
                                {{ atAGlance.vo2Fitness.delta > 0 ? '+' : ''
                                }}{{ atAGlance.vo2Fitness.delta.toFixed(1) }}y
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 space-y-0.5">
                        <div
                            class="truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            <template
                                v-if="
                                    atAGlance.vo2Fitness.chronologicalAge !==
                                    null
                                "
                            >
                                Your Age
                                {{
                                    Math.round(
                                        atAGlance.vo2Fitness.chronologicalAge,
                                    )
                                }}
                            </template>
                            <template v-else>No fitness age recorded</template>
                        </div>
                        <div
                            v-if="atAGlance.vo2Fitness.fitnessAgeDate"
                            class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            Updated
                            {{
                                formatShortDate(
                                    atAGlance.vo2Fitness.fitnessAgeDate,
                                )
                            }}
                        </div>
                    </div>
                </Link>

                <!-- 8. Health Status (Local Baseline Check) -->
                <div
                    class="relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10"
                        :class="healthStatusAccentClass"
                    />
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Health Status
                            </span>
                            <span
                                title="Evaluated locally against your personal 28-day baselines for HRV, Resting HR, Respiration, and SpO2"
                                class="rounded bg-zinc-500/10 px-1.5 py-0.5 font-mono text-[9px] font-medium text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Est.
                            </span>
                        </div>

                        <div class="mt-1.5 flex items-center gap-2">
                            <CheckCircle2
                                v-if="
                                    atAGlance.healthStatus.tone === 'positive'
                                "
                                class="size-5 shrink-0 text-emerald-500"
                            />
                            <AlertTriangle
                                v-else-if="
                                    atAGlance.healthStatus.tone ===
                                        'moderate' ||
                                    atAGlance.healthStatus.tone === 'negative'
                                "
                                class="size-5 shrink-0"
                                :class="
                                    atAGlance.healthStatus.tone === 'negative'
                                        ? 'text-rose-500'
                                        : 'text-amber-500'
                                "
                            />
                            <span
                                class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                {{ atAGlance.healthStatus.status ?? '—' }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 space-y-1.5">
                        <p
                            class="truncate font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                        >
                            {{ atAGlance.healthStatus.summary }}
                        </p>
                        <div
                            v-if="atAGlance.healthStatus.metrics.length > 0"
                            class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            <span
                                v-for="item in atAGlance.healthStatus.metrics"
                                :key="item.key"
                                :title="`${item.label}: ${item.detail}`"
                                class="inline-flex items-center gap-1"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :class="
                                        item.inRange
                                            ? 'bg-emerald-500'
                                            : 'bg-amber-500'
                                    "
                                />
                                <span>{{ item.label }}</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 9. Calories -->
                <Link
                    :href="healthShow.url('calories')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-orange-500"
                    />
                    <div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5">
                                <span
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Calories
                                </span>
                                <span
                                    v-if="atAGlance.calories.dayNote"
                                    :title="
                                        atAGlance.calories.rawTotal !== null
                                            ? `Raw ${formatNumber(atAGlance.calories.rawTotal)} kcal`
                                            : undefined
                                    "
                                    class="rounded bg-amber-500/12 px-1 py-0.5 font-mono text-[9px] font-medium text-amber-700 dark:text-amber-400"
                                >
                                    {{ atAGlance.calories.dayNote }}
                                </span>
                            </div>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{
                                atAGlance.calories.total !== null
                                    ? `${formatNumber(atAGlance.calories.total)} kcal`
                                    : '—'
                            }}
                        </div>
                    </div>

                    <div class="mt-3 space-y-1.5">
                        <div
                            v-if="caloriesSplit.hasData"
                            class="flex h-1.5 gap-0.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                        >
                            <div
                                v-if="caloriesSplit.activePct > 0"
                                class="h-full rounded-l-full bg-orange-500"
                                :style="{
                                    width: `${caloriesSplit.activePct}%`,
                                }"
                            />
                            <div
                                v-if="caloriesSplit.bmrPct > 0"
                                class="h-full flex-1 bg-sky-500/80 dark:bg-sky-400/80"
                                :class="
                                    caloriesSplit.activePct > 0
                                        ? 'rounded-r-full'
                                        : 'rounded-full'
                                "
                            />
                        </div>
                        <div
                            class="truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                        >
                            Active
                            {{
                                atAGlance.calories.active !== null
                                    ? formatNumber(atAGlance.calories.active)
                                    : '—'
                            }}
                            · Resting
                            {{
                                atAGlance.calories.bmr !== null
                                    ? formatNumber(atAGlance.calories.bmr)
                                    : '—'
                            }}
                        </div>
                    </div>
                </Link>

                <!-- 10. Resp & SpO2 -->
                <Link
                    :href="healthShow.url('respiration-spo2')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-teal-500"
                    />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Resp &amp; SpO2
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{
                                atAGlance.respirationSpo2.avgRespiration !==
                                null
                                    ? `${atAGlance.respirationSpo2.avgRespiration.toFixed(1)} brpm`
                                    : '—'
                            }}
                        </div>
                    </div>

                    <div
                        class="mt-3 truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        SpO2
                        {{
                            atAGlance.respirationSpo2.avgSpo2 !== null
                                ? `${Math.round(atAGlance.respirationSpo2.avgSpo2)}%`
                                : '—'
                        }}
                        · Low
                        {{
                            atAGlance.respirationSpo2.lowestSpo2 !== null
                                ? `${Math.round(atAGlance.respirationSpo2.lowestSpo2)}%`
                                : '—'
                        }}
                    </div>
                </Link>

                <!-- 11. Hydration (conditional) -->
                <Link
                    v-if="showHydrationCard"
                    :href="healthShow.url('hydration')"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 transition-colors hover:border-zinc-300 dark:border-zinc-800/90 dark:bg-[#141417] dark:hover:border-zinc-700"
                >
                    <span class="absolute top-0 left-0 h-0.5 w-10 bg-sky-500" />
                    <div>
                        <div class="flex items-center justify-between">
                            <span
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Hydration
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-300 transition-colors group-hover:text-zinc-600 dark:text-zinc-700 dark:group-hover:text-zinc-300"
                            />
                        </div>
                        <div
                            class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{
                                units.formatFluidVolume(atAGlance.hydration.ml)
                            }}
                        </div>
                    </div>

                    <div
                        class="mt-3 truncate font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{ hydrationSubLabel }}
                    </div>
                </Link>
            </div>
        </section>

        <!-- Multi-Year Cumulative & Activity Calendar Summary -->
        <ActivitySummaryCharts
            :summary="annualActivitySummary"
            mode="health"
            title="Annual Activity Summary"
        />
    </div>
</template>
