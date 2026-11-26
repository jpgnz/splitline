<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    ArrowUpRight,
    Check,
    EyeOff,
    Flame,
    Layers,
    MapPin,
    Mountain,
    Pencil,
    RotateCcw,
    Sparkles,
    Target,
    Trophy,
    Undo2,
    X,
    Zap,
} from '@lucide/vue';
import type { EChartsOption, SeriesOption } from 'echarts';
import { computed, ref, watch } from 'vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import ActivitySummaryCharts from '@/components/charts/ActivitySummaryCharts.vue';
import type { AnnualActivitySummaryPayload } from '@/components/charts/annualSummaryTypes';
import type { ChartPointClickPayload } from '@/components/charts/BaseChart.vue';
import DateRangeControl, {
    type DateRangePreset,
    type DateWindowMeta,
} from '@/components/DateRangeControl.vue';
import type { CompactGoalProgress } from '@/components/goals/GoalProgressStrip.vue';
import GoalProgressStrip from '@/components/goals/GoalProgressStrip.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatDurationHuman,
    formatNumber,
    formatShortDate,
    formatTimeOfDay,
} from '@/lib/format';
import { useSports } from '@/lib/sports';
import { M_TO_FT, useUnits } from '@/lib/units';
import {
    index as activitiesIndex,
    show as showActivity,
} from '@/routes/activities';
import { update as updateBestEffort } from '@/routes/best-efforts';

type EnvironmentFilter = 'all' | 'outdoor' | 'indoor';
type TerrainFilter = 'all' | 'flat' | 'rolling' | 'steep';
type VolumeRange = '12w' | '6m' | '1y' | 'all' | 'custom';
type ZoneRange = '30d' | '90d' | 'all' | 'custom';
type VolumeMetric = 'distance' | 'vertical' | 'load';
type OffroadLeaderboardTab = 'hardest' | 'smoothest' | 'jumps';

interface FamilyTab {
    key: string;
    label: string;
    short_label: string;
    href: string;
    slug: string;
}

interface ZoneAggregate {
    zone: number;
    label: string;
    seconds: number;
    pct: number;
}

interface BestEffortBoardEntry {
    id: number;
    activity_id: number;
    activity_name: string;
    achieved_at: string;
    elapsed_seconds: number;
    effective_seconds: number;
    override_seconds: number | null;
    pace_seconds_per_km: number;
    source: string;
    is_excluded: boolean;
    rank: number | null;
}

interface BestEffortProgressionPoint {
    date: string;
    effective_seconds: number;
    is_pr: boolean;
    activity_id: number;
    activity_name: string;
}

interface BestEffortDistanceBoard {
    key: string;
    label: string;
    distance_meters: number;
    allTimeTop3: BestEffortBoardEntry[];
    annualTop10: BestEffortBoardEntry[];
    progression: BestEffortProgressionPoint[];
    excluded: BestEffortBoardEntry[];
}

interface RacePredictionItem {
    key: string;
    label: string;
    distance_meters: number;
    predicted_seconds: number;
    pace_seconds_per_km: number;
}

interface PaceModule {
    efficiency: Array<{
        date: string;
        activity_id: number;
        name: string;
        raw_speed: number | null;
        gap_speed: number | null;
        effective_speed: number;
        pace_sec_per_km: number;
        avg_hr: number;
        efficiency: number;
    }>;
    cadenceStride: Array<{
        date: string;
        name: string;
        cadence: number;
        stride_length_cm: number | null;
    }>;
    runWalkBreakdown: {
        run_seconds: number;
        walk_seconds: number;
        idle_seconds: number;
        total_seconds: number;
        run_pct: number;
        walk_pct: number;
        idle_pct: number;
    } | null;
    vo2MaxTrend: Array<{
        date: string;
        value: number;
    }>;
    bestEfforts: {
        selectedYear: number;
        availableYears: number[];
        distances: BestEffortDistanceBoard[];
    } | null;
    racePredictor: {
        source_label: string;
        source_time_seconds: number;
        source_date: string;
        predictions: RacePredictionItem[];
    } | null;
}

interface PowerCurvePoint {
    seconds: number;
    label: string;
    allTime: number | null;
    season: number | null;
    last90d: number | null;
    allTimeWkg: number | null;
    seasonWkg: number | null;
    last90dWkg: number | null;
}

interface CyclingBestEffortItem {
    key: string;
    label: string;
    distance_meters: number;
    best: {
        id: number;
        activity_id: number;
        activity_name: string;
        achieved_at: string;
        effective_seconds: number;
        avg_speed: number;
    } | null;
}

interface PowerModule {
    ftp: number | null;
    weight_kg: number | null;
    w_per_kg: number | null;
    latest_ctl: number;
    latest_atl: number;
    latest_tsb: number;
    avg_np: number | null;
    avg_if: number | null;
    avg_vi: number | null;
    avg_left_balance: number | null;
    powerCurve: PowerCurvePoint[];
    fitnessSeries: Array<{
        date: string;
        tss: number;
        ctl: number;
        atl: number;
        tsb: number;
    }>;
    weeklyTss: Array<{
        week_start: string;
        label: string;
        tss: number;
        distance_meters: number;
        sessions: number;
    }>;
    ftpHistory: Array<{
        date: string;
        ftp: number | null;
        max_20min_power: number | null;
    }>;
    powerZones: ZoneAggregate[];
    bestEfforts: CyclingBestEffortItem[] | null;
}

interface ClimbingModule {
    total_elevation_gain: number;
    total_elevation_loss: number;
    avg_climb_density_m_per_km: number | null;
    avg_vam: number | null;
    max_vam: number | null;
    regions: Array<{
        name: string;
        sessions: number;
        distance_meters: number;
        elevation_gain: number;
        avg_grit: number | null;
        pct: number;
    }>;
    stamina: {
        avg_begin: number | null;
        avg_end: number | null;
        avg_drop: number | null;
        count: number;
    };
    feelRpe: {
        avg_feel: number | null;
        avg_rpe: number | null;
        count: number;
    };
}

interface OffroadLeaderboardSession {
    id: number;
    name: string;
    date: string;
    location_name: string | null;
    grit: number | null;
    avg_flow: number | null;
    elevation_gain: number | null;
    distance_meters: number;
}

interface OffroadJumpSession extends OffroadLeaderboardSession {
    jump_count: number;
}

interface OffroadModule {
    avg_grit: number | null;
    avg_flow: number | null;
    avg_unpaved_pct: number | null;
    total_jumps: number;
    dynamicsTrend: Array<{
        date: string;
        activity_id: number;
        name: string;
        grit: number | null;
        avg_flow: number | null;
        jump_count: number | null;
        unpaved_pct: number | null;
        elevation_gain: number | null;
    }>;
    hardestSessions: OffroadLeaderboardSession[];
    smoothestSessions: OffroadLeaderboardSession[];
    topJumpSessions: OffroadJumpSession[];
}

interface WeeklyVolumePoint {
    week_start: string;
    label: string;
    distance_meters: number;
    elevation_gain: number;
    duration_seconds: number;
    training_load: number;
    tss: number;
    sessions: number;
}

interface RecentActivityRow {
    id: number;
    name: string;
    started_at_local: string;
    activity_type_key: string;
    location_name: string | null;
    is_indoor: boolean;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number | null;
    elevation_loss: number | null;
    avg_speed: number | null;
    avg_grade_adjusted_speed: number | null;
    avg_hr: number | null;
    avg_cadence: number | null;
    avg_power: number | null;
    normalized_power: number | null;
    tss: number | null;
    intensity_factor: number | null;
    training_load: number | null;
    grit: number | null;
    avg_flow: number | null;
    jump_count: number | null;
    unpaved_pct: number | null;
    is_pr: boolean;
}

interface EddingtonTarget {
    target: number;
    completed_days: number;
    remaining_days: number;
}

interface EddingtonDistributionBucket {
    threshold: number;
    days: number;
}

interface EddingtonUnitSummary {
    unit: 'km' | 'mi';
    number: number;
    next_target: number;
    completed_for_next: number;
    days_needed_for_next: number;
    next_targets: EddingtonTarget[];
    distribution: EddingtonDistributionBucket[];
}

interface EddingtonPayload {
    active_days: number;
    max_single_day_meters: number;
    metric: EddingtonUnitSummary;
    imperial: EddingtonUnitSummary;
}

interface SportsEnduranceProps {
    sport: {
        key: string;
        parent: string | null;
        sport_category: string;
        discipline: string | null;
        indoor_only: boolean;
        label: string;
        short_label: string;
        slug: string;
        href: string;
        icon: string;
        color_token: string;
        accent_hex: string;
        metric_mode: 'pace' | 'speed' | 'pace_100m' | 'duration';
        cadence_unit: 'spm' | 'rpm' | 'strokes/min' | null;
        family_key: string | null;
        tabs: FamilyTab[];
    };
    filters: {
        environment: EnvironmentFilter;
        terrain: TerrainFilter;
        volumeRange: VolumeRange;
        volumeWindow: DateWindowMeta;
        year: number;
        zoneRange: ZoneRange;
        zoneWindow: DateWindowMeta;
        availableYears: number[];
        showEnvironmentFilter: boolean;
        showTerrainFilter: boolean;
    };
    summary: {
        ytd_sessions: number;
        ytd_distance_meters: number;
        ytd_duration_seconds: number;
        ytd_elevation_gain: number;
        ytd_load: number;
        ytd_avg_speed: number | null;
        ytd_avg_gap_speed: number | null;
        ytd_avg_hr: number | null;
        total_sessions: number;
        total_distance_meters: number;
        total_elevation_gain: number;
        weekly_avg_distance_meters: number;
        weekly_avg_elevation_gain: number;
        latest_vo2_max: number | null;
        latest_ftp: number | null;
        w_per_kg: number | null;
        latest_ctl: number;
        latest_atl: number;
        latest_tsb: number;
    };
    weeklyVolume: WeeklyVolumePoint[];
    annualSummary: AnnualActivitySummaryPayload | null;
    eddington?: EddingtonPayload | null;
    hrZones: {
        range: string;
        total_seconds: number;
        zones: ZoneAggregate[];
        polarization: {
            easy_pct: number;
            moderate_pct: number;
            hard_pct: number;
        };
    };
    goals: CompactGoalProgress[];
    milestones: {
        longest: {
            activity_id: number;
            name: string;
            date: string;
            distance_meters: number;
            duration_seconds: number;
        } | null;
        fastest: {
            activity_id: number;
            name: string;
            date: string;
            avg_speed: number;
            distance_meters: number;
        } | null;
        biggest_climb: {
            activity_id: number;
            name: string;
            date: string;
            elevation_gain: number;
            distance_meters: number;
        } | null;
        highest_load: {
            activity_id: number;
            name: string;
            date: string;
            training_load: number | null;
            tss: number | null;
            duration_seconds: number;
        } | null;
        hardest_grit: {
            activity_id: number;
            name: string;
            date: string;
            grit: number;
            avg_flow: number | null;
            distance_meters: number;
            location_name: string | null;
        } | null;
    };
    recentActivities: RecentActivityRow[];
    modules: {
        pace: PaceModule | null;
        power: PowerModule | null;
        climbing: ClimbingModule | null;
        offroad: OffroadModule | null;
    };
}

const props = defineProps<SportsEnduranceProps>();
const theme = useChartTheme();
const units = useUnits();
const { getSportMeta } = useSports();

const sportMeta = computed(() => getSportMeta(props.sport.key));
const accentHex = computed<string>(
    () => props.sport.accent_hex || sportMeta.value.accent_hex,
);
const isPaceSport = computed<boolean>(() => props.sport.metric_mode === 'pace');

const goalAccent = computed<'amber' | 'sky' | 'emerald' | 'indigo'>(() => {
    const token = props.sport.color_token || sportMeta.value.color_token;

    if (token === 'amber' || token === 'orange' || token === 'rose') {
        return 'amber';
    }

    if (token === 'emerald' || token === 'teal' || token === 'lime') {
        return 'emerald';
    }

    if (token === 'sky' || token === 'blue' || token === 'cyan') {
        return 'sky';
    }

    return 'indigo';
});

const sessionNoun = computed<{ singular: string; plural: string }>(() => {
    if (props.sport.sport_category === 'running') {
        return { singular: 'run', plural: 'runs' };
    }

    if (props.sport.sport_category === 'biking') {
        return { singular: 'ride', plural: 'rides' };
    }

    if (props.sport.sport_category === 'hiking') {
        return { singular: 'hike', plural: 'hikes' };
    }

    return { singular: 'session', plural: 'sessions' };
});

// Interactive UI state
const volumeMetric = ref<VolumeMetric>('distance');
const showElevationOverlay = ref<boolean>(true);
const powerUnit = ref<'watts' | 'wkg'>('watts');
const effortsTab = ref<'allTime' | 'annual'>('allTime');
const selectedDistanceKey = ref<string>('');
const editingEffortId = ref<number | null>(null);
const editTimeInput = ref<string>('');
const editTimeError = ref<string | null>(null);
const busyEffortId = ref<number | null>(null);
const offroadTab = ref<OffroadLeaderboardTab>('hardest');

// Initialize default selected distance tab for running best efforts
function resolveDefaultDistanceKey(
    distances: BestEffortDistanceBoard[],
): string {
    if (distances.length === 0) {
        return '';
    }

    const fiveK = distances.find(
        (d) =>
            d.key === '5k' &&
            (d.allTimeTop3.length > 0 || d.annualTop10.length > 0),
    );

    if (fiveK) {
        return fiveK.key;
    }

    const firstPopulated = distances.find(
        (d) => d.allTimeTop3.length > 0 || d.annualTop10.length > 0,
    );

    return firstPopulated?.key ?? distances[0].key;
}

watch(
    () => props.modules.pace?.bestEfforts?.distances ?? [],
    (distances) => {
        if (distances.length === 0) {
            selectedDistanceKey.value = '';

            return;
        }

        if (!distances.some((d) => d.key === selectedDistanceKey.value)) {
            selectedDistanceKey.value = resolveDefaultDistanceKey(distances);
        }
    },
    { immediate: true },
);

// Filter options
const ENVIRONMENT_OPTIONS: Array<{ value: EnvironmentFilter; label: string }> =
    [
        { value: 'all', label: 'All' },
        { value: 'outdoor', label: 'Outdoor' },
        { value: 'indoor', label: 'Indoor' },
    ];

const terrainOptions = computed<
    Array<{
        value: TerrainFilter;
        label: string;
        sublabel: string | null;
        tooltip: string;
    }>
>(() => {
    const isImp = units.isImperial.value;

    return [
        {
            value: 'all',
            label: 'All',
            sublabel: null,
            tooltip: 'All terrain profiles',
        },
        {
            value: 'flat',
            label: 'Flat',
            sublabel: isImp ? '<53 ft/mi' : '<10 m/km',
            tooltip: isImp
                ? 'Flat terrain (<53 ft/mi climb density)'
                : 'Flat terrain (<10 m/km climb density)',
        },
        {
            value: 'rolling',
            label: 'Rolling',
            sublabel: isImp ? '53–132 ft/mi' : '10–25 m/km',
            tooltip: isImp
                ? 'Rolling terrain (53–132 ft/mi climb density)'
                : 'Rolling terrain (10–25 m/km climb density)',
        },
        {
            value: 'steep',
            label: 'Steep',
            sublabel: isImp ? '132+ ft/mi' : '25+ m/km',
            tooltip: isImp
                ? 'Steep terrain (132+ ft/mi climb density)'
                : 'Steep terrain (25+ m/km climb density)',
        },
    ];
});

const VOLUME_RANGE_OPTIONS: DateRangePreset[] = [
    { value: '12w', label: '12w' },
    { value: '6m', label: '6m' },
    { value: '1y', label: '1y' },
    { value: 'all', label: 'All' },
];

const ZONE_RANGE_OPTIONS: DateRangePreset[] = [
    { value: '30d', label: '30d' },
    { value: '90d', label: '90d' },
    { value: 'all', label: 'All' },
];

const VOLUME_METRIC_OPTIONS: Array<{ value: VolumeMetric; label: string }> = [
    { value: 'distance', label: 'Distance' },
    { value: 'vertical', label: 'Vertical' },
    { value: 'load', label: 'Load' },
];

function applyFilters(
    overrides: Partial<{
        environment: EnvironmentFilter;
        terrain: TerrainFilter;
        volumeRange: VolumeRange;
        volumeEnd: string | null;
        volumeFrom: string | null;
        volumeTo: string | null;
        year: number;
        zoneRange: ZoneRange;
        zoneEnd: string | null;
        zoneFrom: string | null;
        zoneTo: string | null;
    }>,
): void {
    const nextVolumeRange = overrides.volumeRange ?? props.filters.volumeRange;
    const nextVolumeEnd =
        overrides.volumeEnd !== undefined
            ? overrides.volumeEnd
            : props.filters.volumeWindow.isLatest
              ? null
              : props.filters.volumeWindow.end;
    const nextVolumeFrom =
        overrides.volumeFrom !== undefined
            ? overrides.volumeFrom
            : props.filters.volumeWindow.from;
    const nextVolumeTo =
        overrides.volumeTo !== undefined
            ? overrides.volumeTo
            : props.filters.volumeWindow.to;

    const nextZoneRange = overrides.zoneRange ?? props.filters.zoneRange;
    const nextZoneEnd =
        overrides.zoneEnd !== undefined
            ? overrides.zoneEnd
            : props.filters.zoneWindow.isLatest
              ? null
              : props.filters.zoneWindow.end;
    const nextZoneFrom =
        overrides.zoneFrom !== undefined
            ? overrides.zoneFrom
            : props.filters.zoneWindow.from;
    const nextZoneTo =
        overrides.zoneTo !== undefined
            ? overrides.zoneTo
            : props.filters.zoneWindow.to;

    const params: Record<string, string | number> = {
        volumeRange: nextVolumeRange,
        zoneRange: nextZoneRange,
        year: overrides.year ?? props.filters.year,
    };

    if (nextVolumeRange === 'custom') {
        if (nextVolumeFrom) {
            params.volumeFrom = nextVolumeFrom;
        }

        if (nextVolumeTo) {
            params.volumeTo = nextVolumeTo;
        }
    } else if (nextVolumeRange !== 'all' && nextVolumeEnd) {
        params.volumeEnd = nextVolumeEnd;
    }

    if (nextZoneRange === 'custom') {
        if (nextZoneFrom) {
            params.zoneFrom = nextZoneFrom;
        }

        if (nextZoneTo) {
            params.zoneTo = nextZoneTo;
        }
    } else if (nextZoneRange !== 'all' && nextZoneEnd) {
        params.zoneEnd = nextZoneEnd;
    }

    if (props.filters.showEnvironmentFilter) {
        params.environment = overrides.environment ?? props.filters.environment;
    }

    if (props.filters.showTerrainFilter) {
        params.terrain = overrides.terrain ?? props.filters.terrain;
    }

    router.get(props.sport.href, params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function selectEnvironment(environment: EnvironmentFilter): void {
    if (environment === props.filters.environment) {
        return;
    }

    applyFilters({ environment });
}

function selectTerrain(terrain: TerrainFilter): void {
    if (terrain === props.filters.terrain) {
        return;
    }

    applyFilters({ terrain });
}

function selectVolumeRange(volumeRange: string): void {
    applyFilters({
        volumeRange: volumeRange as VolumeRange,
        volumeEnd: null,
        volumeFrom: null,
        volumeTo: null,
    });
}

function stepVolumeRange(end: string | null): void {
    applyFilters({
        volumeRange: props.filters.volumeRange,
        volumeEnd: end,
        volumeFrom: null,
        volumeTo: null,
    });
}

function applyCustomVolumeRange(payload: {
    from: string | null;
    to: string | null;
}): void {
    applyFilters({
        volumeRange: 'custom',
        volumeEnd: null,
        volumeFrom: payload.from,
        volumeTo: payload.to,
    });
}

function selectZoneRange(zoneRange: string): void {
    applyFilters({
        zoneRange: zoneRange as ZoneRange,
        zoneEnd: null,
        zoneFrom: null,
        zoneTo: null,
    });
}

function stepZoneRange(end: string | null): void {
    applyFilters({
        zoneRange: props.filters.zoneRange,
        zoneEnd: end,
        zoneFrom: null,
        zoneTo: null,
    });
}

function applyCustomZoneRange(payload: {
    from: string | null;
    to: string | null;
}): void {
    applyFilters({
        zoneRange: 'custom',
        zoneEnd: null,
        zoneFrom: payload.from,
        zoneTo: payload.to,
    });
}

function onYearChange(event: Event): void {
    const target = event.target as HTMLSelectElement;
    const nextYear = Number(target.value);

    if (!Number.isFinite(nextYear) || nextYear === props.filters.year) {
        return;
    }

    effortsTab.value = 'annual';
    applyFilters({ year: nextYear });
}

// Formatting helpers
const MONTH_ABBRS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function formatEffortDate(dateStr: string): string {
    if (!dateStr) {
        return '—';
    }

    const trimmed = dateStr.trim();
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(trimmed);

    if (match) {
        const year = Number(match[1]);
        const month = MONTH_ABBRS[Number(match[2]) - 1] ?? '';
        const day = Number(match[3]);

        return `${day} ${month} ${year}`;
    }

    return formatShortDate(trimmed);
}

function formatPaceFromSecPerKm(secPerKm: number | null | undefined): string {
    if (
        secPerKm === null ||
        secPerKm === undefined ||
        !Number.isFinite(secPerKm) ||
        secPerKm <= 0
    ) {
        return '—';
    }

    return units.formatPace(1000 / secPerKm);
}

function formatRate(speedMps: number | null | undefined): string {
    if (
        speedMps === null ||
        speedMps === undefined ||
        !Number.isFinite(speedMps) ||
        speedMps <= 0
    ) {
        return '—';
    }

    return isPaceSport.value
        ? units.formatPace(speedMps)
        : units.formatSpeed(speedMps);
}

function formatVam(metersPerHour: number | null | undefined): string {
    if (
        metersPerHour === null ||
        metersPerHour === undefined ||
        !Number.isFinite(metersPerHour)
    ) {
        return '—';
    }

    const converted = units.elevationFromMeters(metersPerHour);

    return `${formatNumber(Math.round(converted), 0)} ${units.elevationUnit.value}/h`;
}

function formatLeftRightBalance(left: number | null): string {
    if (left === null || !Number.isFinite(left)) {
        return '—';
    }

    const leftRounded = Number(left.toFixed(1));
    const rightRounded = Number((100 - left).toFixed(1));

    return `${leftRounded}% / ${rightRounded}%`;
}

function clampPct(value: number | null | undefined): number {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return 0;
    }

    return Math.max(0, Math.min(100, value));
}

function feelLabel(score: number | null): string {
    if (score === null || !Number.isFinite(score)) {
        return '—';
    }

    if (score >= 80) {
        return 'Very Strong';
    }

    if (score >= 60) {
        return 'Strong';
    }

    if (score >= 40) {
        return 'Normal';
    }

    if (score >= 20) {
        return 'Weak';
    }

    return 'Very Weak';
}

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

function medalBadgeClass(rank: number): string {
    if (rank === 1) {
        return 'border-amber-300/90 bg-amber-100/90 text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/20 dark:text-amber-300';
    }

    if (rank === 2) {
        return 'border-zinc-300/90 bg-zinc-100 text-zinc-700 dark:border-zinc-600/70 dark:bg-zinc-700/60 dark:text-zinc-200';
    }

    if (rank === 3) {
        return 'border-orange-300/90 bg-orange-100/90 text-orange-800 dark:border-orange-500/40 dark:bg-orange-500/20 dark:text-orange-300';
    }

    return 'border-zinc-200/90 bg-zinc-50 text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400';
}

function formatEffortSource(effort: BestEffortBoardEntry): string {
    if (effort.override_seconds !== null) {
        return 'Manual';
    }

    if (effort.source === 'fit_stream') {
        return 'FIT';
    }

    if (effort.source === 'garmin_split') {
        return 'Garmin';
    }

    return effort.source;
}

// Best effort editing & exclusion (shared for running & cycling boards)
function parseTimeToSeconds(input: string): number | null {
    const trimmed = input.trim();

    if (!trimmed) {
        return null;
    }

    const parts = trimmed.split(':');

    if (parts.length !== 2 && parts.length !== 3) {
        return null;
    }

    if (parts.length === 2) {
        const minutes = Number(parts[0]);
        const seconds = Number(parts[1]);

        if (
            !Number.isInteger(minutes) ||
            !Number.isFinite(seconds) ||
            minutes < 0 ||
            seconds < 0 ||
            seconds >= 60
        ) {
            return null;
        }

        const total = Math.round(minutes * 60 + seconds);

        return total > 0 ? total : null;
    }

    const hours = Number(parts[0]);
    const minutes = Number(parts[1]);
    const seconds = Number(parts[2]);

    if (
        !Number.isInteger(hours) ||
        !Number.isInteger(minutes) ||
        !Number.isFinite(seconds) ||
        hours < 0 ||
        minutes < 0 ||
        minutes >= 60 ||
        seconds < 0 ||
        seconds >= 60
    ) {
        return null;
    }

    const total = Math.round(hours * 3600 + minutes * 60 + seconds);

    return total > 0 ? total : null;
}

function startEditEffort(effort: {
    id: number;
    effective_seconds: number;
}): void {
    editingEffortId.value = effort.id;
    editTimeInput.value = formatDurationClock(effort.effective_seconds);
    editTimeError.value = null;
}

function cancelEditEffort(): void {
    editingEffortId.value = null;
    editTimeInput.value = '';
    editTimeError.value = null;
}

function saveEffortOverride(effortId: number): void {
    const parsedSeconds = parseTimeToSeconds(editTimeInput.value);

    if (parsedSeconds === null) {
        editTimeError.value = 'Use mm:ss or hh:mm:ss';

        return;
    }

    editTimeError.value = null;
    busyEffortId.value = effortId;

    router.patch(
        updateBestEffort.url(effortId),
        { override_seconds: parsedSeconds },
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelEditEffort();
            },
            onFinish: () => {
                busyEffortId.value = null;
            },
        },
    );
}

function resetEffortOverride(effortId: number): void {
    busyEffortId.value = effortId;

    router.patch(
        updateBestEffort.url(effortId),
        { override_seconds: null },
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelEditEffort();
            },
            onFinish: () => {
                busyEffortId.value = null;
            },
        },
    );
}

function excludeEffort(effortId: number): void {
    busyEffortId.value = effortId;

    router.patch(
        updateBestEffort.url(effortId),
        { is_excluded: true },
        {
            preserveScroll: true,
            onSuccess: () => {
                if (editingEffortId.value === effortId) {
                    cancelEditEffort();
                }
            },
            onFinish: () => {
                busyEffortId.value = null;
            },
        },
    );
}

function restoreEffort(effortId: number): void {
    busyEffortId.value = effortId;

    router.patch(
        updateBestEffort.url(effortId),
        { is_excluded: false },
        {
            preserveScroll: true,
            onFinish: () => {
                busyEffortId.value = null;
            },
        },
    );
}

// Primary Weekly Volume Chart
const hasWeeklyElevation = computed<boolean>(() =>
    props.weeklyVolume.some((w) => w.elevation_gain > 0),
);

const weeklyVolumeTotals = computed(() => {
    let distanceM = 0;
    let elevationM = 0;
    let load = 0;
    let sessions = 0;

    for (const w of props.weeklyVolume) {
        distanceM += w.distance_meters;
        elevationM += w.elevation_gain;
        load += w.training_load;
        sessions += w.sessions;
    }

    return { distanceM, elevationM, load, sessions };
});

const weeklyVolumeChartOption = computed<EChartsOption>(() => {
    const weeks = props.weeklyVolume;
    const metric = volumeMetric.value;
    const monoFont = theme.fontFamilyMono;
    const showElevLine =
        showElevationOverlay.value &&
        hasWeeklyElevation.value &&
        metric !== 'vertical';

    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    const primaryName =
        metric === 'distance'
            ? `Distance (${units.distanceUnit.value})`
            : metric === 'vertical'
              ? `Vertical (${units.elevationUnit.value})`
              : 'Training Load';

    const primaryUnit =
        metric === 'distance'
            ? units.distanceUnit.value
            : metric === 'vertical'
              ? units.elevationUnit.value
              : 'load';

    const primaryData = weeks.map((w) => {
        if (metric === 'distance') {
            return Number(
                units.distanceFromMeters(w.distance_meters).toFixed(1),
            );
        }

        if (metric === 'vertical') {
            return Math.round(units.elevationFromMeters(w.elevation_gain));
        }

        return Math.round(w.training_load);
    });

    const series: SeriesOption[] = [
        {
            name: primaryName,
            type: 'bar',
            yAxisIndex: 0,
            barMaxWidth: 24,
            itemStyle: {
                color: accentHex.value,
                borderRadius: [4, 4, 0, 0],
            },
            data: primaryData,
        },
    ];

    if (showElevLine) {
        series.push({
            name: `Elevation (${units.elevationUnit.value})`,
            type: 'line',
            yAxisIndex: 1,
            smooth: 0.25,
            showSymbol: weeks.length <= 26,
            symbolSize: 4,
            lineStyle: {
                color: '#10b981',
                width: 1.75,
                type: 'dashed',
            },
            itemStyle: { color: '#10b981' },
            data: weeks.map((w) =>
                Math.round(units.elevationFromMeters(w.elevation_gain)),
            ),
        });
    }

    return {
        animation: false,
        grid: {
            left: 12,
            right: showElevLine ? 16 : 12,
            top: 44,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const week =
                    first?.dataIndex !== undefined
                        ? weeks[first.dataIndex]
                        : undefined;

                if (!week) {
                    return '';
                }

                return [
                    `<strong>Week of ${escapeHtml(week.label)}</strong>`,
                    `Distance: <strong>${units.formatDistanceCompact(week.distance_meters, 1)}</strong> · ${week.sessions} ${week.sessions === 1 ? sessionNoun.value.singular : sessionNoun.value.plural}`,
                    `Vertical: <strong>+${units.formatElevation(week.elevation_gain)}</strong>`,
                    `Duration: ${formatDurationHuman(week.duration_seconds)} · Load: ${formatNumber(week.training_load, 0)}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: weeks.map((w) => w.label),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: showElevLine
            ? [
                  {
                      type: 'value',
                      name: primaryUnit,
                      nameGap: 12,
                      nameTextStyle: { ...axisLabelStyle, align: 'left' },
                      axisLabel: axisLabelStyle,
                      splitLine: {
                          lineStyle: {
                              color: theme.gridLineColor.value,
                              type: 'dashed',
                          },
                      },
                  },
                  {
                      type: 'value',
                      name: units.elevationUnit.value,
                      nameGap: 12,
                      nameTextStyle: { ...axisLabelStyle, align: 'right' },
                      axisLabel: axisLabelStyle,
                      splitLine: { show: false },
                  },
              ]
            : {
                  type: 'value',
                  name: primaryUnit,
                  nameGap: 12,
                  nameTextStyle: { ...axisLabelStyle, align: 'left' },
                  axisLabel: axisLabelStyle,
                  splitLine: {
                      lineStyle: {
                          color: theme.gridLineColor.value,
                          type: 'dashed',
                      },
                  },
              },
        series,
    };
});

// HR Zones, Milestones & Eddington Number
const activeEddington = computed<EddingtonUnitSummary | null>(() => {
    if (!props.eddington) {
        return null;
    }

    return units.isImperial.value
        ? props.eddington.imperial
        : props.eddington.metric;
});

const eddingtonNextProgressPct = computed<number>(() => {
    const current = activeEddington.value;

    if (!current || current.next_target <= 0) {
        return 0;
    }

    return clampPct((current.completed_for_next / current.next_target) * 100);
});

const hasHrZoneData = computed<boolean>(() => props.hrZones.total_seconds > 0);

interface MilestoneDisplayItem {
    key: string;
    label: string;
    activity_id: number;
    name: string;
    date: string;
    value: string;
    meta: string;
}

const milestoneItems = computed<MilestoneDisplayItem[]>(() => {
    const items: MilestoneDisplayItem[] = [];
    const m = props.milestones;

    if (m.longest) {
        items.push({
            key: 'longest',
            label: `Longest ${sessionNoun.value.singular}`,
            activity_id: m.longest.activity_id,
            name: m.longest.name,
            date: m.longest.date,
            value: units.formatDistanceCompact(m.longest.distance_meters, 1),
            meta: formatDurationHuman(m.longest.duration_seconds),
        });
    }

    if (m.fastest) {
        items.push({
            key: 'fastest',
            label: isPaceSport.value ? 'Fastest Pace' : 'Fastest Avg Speed',
            activity_id: m.fastest.activity_id,
            name: m.fastest.name,
            date: m.fastest.date,
            value: formatRate(m.fastest.avg_speed),
            meta: units.formatDistanceCompact(m.fastest.distance_meters, 1),
        });
    }

    if (m.biggest_climb) {
        items.push({
            key: 'biggest_climb',
            label: 'Biggest Climb',
            activity_id: m.biggest_climb.activity_id,
            name: m.biggest_climb.name,
            date: m.biggest_climb.date,
            value: `+${units.formatElevation(m.biggest_climb.elevation_gain)}`,
            meta: units.formatDistanceCompact(
                m.biggest_climb.distance_meters,
                1,
            ),
        });
    }

    if (m.highest_load) {
        const loadVal = m.highest_load.tss ?? m.highest_load.training_load ?? 0;
        const loadLabel = m.highest_load.tss !== null ? 'TSS' : 'Load';
        items.push({
            key: 'highest_load',
            label: 'Highest Load',
            activity_id: m.highest_load.activity_id,
            name: m.highest_load.name,
            date: m.highest_load.date,
            value: `${formatNumber(loadVal, 0)} ${loadLabel}`,
            meta: formatDurationHuman(m.highest_load.duration_seconds),
        });
    }

    if (m.hardest_grit) {
        items.push({
            key: 'hardest_grit',
            label: 'Hardest Grit',
            activity_id: m.hardest_grit.activity_id,
            name: m.hardest_grit.name,
            date: m.hardest_grit.date,
            value: `${formatNumber(m.hardest_grit.grit, 1)} Grit`,
            meta:
                m.hardest_grit.avg_flow !== null
                    ? `Flow ${formatNumber(m.hardest_grit.avg_flow, 1)} · ${units.formatDistanceCompact(m.hardest_grit.distance_meters, 1)}`
                    : units.formatDistanceCompact(
                          m.hardest_grit.distance_meters,
                          1,
                      ),
        });
    }

    return items;
});

// ========================
// MODULE 1: PACE & RUNNING DYNAMICS
// ========================
const selectedDistanceBoard = computed<BestEffortDistanceBoard | null>(() => {
    const distances = props.modules.pace?.bestEfforts?.distances ?? [];

    if (distances.length === 0) {
        return null;
    }

    return (
        distances.find((d) => d.key === selectedDistanceKey.value) ??
        distances[0]
    );
});

const activeRunningEfforts = computed<BestEffortBoardEntry[]>(() => {
    const board = selectedDistanceBoard.value;

    if (!board) {
        return [];
    }

    return effortsTab.value === 'allTime'
        ? board.allTimeTop3
        : board.annualTop10;
});

const gapScatterOption = computed<EChartsOption>(() => {
    const items = props.modules.pace?.efficiency ?? [];
    const monoFont = theme.fontFamilyMono;

    if (items.length === 0) {
        return {};
    }

    const gapPoints: Array<{
        value: [number, number];
        item: (typeof items)[number];
    }> = [];
    const rawPoints: Array<{
        value: [number, number];
        item: (typeof items)[number];
    }> = [];

    for (const item of items) {
        const gapSec =
            item.gap_speed !== null
                ? units.paceSecondsFromMps(item.gap_speed)
                : null;
        const rawSec =
            item.raw_speed !== null
                ? units.paceSecondsFromMps(item.raw_speed)
                : null;

        if (gapSec !== null) {
            gapPoints.push({ value: [item.avg_hr, Math.round(gapSec)], item });
        }

        if (rawSec !== null) {
            rawPoints.push({ value: [item.avg_hr, Math.round(rawSec)], item });
        }
    }

    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    const series: SeriesOption[] = [];

    if (gapPoints.length > 0) {
        series.push({
            name: 'GAP Pace',
            type: 'scatter',
            symbolSize: 8,
            itemStyle: {
                color: '#10b981',
                opacity: 0.9,
            },
            data: gapPoints,
        });
    }

    if (rawPoints.length > 0) {
        series.push({
            name: 'Raw Pace',
            type: 'scatter',
            symbolSize: 7,
            itemStyle: {
                color: accentHex.value,
                opacity: 0.65,
            },
            data: rawPoints,
        });
    }

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 44,
            bottom: 24,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'circle',
            itemWidth: 8,
            itemHeight: 8,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'item',
            formatter: (params: unknown) => {
                const entry = params as {
                    data?: { item?: (typeof items)[number] };
                };
                const item = entry.data?.item;

                if (!item) {
                    return '';
                }

                const lines = [
                    `<div style="font-size:11px;opacity:0.75;">${formatEffortDate(item.date)}</div>`,
                    `<div style="font-weight:600;margin-top:2px;">${escapeHtml(item.name)}</div>`,
                ];

                if (item.gap_speed !== null) {
                    lines.push(
                        `<span style="color:#10b981;">●</span> GAP: <strong>${units.formatPace(item.gap_speed)}</strong>`,
                    );
                }

                if (item.raw_speed !== null) {
                    lines.push(
                        `<span style="color:${accentHex.value};">●</span> Raw: <strong>${units.formatPace(item.raw_speed)}</strong>`,
                    );
                }

                lines.push(
                    `Avg HR: <strong>${Math.round(item.avg_hr)} bpm</strong> · Eff: ${formatNumber(item.efficiency, 2)}`,
                );

                return lines.join('<br/>');
            },
        },
        xAxis: {
            type: 'value',
            scale: true,
            name: 'Avg HR (bpm)',
            nameLocation: 'middle',
            nameGap: 22,
            nameTextStyle: axisLabelStyle,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
            splitLine: {
                lineStyle: {
                    color: theme.gridLineColor.value,
                    type: 'dashed',
                },
            },
        },
        yAxis: {
            type: 'value',
            scale: true,
            inverse: true,
            name: `Pace (${units.paceUnit.value})`,
            nameGap: 12,
            nameTextStyle: { ...axisLabelStyle, align: 'left' },
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: {
                lineStyle: {
                    color: theme.gridLineColor.value,
                    type: 'dashed',
                },
            },
            axisLabel: {
                ...axisLabelStyle,
                formatter: (val: number) => formatDurationClock(val),
            },
        },
        series,
    };
});

function onEfficiencyScatterClick(payload: ChartPointClickPayload): void {
    const items = props.modules.pace?.efficiency ?? [];
    const seriesItems =
        payload.seriesName === 'GAP Pace'
            ? items.filter((i) => i.gap_speed !== null)
            : items.filter((i) => i.raw_speed !== null);
    const clicked = seriesItems[payload.dataIndex];

    if (clicked?.activity_id) {
        router.visit(showActivity.url(clicked.activity_id));
    }
}

const hasStrideData = computed<boolean>(() =>
    (props.modules.pace?.cadenceStride ?? []).some(
        (d) => d.stride_length_cm !== null,
    ),
);

const cadenceStrideOption = computed<EChartsOption>(() => {
    const items = props.modules.pace?.cadenceStride ?? [];
    const monoFont = theme.fontFamilyMono;
    const isImp = units.isImperial.value;

    const strideAxisVal = (cm: number | null): number | null => {
        if (cm === null) {
            return null;
        }

        const meters = cm / 100;

        return Number((isImp ? meters * M_TO_FT : meters).toFixed(2));
    };

    const series: SeriesOption[] = [
        {
            name: 'Cadence',
            type: 'line',
            yAxisIndex: 0,
            smooth: 0.3,
            showSymbol: items.length <= 28,
            symbolSize: 5,
            lineStyle: { color: accentHex.value, width: 2 },
            itemStyle: { color: accentHex.value },
            data: items.map((d) => Math.round(d.cadence)),
        },
    ];

    if (hasStrideData.value) {
        series.push({
            name: `Stride (${units.strideUnit.value})`,
            type: 'line',
            yAxisIndex: 1,
            smooth: 0.3,
            connectNulls: true,
            showSymbol: items.length <= 28,
            symbolSize: 4,
            lineStyle: { color: '#0ea5e9', width: 2 },
            itemStyle: { color: '#0ea5e9' },
            data: items.map((d) => strideAxisVal(d.stride_length_cm)),
        });
    }

    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    return {
        animation: false,
        grid: {
            left: 10,
            right: 12,
            top: 40,
            bottom: 10,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
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

                const strideLine =
                    item.stride_length_cm !== null
                        ? `<div><span style="color:#0ea5e9;">●</span> Stride: <strong>${units.formatStride(item.stride_length_cm)}</strong></div>`
                        : '';

                return [
                    `<div style="font-size:11px;opacity:0.75;">${formatEffortDate(item.date)}</div>`,
                    `<div style="font-weight:500;margin-top:2px;">${escapeHtml(item.name)}</div>`,
                    `<div style="margin-top:3px;"><span style="color:${accentHex.value};">●</span> Cadence: <strong>${Math.round(item.cadence)} spm</strong></div>`,
                    strideLine,
                ].join('');
            },
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: items.map((d) => d.date),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                ...axisLabelStyle,
                formatter: (val: string) => formatShortDate(val),
            },
        },
        yAxis: [
            {
                type: 'value',
                scale: true,
                axisLine: { show: false },
                axisTick: { show: false },
                splitLine: {
                    lineStyle: {
                        color: theme.gridLineColor.value,
                        type: 'dashed',
                    },
                },
                axisLabel: {
                    ...axisLabelStyle,
                    formatter: (val: number) => `${Math.round(val)} spm`,
                },
            },
            {
                type: 'value',
                scale: true,
                show: hasStrideData.value,
                axisLine: { show: false },
                axisTick: { show: false },
                splitLine: { show: false },
                axisLabel: {
                    ...axisLabelStyle,
                    formatter: (val: number) =>
                        `${val.toFixed(2)} ${units.strideUnit.value}`,
                },
            },
        ],
        series,
    };
});

const vo2Delta = computed<number | null>(() => {
    const items = props.modules.pace?.vo2MaxTrend ?? [];

    if (items.length < 2) {
        return null;
    }

    return Number((items[items.length - 1].value - items[0].value).toFixed(1));
});

const vo2TrendOption = computed<EChartsOption>(() => {
    const items = props.modules.pace?.vo2MaxTrend ?? [];
    const monoFont = theme.fontFamilyMono;

    return {
        animation: false,
        grid: {
            left: 10,
            right: 12,
            top: 18,
            bottom: 10,
            containLabel: true,
        },
        tooltip: {
            trigger: 'axis',
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

                return `${formatEffortDate(item.date)}<br/><strong style="color:#6366f1;">${formatNumber(item.value, 1)}</strong> mL/kg/min`;
            },
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: items.map((d) => d.date),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 10,
                formatter: (val: string) => formatShortDate(val),
            },
        },
        yAxis: {
            type: 'value',
            scale: true,
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
                fontFamily: monoFont,
                fontSize: 10,
            },
        },
        series: [
            {
                name: 'VO2 Max',
                type: 'line',
                smooth: 0.3,
                showSymbol: items.length <= 24,
                symbolSize: 5,
                lineStyle: { color: '#6366f1', width: 2 },
                itemStyle: { color: '#6366f1' },
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0,
                        y: 0,
                        x2: 0,
                        y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(99, 102, 241, 0.24)' },
                            { offset: 1, color: 'rgba(99, 102, 241, 0.01)' },
                        ],
                    },
                },
                data: items.map((d) => d.value),
            },
        ],
    };
});

const prProgressionOption = computed<EChartsOption>(() => {
    const board = selectedDistanceBoard.value;
    const points = board?.progression ?? [];
    const monoFont = theme.fontFamilyMono;

    if (points.length === 0) {
        return {};
    }

    let runningBest = Infinity;
    const prStepValues = points.map((point) => {
        if (point.effective_seconds < runningBest) {
            runningBest = point.effective_seconds;
        }

        return runningBest;
    });

    const times = points.map((p) => p.effective_seconds);
    const minSec = Math.min(...times);
    const maxSec = Math.max(...times);
    const pad = Math.max(5, Math.round((maxSec - minSec) * 0.14));

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 20,
            bottom: 12,
            containLabel: true,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: { color: theme.mutedColor.value, type: 'dashed' },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const item =
                    first?.dataIndex !== undefined
                        ? points[first.dataIndex]
                        : undefined;

                if (!item) {
                    return '';
                }

                const prTag = item.is_pr
                    ? ' <span style="color:#f59e0b;font-weight:600;">★ PR</span>'
                    : '';

                return [
                    `<div style="font-size:11px;opacity:0.75;">${formatEffortDate(item.date)}</div>`,
                    `<div style="font-weight:600;margin-top:2px;">${formatDurationClock(item.effective_seconds)}${prTag}</div>`,
                    `<div style="font-size:11px;opacity:0.85;margin-top:2px;">${escapeHtml(item.activity_name)}</div>`,
                ].join('');
            },
        },
        xAxis: {
            type: 'category',
            data: points.map((p) => p.date),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 10,
                formatter: (val: string) => formatShortDate(val),
            },
        },
        yAxis: {
            type: 'value',
            inverse: true,
            min: Math.max(1, minSec - pad),
            max: maxSec + pad,
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
                fontFamily: monoFont,
                fontSize: 10,
                formatter: (val: number) => formatDurationClock(val),
            },
        },
        series: [
            {
                name: 'PR Progression',
                type: 'line',
                step: 'end',
                showSymbol: false,
                lineStyle: { color: accentHex.value, width: 2 },
                itemStyle: { color: accentHex.value },
                data: prStepValues,
                z: 2,
            },
            {
                name: 'Efforts',
                type: 'scatter',
                symbolSize: (_val: unknown, params: { dataIndex: number }) =>
                    points[params.dataIndex]?.is_pr ? 9 : 6,
                itemStyle: {
                    color: (params: { dataIndex: number }) =>
                        points[params.dataIndex]?.is_pr
                            ? accentHex.value
                            : theme.isDark.value
                              ? '#71717a'
                              : '#a1a1aa',
                    borderColor: theme.isDark.value ? '#141417' : '#ffffff',
                    borderWidth: 1.5,
                },
                data: points.map((p) => p.effective_seconds),
                z: 3,
            },
        ],
    };
});

// ========================
// MODULE 2: SPEED & POWER
// ========================
const canShowWkg = computed<boolean>(
    () =>
        props.modules.power !== null &&
        props.modules.power.weight_kg !== null &&
        props.modules.power.weight_kg > 0,
);

const powerFormMeta = computed<{ label: string; className: string }>(() => {
    const tsb = props.modules.power?.latest_tsb ?? props.summary.latest_tsb;

    if (tsb >= 15) {
        return {
            label: 'Transition',
            className: 'text-sky-600 dark:text-sky-400',
        };
    }

    if (tsb >= 5) {
        return {
            label: 'Fresh',
            className: 'text-emerald-600 dark:text-emerald-400',
        };
    }

    if (tsb >= -10) {
        return {
            label: 'Neutral',
            className: 'text-zinc-600 dark:text-zinc-400',
        };
    }

    if (tsb >= -30) {
        return {
            label: 'Optimal Load',
            className: 'text-amber-600 dark:text-amber-400',
        };
    }

    return {
        label: 'High Fatigue',
        className: 'text-rose-600 dark:text-rose-400',
    };
});

function pickPowerCurveVal(
    point: PowerCurvePoint,
    window: 'allTime' | 'season' | 'last90d',
): number | null {
    const useWkg = powerUnit.value === 'wkg' && canShowWkg.value;

    if (window === 'allTime') {
        return useWkg ? point.allTimeWkg : point.allTime;
    }

    if (window === 'season') {
        return useWkg ? point.seasonWkg : point.season;
    }

    return useWkg ? point.last90dWkg : point.last90d;
}

function formatPowerPointVal(value: number | null): string {
    if (value === null || !Number.isFinite(value)) {
        return '—';
    }

    if (powerUnit.value === 'wkg' && canShowWkg.value) {
        return `${value.toFixed(2)} W/kg`;
    }

    return `${formatNumber(Math.round(value), 0)} W`;
}

const hasPowerCurve = computed<boolean>(() =>
    (props.modules.power?.powerCurve ?? []).some(
        (p) => p.allTime !== null || p.season !== null || p.last90d !== null,
    ),
);

const powerBenchmarks = computed<PowerCurvePoint[]>(() => {
    const curve = props.modules.power?.powerCurve ?? [];
    const targets = [5, 60, 300, 1200, 3600];

    return targets
        .map((sec) => curve.find((p) => p.seconds === sec))
        .filter((p): p is PowerCurvePoint => p !== undefined);
});

const powerCurveChartOption = computed<EChartsOption>(() => {
    const points = props.modules.power?.powerCurve ?? [];
    const monoFont = theme.fontFamilyMono;
    const isWkg = powerUnit.value === 'wkg' && canShowWkg.value;
    const unitLabel = isWkg ? 'W/kg' : 'W';
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 52,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: { color: theme.mutedColor.value, type: 'dashed' },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const point =
                    first?.dataIndex !== undefined
                        ? points[first.dataIndex]
                        : undefined;

                if (!point) {
                    return '';
                }

                return [
                    `<strong>${point.label}</strong>`,
                    `All-Time: ${formatPowerPointVal(pickPowerCurveVal(point, 'allTime'))}`,
                    `Season: ${formatPowerPointVal(pickPowerCurveVal(point, 'season'))}`,
                    `Last 90d: ${formatPowerPointVal(pickPowerCurveVal(point, 'last90d'))}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: points.map((p) => p.label),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: {
            type: 'value',
            name: unitLabel,
            nameGap: 14,
            nameTextStyle: { ...axisLabelStyle, align: 'left' },
            axisLabel: axisLabelStyle,
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'All-Time',
                type: 'line',
                smooth: 0.25,
                connectNulls: true,
                symbolSize: 6,
                lineStyle: { color: '#0ea5e9', width: 2.25 },
                itemStyle: { color: '#0ea5e9' },
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0,
                        y: 0,
                        x2: 0,
                        y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(14, 165, 233, 0.22)' },
                            { offset: 1, color: 'rgba(14, 165, 233, 0.01)' },
                        ],
                    },
                },
                data: points.map((p) => pickPowerCurveVal(p, 'allTime')),
            },
            {
                name: 'Season',
                type: 'line',
                smooth: 0.25,
                connectNulls: true,
                symbolSize: 5,
                lineStyle: { color: '#818cf8', width: 2 },
                itemStyle: { color: '#818cf8' },
                data: points.map((p) => pickPowerCurveVal(p, 'season')),
            },
            {
                name: 'Last 90d',
                type: 'line',
                smooth: 0.25,
                connectNulls: true,
                symbolSize: 4,
                lineStyle: { color: '#a1a1aa', width: 1.75, type: 'dashed' },
                itemStyle: { color: '#a1a1aa' },
                data: points.map((p) => pickPowerCurveVal(p, 'last90d')),
            },
        ],
    };
});

const fitnessLoadChartOption = computed<EChartsOption>(() => {
    const items = props.modules.power?.fitnessSeries ?? [];
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 52,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: { color: theme.mutedColor.value, type: 'dashed' },
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

                const tsbPrefix = item.tsb > 0 ? '+' : '';

                return [
                    `<strong>${formatShortDate(item.date)}</strong>`,
                    `Fitness (CTL): ${formatNumber(item.ctl, 1)}`,
                    `Fatigue (ATL): ${formatNumber(item.atl, 1)}`,
                    `Form (TSB): ${tsbPrefix}${formatNumber(item.tsb, 1)}`,
                    `Daily TSS: ${formatNumber(item.tss, 0)}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: items.map((i) => formatShortDate(i.date)),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: [
            {
                type: 'value',
                name: 'CTL / ATL',
                nameGap: 14,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                name: 'Form (TSB)',
                nameGap: 14,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: 'Form (TSB)',
                type: 'bar',
                yAxisIndex: 1,
                barMaxWidth: 8,
                data: items.map((i) => ({
                    value: Number(i.tsb.toFixed(1)),
                    itemStyle: {
                        color:
                            i.tsb >= 0
                                ? 'rgba(16, 185, 129, 0.32)'
                                : 'rgba(244, 63, 94, 0.28)',
                        borderRadius: i.tsb >= 0 ? [2, 2, 0, 0] : [0, 0, 2, 2],
                    },
                })),
            },
            {
                name: 'Fitness (CTL)',
                type: 'line',
                yAxisIndex: 0,
                smooth: 0.25,
                showSymbol: false,
                lineStyle: { color: '#0ea5e9', width: 2.25 },
                itemStyle: { color: '#0ea5e9' },
                data: items.map((i) => Number(i.ctl.toFixed(1))),
            },
            {
                name: 'Fatigue (ATL)',
                type: 'line',
                yAxisIndex: 0,
                smooth: 0.25,
                showSymbol: false,
                lineStyle: { color: '#f59e0b', width: 1.75 },
                itemStyle: { color: '#f59e0b' },
                data: items.map((i) => Number(i.atl.toFixed(1))),
            },
        ],
    };
});

const weeklyTssChartOption = computed<EChartsOption>(() => {
    const weeks = props.modules.power?.weeklyTss ?? [];
    const monoFont = theme.fontFamilyMono;
    const distUnit = units.distanceUnit.value;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 52,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as { dataIndex?: number } | undefined;
                const week =
                    first?.dataIndex !== undefined
                        ? weeks[first.dataIndex]
                        : undefined;

                if (!week) {
                    return '';
                }

                return [
                    `<strong>${escapeHtml(week.label)}</strong>`,
                    `TSS: <strong>${formatNumber(week.tss, 0)}</strong>`,
                    `Distance: ${units.formatDistanceCompact(week.distance_meters, 1)}`,
                    `Sessions: ${week.sessions}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: weeks.map((w) => w.label),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: [
            {
                type: 'value',
                name: 'TSS',
                nameGap: 14,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                name: distUnit,
                nameGap: 14,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: 'Weekly TSS',
                type: 'bar',
                yAxisIndex: 0,
                barMaxWidth: 22,
                itemStyle: { color: '#0ea5e9', borderRadius: [3, 3, 0, 0] },
                data: weeks.map((w) => Math.round(w.tss)),
            },
            {
                name: `Distance (${distUnit})`,
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.25,
                symbolSize: 5,
                lineStyle: { color: '#818cf8', width: 1.75, type: 'dashed' },
                itemStyle: { color: '#818cf8' },
                data: weeks.map((w) =>
                    Number(
                        units.distanceFromMeters(w.distance_meters).toFixed(1),
                    ),
                ),
            },
        ],
    };
});

const hasFtpHistory = computed<boolean>(() =>
    (props.modules.power?.ftpHistory ?? []).some(
        (p) => p.ftp !== null || p.max_20min_power !== null,
    ),
);

const ftpHistoryChartOption = computed<EChartsOption>(() => {
    const items = props.modules.power?.ftpHistory ?? [];
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 52,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: { color: theme.mutedColor.value, type: 'dashed' },
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

                return [
                    `<strong>${formatShortDate(item.date)}</strong>`,
                    `FTP: ${item.ftp !== null ? `${Math.round(item.ftp)} W` : '—'}`,
                    `Max 20m: ${item.max_20min_power !== null ? `${Math.round(item.max_20min_power)} W` : '—'}`,
                ].join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: items.map((i) => formatShortDate(i.date)),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: {
            type: 'value',
            scale: true,
            name: 'W',
            nameGap: 14,
            nameTextStyle: { ...axisLabelStyle, align: 'left' },
            axisLabel: axisLabelStyle,
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'FTP',
                type: 'line',
                step: 'end',
                connectNulls: true,
                symbolSize: 5,
                lineStyle: { color: '#0ea5e9', width: 2.25 },
                itemStyle: { color: '#0ea5e9' },
                data: items.map((i) =>
                    i.ftp !== null ? Math.round(i.ftp) : null,
                ),
            },
            {
                name: 'Max 20m Power',
                type: 'line',
                smooth: 0.25,
                connectNulls: true,
                symbolSize: 5,
                lineStyle: { color: '#818cf8', width: 1.75, type: 'dashed' },
                itemStyle: { color: '#818cf8' },
                data: items.map((i) =>
                    i.max_20min_power !== null
                        ? Math.round(i.max_20min_power)
                        : null,
                ),
            },
        ],
    };
});

const hasPowerZones = computed<boolean>(() =>
    (props.modules.power?.powerZones ?? []).some((z) => z.seconds > 0),
);

// ========================
// MODULE 3: CLIMBING & REGIONS
// ========================
const hasStaminaData = computed<boolean>(
    () =>
        props.modules.climbing !== null &&
        props.modules.climbing.stamina.count > 0 &&
        (props.modules.climbing.stamina.avg_begin !== null ||
            props.modules.climbing.stamina.avg_end !== null),
);

const hasFeelData = computed<boolean>(
    () =>
        props.modules.climbing !== null &&
        props.modules.climbing.feelRpe.count > 0 &&
        (props.modules.climbing.feelRpe.avg_feel !== null ||
            props.modules.climbing.feelRpe.avg_rpe !== null),
);

// ========================
// MODULE 4: OFF-ROAD DYNAMICS
// ========================
interface OffroadKpiCard {
    key: string;
    label: string;
    value: string;
    subtitle: string | null;
    barClass: string;
}

interface OffroadTabOption {
    key: OffroadLeaderboardTab;
    label: string;
}

const offroadKpiCards = computed<OffroadKpiCard[]>(() => {
    const offroad = props.modules.offroad;

    if (!offroad) {
        return [];
    }

    const cards: OffroadKpiCard[] = [];

    if (offroad.avg_grit !== null) {
        cards.push({
            key: 'grit',
            label: 'Avg Grit',
            value: formatNumber(offroad.avg_grit, 1),
            subtitle: 'Higher = more technical',
            barClass: 'bg-emerald-500',
        });
    }

    if (offroad.avg_flow !== null) {
        cards.push({
            key: 'flow',
            label: 'Avg Flow',
            value: formatNumber(offroad.avg_flow, 1),
            subtitle: 'Lower = smoother riding',
            barClass: 'bg-sky-500',
        });
    }

    if (offroad.avg_unpaved_pct !== null && offroad.avg_unpaved_pct > 0) {
        cards.push({
            key: 'unpaved',
            label: 'Avg Unpaved %',
            value: `${Math.round(offroad.avg_unpaved_pct)}%`,
            subtitle: null,
            barClass: 'bg-amber-500',
        });
    }

    if (offroad.total_jumps > 0) {
        cards.push({
            key: 'jumps',
            label: 'Total Jumps',
            value: formatNumber(offroad.total_jumps, 0),
            subtitle: null,
            barClass: 'bg-amber-500',
        });
    }

    return cards;
});

const offroadKpiGridClass = computed<string>(() => {
    switch (offroadKpiCards.value.length) {
        case 1:
            return 'grid-cols-1';
        case 2:
            return 'grid-cols-2';
        case 3:
            return 'grid-cols-2 sm:grid-cols-3';
        default:
            return 'grid-cols-2 lg:grid-cols-4';
    }
});

const availableOffroadTabs = computed<OffroadTabOption[]>(() => {
    const offroad = props.modules.offroad;

    if (!offroad) {
        return [];
    }

    const tabs: OffroadTabOption[] = [];

    if (offroad.hardestSessions.length > 0) {
        tabs.push({ key: 'hardest', label: 'Hardest' });
    }

    if (offroad.smoothestSessions.length > 0) {
        tabs.push({ key: 'smoothest', label: 'Smoothest' });
    }

    if (offroad.topJumpSessions.length > 0) {
        tabs.push({ key: 'jumps', label: 'Top Jumps' });
    }

    return tabs;
});

watch(
    availableOffroadTabs,
    (tabs) => {
        if (tabs.length > 0 && !tabs.some((t) => t.key === offroadTab.value)) {
            offroadTab.value = tabs[0].key;
        }
    },
    { immediate: true },
);

const hasDynamicsTrend = computed<boolean>(() =>
    (props.modules.offroad?.dynamicsTrend ?? []).some(
        (p) => p.grit !== null || p.avg_flow !== null,
    ),
);

const hasOffroadModuleContent = computed<boolean>(
    () =>
        offroadKpiCards.value.length > 0 ||
        hasDynamicsTrend.value ||
        availableOffroadTabs.value.length > 0,
);

const dynamicsTrendChartOption = computed<EChartsOption>(() => {
    const items = props.modules.offroad?.dynamicsTrend ?? [];
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 11,
    };

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 52,
            bottom: 12,
            containLabel: true,
        },
        legend: {
            top: 0,
            left: 0,
            padding: [0, 0, 0, 0],
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 6,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 11,
            },
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: { color: theme.mutedColor.value, type: 'dashed' },
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

                const lines = [
                    `<strong>${escapeHtml(item.name)}</strong>`,
                    `<span style="opacity:0.7">${formatShortDate(item.date)}</span>`,
                    `Grit: ${item.grit !== null ? formatNumber(item.grit, 1) : '—'}`,
                    `Avg Flow: ${item.avg_flow !== null ? formatNumber(item.avg_flow, 1) : '—'}`,
                ];

                if (item.elevation_gain !== null) {
                    lines.push(
                        `Vert: ${units.formatElevation(item.elevation_gain)}`,
                    );
                }

                if (item.jump_count !== null && item.jump_count > 0) {
                    lines.push(`Jumps: ${formatNumber(item.jump_count, 0)}`);
                }

                return lines.join('<br/>');
            },
        },
        xAxis: {
            type: 'category',
            data: items.map((i) => formatShortDate(i.date)),
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: axisLabelStyle,
        },
        yAxis: [
            {
                type: 'value',
                name: 'Grit',
                nameGap: 14,
                nameTextStyle: { ...axisLabelStyle, align: 'left' },
                axisLabel: axisLabelStyle,
                splitLine: { lineStyle: { color: theme.gridLineColor.value } },
            },
            {
                type: 'value',
                name: 'Flow (↓ smoother)',
                nameGap: 14,
                nameTextStyle: { ...axisLabelStyle, align: 'right' },
                axisLabel: axisLabelStyle,
                splitLine: { show: false },
            },
        ],
        series: [
            {
                name: 'Grit',
                type: 'line',
                yAxisIndex: 0,
                smooth: 0.25,
                connectNulls: true,
                symbolSize: 6,
                lineStyle: { color: '#10b981', width: 2.25 },
                itemStyle: { color: '#10b981' },
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0,
                        y: 0,
                        x2: 0,
                        y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(16, 185, 129, 0.2)' },
                            { offset: 1, color: 'rgba(16, 185, 129, 0.01)' },
                        ],
                    },
                },
                data: items.map((i) =>
                    i.grit !== null ? Number(i.grit.toFixed(1)) : null,
                ),
            },
            {
                name: 'Avg Flow',
                type: 'line',
                yAxisIndex: 1,
                smooth: 0.25,
                connectNulls: true,
                symbolSize: 6,
                lineStyle: { color: '#0ea5e9', width: 2, type: 'dashed' },
                itemStyle: { color: '#0ea5e9' },
                data: items.map((i) =>
                    i.avg_flow !== null ? Number(i.avg_flow.toFixed(1)) : null,
                ),
            },
        ],
    };
});

function onDynamicsPointClick(payload: ChartPointClickPayload): void {
    const item = props.modules.offroad?.dynamicsTrend[payload.dataIndex];

    if (item?.activity_id) {
        router.visit(showActivity.url(item.activity_id));
    }
}

// Recent activities table dynamic column flags
const recentHasPower = computed<boolean>(() =>
    props.recentActivities.some(
        (a) => a.avg_power !== null || a.normalized_power !== null,
    ),
);
const recentHasOffroad = computed<boolean>(() =>
    props.recentActivities.some((a) => a.grit !== null || a.avg_flow !== null),
);
</script>

<template>
    <div class="space-y-6">
        <Head :title="sport.label" />

        <!-- Page Header + Segmented Discipline Tabs & Filters -->
        <header class="space-y-4">
            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
            >
                <div>
                    <p
                        v-if="sport.family_key"
                        class="font-mono text-[11px] tracking-widest uppercase"
                        :style="{ color: accentHex }"
                    >
                        {{
                            `${getSportMeta(sport.family_key).label} · ${sport.short_label}`
                        }}
                    </p>
                    <h1
                        class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                        :class="sport.family_key ? 'mt-1' : ''"
                    >
                        {{ sport.label }}
                    </h1>
                </div>

                <!-- Segmented Discipline Tabs -->
                <nav
                    v-if="sport.tabs.length > 0"
                    aria-label="Discipline navigation"
                    class="inline-flex flex-wrap self-start rounded-lg border border-zinc-200/90 bg-white p-1 lg:self-auto dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <Link
                        v-for="tab in sport.tabs"
                        :key="tab.key"
                        :href="tab.href"
                        prefetch
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-mono text-xs font-medium transition-colors"
                        :class="
                            tab.key === sport.key
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                    >
                        <span
                            v-if="tab.key === sport.key"
                            class="size-1.5 rounded-full"
                            :style="{ backgroundColor: accentHex }"
                        />
                        <span>{{ tab.short_label }}</span>
                    </Link>
                </nav>
            </div>

            <!-- Filter Bar -->
            <div
                v-if="
                    filters.showEnvironmentFilter || filters.showTerrainFilter
                "
                class="flex flex-wrap items-center gap-2.5"
            >
                <!-- Environment Filter -->
                <div
                    v-if="filters.showEnvironmentFilter"
                    class="inline-flex rounded-lg border border-zinc-200/90 bg-white p-1 dark:border-zinc-800/90 dark:bg-[#141417]"
                    role="group"
                    aria-label="Environment filter"
                >
                    <button
                        v-for="option in ENVIRONMENT_OPTIONS"
                        :key="option.value"
                        type="button"
                        class="inline-flex cursor-pointer items-center rounded-md px-2.5 py-1 font-mono text-xs font-medium transition-colors"
                        :class="
                            filters.environment === option.value
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="selectEnvironment(option.value)"
                    >
                        <span>{{ option.label }}</span>
                    </button>
                </div>

                <!-- Terrain Profile Filter -->
                <div
                    v-if="filters.showTerrainFilter"
                    class="inline-flex flex-wrap rounded-lg border border-zinc-200/90 bg-white p-1 dark:border-zinc-800/90 dark:bg-[#141417]"
                    role="group"
                    aria-label="Terrain profile filter"
                >
                    <button
                        v-for="option in terrainOptions"
                        :key="option.value"
                        type="button"
                        :title="option.tooltip"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2.5 py-1 font-mono text-xs font-medium transition-colors"
                        :class="
                            filters.terrain === option.value
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="selectTerrain(option.value)"
                    >
                        <span>{{ option.label }}</span>
                        <span
                            v-if="option.sublabel"
                            class="hidden text-[10px] opacity-65 sm:inline"
                        >
                            {{ option.sublabel }}
                        </span>
                    </button>
                </div>
            </div>
        </header>

        <!-- ALWAYS-ON BALANCED CORE: Hero KPI Grid -->
        <section class="grid grid-cols-2 gap-3.5 sm:grid-cols-3 lg:grid-cols-5">
            <!-- YTD Distance -->
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    YTD Distance
                </p>
                <p
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{
                        units.formatDistanceCompact(
                            summary.ytd_distance_meters,
                            1,
                        )
                    }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{
                        units.formatDistanceCompact(
                            summary.weekly_avg_distance_meters,
                            1,
                        )
                    }}/wk ·
                    {{
                        units.formatDistanceCompact(
                            summary.total_distance_meters,
                            0,
                        )
                    }}
                    total
                </p>
            </div>

            <!-- YTD Sessions & Duration -->
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Sessions &amp; Time
                </p>
                <div class="mt-1.5 flex items-baseline gap-1.5 tabular-nums">
                    <span
                        class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        {{ formatNumber(summary.ytd_sessions, 0) }}
                    </span>
                    <span
                        class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        {{
                            summary.ytd_sessions === 1
                                ? sessionNoun.singular
                                : sessionNoun.plural
                        }}
                    </span>
                </div>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{ formatDurationHuman(summary.ytd_duration_seconds) }} ·
                    {{ formatNumber(summary.total_sessions, 0) }} total
                </p>
            </div>

            <!-- Elevation Gain -->
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    YTD Elevation
                </p>
                <p
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    +{{ units.formatElevation(summary.ytd_elevation_gain) }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    +{{
                        units.formatElevation(
                            summary.weekly_avg_elevation_gain,
                        )
                    }}/wk · +{{
                        units.formatElevation(summary.total_elevation_gain)
                    }}
                    total
                </p>
            </div>

            <!-- Avg Pace / Speed (+ GAP if running) -->
            <div
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ isPaceSport ? 'Avg Pace' : 'Avg Speed' }}
                </p>
                <p
                    class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatRate(summary.ytd_avg_speed) }}
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    <template
                        v-if="isPaceSport && summary.ytd_avg_gap_speed !== null"
                    >
                        GAP {{ units.formatPace(summary.ytd_avg_gap_speed) }}
                    </template>
                    <template v-else>
                        YTD {{ sport.short_label.toLowerCase() }} average
                    </template>
                </p>
            </div>

            <!-- Avg HR / Load + FTP or VO2 Max pill -->
            <div
                class="relative col-span-2 overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 sm:col-span-1 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-10"
                    :style="{ backgroundColor: accentHex }"
                />
                <div class="flex items-center justify-between gap-1.5">
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Avg HR &amp; Load
                    </p>
                    <span
                        v-if="summary.latest_ftp !== null"
                        class="rounded bg-sky-500/12 px-1.5 py-0.5 font-mono text-[10px] font-medium text-sky-700 tabular-nums dark:text-sky-300"
                    >
                        {{ Math.round(summary.latest_ftp) }}W<template
                            v-if="summary.w_per_kg !== null"
                        >
                            · {{ summary.w_per_kg.toFixed(2) }}
                        </template>
                    </span>
                    <span
                        v-else-if="summary.latest_vo2_max !== null"
                        class="rounded bg-indigo-500/12 px-1.5 py-0.5 font-mono text-[10px] font-medium text-indigo-700 tabular-nums dark:text-indigo-300"
                    >
                        VO2 {{ formatNumber(summary.latest_vo2_max, 1) }}
                    </span>
                </div>
                <div class="mt-1.5 flex items-baseline gap-2 tabular-nums">
                    <span
                        class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        {{
                            summary.ytd_avg_hr !== null
                                ? `${Math.round(summary.ytd_avg_hr)}`
                                : '—'
                        }}
                    </span>
                    <span
                        v-if="summary.ytd_avg_hr !== null"
                        class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        bpm
                    </span>
                </div>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    YTD Load {{ formatNumber(summary.ytd_load, 0) }}
                </p>
            </div>
        </section>

        <!-- Active Goals -->
        <GoalProgressStrip
            v-if="goals.length > 0"
            :goals="goals"
            :accent="goalAccent"
            :title="`${sport.label} Goals`"
        />

        <!-- ALWAYS-ON BALANCED CORE: Primary Weekly Volume Chart -->
        <section
            class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <div>
                    <h2
                        class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        Weekly Volume
                    </h2>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        <span>
                            {{
                                units.formatDistanceCompact(
                                    weeklyVolumeTotals.distanceM,
                                    0,
                                )
                            }}
                        </span>
                        <span>
                            +{{
                                units.formatElevation(
                                    weeklyVolumeTotals.elevationM,
                                )
                            }}
                        </span>
                        <span>
                            {{ formatNumber(weeklyVolumeTotals.sessions, 0) }}
                            {{
                                weeklyVolumeTotals.sessions === 1
                                    ? sessionNoun.singular
                                    : sessionNoun.plural
                            }}
                        </span>
                        <span>
                            {{ formatNumber(weeklyVolumeTotals.load, 0) }} load
                        </span>
                    </div>
                </div>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <!-- Elevation Overlay Toggle -->
                    <button
                        v-if="hasWeeklyElevation && volumeMetric !== 'vertical'"
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            showElevationOverlay
                                ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                : 'border-zinc-200/90 bg-white text-zinc-500 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:text-zinc-100'
                        "
                        @click="showElevationOverlay = !showElevationOverlay"
                    >
                        <Layers class="size-3" />
                        <span>Vert Overlay</span>
                    </button>

                    <!-- Metric Toggle -->
                    <div
                        class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                        role="group"
                        aria-label="Weekly volume metric"
                    >
                        <button
                            v-for="opt in VOLUME_METRIC_OPTIONS"
                            :key="opt.value"
                            type="button"
                            class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                            :class="
                                volumeMetric === opt.value
                                    ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                    : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="volumeMetric = opt.value"
                        >
                            {{ opt.label }}
                        </button>
                    </div>

                    <!-- Volume Range Switcher -->
                    <DateRangeControl
                        :presets="VOLUME_RANGE_OPTIONS"
                        :window="filters.volumeWindow"
                        popover-align="right"
                        aria-label="Volume time range"
                        @select-preset="selectVolumeRange"
                        @step="stepVolumeRange"
                        @apply-custom="applyCustomVolumeRange"
                    />
                </div>
            </div>

            <BaseChart
                v-if="weeklyVolume.length > 0"
                :option="weeklyVolumeChartOption"
                :height="280"
            />
            <div
                v-else
                class="flex h-[280px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
            >
                No weekly volume matches the current filter.
            </div>
        </section>

        <!-- Multi-Year Cumulative & Activity Calendar Summary -->
        <ActivitySummaryCharts
            v-if="annualSummary"
            :summary="annualSummary"
            mode="sport"
            :sport-key="sport.key"
            :title="`${sport.label} Annual Summary`"
        />

        <!-- ALWAYS-ON BALANCED CORE: HR Zone Distribution & Milestones -->
        <section class="grid gap-4 lg:grid-cols-12">
            <!-- HR Zone Distribution & Polarization -->
            <div
                class="flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 lg:col-span-7 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div>
                    <div class="space-y-2.5">
                        <h2
                            class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            HR Zone Distribution &amp; Polarization
                        </h2>

                        <DateRangeControl
                            :presets="ZONE_RANGE_OPTIONS"
                            :window="filters.zoneWindow"
                            popover-align="left"
                            aria-label="HR zone time window"
                            @select-preset="selectZoneRange"
                            @step="stepZoneRange"
                            @apply-custom="applyCustomZoneRange"
                        />
                    </div>

                    <div v-if="hasHrZoneData" class="mt-4 space-y-4">
                        <!-- Polarization Summary Bar -->
                        <div>
                            <div
                                class="flex h-3 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                            >
                                <div
                                    v-if="hrZones.polarization.easy_pct > 0"
                                    class="h-full bg-emerald-500 transition-all"
                                    :style="{
                                        width: `${hrZones.polarization.easy_pct}%`,
                                    }"
                                    :title="`Easy (Z1–Z2): ${hrZones.polarization.easy_pct.toFixed(1)}%`"
                                />
                                <div
                                    v-if="hrZones.polarization.moderate_pct > 0"
                                    class="h-full bg-amber-500 transition-all"
                                    :style="{
                                        width: `${hrZones.polarization.moderate_pct}%`,
                                    }"
                                    :title="`Moderate (Z3): ${hrZones.polarization.moderate_pct.toFixed(1)}%`"
                                />
                                <div
                                    v-if="hrZones.polarization.hard_pct > 0"
                                    class="h-full bg-rose-500 transition-all"
                                    :style="{
                                        width: `${hrZones.polarization.hard_pct}%`,
                                    }"
                                    :title="`Hard (Z4–Z5): ${hrZones.polarization.hard_pct.toFixed(1)}%`"
                                />
                            </div>

                            <div class="mt-2.5 grid grid-cols-3 gap-2">
                                <div
                                    class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                                >
                                    <div
                                        class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-emerald-500"
                                        />
                                        Easy Z1–Z2
                                    </div>
                                    <div
                                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                hrZones.polarization.easy_pct,
                                                1,
                                            )
                                        }}%
                                    </div>
                                </div>
                                <div
                                    class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                                >
                                    <div
                                        class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-amber-500"
                                        />
                                        Mod Z3
                                    </div>
                                    <div
                                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                hrZones.polarization
                                                    .moderate_pct,
                                                1,
                                            )
                                        }}%
                                    </div>
                                </div>
                                <div
                                    class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                                >
                                    <div
                                        class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-rose-500"
                                        />
                                        Hard Z4–Z5
                                    </div>
                                    <div
                                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                                    >
                                        {{
                                            formatNumber(
                                                hrZones.polarization.hard_pct,
                                                1,
                                            )
                                        }}%
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 5-Zone Breakdown -->
                        <div class="space-y-2">
                            <div
                                v-for="z in hrZones.zones"
                                :key="z.zone"
                                class="grid grid-cols-[6.25rem_1fr_3rem_3.5rem] items-center gap-2.5 text-xs tabular-nums"
                            >
                                <span
                                    class="truncate font-mono text-[11px] text-zinc-600 dark:text-zinc-400"
                                >
                                    Z{{ z.zone }} · {{ z.label }}
                                </span>
                                <div
                                    class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                                >
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :class="hrZoneBarClass(z.zone)"
                                        :style="{
                                            width: `${clampPct(z.pct)}%`,
                                        }"
                                    />
                                </div>
                                <span
                                    class="text-right font-mono text-[11px] font-medium text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ formatNumber(z.pct, 0) }}%
                                </span>
                                <span
                                    class="text-right font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ formatDurationHuman(z.seconds) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="mt-4 flex h-48 items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                    >
                        No HR zone data for {{ filters.zoneWindow.label }}
                    </div>
                </div>

                <p
                    v-if="hasHrZoneData"
                    class="mt-3 border-t border-zinc-100 pt-2.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
                >
                    Total HR time ({{ filters.zoneWindow.label }}):
                    {{ formatDurationHuman(hrZones.total_seconds) }}
                </p>
            </div>

            <!-- Milestones Card -->
            <div
                class="rounded-xl border border-zinc-200/90 bg-white p-5 lg:col-span-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="mb-3.5 flex items-center justify-between gap-2">
                    <h2
                        class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        Milestones
                    </h2>
                    <Trophy class="size-4" :style="{ color: accentHex }" />
                </div>

                <div v-if="milestoneItems.length > 0" class="space-y-2.5">
                    <Link
                        v-for="item in milestoneItems"
                        :key="item.key"
                        :href="showActivity.url(item.activity_id)"
                        class="group flex items-center justify-between gap-3 rounded-lg border border-zinc-200/75 bg-[#f7f7f5]/70 px-3.5 py-2.5 transition-colors hover:border-zinc-400/70 dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:hover:border-zinc-700"
                    >
                        <div class="min-w-0">
                            <p
                                class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                {{ item.label }}
                            </p>
                            <p
                                class="mt-0.5 truncate text-xs font-medium text-zinc-900 transition-colors group-hover:text-zinc-600 dark:text-zinc-100 dark:group-hover:text-zinc-300"
                            >
                                {{ item.name }}
                            </p>
                            <p
                                class="mt-0.5 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                            >
                                {{ formatShortDate(item.date) }} ·
                                {{ item.meta }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            <span
                                class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                            >
                                {{ item.value }}
                            </span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-zinc-900 dark:group-hover:text-zinc-100"
                            />
                        </div>
                    </Link>
                </div>

                <div
                    v-else
                    class="flex h-48 items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                >
                    No qualifying milestones recorded yet.
                </div>
            </div>
        </section>

        <!-- Eddington Number Card -->
        <section
            v-if="eddington && activeEddington"
            class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <span
                class="absolute top-0 left-0 h-0.5 w-12"
                :style="{ backgroundColor: accentHex }"
            />

            <div class="grid gap-5 lg:grid-cols-12 lg:items-center">
                <!-- Left: Headline E = N, Progress Bar & Longest Day -->
                <div class="space-y-4 lg:col-span-7">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <p
                                class="font-mono text-[10px] tracking-widest uppercase"
                                :style="{ color: accentHex }"
                            >
                                Eddington Number · Daily Distance Consistency
                            </p>
                            <div
                                class="mt-1.5 flex items-baseline gap-2.5 tabular-nums"
                            >
                                <span
                                    class="font-mono text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-zinc-100"
                                >
                                    E =
                                    {{
                                        formatNumber(activeEddington.number, 0)
                                    }}
                                </span>
                                <span
                                    class="rounded-md border border-zinc-200/90 bg-[#f7f7f5] px-2 py-0.5 font-mono text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-300"
                                >
                                    {{ activeEddington.unit }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-2 font-mono text-[11px] tabular-nums"
                        >
                            <div
                                class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-1.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                            >
                                <span
                                    class="text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                                >
                                    Longest Day
                                </span>
                                <p
                                    class="mt-0.5 font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        eddington.max_single_day_meters > 0
                                            ? units.formatDistanceCompact(
                                                  eddington.max_single_day_meters,
                                                  1,
                                              )
                                            : '—'
                                    }}
                                </p>
                            </div>
                            <div
                                class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-1.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                            >
                                <span
                                    class="text-[10px] tracking-wider text-zinc-400 uppercase dark:text-zinc-500"
                                >
                                    Active Days
                                </span>
                                <p
                                    class="mt-0.5 font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{ formatNumber(eddington.active_days, 0) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Toward E + 1 -->
                    <div class="space-y-2">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 font-mono text-xs tabular-nums"
                        >
                            <span class="text-zinc-700 dark:text-zinc-300">
                                <strong
                                    class="font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{
                                        formatNumber(
                                            activeEddington.days_needed_for_next,
                                            0,
                                        )
                                    }}
                                </strong>
                                more
                                {{
                                    activeEddington.days_needed_for_next === 1
                                        ? 'day'
                                        : 'days'
                                }}
                                of ≥ {{ activeEddington.next_target }}
                                {{ activeEddington.unit }} to reach E =
                                {{ activeEddington.next_target }}
                            </span>
                            <span
                                class="text-[11px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{ activeEddington.completed_for_next }} /
                                {{ activeEddington.next_target }} days
                            </span>
                        </div>

                        <div
                            class="h-2.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                        >
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                :style="{
                                    width: `${eddingtonNextProgressPct}%`,
                                    backgroundColor: accentHex,
                                }"
                            />
                        </div>
                    </div>
                </div>

                <!-- Right: Next 3 Milestones Mini-Table -->
                <div
                    class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/60 p-3.5 lg:col-span-5 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                >
                    <div class="mb-2.5 flex items-center justify-between gap-2">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Next Targets
                        </span>
                        <Target
                            class="size-3.5"
                            :style="{ color: accentHex }"
                        />
                    </div>

                    <div
                        class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70"
                    >
                        <div
                            v-for="row in activeEddington.next_targets"
                            :key="row.target"
                            class="grid grid-cols-[4.5rem_1fr_auto] items-center gap-3 py-2 font-mono text-xs tabular-nums first:pt-0 last:pb-0"
                        >
                            <span
                                class="font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                E = {{ row.target }}
                            </span>

                            <div class="flex items-center gap-2">
                                <div
                                    class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-800"
                                >
                                    <div
                                        class="h-full rounded-full"
                                        :style="{
                                            width: `${clampPct((row.completed_days / row.target) * 100)}%`,
                                            backgroundColor: accentHex,
                                        }"
                                    />
                                </div>
                                <span
                                    class="text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ row.completed_days }}/{{ row.target }}
                                </span>
                            </div>

                            <span
                                class="text-right text-[11px] font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                +{{ row.remaining_days }}
                                {{ row.remaining_days === 1 ? 'day' : 'days' }}
                                <span class="text-zinc-400 dark:text-zinc-500">
                                    ≥ {{ row.target }}
                                    {{ activeEddington.unit }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================================= -->
        <!-- MODULE 1: PACE & RUNNING DYNAMICS (modules.pace !== null) -->
        <!-- ========================================================= -->
        <template v-if="modules.pace !== null">
            <!-- Interactive Best Efforts Board (with distance tabs, progression chart, inline edit/exclude/restore) -->
            <section
                v-if="
                    modules.pace.bestEfforts !== null &&
                    modules.pace.bestEfforts.distances.length > 0
                "
                class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex flex-col gap-3 border-b border-zinc-200/80 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800/80"
                >
                    <div>
                        <p
                            class="font-mono text-[10px] tracking-widest uppercase"
                            :style="{ color: accentHex }"
                        >
                            Benchmark Distances · 400m to 50K
                        </p>
                        <h2
                            class="mt-0.5 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            Best Efforts Board
                        </h2>
                    </div>

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <select
                            v-if="
                                effortsTab === 'annual' &&
                                modules.pace.bestEfforts.availableYears.length >
                                    0
                            "
                            :value="modules.pace.bestEfforts.selectedYear"
                            aria-label="Select best efforts year"
                            class="h-7.5 cursor-pointer rounded-lg border border-zinc-200/90 bg-white px-2.5 font-mono text-xs text-zinc-800 tabular-nums focus:border-amber-500 focus:outline-none dark:border-zinc-800/90 dark:bg-zinc-900 dark:text-zinc-200"
                            @change="onYearChange"
                        >
                            <option
                                v-for="yr in modules.pace.bestEfforts
                                    .availableYears"
                                :key="yr"
                                :value="yr"
                            >
                                {{ yr }}
                            </option>
                        </select>

                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            role="tablist"
                            aria-label="Best efforts ranking mode"
                        >
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="effortsTab === 'allTime'"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    effortsTab === 'allTime'
                                        ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="effortsTab = 'allTime'"
                            >
                                All-Time Top 3
                            </button>
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="effortsTab === 'annual'"
                                class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    effortsTab === 'annual'
                                        ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="effortsTab = 'annual'"
                            >
                                Annual Top 10
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Distance Tabs Strip -->
                <div
                    class="flex gap-1.5 overflow-x-auto border-b border-zinc-200/70 bg-[#f7f7f5]/70 px-5 py-2.5 dark:border-zinc-800/70 dark:bg-zinc-900/40"
                    role="tablist"
                    aria-label="Best effort distances"
                >
                    <button
                        v-for="dist in modules.pace.bestEfforts.distances"
                        :key="dist.key"
                        type="button"
                        role="tab"
                        :aria-selected="selectedDistanceKey === dist.key"
                        class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] tabular-nums transition-colors"
                        :class="
                            selectedDistanceKey === dist.key
                                ? 'border-zinc-900 bg-zinc-900 font-semibold text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                : 'border-zinc-200/90 bg-white text-zinc-700 hover:border-zinc-400 dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-300 dark:hover:border-zinc-600'
                        "
                        @click="
                            selectedDistanceKey = dist.key;
                            cancelEditEffort();
                        "
                    >
                        <span>{{ dist.label }}</span>
                        <span
                            v-if="
                                (effortsTab === 'allTime'
                                    ? dist.allTimeTop3[0]
                                    : dist.annualTop10[0]) !== undefined
                            "
                            class="text-[10px] opacity-75"
                        >
                            {{
                                formatDurationClock(
                                    (effortsTab === 'allTime'
                                        ? dist.allTimeTop3[0]
                                        : dist.annualTop10[0]
                                    ).effective_seconds,
                                )
                            }}
                        </span>
                    </button>
                </div>

                <!-- Selected Distance Detail Panel -->
                <div v-if="selectedDistanceBoard" class="p-5">
                    <div class="grid gap-5 lg:grid-cols-12">
                        <!-- PR Progression Chart -->
                        <div
                            class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/50 p-4 lg:col-span-5 dark:border-zinc-800/80 dark:bg-zinc-900/30"
                        >
                            <div
                                class="mb-2 flex items-center justify-between gap-2"
                            >
                                <h3
                                    class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{ selectedDistanceBoard.label }}
                                    Timeline
                                </h3>
                                <span
                                    class="font-mono text-[10px]"
                                    :style="{ color: accentHex }"
                                >
                                    Faster ↑
                                </span>
                            </div>

                            <BaseChart
                                v-if="
                                    selectedDistanceBoard.progression.length > 0
                                "
                                :option="prProgressionOption"
                                :height="220"
                            />
                            <div
                                v-else
                                class="flex h-[220px] items-center justify-center font-mono text-xs text-zinc-400 dark:text-zinc-500"
                            >
                                No progression history yet
                            </div>
                        </div>

                        <!-- Ranked Table & Excluded Efforts -->
                        <div class="space-y-4 lg:col-span-7">
                            <div
                                class="overflow-hidden rounded-xl border border-zinc-200/80 dark:border-zinc-800/80"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200/70 bg-[#f7f7f5]/60 px-4 py-2.5 dark:border-zinc-800/70 dark:bg-zinc-900/40"
                                >
                                    <p
                                        class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                                    >
                                        {{
                                            effortsTab === 'allTime'
                                                ? `${selectedDistanceBoard.label} All-Time Top 3`
                                                : `${selectedDistanceBoard.label} ${modules.pace.bestEfforts.selectedYear} Top 10`
                                        }}
                                    </p>
                                </div>

                                <div
                                    v-if="activeRunningEfforts.length > 0"
                                    class="overflow-x-auto"
                                >
                                    <table
                                        class="w-full text-left text-xs tabular-nums"
                                    >
                                        <thead
                                            class="border-b border-zinc-200/70 bg-[#f7f7f5]/40 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/70 dark:bg-zinc-900/20 dark:text-zinc-400"
                                        >
                                            <tr>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Rank
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Time
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Pace
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Activity
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Date
                                                </th>
                                                <th
                                                    class="px-3 py-2 text-right font-medium"
                                                >
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody
                                            class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70"
                                        >
                                            <tr
                                                v-for="(
                                                    effort, idx
                                                ) in activeRunningEfforts"
                                                :key="effort.id"
                                                class="hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30"
                                            >
                                                <td
                                                    class="px-3 py-2.5 whitespace-nowrap"
                                                >
                                                    <span
                                                        class="inline-flex items-center justify-center rounded border px-1.5 py-0.5 font-mono text-[10px] font-semibold"
                                                        :class="
                                                            medalBadgeClass(
                                                                effort.rank ??
                                                                    idx + 1,
                                                            )
                                                        "
                                                    >
                                                        #{{
                                                            effort.rank ??
                                                            idx + 1
                                                        }}
                                                    </span>
                                                </td>

                                                <td
                                                    class="px-3 py-2.5 whitespace-nowrap"
                                                >
                                                    <div
                                                        v-if="
                                                            editingEffortId ===
                                                            effort.id
                                                        "
                                                        class="space-y-1"
                                                    >
                                                        <div
                                                            class="flex items-center gap-1"
                                                        >
                                                            <input
                                                                v-model="
                                                                    editTimeInput
                                                                "
                                                                type="text"
                                                                placeholder="mm:ss"
                                                                aria-label="Override effort time"
                                                                class="w-22 rounded border border-amber-400 bg-white px-2 py-1 font-mono text-xs text-zinc-900 focus:outline-none dark:border-amber-500/70 dark:bg-zinc-900 dark:text-zinc-100"
                                                                @keydown.enter.prevent="
                                                                    saveEffortOverride(
                                                                        effort.id,
                                                                    )
                                                                "
                                                                @keydown.escape.prevent="
                                                                    cancelEditEffort
                                                                "
                                                            />
                                                            <button
                                                                type="button"
                                                                :disabled="
                                                                    busyEffortId ===
                                                                    effort.id
                                                                "
                                                                title="Save chip time"
                                                                class="inline-flex size-6 cursor-pointer items-center justify-center rounded bg-amber-500 text-white transition-colors hover:bg-amber-600 disabled:opacity-50"
                                                                @click="
                                                                    saveEffortOverride(
                                                                        effort.id,
                                                                    )
                                                                "
                                                            >
                                                                <Check
                                                                    class="size-3.5"
                                                                />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                title="Cancel"
                                                                class="inline-flex size-6 cursor-pointer items-center justify-center rounded border border-zinc-200 bg-white text-zinc-600 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                                                @click="
                                                                    cancelEditEffort
                                                                "
                                                            >
                                                                <X
                                                                    class="size-3.5"
                                                                />
                                                            </button>
                                                        </div>
                                                        <p
                                                            v-if="editTimeError"
                                                            class="font-mono text-[10px] text-rose-600 dark:text-rose-400"
                                                        >
                                                            {{ editTimeError }}
                                                        </p>
                                                    </div>

                                                    <div
                                                        v-else
                                                        class="flex items-center gap-1.5"
                                                    >
                                                        <span
                                                            class="font-mono font-semibold text-zinc-900 dark:text-zinc-100"
                                                        >
                                                            {{
                                                                formatDurationClock(
                                                                    effort.effective_seconds,
                                                                )
                                                            }}
                                                        </span>
                                                        <span
                                                            class="rounded border border-zinc-200/80 px-1 py-0.5 font-mono text-[9px] text-zinc-500 uppercase dark:border-zinc-800 dark:text-zinc-400"
                                                        >
                                                            {{
                                                                formatEffortSource(
                                                                    effort,
                                                                )
                                                            }}
                                                        </span>
                                                    </div>
                                                </td>

                                                <td
                                                    class="px-3 py-2.5 font-mono whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                                                >
                                                    {{
                                                        formatPaceFromSecPerKm(
                                                            effort.pace_seconds_per_km,
                                                        )
                                                    }}
                                                </td>

                                                <td
                                                    class="max-w-44 px-3 py-2.5"
                                                >
                                                    <Link
                                                        :href="
                                                            showActivity.url(
                                                                effort.activity_id,
                                                            )
                                                        "
                                                        class="block truncate font-medium text-zinc-800 transition-colors hover:text-amber-600 dark:text-zinc-200 dark:hover:text-amber-400"
                                                    >
                                                        {{
                                                            effort.activity_name
                                                        }}
                                                    </Link>
                                                </td>

                                                <td
                                                    class="px-3 py-2.5 font-mono whitespace-nowrap text-zinc-500 dark:text-zinc-400"
                                                >
                                                    {{
                                                        formatEffortDate(
                                                            effort.achieved_at,
                                                        )
                                                    }}
                                                </td>

                                                <td
                                                    class="px-3 py-2.5 text-right whitespace-nowrap"
                                                >
                                                    <div
                                                        class="inline-flex items-center gap-1"
                                                    >
                                                        <button
                                                            v-if="
                                                                editingEffortId !==
                                                                effort.id
                                                            "
                                                            type="button"
                                                            :disabled="
                                                                busyEffortId ===
                                                                effort.id
                                                            "
                                                            class="inline-flex cursor-pointer items-center gap-1 rounded border border-zinc-200/90 bg-white px-2 py-1 font-mono text-[10px] text-zinc-700 transition-colors hover:border-zinc-300 hover:text-zinc-950 disabled:opacity-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-700"
                                                            @click="
                                                                startEditEffort(
                                                                    effort,
                                                                )
                                                            "
                                                        >
                                                            <Pencil
                                                                class="size-2.5"
                                                            />
                                                            Edit
                                                        </button>
                                                        <button
                                                            v-if="
                                                                effort.override_seconds !==
                                                                null
                                                            "
                                                            type="button"
                                                            :disabled="
                                                                busyEffortId ===
                                                                effort.id
                                                            "
                                                            title="Reset to measured time"
                                                            class="inline-flex cursor-pointer items-center gap-1 rounded border border-amber-200/90 bg-amber-50/70 px-2 py-1 font-mono text-[10px] text-amber-800 transition-colors hover:border-amber-300 disabled:opacity-50 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
                                                            @click="
                                                                resetEffortOverride(
                                                                    effort.id,
                                                                )
                                                            "
                                                        >
                                                            <RotateCcw
                                                                class="size-2.5"
                                                            />
                                                            Reset
                                                        </button>
                                                        <button
                                                            type="button"
                                                            :disabled="
                                                                busyEffortId ===
                                                                effort.id
                                                            "
                                                            title="Exclude this effort"
                                                            class="inline-flex cursor-pointer items-center gap-1 rounded border border-zinc-200/90 bg-white px-2 py-1 font-mono text-[10px] text-zinc-500 transition-colors hover:border-rose-300 hover:text-rose-600 disabled:opacity-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:border-rose-500/40 dark:hover:text-rose-400"
                                                            @click="
                                                                excludeEffort(
                                                                    effort.id,
                                                                )
                                                            "
                                                        >
                                                            <EyeOff
                                                                class="size-2.5"
                                                            />
                                                            Exclude
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div
                                    v-else
                                    class="px-4 py-8 text-center font-mono text-xs text-zinc-400 dark:text-zinc-500"
                                >
                                    No recorded efforts for
                                    {{ selectedDistanceBoard.label }}
                                    {{
                                        effortsTab === 'annual'
                                            ? `in ${modules.pace.bestEfforts.selectedYear}`
                                            : 'yet'
                                    }}.
                                </div>
                            </div>

                            <!-- Excluded Efforts -->
                            <div
                                v-if="selectedDistanceBoard.excluded.length > 0"
                                class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/30"
                            >
                                <p
                                    class="mb-2 font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Excluded Efforts ({{
                                        selectedDistanceBoard.excluded.length
                                    }})
                                </p>
                                <div class="space-y-1.5">
                                    <div
                                        v-for="excludedEffort in selectedDistanceBoard.excluded"
                                        :key="excludedEffort.id"
                                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200/70 bg-white px-3 py-2 text-xs dark:border-zinc-800/70 dark:bg-[#141417]"
                                    >
                                        <div
                                            class="flex flex-wrap items-center gap-2.5 tabular-nums"
                                        >
                                            <span
                                                class="font-mono font-medium text-zinc-500 line-through dark:text-zinc-400"
                                            >
                                                {{
                                                    formatDurationClock(
                                                        excludedEffort.effective_seconds,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                                            >
                                                {{
                                                    formatEffortDate(
                                                        excludedEffort.achieved_at,
                                                    )
                                                }}
                                            </span>
                                            <Link
                                                :href="
                                                    showActivity.url(
                                                        excludedEffort.activity_id,
                                                    )
                                                "
                                                class="max-w-48 truncate text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white"
                                            >
                                                {{
                                                    excludedEffort.activity_name
                                                }}
                                            </Link>
                                        </div>
                                        <button
                                            type="button"
                                            :disabled="
                                                busyEffortId ===
                                                excludedEffort.id
                                            "
                                            class="inline-flex cursor-pointer items-center gap-1 rounded border border-zinc-200/90 bg-white px-2 py-1 font-mono text-[10px] font-medium text-zinc-700 transition-colors hover:border-emerald-300 hover:text-emerald-700 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:border-emerald-500/40 dark:hover:text-emerald-400"
                                            @click="
                                                restoreEffort(excludedEffort.id)
                                            "
                                        >
                                            <Undo2 class="size-2.5" />
                                            Restore
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Riegel Race Predictor + VO2 Max Trend -->
            <section
                v-if="
                    modules.pace.racePredictor !== null ||
                    modules.pace.vo2MaxTrend.length > 0
                "
                class="grid gap-4 lg:grid-cols-12"
            >
                <!-- Riegel Race Predictor -->
                <div
                    v-if="modules.pace.racePredictor !== null"
                    class="flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="
                        modules.pace.vo2MaxTrend.length > 0
                            ? 'lg:col-span-5'
                            : 'lg:col-span-12'
                    "
                >
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p
                                    class="font-mono text-[10px] tracking-widest uppercase"
                                    :style="{ color: accentHex }"
                                >
                                    Riegel Equivalence Model
                                </p>
                                <h2
                                    class="mt-0.5 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                                >
                                    Race Predictor
                                </h2>
                            </div>
                            <Trophy
                                class="size-4"
                                :style="{ color: accentHex }"
                            />
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div
                                v-for="prediction in modules.pace.racePredictor
                                    .predictions"
                                :key="prediction.key"
                                class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                            >
                                <p
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    {{ prediction.label }}
                                </p>
                                <p
                                    class="mt-1 font-mono text-xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-zinc-50"
                                >
                                    {{
                                        formatDurationClock(
                                            prediction.predicted_seconds,
                                        )
                                    }}
                                </p>
                                <p
                                    class="mt-1 font-mono text-[11px] tabular-nums"
                                    :style="{ color: accentHex }"
                                >
                                    {{
                                        formatPaceFromSecPerKm(
                                            prediction.pace_seconds_per_km,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <p
                        class="mt-4 border-t border-zinc-100 pt-3 font-mono text-[11px] text-zinc-500 tabular-nums dark:border-zinc-800/80 dark:text-zinc-400"
                    >
                        Projected from
                        {{ modules.pace.racePredictor.source_label }} ({{
                            formatDurationClock(
                                modules.pace.racePredictor.source_time_seconds,
                            )
                        }}) on
                        {{
                            formatEffortDate(
                                modules.pace.racePredictor.source_date,
                            )
                        }}
                    </p>
                </div>

                <!-- VO2 Max Trend -->
                <div
                    v-if="modules.pace.vo2MaxTrend.length > 0"
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="
                        modules.pace.racePredictor !== null
                            ? 'lg:col-span-7'
                            : 'lg:col-span-12'
                    "
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            VO2 Max Trend
                        </h2>

                        <div
                            v-if="summary.latest_vo2_max !== null"
                            class="flex items-baseline gap-2"
                        >
                            <span
                                class="font-mono text-lg font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                            >
                                {{ formatNumber(summary.latest_vo2_max, 1) }}
                            </span>
                            <span
                                v-if="vo2Delta !== null && vo2Delta !== 0"
                                class="rounded px-1.5 py-0.5 font-mono text-[10px] font-medium tabular-nums"
                                :class="
                                    vo2Delta > 0
                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400'
                                        : 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400'
                                "
                            >
                                {{ vo2Delta > 0 ? '+' : '' }}{{ vo2Delta }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <BaseChart :option="vo2TrendOption" :height="230" />
                    </div>
                </div>
            </section>

            <!-- GAP vs Raw Aerobic Efficiency Scatter + Cadence & Stride Length -->
            <section
                v-if="
                    modules.pace.efficiency.length > 0 ||
                    modules.pace.cadenceStride.length > 0
                "
                class="grid gap-4 lg:grid-cols-12"
            >
                <!-- GAP vs Raw Aerobic Efficiency Scatter -->
                <div
                    v-if="modules.pace.efficiency.length > 0"
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="
                        modules.pace.cadenceStride.length > 0
                            ? 'lg:col-span-6'
                            : 'lg:col-span-12'
                    "
                >
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p
                                class="font-mono text-[10px] tracking-widest text-emerald-600 uppercase dark:text-emerald-400"
                            >
                                Grade-Adjusted vs Raw Pace
                            </p>
                            <h2
                                class="mt-0.5 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                            >
                                Aerobic Efficiency
                            </h2>
                        </div>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            Faster ↑
                        </span>
                    </div>

                    <div class="mt-3">
                        <BaseChart
                            :option="gapScatterOption"
                            :height="250"
                            @point-click="onEfficiencyScatterClick"
                        />
                    </div>
                </div>

                <!-- Cadence & Stride Length Trend -->
                <div
                    v-if="modules.pace.cadenceStride.length > 0"
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="
                        modules.pace.efficiency.length > 0
                            ? 'lg:col-span-6'
                            : 'lg:col-span-12'
                    "
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            Cadence &amp; Stride Length
                        </h2>
                        <span
                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            spm · {{ units.strideUnit.value }}
                        </span>
                    </div>

                    <div class="mt-3">
                        <BaseChart
                            :option="cadenceStrideOption"
                            :height="250"
                        />
                    </div>
                </div>
            </section>

            <!-- Run / Walk / Idle Proportion Bar -->
            <section
                v-if="modules.pace.runWalkBreakdown !== null"
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2
                        class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        Run / Walk / Idle Breakdown
                    </h2>
                    <span
                        class="font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        {{
                            formatDurationHuman(
                                modules.pace.runWalkBreakdown.total_seconds,
                            )
                        }}
                        tracked
                    </span>
                </div>

                <div
                    class="flex h-3 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"
                >
                    <div
                        v-if="modules.pace.runWalkBreakdown.run_pct > 0"
                        class="h-full bg-amber-500 transition-all"
                        :style="{
                            width: `${modules.pace.runWalkBreakdown.run_pct}%`,
                        }"
                    />
                    <div
                        v-if="modules.pace.runWalkBreakdown.walk_pct > 0"
                        class="h-full bg-sky-500 transition-all"
                        :style="{
                            width: `${modules.pace.runWalkBreakdown.walk_pct}%`,
                        }"
                    />
                    <div
                        v-if="modules.pace.runWalkBreakdown.idle_pct > 0"
                        class="h-full bg-zinc-400 transition-all dark:bg-zinc-600"
                        :style="{
                            width: `${modules.pace.runWalkBreakdown.idle_pct}%`,
                        }"
                    />
                </div>

                <div class="mt-3 grid grid-cols-3 gap-3 tabular-nums">
                    <div
                        class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/70 px-3 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                    >
                        <div
                            class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <span class="size-1.5 rounded-full bg-amber-500" />
                            Run
                        </div>
                        <div
                            class="mt-0.5 flex items-baseline justify-between gap-2"
                        >
                            <span
                                class="font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatNumber(
                                        modules.pace.runWalkBreakdown.run_pct,
                                        1,
                                    )
                                }}%
                            </span>
                            <span
                                class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    formatDurationHuman(
                                        modules.pace.runWalkBreakdown
                                            .run_seconds,
                                    )
                                }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/70 px-3 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                    >
                        <div
                            class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <span class="size-1.5 rounded-full bg-sky-500" />
                            Walk
                        </div>
                        <div
                            class="mt-0.5 flex items-baseline justify-between gap-2"
                        >
                            <span
                                class="font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatNumber(
                                        modules.pace.runWalkBreakdown.walk_pct,
                                        1,
                                    )
                                }}%
                            </span>
                            <span
                                class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    formatDurationHuman(
                                        modules.pace.runWalkBreakdown
                                            .walk_seconds,
                                    )
                                }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/70 px-3 py-2 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                    >
                        <div
                            class="flex items-center gap-1.5 font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <span
                                class="size-1.5 rounded-full bg-zinc-400 dark:bg-zinc-500"
                            />
                            Idle
                        </div>
                        <div
                            class="mt-0.5 flex items-baseline justify-between gap-2"
                        >
                            <span
                                class="font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    formatNumber(
                                        modules.pace.runWalkBreakdown.idle_pct,
                                        1,
                                    )
                                }}%
                            </span>
                            <span
                                class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{
                                    formatDurationHuman(
                                        modules.pace.runWalkBreakdown
                                            .idle_seconds,
                                    )
                                }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </template>

        <!-- ========================================================= -->
        <!-- MODULE 2: SPEED & POWER (modules.power !== null)          -->
        <!-- ========================================================= -->
        <template v-if="modules.power !== null">
            <!-- Power KPIs Strip -->
            <section class="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
                <!-- FTP & W/kg -->
                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span class="absolute top-0 left-0 h-0.5 w-10 bg-sky-500" />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        FTP &amp; W/kg
                    </p>
                    <div class="mt-1.5 flex items-baseline gap-2 tabular-nums">
                        <span
                            class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                modules.power.ftp !== null
                                    ? `${Math.round(modules.power.ftp)} W`
                                    : '—'
                            }}
                        </span>
                        <span
                            v-if="modules.power.w_per_kg !== null"
                            class="font-mono text-xs font-medium text-sky-600 dark:text-sky-400"
                        >
                            {{ modules.power.w_per_kg.toFixed(2) }} W/kg
                        </span>
                    </div>
                    <p
                        class="mt-1 font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{
                            modules.power.weight_kg !== null
                                ? `Ref weight ${units.formatWeight(modules.power.weight_kg)}`
                                : 'No weight synced'
                        }}
                    </p>
                </div>

                <!-- CTL / ATL / TSB Form -->
                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span class="absolute top-0 left-0 h-0.5 w-10 bg-sky-500" />
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Fitness / Form
                        </span>
                        <span
                            class="font-mono text-[10px] font-medium"
                            :class="powerFormMeta.className"
                        >
                            {{ powerFormMeta.label }}
                        </span>
                    </div>
                    <div class="mt-1.5 flex items-baseline gap-2 tabular-nums">
                        <span
                            class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                        >
                            {{ formatNumber(modules.power.latest_ctl, 0) }}
                        </span>
                        <span
                            class="font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            CTL
                        </span>
                    </div>
                    <div
                        class="mt-1 flex items-center gap-2 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        <span>
                            ATL {{ formatNumber(modules.power.latest_atl, 0) }}
                        </span>
                        <span>·</span>
                        <span :class="powerFormMeta.className">
                            TSB {{ modules.power.latest_tsb > 0 ? '+' : ''
                            }}{{ formatNumber(modules.power.latest_tsb, 0) }}
                        </span>
                    </div>
                </div>

                <!-- Avg NP / IF / VI -->
                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-indigo-500"
                    />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Avg NP / IF / VI
                    </p>
                    <p
                        class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{
                            modules.power.avg_np !== null
                                ? `${Math.round(modules.power.avg_np)} W`
                                : '—'
                        }}
                    </p>
                    <p
                        class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        IF
                        {{
                            modules.power.avg_if !== null
                                ? modules.power.avg_if.toFixed(2)
                                : '—'
                        }}
                        · VI
                        {{
                            modules.power.avg_vi !== null
                                ? modules.power.avg_vi.toFixed(2)
                                : '—'
                        }}
                    </p>
                </div>

                <!-- L-R Balance -->
                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-indigo-500"
                    />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        L–R Power Balance
                    </p>
                    <p
                        class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{
                            formatLeftRightBalance(
                                modules.power.avg_left_balance,
                            )
                        }}
                    </p>
                </div>
            </section>

            <!-- Mean Maximal Power Curve -->
            <section
                v-if="hasPowerCurve"
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex items-center gap-2">
                        <Zap class="size-3.5 text-sky-500" />
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Mean Maximal Power Curve
                        </span>
                    </div>

                    <div
                        class="inline-flex self-start rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 sm:self-auto dark:border-zinc-800 dark:bg-zinc-900/60"
                    >
                        <button
                            type="button"
                            class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                            :class="
                                powerUnit === 'watts'
                                    ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                    : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="powerUnit = 'watts'"
                        >
                            Watts
                        </button>
                        <button
                            type="button"
                            :disabled="!canShowWkg"
                            :title="
                                canShowWkg
                                    ? `Normalized by ${units.formatWeight(modules.power.weight_kg)}`
                                    : 'Requires a synced weight measurement'
                            "
                            class="rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                            :class="
                                powerUnit === 'wkg' && canShowWkg
                                    ? 'cursor-pointer bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                    : 'cursor-pointer text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="canShowWkg && (powerUnit = 'wkg')"
                        >
                            W/kg
                        </button>
                    </div>
                </div>

                <BaseChart :option="powerCurveChartOption" :height="285" />

                <div
                    v-if="powerBenchmarks.length > 0"
                    class="mt-4 grid grid-cols-2 gap-2.5 border-t border-zinc-100 pt-4 sm:grid-cols-5 dark:border-zinc-800/80"
                >
                    <div
                        v-for="point in powerBenchmarks"
                        :key="point.seconds"
                        class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 px-3 py-2 tabular-nums dark:border-zinc-800/80 dark:bg-zinc-900/40"
                    >
                        <div
                            class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            {{ point.label }} Peak
                        </div>
                        <div
                            class="mt-0.5 text-base font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            {{
                                formatPowerPointVal(
                                    pickPowerCurveVal(point, 'allTime'),
                                )
                            }}
                        </div>
                        <div
                            class="mt-0.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            90d:
                            {{
                                formatPowerPointVal(
                                    pickPowerCurveVal(point, 'last90d'),
                                )
                            }}
                        </div>
                    </div>
                </div>
            </section>

            <!-- 180-Day Fitness Load (CTL / ATL / TSB) -->
            <section
                v-if="modules.power.fitnessSeries.length > 0"
                class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        180-Day Fitness, Fatigue &amp; Form (CTL / ATL / TSB)
                    </span>
                    <div
                        class="flex flex-wrap items-center gap-3 font-mono text-xs tabular-nums"
                    >
                        <span class="text-sky-600 dark:text-sky-400">
                            CTL {{ formatNumber(modules.power.latest_ctl, 1) }}
                        </span>
                        <span class="text-amber-600 dark:text-amber-400">
                            ATL {{ formatNumber(modules.power.latest_atl, 1) }}
                        </span>
                        <span :class="powerFormMeta.className">
                            TSB {{ modules.power.latest_tsb > 0 ? '+' : ''
                            }}{{ formatNumber(modules.power.latest_tsb, 1) }}
                        </span>
                    </div>
                </div>

                <BaseChart :option="fitnessLoadChartOption" :height="265" />
            </section>

            <!-- Weekly TSS & FTP History -->
            <section class="grid gap-4 lg:grid-cols-2">
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Weekly TSS &amp; Distance
                        </span>
                        <span
                            class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            {{ modules.power.weeklyTss.length }} weeks
                        </span>
                    </div>
                    <BaseChart
                        v-if="modules.power.weeklyTss.length > 0"
                        :option="weeklyTssChartOption"
                        :height="240"
                    />
                    <div
                        v-else
                        class="flex h-[240px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                    >
                        No weekly TSS data recorded yet.
                    </div>
                </div>

                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            FTP &amp; 20-Min Power History
                        </span>
                        <span
                            v-if="modules.power.ftp !== null"
                            class="font-mono text-[11px] text-sky-600 tabular-nums dark:text-sky-400"
                        >
                            Current {{ Math.round(modules.power.ftp) }} W
                        </span>
                    </div>
                    <BaseChart
                        v-if="hasFtpHistory"
                        :option="ftpHistoryChartOption"
                        :height="240"
                    />
                    <div
                        v-else
                        class="flex h-[240px] items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                    >
                        No FTP or 20-minute power history recorded yet.
                    </div>
                </div>
            </section>

            <!-- 7-Zone Power Distribution + Cycling Distance Best Efforts -->
            <section
                v-if="hasPowerZones || modules.power.bestEfforts !== null"
                class="grid gap-4 lg:grid-cols-12"
            >
                <!-- 7-Zone Power Distribution -->
                <div
                    v-if="hasPowerZones"
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="
                        modules.power.bestEfforts !== null
                            ? 'lg:col-span-5'
                            : 'lg:col-span-12'
                    "
                >
                    <div class="mb-4 flex items-center justify-between">
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Power Zones Distribution
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                        >
                            Z1 – Z7
                        </span>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="zone in modules.power.powerZones"
                            :key="zone.zone"
                            class="space-y-1"
                        >
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
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span
                                        class="text-zinc-700 dark:text-zinc-300"
                                    >
                                        {{ formatDurationHuman(zone.seconds) }}
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
                                    :class="powerZoneBarClass(zone.zone)"
                                    :style="{ width: `${clampPct(zone.pct)}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cycling Distance Best Efforts -->
                <div
                    v-if="modules.power.bestEfforts !== null"
                    class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="hasPowerZones ? 'lg:col-span-7' : 'lg:col-span-12'"
                >
                    <div
                        class="flex items-center justify-between border-b border-zinc-200/80 px-5 py-3.5 dark:border-zinc-800/80"
                    >
                        <div class="flex items-center gap-2">
                            <Trophy class="size-3.5 text-sky-500" />
                            <span
                                class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Cycling Best Efforts
                            </span>
                        </div>
                        <span
                            class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                        >
                            5K → 100K &amp; 40K TT
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm tabular-nums">
                            <thead
                                class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                            >
                                <tr>
                                    <th class="px-4 py-2.5 font-medium">
                                        Distance
                                    </th>
                                    <th
                                        class="px-3 py-2.5 text-right font-medium"
                                    >
                                        Best Time
                                    </th>
                                    <th
                                        class="px-3 py-2.5 text-right font-medium"
                                    >
                                        Avg Speed
                                    </th>
                                    <th class="px-3 py-2.5 font-medium">
                                        Ride
                                    </th>
                                    <th
                                        class="px-4 py-2.5 text-right font-medium"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 dark:divide-zinc-800/80"
                            >
                                <tr
                                    v-for="effort in modules.power.bestEfforts"
                                    :key="effort.key"
                                    class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                                >
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex items-center gap-1.5">
                                            <span
                                                class="font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100"
                                            >
                                                {{ effort.label }}
                                            </span>
                                            <span
                                                v-if="effort.key === '40k'"
                                                class="rounded bg-sky-500/12 px-1.5 py-0.5 font-mono text-[9px] font-semibold tracking-wider text-sky-600 uppercase dark:text-sky-400"
                                            >
                                                TT
                                            </span>
                                        </div>
                                    </td>

                                    <td
                                        class="px-3 py-3 text-right font-mono text-xs font-semibold whitespace-nowrap text-zinc-900 dark:text-zinc-100"
                                    >
                                        <div
                                            v-if="
                                                effort.best &&
                                                editingEffortId ===
                                                    effort.best.id
                                            "
                                            class="inline-flex flex-col items-end gap-1"
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                <input
                                                    v-model="editTimeInput"
                                                    type="text"
                                                    placeholder="hh:mm:ss"
                                                    aria-label="Override effort time"
                                                    class="w-22 rounded border border-sky-400 bg-white px-2 py-1 font-mono text-xs text-zinc-900 focus:outline-none dark:border-sky-500/70 dark:bg-zinc-900 dark:text-zinc-100"
                                                    @keydown.enter.prevent="
                                                        saveEffortOverride(
                                                            effort.best.id,
                                                        )
                                                    "
                                                    @keydown.escape.prevent="
                                                        cancelEditEffort
                                                    "
                                                />
                                                <button
                                                    type="button"
                                                    :disabled="
                                                        busyEffortId ===
                                                        effort.best.id
                                                    "
                                                    title="Save time"
                                                    class="inline-flex size-6 cursor-pointer items-center justify-center rounded bg-sky-500 text-white hover:bg-sky-600 disabled:opacity-50"
                                                    @click="
                                                        saveEffortOverride(
                                                            effort.best.id,
                                                        )
                                                    "
                                                >
                                                    <Check class="size-3.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    title="Cancel"
                                                    class="inline-flex size-6 cursor-pointer items-center justify-center rounded border border-zinc-200 bg-white text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                                    @click="cancelEditEffort"
                                                >
                                                    <X class="size-3.5" />
                                                </button>
                                            </div>
                                            <p
                                                v-if="editTimeError"
                                                class="font-mono text-[10px] text-rose-600 dark:text-rose-400"
                                            >
                                                {{ editTimeError }}
                                            </p>
                                        </div>
                                        <span v-else>
                                            {{
                                                effort.best
                                                    ? formatDurationClock(
                                                          effort.best
                                                              .effective_seconds,
                                                      )
                                                    : '—'
                                            }}
                                        </span>
                                    </td>

                                    <td
                                        class="px-3 py-3 text-right font-mono text-xs whitespace-nowrap text-sky-600 dark:text-sky-400"
                                    >
                                        {{
                                            effort.best
                                                ? units.formatSpeed(
                                                      effort.best.avg_speed,
                                                  )
                                                : '—'
                                        }}
                                    </td>

                                    <td class="max-w-48 px-3 py-3">
                                        <Link
                                            v-if="effort.best"
                                            :href="
                                                showActivity.url(
                                                    effort.best.activity_id,
                                                )
                                            "
                                            class="block truncate text-xs font-medium text-zinc-800 hover:text-sky-600 dark:text-zinc-200 dark:hover:text-sky-400"
                                        >
                                            {{ effort.best.activity_name }}
                                        </Link>
                                        <div
                                            v-if="effort.best"
                                            class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                        >
                                            {{
                                                formatShortDate(
                                                    effort.best.achieved_at,
                                                )
                                            }}
                                        </div>
                                        <span
                                            v-else
                                            class="font-mono text-xs text-zinc-400 dark:text-zinc-500"
                                        >
                                            No benchmark yet
                                        </span>
                                    </td>

                                    <td
                                        class="px-4 py-3 text-right whitespace-nowrap"
                                    >
                                        <div
                                            v-if="effort.best"
                                            class="inline-flex items-center gap-1"
                                        >
                                            <button
                                                v-if="
                                                    editingEffortId !==
                                                    effort.best.id
                                                "
                                                type="button"
                                                :disabled="
                                                    busyEffortId ===
                                                    effort.best.id
                                                "
                                                class="inline-flex cursor-pointer items-center gap-1 rounded border border-zinc-200/90 bg-white px-2 py-1 font-mono text-[10px] text-zinc-700 hover:border-zinc-300 disabled:opacity-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300"
                                                @click="
                                                    startEditEffort(effort.best)
                                                "
                                            >
                                                <Pencil class="size-2.5" />
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="
                                                    busyEffortId ===
                                                    effort.best.id
                                                "
                                                title="Exclude this effort"
                                                class="inline-flex cursor-pointer items-center gap-1 rounded border border-zinc-200/90 bg-white px-2 py-1 font-mono text-[10px] text-zinc-500 hover:border-rose-300 hover:text-rose-600 disabled:opacity-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:border-rose-500/40"
                                                @click="
                                                    excludeEffort(
                                                        effort.best.id,
                                                    )
                                                "
                                            >
                                                <EyeOff class="size-2.5" />
                                                Exclude
                                            </button>
                                        </div>
                                        <span
                                            v-else
                                            class="font-mono text-xs text-zinc-400 dark:text-zinc-600"
                                        >
                                            —
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </template>

        <!-- ========================================================= -->
        <!-- MODULE 3: CLIMBING & REGIONS (modules.climbing !== null)  -->
        <!-- ========================================================= -->
        <section v-if="modules.climbing !== null" class="space-y-4">
            <!-- Climbing KPIs Strip -->
            <div class="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-emerald-500"
                    />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Total Gain / Loss
                    </p>
                    <p
                        class="mt-1.5 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        +{{
                            units.formatElevation(
                                modules.climbing.total_elevation_gain,
                            )
                        }}
                    </p>
                    <p
                        class="mt-1 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        ↓
                        {{
                            units.formatElevation(
                                modules.climbing.total_elevation_loss,
                            )
                        }}
                        descended
                    </p>
                </div>

                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-emerald-500"
                    />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Avg Climb Density
                    </p>
                    <p
                        class="mt-1.5 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{
                            units.formatClimbDensity(
                                modules.climbing.avg_climb_density_m_per_km,
                            )
                        }}
                    </p>
                </div>

                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-emerald-500"
                    />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Avg VAM
                    </p>
                    <p
                        class="mt-1.5 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatVam(modules.climbing.avg_vam) }}
                    </p>
                </div>

                <div
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10 bg-emerald-500"
                    />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Peak VAM
                    </p>
                    <p
                        class="mt-1.5 text-xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatVam(modules.climbing.max_vam) }}
                    </p>
                </div>
            </div>

            <!-- Trail / Route Regions Breakdown + Stamina & Feel/RPE -->
            <div class="grid items-start gap-4 lg:grid-cols-12">
                <!-- Regions Table -->
                <div
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="
                        hasStaminaData || hasFeelData
                            ? 'lg:col-span-7'
                            : 'lg:col-span-12'
                    "
                >
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1.5">
                            <MapPin class="size-3.5 text-emerald-500" />
                            <span
                                class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Trail &amp; Route Regions
                            </span>
                        </div>
                        <span
                            class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            {{ modules.climbing.regions.length }}
                            {{
                                modules.climbing.regions.length === 1
                                    ? 'region'
                                    : 'regions'
                            }}
                        </span>
                    </div>

                    <div
                        v-if="modules.climbing.regions.length > 0"
                        class="space-y-3.5"
                        :class="
                            hasStaminaData || hasFeelData
                                ? 'max-h-60 overflow-y-auto pr-1.5'
                                : 'max-h-96 overflow-y-auto pr-1.5'
                        "
                    >
                        <div
                            v-for="region in modules.climbing.regions"
                            :key="region.name"
                            class="space-y-1.5"
                        >
                            <div
                                class="flex items-baseline justify-between gap-2 text-xs tabular-nums"
                            >
                                <span
                                    class="truncate font-medium text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ region.name }}
                                </span>
                                <span
                                    class="shrink-0 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    +{{
                                        units.formatElevation(
                                            region.elevation_gain,
                                        )
                                    }}
                                    · {{ Math.round(region.pct) }}%
                                </span>
                            </div>
                            <div
                                class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800/80"
                            >
                                <div
                                    class="h-full rounded-full bg-emerald-500 transition-all"
                                    :style="{
                                        width: `${clampPct(region.pct)}%`,
                                    }"
                                />
                            </div>
                            <div
                                class="flex flex-wrap items-center justify-between gap-2 font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                            >
                                <span>
                                    {{ formatNumber(region.sessions, 0) }}
                                    {{
                                        region.sessions === 1
                                            ? sessionNoun.singular
                                            : sessionNoun.plural
                                    }}
                                    ·
                                    {{
                                        units.formatDistanceCompact(
                                            region.distance_meters,
                                            0,
                                        )
                                    }}
                                </span>
                                <span v-if="region.avg_grit !== null">
                                    Avg Grit
                                    {{ formatNumber(region.avg_grit, 1) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="flex h-36 items-center justify-center rounded-lg border border-dashed border-zinc-200/80 font-mono text-xs text-zinc-400 dark:border-zinc-800/80 dark:text-zinc-500"
                    >
                        No route locations recorded yet.
                    </div>
                </div>

                <!-- Stamina & Feel / RPE Summary -->
                <div
                    v-if="hasStaminaData || hasFeelData"
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 lg:col-span-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-1.5">
                            <Mountain class="size-3.5 text-emerald-500" />
                            <span
                                class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Stamina &amp; Feel / RPE
                            </span>
                        </div>
                        <span
                            class="font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            {{ modules.climbing.stamina.count }} stamina ·
                            {{ modules.climbing.feelRpe.count }} feel
                        </span>
                    </div>

                    <div class="space-y-4">
                        <div
                            v-if="hasStaminaData"
                            class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-4 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                        >
                            <div
                                class="flex items-center justify-between font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                <span>Average Stamina Profile</span>
                                <span
                                    v-if="
                                        modules.climbing.stamina.avg_drop !==
                                        null
                                    "
                                    class="font-semibold text-amber-600 tabular-nums dark:text-amber-400"
                                >
                                    -{{
                                        Math.round(
                                            modules.climbing.stamina.avg_drop,
                                        )
                                    }}% avg drain
                                </span>
                            </div>

                            <div
                                class="mt-2.5 flex items-baseline justify-between tabular-nums"
                            >
                                <div>
                                    <span
                                        class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                                    >
                                        {{
                                            modules.climbing.stamina
                                                .avg_begin !== null
                                                ? `${Math.round(modules.climbing.stamina.avg_begin)}%`
                                                : '—'
                                        }}
                                    </span>
                                    <span
                                        class="ml-1.5 font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                                    >
                                        start
                                    </span>
                                </div>
                                <span
                                    class="font-mono text-xs text-zinc-400 dark:text-zinc-500"
                                >
                                    →
                                </span>
                                <div class="text-right">
                                    <span
                                        class="text-2xl font-semibold tracking-tight text-emerald-600 dark:text-emerald-400"
                                    >
                                        {{
                                            modules.climbing.stamina.avg_end !==
                                            null
                                                ? `${Math.round(modules.climbing.stamina.avg_end)}%`
                                                : '—'
                                        }}
                                    </span>
                                    <span
                                        class="ml-1.5 font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                                    >
                                        finish
                                    </span>
                                </div>
                            </div>

                            <div
                                class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-zinc-200/80 dark:bg-zinc-800"
                            >
                                <div
                                    class="h-full rounded-full bg-emerald-500 transition-all"
                                    :style="{
                                        width: `${clampPct(modules.climbing.stamina.avg_end)}%`,
                                    }"
                                />
                            </div>
                        </div>

                        <div
                            v-if="hasFeelData"
                            class="grid grid-cols-2 gap-3 tabular-nums"
                        >
                            <div
                                class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                            >
                                <p
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Avg Feel
                                </p>
                                <p
                                    class="mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        modules.climbing.feelRpe.avg_feel !==
                                        null
                                            ? Math.round(
                                                  modules.climbing.feelRpe
                                                      .avg_feel,
                                              )
                                            : '—'
                                    }}
                                </p>
                                <p
                                    class="mt-0.5 font-mono text-[10px] text-emerald-600 dark:text-emerald-400"
                                >
                                    {{
                                        feelLabel(
                                            modules.climbing.feelRpe.avg_feel,
                                        )
                                    }}
                                </p>
                            </div>

                            <div
                                class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 p-3.5 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                            >
                                <p
                                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    Avg RPE
                                </p>
                                <p
                                    class="mt-1 text-xl font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        modules.climbing.feelRpe.avg_rpe !==
                                        null
                                            ? `${formatNumber(modules.climbing.feelRpe.avg_rpe, 1)} / 10`
                                            : '—'
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================================= -->
        <!-- MODULE 4: OFF-ROAD DYNAMICS (modules.offroad !== null)    -->
        <!-- ========================================================= -->
        <section
            v-if="modules.offroad !== null && hasOffroadModuleContent"
            class="space-y-4"
        >
            <!-- Off-Road KPIs -->
            <div
                v-if="offroadKpiCards.length > 0"
                class="grid gap-3.5"
                :class="offroadKpiGridClass"
            >
                <div
                    v-for="card in offroadKpiCards"
                    :key="card.key"
                    class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
                >
                    <span
                        class="absolute top-0 left-0 h-0.5 w-10"
                        :class="card.barClass"
                    />
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{ card.label }}
                    </p>
                    <p
                        class="mt-1.5 text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ card.value }}
                    </p>
                    <p
                        v-if="card.subtitle"
                        class="mt-1 font-mono text-[11px] text-zinc-400 dark:text-zinc-500"
                    >
                        {{ card.subtitle }}
                    </p>
                </div>
            </div>

            <!-- Grit & Flow Trend + Tabbed Leaderboard -->
            <div
                v-if="hasDynamicsTrend || availableOffroadTabs.length > 0"
                class="grid gap-4 lg:grid-cols-12"
            >
                <!-- Grit & Flow Trend Chart -->
                <div
                    v-if="hasDynamicsTrend"
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="
                        availableOffroadTabs.length > 0
                            ? 'lg:col-span-7'
                            : 'lg:col-span-12'
                    "
                >
                    <div
                        class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <span
                            class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Off-Road Dynamics · Grit &amp; Flow Trend
                        </span>
                        <span
                            class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                        >
                            {{ modules.offroad.dynamicsTrend.length }} sessions
                        </span>
                    </div>

                    <BaseChart
                        :option="dynamicsTrendChartOption"
                        :height="270"
                        @point-click="onDynamicsPointClick"
                    />
                </div>

                <!-- Tabbed Off-Road Leaderboards -->
                <div
                    v-if="availableOffroadTabs.length > 0"
                    class="rounded-xl border border-zinc-200/90 bg-white p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
                    :class="
                        hasDynamicsTrend ? 'lg:col-span-5' : 'lg:col-span-12'
                    "
                >
                    <div
                        class="mb-3.5 flex flex-wrap items-center justify-between gap-2"
                    >
                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            role="tablist"
                            aria-label="Off-road leaderboards"
                        >
                            <button
                                v-for="tab in availableOffroadTabs"
                                :key="tab.key"
                                type="button"
                                role="tab"
                                :aria-selected="offroadTab === tab.key"
                                class="inline-flex cursor-pointer items-center gap-1 rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    offroadTab === tab.key
                                        ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="offroadTab = tab.key"
                            >
                                <Flame
                                    v-if="tab.key === 'hardest'"
                                    class="size-3 text-emerald-500"
                                />
                                <Sparkles
                                    v-else-if="tab.key === 'smoothest'"
                                    class="size-3 text-sky-500"
                                />
                                <Trophy v-else class="size-3 text-amber-500" />
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Hardest Sessions -->
                    <div v-if="offroadTab === 'hardest'" class="space-y-2">
                        <Link
                            v-for="(session, idx) in modules.offroad
                                .hardestSessions"
                            :key="session.id"
                            :href="showActivity.url(session.id)"
                            class="group flex items-center justify-between gap-3 rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/60 px-3 py-2.5 transition-colors hover:border-emerald-500/40 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                        >
                            <div class="flex min-w-0 items-start gap-2.5">
                                <span
                                    class="mt-0.5 font-mono text-[11px] font-semibold text-zinc-400 tabular-nums"
                                >
                                    {{ idx + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-xs font-medium text-zinc-900 group-hover:text-emerald-600 dark:text-zinc-100 dark:group-hover:text-emerald-400"
                                    >
                                        {{ session.name }}
                                    </p>
                                    <p
                                        class="mt-0.5 truncate font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                    >
                                        {{ formatShortDate(session.date) }}
                                        ·
                                        {{
                                            units.formatElevation(
                                                session.elevation_gain,
                                            )
                                        }}
                                        ·
                                        {{
                                            units.formatDistanceCompact(
                                                session.distance_meters,
                                                1,
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <span
                                class="shrink-0 rounded-md bg-emerald-500/12 px-2 py-0.5 font-mono text-xs font-semibold text-emerald-700 tabular-nums dark:text-emerald-300"
                            >
                                {{ formatNumber(session.grit, 1) }}
                            </span>
                        </Link>
                    </div>

                    <!-- Smoothest Sessions -->
                    <div
                        v-else-if="offroadTab === 'smoothest'"
                        class="space-y-2"
                    >
                        <Link
                            v-for="(session, idx) in modules.offroad
                                .smoothestSessions"
                            :key="session.id"
                            :href="showActivity.url(session.id)"
                            class="group flex items-center justify-between gap-3 rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/60 px-3 py-2.5 transition-colors hover:border-sky-500/40 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                        >
                            <div class="flex min-w-0 items-start gap-2.5">
                                <span
                                    class="mt-0.5 font-mono text-[11px] font-semibold text-zinc-400 tabular-nums"
                                >
                                    {{ idx + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-xs font-medium text-zinc-900 group-hover:text-sky-600 dark:text-zinc-100 dark:group-hover:text-sky-400"
                                    >
                                        {{ session.name }}
                                    </p>
                                    <p
                                        class="mt-0.5 truncate font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                    >
                                        {{ formatShortDate(session.date) }}
                                        ·
                                        {{
                                            units.formatElevation(
                                                session.elevation_gain,
                                            )
                                        }}
                                        ·
                                        {{
                                            units.formatDistanceCompact(
                                                session.distance_meters,
                                                1,
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                            <span
                                class="shrink-0 rounded-md bg-sky-500/12 px-2 py-0.5 font-mono text-xs font-semibold text-sky-700 tabular-nums dark:text-sky-300"
                            >
                                {{ formatNumber(session.avg_flow, 1) }}
                            </span>
                        </Link>
                    </div>

                    <!-- Top Jumps Sessions -->
                    <div v-else class="space-y-2">
                        <Link
                            v-for="(session, idx) in modules.offroad
                                .topJumpSessions"
                            :key="session.id"
                            :href="showActivity.url(session.id)"
                            class="group flex items-center justify-between gap-3 rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/60 px-3 py-2.5 transition-colors hover:border-amber-500/40 dark:border-zinc-800/80 dark:bg-zinc-900/40"
                        >
                            <div class="flex min-w-0 items-start gap-2.5">
                                <span
                                    class="mt-0.5 font-mono text-[11px] font-semibold text-zinc-400 tabular-nums"
                                >
                                    {{ idx + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-xs font-medium text-zinc-900 group-hover:text-amber-600 dark:text-zinc-100 dark:group-hover:text-amber-400"
                                    >
                                        {{ session.name }}
                                    </p>
                                    <p
                                        class="mt-0.5 truncate font-mono text-[10px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                    >
                                        {{ formatShortDate(session.date) }}
                                        ·
                                        {{
                                            units.formatElevation(
                                                session.elevation_gain,
                                            )
                                        }}
                                        <template v-if="session.grit !== null">
                                            · Grit
                                            {{ formatNumber(session.grit, 1) }}
                                        </template>
                                    </p>
                                </div>
                            </div>
                            <span
                                class="shrink-0 rounded-md bg-amber-500/12 px-2 py-0.5 font-mono text-xs font-semibold text-amber-700 tabular-nums dark:text-amber-300"
                            >
                                {{ formatNumber(session.jump_count, 0) }}
                            </span>
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================================= -->
        <!-- RECENT ACTIVITIES TABLE                                   -->
        <!-- ========================================================= -->
        <section
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex items-center justify-between gap-3 border-b border-zinc-200/80 px-5 py-4 dark:border-zinc-800/80"
            >
                <h2
                    class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                >
                    Recent {{ sport.label }}
                </h2>

                <Link
                    :href="activitiesIndex.url({ query: { sport: sport.key } })"
                    class="inline-flex items-center gap-1 font-mono text-xs text-zinc-600 transition-colors hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100"
                >
                    <span>All {{ sessionNoun.plural }}</span>
                    <ArrowUpRight class="size-3.5" />
                </Link>
            </div>

            <div v-if="recentActivities.length > 0" class="overflow-x-auto">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead
                        class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                    >
                        <tr>
                            <th
                                class="px-4 py-2.5 font-medium whitespace-nowrap"
                            >
                                Date
                            </th>
                            <th
                                class="px-4 py-2.5 font-medium whitespace-nowrap"
                            >
                                Activity
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Distance
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Duration
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                {{ isPaceSport ? 'Pace' : 'Speed' }}
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Elevation
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Avg HR
                            </th>
                            <th
                                v-if="recentHasPower"
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Avg / NP
                            </th>
                            <th
                                v-if="recentHasOffroad"
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Grit / Flow
                            </th>
                            <th
                                class="px-4 py-2.5 text-right font-medium whitespace-nowrap"
                            >
                                Load
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70"
                    >
                        <tr
                            v-for="item in recentActivities"
                            :key="item.id"
                            class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40"
                        >
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div
                                    class="font-mono text-xs text-zinc-800 dark:text-zinc-200"
                                >
                                    {{ formatShortDate(item.started_at_local) }}
                                </div>
                                <div
                                    class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    {{ formatTimeOfDay(item.started_at_local) }}
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="size-1.5 shrink-0 rounded-full"
                                        :style="{ backgroundColor: accentHex }"
                                    />
                                    <Link
                                        :href="showActivity.url(item.id)"
                                        class="max-w-56 truncate font-medium text-zinc-900 transition-colors hover:text-zinc-600 dark:text-zinc-100 dark:hover:text-zinc-300"
                                    >
                                        {{ item.name }}
                                    </Link>
                                    <span
                                        v-if="item.is_pr"
                                        class="inline-flex items-center gap-0.5 rounded border border-amber-300/80 bg-amber-50 px-1.5 py-0.5 font-mono text-[10px] font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300"
                                    >
                                        <Trophy class="size-2.5" />
                                        PR
                                    </span>
                                    <span
                                        v-if="item.is_indoor"
                                        class="rounded border border-zinc-200/90 bg-[#f7f7f5] px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400"
                                    >
                                        Indoor
                                    </span>
                                </div>
                                <div
                                    v-if="item.location_name"
                                    class="mt-0.5 flex items-center gap-1 pl-3.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    <MapPin class="size-2.5" />
                                    <span class="max-w-44 truncate">
                                        {{ item.location_name }}
                                    </span>
                                </div>
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs font-medium whitespace-nowrap text-zinc-900 dark:text-zinc-100"
                            >
                                {{
                                    units.formatDistance(
                                        item.distance_meters,
                                        2,
                                    )
                                }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                {{ formatDurationClock(item.duration_seconds) }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                {{ formatRate(item.avg_speed) }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{
                                    item.elevation_gain !== null
                                        ? `+${units.formatElevation(item.elevation_gain)}`
                                        : '—'
                                }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{
                                    item.avg_hr !== null
                                        ? `${Math.round(item.avg_hr)} bpm`
                                        : '—'
                                }}
                            </td>

                            <td
                                v-if="recentHasPower"
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                <template
                                    v-if="
                                        item.avg_power !== null ||
                                        item.normalized_power !== null
                                    "
                                >
                                    <span>
                                        {{
                                            item.avg_power !== null
                                                ? `${Math.round(item.avg_power)}W`
                                                : '—'
                                        }}
                                    </span>
                                    <span
                                        class="text-zinc-400 dark:text-zinc-500"
                                    >
                                        /
                                    </span>
                                    <span
                                        class="text-sky-600 dark:text-sky-400"
                                    >
                                        {{
                                            item.normalized_power !== null
                                                ? `${Math.round(item.normalized_power)}W`
                                                : '—'
                                        }}
                                    </span>
                                </template>
                                <span
                                    v-else
                                    class="text-zinc-400 dark:text-zinc-500"
                                >
                                    —
                                </span>
                            </td>

                            <td
                                v-if="recentHasOffroad"
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 dark:text-zinc-300"
                            >
                                <template
                                    v-if="
                                        item.grit !== null ||
                                        item.avg_flow !== null
                                    "
                                >
                                    <span
                                        class="text-emerald-600 dark:text-emerald-400"
                                    >
                                        {{ formatNumber(item.grit, 1) }}
                                    </span>
                                    <span
                                        class="text-zinc-400 dark:text-zinc-500"
                                    >
                                        /
                                    </span>
                                    <span
                                        class="text-sky-600 dark:text-sky-400"
                                    >
                                        {{ formatNumber(item.avg_flow, 1) }}
                                    </span>
                                </template>
                                <span
                                    v-else
                                    class="text-zinc-400 dark:text-zinc-500"
                                >
                                    —
                                </span>
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400"
                            >
                                {{
                                    (item.tss ?? item.training_load) !== null
                                        ? formatNumber(
                                              item.tss ?? item.training_load,
                                              0,
                                          )
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-else
                class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center"
            >
                <Activity class="size-5 text-zinc-400 dark:text-zinc-500" />
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    No {{ sessionNoun.plural }} match the current filter.
                </p>
            </div>
        </section>
    </div>
</template>
