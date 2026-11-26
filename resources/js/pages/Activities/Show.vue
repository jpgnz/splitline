<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronLeft,
    ChevronRight,
    MapPin,
    RefreshCw,
    Star,
    Trophy,
    Wind,
    X,
} from '@lucide/vue';
import type { EChartsOption } from 'echarts';
import { computed, ref, watch } from 'vue';
import {
    syncStreams as syncStreamsAction,
    syncWeather,
    toggleFavorite as toggleFavoriteAction,
    updateDiscipline as updateDisciplineAction,
} from '@/actions/App/Http/Controllers/ActivityController';
import { assignToActivity } from '@/actions/App/Http/Controllers/GearController';
import ActivityAllStats from '@/components/activities/ActivityAllStats.vue';
import ActivityBestEfforts from '@/components/activities/ActivityBestEfforts.vue';
import ActivityEffortContext from '@/components/activities/ActivityEffortContext.vue';
import ActivityMatchedRoute from '@/components/activities/ActivityMatchedRoute.vue';
import ActivityPaceAnalysis from '@/components/activities/ActivityPaceAnalysis.vue';
import ActivityPaceDistribution from '@/components/activities/ActivityPaceDistribution.vue';
import ActivityWeatherCard from '@/components/activities/ActivityWeatherCard.vue';
import BaseChart from '@/components/charts/BaseChart.vue';
import ActivityMap from '@/components/maps/ActivityMap.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatNumber,
    formatTimeOfDay,
} from '@/lib/format';
import { useSports } from '@/lib/sports';
import {
    buildPaceTelemetrySeries,
    buildStartupGatedTelemetrySeries,
    rollingAverage,
} from '@/lib/telemetry';
import {
    computeSplitsFromSeries,
    KM_TO_MI,
    M_TO_FT,
    useUnits,
} from '@/lib/units';
import {
    computeEffectiveWind,
    computeSegmentBearing,
    formatCompass,
    interpolateWeatherAt,
} from '@/lib/wind';
import {
    index as activitiesIndex,
    show as showActivity,
} from '@/routes/activities';
import type {
    ActivityBestEffortItem,
    ActivityDetail,
    ActivityStreamPayload,
    ActivityWeatherPayload,
    EffortContextPayload,
    HighlightRange,
    HrZoneItem,
    JumpEvent,
    KmSplitItem,
    MapColorMode,
    PaceDistributionPayload,
    PowerZoneItem,
    RunWalkSegment,
    SplitItem,
    StreamPoint,
} from '@/types/activity';
import type { ActivityMatchedRoutePayload } from '@/types/routes';

interface AdjacentActivity {
    id: number;
    name: string;
}

interface ActivityGearOption {
    id: number;
    name: string;
    type: string;
    default_sport: string | null;
    is_retired: boolean;
}

interface ActivityGearPayload {
    assigned_id: number | null;
    assigned_name: string | null;
    available: ActivityGearOption[];
}

interface DisciplineOption {
    value: string;
    label: string;
}

interface ActivityShowProps {
    activity: ActivityDetail;
    hrZones: HrZoneItem[];
    powerZones: PowerZoneItem[];
    paceDistribution: PaceDistributionPayload | null;
    effortContext: EffortContextPayload;
    stream: ActivityStreamPayload | null;
    weather: ActivityWeatherPayload | null;
    bestEfforts: ActivityBestEffortItem[];
    prevActivity: AdjacentActivity | null;
    nextActivity: AdjacentActivity | null;
    gear?: ActivityGearPayload;
    matchedRoute?: ActivityMatchedRoutePayload | null;
    discipline_options?: DisciplineOption[];
}

type DetailTab = 'overview' | 'pace' | 'efforts' | 'effort' | 'route' | 'stats';

const props = defineProps<ActivityShowProps>();
const theme = useChartTheme();
const { getSportMeta, resolveActivitySportKey, sportBadgeClasses } =
    useSports();
const {
    units,
    isImperial,
    distanceUnit,
    elevationUnit,
    speedUnit,
    tempUnit,
    strideUnit,
    distanceFromMeters,
    elevationFromMeters,
    speedFromMps,
    paceSecondsFromMps,
    tempFromC,
    formatDistance,
    formatElevation,
    formatFluidVolume,
    formatPace,
    formatPace100m,
    formatPaceSeconds,
    formatSpeed,
    formatSpeedKmh,
    formatStride,
    formatTemp,
} = useUnits();

const activeTab = ref<DetailTab>('overview');
const activeStreamIndex = ref<number | null>(null);
const mapColorMode = ref<MapColorMode>('solid');
const showWindArrows = ref<boolean>(false);
const highlightedRange = ref<HighlightRange | null>(null);
const splitsTab = ref<'splits' | 'laps'>('splits');
const verticalMetricMode = ref<'ratio' | 'oscillation'>('ratio');
const powerUnitMode = ref<'watts' | 'pct'>('watts');
const isSyncingStreams = ref<boolean>(false);
const isSyncingWeather = ref<boolean>(false);
const isTogglingFavorite = ref<boolean>(false);
const isUpdatingDiscipline = ref<boolean>(false);
const isUpdatingGear = ref<boolean>(false);

const selectedGearId = ref<string>(
    props.gear?.assigned_id !== null && props.gear?.assigned_id !== undefined
        ? String(props.gear.assigned_id)
        : '',
);

watch(
    () => props.gear?.assigned_id,
    (assignedId) => {
        selectedGearId.value =
            assignedId !== null && assignedId !== undefined
                ? String(assignedId)
                : '';
    },
);

const selectedDiscipline = ref<string>(
    props.activity.effective_discipline ??
        props.activity.discipline_override ??
        props.activity.sport_discipline ??
        props.activity.biking_discipline ??
        props.discipline_options?.[0]?.value ??
        'other',
);

watch(
    () => [
        props.activity.effective_discipline,
        props.activity.discipline_override,
        props.activity.sport_discipline,
        props.activity.biking_discipline,
    ],
    ([effective, override, sportDisc, bikingDisc]) => {
        selectedDiscipline.value =
            effective ??
            override ??
            sportDisc ??
            bikingDisc ??
            props.discipline_options?.[0]?.value ??
            'other';
    },
);

watch(
    () => props.activity.id,
    () => {
        activeTab.value = 'overview';
        activeStreamIndex.value = null;
        mapColorMode.value = 'solid';
        showWindArrows.value = false;
        highlightedRange.value = null;
        splitsTab.value =
            (props.stream?.km_splits?.length ?? 0) > 0 ? 'splits' : 'laps';
    },
);

const effectiveDiscipline = computed<string | null>(
    () =>
        props.activity.effective_discipline ??
        props.activity.discipline_override ??
        props.activity.sport_discipline ??
        props.activity.biking_discipline ??
        null,
);

const resolvedSportKey = computed<string>(() =>
    resolveActivitySportKey(
        props.activity.sport_category,
        effectiveDiscipline.value,
        props.activity.is_indoor,
    ),
);

const sportEntry = computed(() => getSportMeta(resolvedSportKey.value));

const isRunning = computed<boolean>(
    () => props.activity.sport_category === 'running',
);
const isSwimming = computed<boolean>(
    () =>
        sportEntry.value.metric_mode === 'pace_100m' ||
        props.activity.sport_category === 'swimming',
);
const usesPace = computed<boolean>(
    () =>
        sportEntry.value.metric_mode === 'pace' ||
        props.activity.sport_category === 'running' ||
        props.activity.sport_category === 'walking' ||
        props.activity.sport_category === 'hiking',
);

const sportMeta = computed<{
    label: string;
    badgeClass: string;
    accentHex: string;
    cadenceUnit: string;
}>(() => ({
    label: sportEntry.value.label,
    badgeClass: sportBadgeClasses(sportEntry.value.color_token),
    accentHex: sportEntry.value.accent_hex,
    cadenceUnit:
        sportEntry.value.cadence_unit ?? (isRunning.value ? 'spm' : 'rpm'),
}));

const fullDateFormatter = new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const formattedStartedAt = computed<string>(() => {
    const raw = props.activity.started_at_local;
    const parsed = new Date(raw);
    const time = formatTimeOfDay(raw);

    if (Number.isNaN(parsed.getTime())) {
        return raw;
    }

    return `${fullDateFormatter.format(parsed)} · ${time}`;
});

function formatTrainingEffectLabel(label: string | null): string | null {
    if (!label) {
        return null;
    }

    return label
        .toLowerCase()
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function formatTotalWork(value: number | null): string {
    if (value === null || !Number.isFinite(value) || value <= 0) {
        return '—';
    }

    const kj = value >= 10000 ? value / 1000 : value;

    return `${formatNumber(Math.round(kj), 0)} kJ`;
}

function formatLeftRightBalance(left: number | null): string {
    if (left === null || !Number.isFinite(left)) {
        return '—';
    }

    const right = Math.max(0, 100 - left);

    return `${left.toFixed(1)}% / ${right.toFixed(1)}%`;
}

function formatFeel(feel: number | null): string | null {
    if (feel === null || !Number.isFinite(feel)) {
        return null;
    }

    const labels: Record<number, string> = {
        0: 'Very Weak',
        25: 'Weak',
        50: 'Normal',
        75: 'Strong',
        100: 'Very Strong',
    };

    return labels[feel] ?? `${feel}`;
}

function formatRpe(rpe: number | null): string | null {
    if (rpe === null || !Number.isFinite(rpe)) {
        return null;
    }

    const normalized = rpe > 10 ? rpe / 10 : rpe;

    return `RPE ${formatNumber(normalized, normalized % 1 === 0 ? 0 : 1)}/10`;
}

function formatExecutionRating(score: number): string {
    if (score >= 85) {
        return 'Good';
    }

    if (score >= 65) {
        return 'Fair';
    }

    return 'Low';
}

function toggleFavorite(): void {
    if (isTogglingFavorite.value) {
        return;
    }

    router.post(
        toggleFavoriteAction.url(props.activity.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isTogglingFavorite.value = true;
            },
            onFinish: () => {
                isTogglingFavorite.value = false;
            },
        },
    );
}

function updateDiscipline(): void {
    if (isUpdatingDiscipline.value) {
        return;
    }

    router.patch(
        updateDisciplineAction.url(props.activity.id),
        { discipline_override: selectedDiscipline.value },
        {
            preserveScroll: true,
            onStart: () => {
                isUpdatingDiscipline.value = true;
            },
            onFinish: () => {
                isUpdatingDiscipline.value = false;
            },
        },
    );
}

function updateGear(): void {
    if (isUpdatingGear.value) {
        return;
    }

    const gearItemId =
        selectedGearId.value === '' ? null : Number(selectedGearId.value);

    router.patch(
        assignToActivity.url(props.activity.id),
        { gear_item_id: gearItemId },
        {
            preserveScroll: true,
            onStart: () => {
                isUpdatingGear.value = true;
            },
            onFinish: () => {
                isUpdatingGear.value = false;
            },
        },
    );
}

function syncStreams(): void {
    if (isSyncingStreams.value) {
        return;
    }

    router.post(
        syncStreamsAction.url(props.activity.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isSyncingStreams.value = true;
            },
            onFinish: () => {
                isSyncingStreams.value = false;
            },
        },
    );
}

function syncActivityWeather(): void {
    if (isSyncingWeather.value) {
        return;
    }

    router.post(
        syncWeather.url(props.activity.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isSyncingWeather.value = true;
            },
            onFinish: () => {
                isSyncingWeather.value = false;
            },
        },
    );
}

function formatPrimaryPaceOrSpeed(speedMps: number | null): string {
    if (isSwimming.value) {
        return formatPace100m(speedMps);
    }

    return usesPace.value ? formatPace(speedMps) : formatSpeed(speedMps);
}

// Primary KPIs
const primaryKpis = computed<
    Array<{
        label: string;
        value: string;
        sub: string | null;
        accentClass?: string;
        accentHex?: string;
    }>
>(() => {
    const a = props.activity;
    const movingSec = a.moving_duration_seconds ?? a.duration_seconds;
    const elapsedDiff =
        a.elapsed_duration_seconds !== null &&
        Math.abs(a.elapsed_duration_seconds - movingSec) >= 15
            ? `Elapsed ${formatDurationClock(a.elapsed_duration_seconds)}`
            : null;

    const paceSub =
        usesPace.value && a.avg_grade_adjusted_speed !== null
            ? `GAP ${formatPace(a.avg_grade_adjusted_speed)}`
            : a.max_speed !== null
              ? `Max ${formatPrimaryPaceOrSpeed(a.max_speed)}`
              : null;

    const elevSub =
        a.min_elevation !== null && a.max_elevation !== null
            ? `${Math.round(elevationFromMeters(a.min_elevation))}–${formatElevation(a.max_elevation)}`
            : a.elevation_loss !== null
              ? `-${formatElevation(a.elevation_loss)}`
              : null;

    const calSub =
        a.sweat_loss_ml !== null
            ? `Sweat ${formatFluidVolume(a.sweat_loss_ml)}`
            : null;

    const trimpScore = props.effortContext?.trimp?.activity_score;

    return [
        {
            label: 'Distance',
            value:
                a.distance_meters > 0
                    ? formatDistance(a.distance_meters, 2)
                    : '—',
            sub: null,
            accentHex: sportMeta.value.accentHex,
        },
        {
            label:
                a.moving_duration_seconds !== null ? 'Moving Time' : 'Duration',
            value: formatDurationClock(movingSec),
            sub: elapsedDiff,
            accentClass: 'bg-zinc-400 dark:bg-zinc-500',
        },
        {
            label: isSwimming.value
                ? 'Avg Pace /100m'
                : usesPace.value
                  ? 'Avg Pace'
                  : 'Avg Speed',
            value: formatPrimaryPaceOrSpeed(a.avg_speed),
            sub: paceSub,
            accentClass: usesPace.value ? 'bg-amber-500' : 'bg-sky-500',
        },
        {
            label: 'Elevation Gain',
            value:
                a.elevation_gain !== null
                    ? `+${formatElevation(a.elevation_gain)}`
                    : '—',
            sub: elevSub,
            accentClass: 'bg-emerald-500',
        },
        {
            label: 'Avg HR',
            value: a.avg_hr !== null ? `${a.avg_hr} bpm` : '—',
            sub: a.max_hr !== null ? `Max ${a.max_hr} bpm` : null,
            accentClass: 'bg-rose-500',
        },
        {
            label: 'Calories',
            value:
                a.calories !== null
                    ? `${formatNumber(a.calories, 0)} kcal`
                    : '—',
            sub: calSub,
            accentClass: 'bg-orange-500',
        },
        {
            label: 'HR Effort / Load',
            value:
                trimpScore !== null && trimpScore !== undefined
                    ? `${formatNumber(trimpScore, 0)}`
                    : a.training_load !== null
                      ? formatNumber(a.training_load, 0)
                      : '—',
            sub:
                trimpScore !== null &&
                trimpScore !== undefined &&
                a.training_load !== null
                    ? `Load ${formatNumber(a.training_load, 0)} · ${formatTrainingEffectLabel(a.training_effect_label) ?? 'TRIMP'}`
                    : formatTrainingEffectLabel(a.training_effect_label),
            accentClass: 'bg-indigo-500',
        },
    ];
});

// Discipline-specific Telemetry Cards (now surfacing Stamina, Power, Dynamics, Feel/RPE, Execution Score across sports)
interface TelemetryCell {
    label: string;
    value: string;
    sub?: string | null;
}

function appendCommonPerformanceCells(
    cells: TelemetryCell[],
    a: ActivityDetail,
): void {
    if (a.begin_stamina !== null && a.end_stamina !== null) {
        const minPart =
            a.min_stamina !== null && a.min_stamina !== a.end_stamina
                ? `Min ${a.min_stamina}%`
                : `-${Math.max(0, a.begin_stamina - a.end_stamina)}% drained`;
        cells.push({
            label: 'Stamina',
            value: `${a.begin_stamina}% → ${a.end_stamina}%`,
            sub: minPart,
        });
    }

    if (a.execution_score !== null) {
        cells.push({
            label: 'Execution',
            value: `${a.execution_score}%`,
            sub: a.workout_meta?.target_label
                ? `${formatExecutionRating(a.execution_score)} · ${a.workout_meta.target_label}`
                : formatExecutionRating(a.execution_score),
        });
    }

    const feelText = formatFeel(a.workout_feel);
    const rpeText = formatRpe(a.workout_rpe);

    if (feelText || rpeText) {
        cells.push({
            label: 'Feel & Effort',
            value:
                feelText && rpeText
                    ? `${feelText} · ${rpeText}`
                    : (feelText ?? rpeText ?? '—'),
        });
    }

    if (
        a.body_battery_diff !== null ||
        (a.begin_body_battery !== null && a.end_body_battery !== null)
    ) {
        const range =
            a.begin_body_battery !== null && a.end_body_battery !== null
                ? `${a.begin_body_battery} → ${a.end_body_battery}`
                : 'Impact';
        cells.push({
            label: 'Body Battery',
            value:
                a.body_battery_diff !== null
                    ? `${a.body_battery_diff > 0 ? '+' : ''}${a.body_battery_diff}`
                    : range,
            sub: a.body_battery_diff !== null ? range : null,
        });
    }
}

const hasCyclingPowerData = computed<boolean>(
    () =>
        !isRunning.value &&
        ((props.activity.avg_power !== null && props.activity.avg_power > 0) ||
            (props.activity.normalized_power !== null &&
                props.activity.normalized_power > 0)),
);

const hasTrailDynamicsData = computed<boolean>(
    () =>
        (props.activity.grit !== null && props.activity.grit > 0) ||
        (props.activity.avg_flow !== null && props.activity.avg_flow > 0) ||
        (props.activity.jump_count !== null && props.activity.jump_count > 0),
);

const disciplineTelemetryTitle = computed<string | null>(() => {
    if (isRunning.value) {
        return 'Running Dynamics';
    }

    if (hasCyclingPowerData.value && hasTrailDynamicsData.value) {
        return 'Power & Trail Dynamics';
    }

    if (hasCyclingPowerData.value) {
        return 'Power';
    }

    if (hasTrailDynamicsData.value) {
        return 'Trail Dynamics';
    }

    if (disciplineTelemetryCells.value.length > 0) {
        return 'Session Stats';
    }

    return null;
});

const disciplineTelemetryCells = computed<TelemetryCell[]>(() => {
    const a = props.activity;

    if (isRunning.value) {
        const cells: TelemetryCell[] = [
            {
                label: 'Cadence',
                value:
                    a.avg_cadence !== null
                        ? `${Math.round(a.avg_cadence)} spm`
                        : '—',
                sub:
                    a.max_cadence !== null
                        ? `Max ${Math.round(a.max_cadence)} spm`
                        : null,
            },
            {
                label: 'Stride Length',
                value: formatStride(a.avg_stride_length),
                sub:
                    a.avg_vertical_ratio !== null &&
                    a.avg_vertical_oscillation !== null
                        ? `${a.avg_vertical_ratio.toFixed(1)}% · ${a.avg_vertical_oscillation.toFixed(1)} cm`
                        : null,
            },
        ];

        if (a.avg_ground_contact_time !== null) {
            cells.push({
                label: 'Ground Contact',
                value: `${Math.round(a.avg_ground_contact_time)} ms`,
                sub:
                    a.avg_vertical_ratio !== null
                        ? `Vert Ratio ${a.avg_vertical_ratio.toFixed(1)}%`
                        : null,
            });
        }

        if (a.avg_power !== null) {
            cells.push({
                label: 'Running Power',
                value: `${Math.round(a.avg_power)} W`,
                sub:
                    a.normalized_power !== null
                        ? `NP ${Math.round(a.normalized_power)} W · Max ${a.max_power !== null ? Math.round(a.max_power) : '—'} W`
                        : a.max_power !== null
                          ? `Max ${Math.round(a.max_power)} W`
                          : null,
            });
        }

        cells.push({
            label: 'Aerobic / Anaerobic',
            value:
                a.aerobic_te !== null || a.anaerobic_te !== null
                    ? `${a.aerobic_te !== null ? a.aerobic_te.toFixed(1) : '—'} / ${a.anaerobic_te !== null ? a.anaerobic_te.toFixed(1) : '—'}`
                    : '—',
            sub: formatTrainingEffectLabel(a.training_effect_label),
        });

        appendCommonPerformanceCells(cells, a);

        return cells;
    }

    const cells: TelemetryCell[] = [];

    if (hasCyclingPowerData.value) {
        if (a.avg_power !== null && a.avg_power > 0) {
            cells.push({
                label: 'Avg Power',
                value: `${Math.round(a.avg_power)} W`,
                sub:
                    a.max_power !== null
                        ? `Max ${Math.round(a.max_power)} W`
                        : null,
            });
        }

        if (a.normalized_power !== null && a.normalized_power > 0) {
            cells.push({
                label: 'Normalized Power',
                value: `${Math.round(a.normalized_power)} W`,
                sub:
                    a.intensity_factor !== null
                        ? `IF ${a.intensity_factor.toFixed(2)}`
                        : null,
            });
        }

        if (a.tss !== null || a.ftp !== null) {
            cells.push({
                label: 'TSS / FTP',
                value: a.tss !== null ? formatNumber(a.tss, 0) : '—',
                sub: a.ftp !== null ? `FTP ${Math.round(a.ftp)} W` : null,
            });
        }

        if (a.left_balance !== null || a.total_work !== null) {
            cells.push({
                label: a.left_balance !== null ? 'L / R Balance' : 'Total Work',
                value:
                    a.left_balance !== null
                        ? formatLeftRightBalance(a.left_balance)
                        : formatTotalWork(a.total_work),
                sub:
                    a.left_balance !== null && a.total_work !== null
                        ? `Work ${formatTotalWork(a.total_work)}`
                        : null,
            });
        }
    }

    if (hasTrailDynamicsData.value) {
        if (a.grit !== null && a.grit > 0) {
            cells.push({
                label: 'Garmin Grit',
                value: formatNumber(a.grit, 1),
                sub: 'Trail difficulty',
            });
        }

        if (a.avg_flow !== null && a.avg_flow > 0) {
            cells.push({
                label: 'Avg Flow',
                value: formatNumber(a.avg_flow, 1),
                sub: 'Lower is smoother',
            });
        }

        if (a.jump_count !== null && a.jump_count > 0) {
            cells.push({
                label: 'Jumps',
                value: formatNumber(a.jump_count, 0),
                sub:
                    a.unpaved_pct !== null && a.unpaved_pct > 0
                        ? `${Math.round(a.unpaved_pct)}% unpaved`
                        : null,
            });
        } else if (a.unpaved_pct !== null && a.unpaved_pct > 0) {
            cells.push({
                label: 'Unpaved Surface',
                value: `${Math.round(a.unpaved_pct)}%`,
                sub: null,
            });
        }
    }

    appendCommonPerformanceCells(cells, a);

    return cells;
});

// Stream & Series Analytics
const series = computed<StreamPoint[]>(() => props.stream?.series ?? []);
const polyline = computed<[number, number][]>(
    () => props.stream?.polyline ?? [],
);
const runWalkSegments = computed<RunWalkSegment[]>(
    () => props.stream?.run_walk_segments ?? [],
);
const jumps = computed<JumpEvent[]>(() => props.stream?.jumps ?? []);

const hasAltitudeStream = computed<boolean>(() =>
    series.value.some((p) => p.altitude !== null),
);
const hasSpeedStream = computed<boolean>(() =>
    series.value.some((p) => p.speed !== null && p.speed > 0),
);
const hasHrStream = computed<boolean>(() =>
    series.value.some((p) => p.hr !== null),
);
const hasCadenceStream = computed<boolean>(() =>
    series.value.some((p) => p.cadence !== null && p.cadence > 0),
);
const hasPowerStream = computed<boolean>(() =>
    series.value.some((p) => p.power !== null && p.power > 0),
);
const hasStaminaStream = computed<boolean>(() =>
    series.value.some(
        (p) =>
            (p.stamina !== null && p.stamina !== undefined) ||
            (p.stamina_potential !== null && p.stamina_potential !== undefined),
    ),
);
const hasPerformanceConditionStream = computed<boolean>(() =>
    series.value.some(
        (p) =>
            p.performance_condition !== null &&
            p.performance_condition !== undefined,
    ),
);
const hasStrideStream = computed<boolean>(() =>
    series.value.some(
        (p) =>
            p.stride_length !== null &&
            p.stride_length !== undefined &&
            p.stride_length > 0,
    ),
);
const hasVerticalRatioStream = computed<boolean>(() =>
    series.value.some(
        (p) =>
            p.vertical_ratio !== null &&
            p.vertical_ratio !== undefined &&
            p.vertical_ratio > 0,
    ),
);
const hasVerticalOscStream = computed<boolean>(() =>
    series.value.some(
        (p) =>
            p.vertical_oscillation !== null &&
            p.vertical_oscillation !== undefined &&
            p.vertical_oscillation > 0,
    ),
);
const hasGctStream = computed<boolean>(() =>
    series.value.some(
        (p) => p.gct !== null && p.gct !== undefined && p.gct > 0,
    ),
);
const hasRespirationStream = computed<boolean>(() =>
    series.value.some(
        (p) =>
            p.respiration !== null &&
            p.respiration !== undefined &&
            p.respiration > 0,
    ),
);
const hasTemperatureStream = computed<boolean>(() =>
    series.value.some(
        (p) => p.temperature !== null && p.temperature !== undefined,
    ),
);
const hasLeftBalanceStream = computed<boolean>(() =>
    series.value.some(
        (p) => p.left_balance !== null && p.left_balance !== undefined,
    ),
);
const hasTorqueEffStream = computed<boolean>(() =>
    series.value.some(
        (p) =>
            (p.left_te !== null && p.left_te !== undefined) ||
            (p.right_te !== null && p.right_te !== undefined),
    ),
);
const hasPedalSmoothnessStream = computed<boolean>(() =>
    series.value.some(
        (p) =>
            (p.left_ps !== null && p.left_ps !== undefined) ||
            (p.right_ps !== null && p.right_ps !== undefined),
    ),
);
const hasGritStream = computed<boolean>(() =>
    series.value.some(
        (p) => p.grit !== null && p.grit !== undefined && p.grit > 0,
    ),
);
const hasFlowStream = computed<boolean>(() =>
    series.value.some(
        (p) => p.flow !== null && p.flow !== undefined && p.flow > 0,
    ),
);

const hasWeatherSamples = computed<boolean>(
    () => props.weather !== null && props.weather.samples.length > 0,
);

const hasCoordinates = computed<boolean>(
    () =>
        (props.activity.start_lat !== null &&
            props.activity.start_lng !== null) ||
        polyline.value.length > 0 ||
        series.value.some((p) => p.lat !== null && p.lng !== null),
);

function selectMapColorMode(mode: MapColorMode): void {
    mapColorMode.value = mode;

    if (mode === 'wind' && hasWeatherSamples.value) {
        showWindArrows.value = true;
    }
}

const mapColorOptions = computed<Array<{ value: MapColorMode; label: string }>>(
    () => {
        const opts: Array<{ value: MapColorMode; label: string }> = [
            { value: 'solid', label: 'Route' },
        ];

        if (hasSpeedStream.value) {
            opts.push({
                value: 'speed',
                label: usesPace.value ? 'Pace' : 'Speed',
            });
        }

        if (hasHrStream.value) {
            opts.push({ value: 'hr', label: 'HR' });
        }

        if (hasAltitudeStream.value) {
            opts.push({ value: 'altitude', label: 'Elevation' });
        }

        if (hasPowerStream.value) {
            opts.push({ value: 'power', label: 'Power' });
        }

        if (hasStaminaStream.value) {
            opts.push({ value: 'stamina', label: 'Stamina' });
        }

        if (hasGritStream.value) {
            opts.push({ value: 'grit', label: 'Grit' });
        }

        if (hasFlowStream.value) {
            opts.push({ value: 'flow', label: 'Flow' });
        }

        if (hasWeatherSamples.value) {
            opts.push({ value: 'wind', label: 'Wind' });
        }

        return opts;
    },
);

const hoveredPoint = computed<StreamPoint | null>(() => {
    const idx = activeStreamIndex.value;

    if (idx === null || idx < 0 || idx >= series.value.length) {
        return null;
    }

    return series.value[idx];
});

function computeLocalRouteBearing(index: number): number | null {
    const pts = series.value;

    if (index < 0 || index >= pts.length) {
        return null;
    }

    const hasGnssCoords = (
        pt: StreamPoint | undefined,
    ): pt is StreamPoint & { lat: number; lng: number } =>
        pt !== undefined &&
        pt.lat !== null &&
        pt.lng !== null &&
        Number.isFinite(pt.lat) &&
        Number.isFinite(pt.lng);

    let backIdx = index;

    for (let i = index - 1; i >= Math.max(0, index - 8); i -= 1) {
        if (hasGnssCoords(pts[i])) {
            backIdx = i;

            if (index - i >= 2) {
                break;
            }
        }
    }

    let aheadIdx = index;

    for (let i = index + 1; i <= Math.min(pts.length - 1, index + 8); i += 1) {
        if (hasGnssCoords(pts[i])) {
            aheadIdx = i;

            if (i - index >= 2) {
                break;
            }
        }
    }

    const p1 = pts[backIdx];
    const p2 = pts[aheadIdx];

    if (
        backIdx === aheadIdx ||
        !hasGnssCoords(p1) ||
        !hasGnssCoords(p2) ||
        (Math.abs(p1.lat - p2.lat) < 1e-7 && Math.abs(p1.lng - p2.lng) < 1e-7)
    ) {
        return null;
    }

    return computeSegmentBearing(p1.lat, p1.lng, p2.lat, p2.lng);
}

const hoveredWeatherReadout = computed<{
    windText: string | null;
    effectiveText: string | null;
    effectiveToneClass: string;
    tempText: string | null;
} | null>(() => {
    const pt = hoveredPoint.value;
    const idx = activeStreamIndex.value;

    if (
        !pt ||
        idx === null ||
        !props.weather ||
        props.weather.samples.length === 0
    ) {
        return null;
    }

    const sample = interpolateWeatherAt(
        props.weather.samples,
        pt.time,
        pt.distance,
    );

    if (!sample) {
        return null;
    }

    let windText: string | null = null;
    let effectiveText: string | null = null;
    let effectiveToneClass = 'text-zinc-500 dark:text-zinc-400';

    if (sample.wind_speed_kmh !== null) {
        const compass =
            sample.wind_direction_compass ??
            (sample.wind_direction_deg !== null
                ? formatCompass(sample.wind_direction_deg)
                : null);
        windText = compass
            ? `${formatSpeedKmh(sample.wind_speed_kmh, 1)} ${compass}`
            : formatSpeedKmh(sample.wind_speed_kmh, 1);

        if (sample.wind_direction_deg !== null) {
            const bearing = computeLocalRouteBearing(idx);

            if (bearing !== null) {
                const effective = computeEffectiveWind(
                    sample.wind_speed_kmh,
                    sample.wind_direction_deg,
                    bearing,
                );

                if (effective.classification === 'calm') {
                    effectiveText = 'calm';
                    effectiveToneClass = 'text-zinc-500 dark:text-zinc-400';
                } else {
                    const effVal =
                        Math.abs(effective.effectiveKmh) < 0.5
                            ? 0
                            : effective.effectiveKmh;
                    const signedSpeed =
                        effVal > 0
                            ? `+${formatSpeedKmh(effVal, 0)}`
                            : formatSpeedKmh(effVal, 0);
                    const kind =
                        effective.classification === 'headwind'
                            ? 'head'
                            : effective.classification === 'tailwind'
                              ? 'tail'
                              : 'cross';

                    effectiveText = `${signedSpeed} ${kind}`;
                    effectiveToneClass =
                        effective.classification === 'headwind'
                            ? 'text-rose-600 dark:text-rose-400'
                            : effective.classification === 'tailwind'
                              ? 'text-emerald-600 dark:text-emerald-400'
                              : 'text-amber-600 dark:text-amber-400';
                }
            }
        }
    }

    const tempText =
        sample.temperature_c !== null
            ? formatTemp(sample.temperature_c, 0)
            : null;

    if (!windText && !tempText) {
        return null;
    }

    return {
        windText,
        effectiveText,
        effectiveToneClass,
        tempText,
    };
});

const streamHasDistance = computed<boolean>(() =>
    series.value.some((p) => p.distance !== null && p.distance > 0),
);

const streamXAxisLabels = computed<string[]>(() =>
    series.value.map((p) => {
        if (streamHasDistance.value && p.distance !== null) {
            return `${distanceFromMeters(p.distance).toFixed(2)} ${distanceUnit.value}`;
        }

        return formatDurationClock(p.time);
    }),
);

function averageOf(values: Array<number | null | undefined>): number | null {
    let sum = 0;
    let count = 0;

    for (const v of values) {
        if (typeof v === 'number' && Number.isFinite(v)) {
            sum += v;
            count += 1;
        }
    }

    return count > 0 ? sum / count : null;
}

function buildRunWalkOption(showXAxisLabels: boolean): EChartsOption {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    const segs = runWalkSegments.value;
    const pointMeta: Array<{
        type: string;
        value: number;
        seg: RunWalkSegment | null;
    }> = [];

    for (const pt of series.value) {
        let matched: RunWalkSegment | null = null;

        for (const seg of segs) {
            if (pt.time >= seg.start_time && pt.time <= seg.end_time) {
                matched = seg;
                break;
            }
        }

        if (!matched && segs.length > 0) {
            // Match nearest segment if timestamp sits on a boundary gap
            let bestDist = Number.POSITIVE_INFINITY;

            for (const seg of segs) {
                const dist =
                    pt.time < seg.start_time
                        ? seg.start_time - pt.time
                        : pt.time > seg.end_time
                          ? pt.time - seg.end_time
                          : 0;

                if (dist < bestDist) {
                    bestDist = dist;
                    matched = seg;
                }
            }
        }

        const type = matched?.type ?? 'run';
        const level = type === 'idle' ? 3 : type === 'walk' ? 2 : 1;

        pointMeta.push({
            type,
            value: level,
            seg: matched,
        });
    }

    return {
        animation: false,
        grid: {
            left: 56,
            right: 16,
            top: 10,
            bottom: showXAxisLabels ? 26 : 10,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: {
                    color: theme.mutedColor.value,
                    width: 1,
                    type: 'dashed',
                },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as
                    | { axisValueLabel?: string; dataIndex?: number }
                    | undefined;

                if (!first || first.dataIndex === undefined) {
                    return '';
                }

                const meta = pointMeta[first.dataIndex];

                if (!meta) {
                    return '';
                }

                const label =
                    meta.type === 'walk'
                        ? 'Walk'
                        : meta.type === 'idle'
                          ? 'Idle'
                          : 'Run';
                const detail = meta.seg
                    ? `${formatDurationClock(meta.seg.duration_seconds)} · ${formatDistance(meta.seg.distance_meters, 2)}`
                    : '';

                return `<div><div style="opacity:0.65;margin-bottom:2px">${first.axisValueLabel ?? ''}</div><strong>${label}</strong>${detail ? ` (${detail})` : ''}</div>`;
            },
        },
        xAxis: {
            type: 'category',
            data: streamXAxisLabels.value,
            boundaryGap: false,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: showXAxisLabels
                ? {
                      ...axisLabelStyle,
                      interval: Math.max(
                          0,
                          Math.floor(streamXAxisLabels.value.length / 6) - 1,
                      ),
                  }
                : { show: false },
        },
        yAxis: {
            type: 'value',
            min: 0,
            max: 3,
            interval: 1,
            axisLabel: {
                ...axisLabelStyle,
                formatter: (val: number) => {
                    if (val === 1) {
                        return 'Run';
                    }

                    if (val === 2) {
                        return 'Walk';
                    }

                    if (val === 3) {
                        return 'Idle';
                    }

                    return '';
                },
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'Run / Walk',
                type: 'bar',
                barWidth: '100%',
                barCategoryGap: '0%',
                data: pointMeta.map((m) => ({
                    value: m.value,
                    itemStyle: {
                        color:
                            m.type === 'walk'
                                ? '#10b981'
                                : m.type === 'idle'
                                  ? '#a1a1aa'
                                  : '#0ea5e9',
                    },
                })),
            },
        ],
    };
}

function buildTelemetryChartOption(config: {
    seriesName: string;
    data: Array<number | null>;
    dottedData?: Array<number | null>;
    tooltipValues?: Array<number | null>;
    color: string;
    areaColor?: string;
    areaOrigin?: 'auto' | 'start' | 'end';
    unit: string;
    inverse?: boolean;
    min?: number | 'dataMin';
    max?: number | 'dataMax';
    avgLine?: { value: number; label: string } | null;
    step?: 'start' | 'middle' | 'end' | false;
    visualMapPieces?: Array<{
        gt?: number;
        gte?: number;
        lt?: number;
        lte?: number;
        color: string;
    }>;
    valueFormatter: (value: number) => string;
    axisValueFormatter?: (value: number) => string;
    showXAxisLabels?: boolean;
}): EChartsOption {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    const markLine = config.avgLine
        ? {
              silent: true,
              symbol: ['none', 'none'],
              lineStyle: {
                  color: theme.isDark.value
                      ? 'rgba(228, 228, 231, 0.45)'
                      : 'rgba(39, 39, 42, 0.45)',
                  type: 'dashed' as const,
                  width: 1,
              },
              label: {
                  show: true,
                  position: 'insideEndTop' as const,
                  formatter: config.avgLine.label,
                  color: theme.mutedColor.value,
                  backgroundColor: theme.isDark.value
                      ? 'rgba(20, 20, 23, 0.85)'
                      : 'rgba(255, 255, 255, 0.85)',
                  padding: [1, 4],
                  borderRadius: 3,
                  fontFamily: monoFont,
                  fontSize: 10,
              },
              data: [{ yAxis: config.avgLine.value }],
          }
        : undefined;

    const seriesList: NonNullable<EChartsOption['series']> = [
        {
            name: config.seriesName,
            type: 'line',
            data: config.data,
            showSymbol: false,
            smooth: config.step ? false : 0.2,
            step: config.step ?? false,
            connectNulls: true,
            lineStyle: {
                color: config.visualMapPieces ? undefined : config.color,
                width: 1.8,
            },
            itemStyle: {
                color: config.color,
            },
            ...(config.areaColor
                ? {
                      areaStyle: {
                          color: config.areaColor,
                          ...(config.areaOrigin
                              ? { origin: config.areaOrigin }
                              : {}),
                      },
                  }
                : {}),
            ...(markLine ? { markLine } : {}),
        },
    ];

    if (config.dottedData && config.dottedData.some((v) => v !== null)) {
        seriesList.push({
            name: `${config.seriesName} (start)`,
            type: 'line',
            data: config.dottedData,
            showSymbol: false,
            smooth: false,
            step: config.step ?? false,
            connectNulls: true,
            z: 4,
            lineStyle: {
                color: config.visualMapPieces ? undefined : config.color,
                width: 1.8,
                type: 'dotted',
                opacity: 0.8,
            },
            itemStyle: {
                color: config.color,
            },
        });
    }

    return {
        animation: false,
        grid: {
            left: 56,
            right: 16,
            top: 14,
            bottom: config.showXAxisLabels ? 26 : 12,
        },
        ...(config.visualMapPieces
            ? {
                  visualMap: {
                      show: false,
                      dimension: 1,
                      pieces: config.visualMapPieces,
                  },
              }
            : {}),
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: {
                    color: theme.mutedColor.value,
                    width: 1,
                    type: 'dashed',
                },
            },
            formatter: (params: unknown) => {
                const list = (
                    Array.isArray(params) ? params : [params]
                ) as Array<{
                    axisValueLabel?: string;
                    dataIndex?: number;
                    value?: number | null;
                    seriesName?: string;
                }>;
                const first = list[0];

                if (!first) {
                    return '';
                }

                let resolvedValue: number | null = null;

                if (
                    config.tooltipValues &&
                    first.dataIndex !== undefined &&
                    first.dataIndex >= 0 &&
                    first.dataIndex < config.tooltipValues.length
                ) {
                    const rawVal = config.tooltipValues[first.dataIndex];

                    if (typeof rawVal === 'number' && Number.isFinite(rawVal)) {
                        resolvedValue = rawVal;
                    }
                } else {
                    for (const item of list) {
                        if (
                            typeof item?.value === 'number' &&
                            Number.isFinite(item.value)
                        ) {
                            resolvedValue = item.value;
                            break;
                        }
                    }
                }

                const val =
                    resolvedValue !== null
                        ? config.valueFormatter(resolvedValue)
                        : '—';

                return `<div><div style="opacity:0.65;margin-bottom:2px">${first.axisValueLabel ?? ''}</div><strong>${config.seriesName}:</strong> ${val}</div>`;
            },
        },
        xAxis: {
            type: 'category',
            data: streamXAxisLabels.value,
            boundaryGap: false,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: config.showXAxisLabels
                ? {
                      ...axisLabelStyle,
                      interval: Math.max(
                          0,
                          Math.floor(streamXAxisLabels.value.length / 6) - 1,
                      ),
                  }
                : { show: false },
        },
        yAxis: {
            type: 'value',
            scale: true,
            inverse: config.inverse ?? false,
            min: config.min,
            max: config.max,
            splitNumber: 3,
            axisLabel: {
                ...axisLabelStyle,
                formatter: (val: number) =>
                    (config.axisValueFormatter ?? config.valueFormatter)(val),
            },
            splitLine: {
                lineStyle: { color: theme.gridLineColor.value },
            },
        },
        series: seriesList,
    };
}

function buildStaminaOption(showXAxisLabels: boolean): EChartsOption {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    return {
        animation: false,
        grid: {
            left: 56,
            right: 16,
            top: 14,
            bottom: showXAxisLabels ? 26 : 12,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: {
                    color: theme.mutedColor.value,
                    width: 1,
                    type: 'dashed',
                },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const header =
                    (list[0] as { axisValueLabel?: string } | undefined)
                        ?.axisValueLabel ?? '';
                const lines = list
                    .map((item) => {
                        const p = item as {
                            seriesName?: string;
                            value?: number | null;
                        };
                        const val =
                            typeof p.value === 'number'
                                ? `${Math.round(p.value)}%`
                                : '—';

                        return `<strong>${p.seriesName}:</strong> ${val}`;
                    })
                    .join('<br/>');

                return `<div><div style="opacity:0.65;margin-bottom:2px">${header}</div>${lines}</div>`;
            },
        },
        xAxis: {
            type: 'category',
            data: streamXAxisLabels.value,
            boundaryGap: false,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: showXAxisLabels
                ? {
                      ...axisLabelStyle,
                      interval: Math.max(
                          0,
                          Math.floor(streamXAxisLabels.value.length / 6) - 1,
                      ),
                  }
                : { show: false },
        },
        yAxis: {
            type: 'value',
            min: 0,
            max: 100,
            splitNumber: 2,
            axisLabel: {
                ...axisLabelStyle,
                formatter: (v: number) => `${v}%`,
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'Stamina',
                type: 'line',
                data: series.value.map((p) => p.stamina ?? null),
                showSymbol: false,
                smooth: 0.15,
                connectNulls: true,
                lineStyle: { color: '#f97316', width: 2 },
                itemStyle: { color: '#f97316' },
                areaStyle: {
                    color: theme.isDark.value
                        ? 'rgba(249, 115, 22, 0.28)'
                        : 'rgba(249, 115, 22, 0.35)',
                },
            },
            {
                name: 'Stamina Potential',
                type: 'line',
                data: series.value.map((p) => p.stamina_potential ?? null),
                showSymbol: false,
                smooth: 0.15,
                connectNulls: true,
                lineStyle: {
                    color: theme.isDark.value ? '#a1a1aa' : '#71717a',
                    width: 1.5,
                    type: 'dashed',
                },
                itemStyle: {
                    color: theme.isDark.value ? '#a1a1aa' : '#71717a',
                },
            },
        ],
    };
}

function buildPerformanceConditionOption(
    showXAxisLabels: boolean,
): EChartsOption {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    const vals = series.value
        .map((p) => p.performance_condition)
        .filter(
            (v): v is number => typeof v === 'number' && Number.isFinite(v),
        );
    const maxAbs = Math.max(2, ...vals.map((v) => Math.abs(v)));

    return {
        animation: false,
        grid: {
            left: 56,
            right: 16,
            top: 14,
            bottom: showXAxisLabels ? 26 : 12,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: {
                    color: theme.mutedColor.value,
                    width: 1,
                    type: 'dashed',
                },
            },
            formatter: (params: unknown) => {
                const list = Array.isArray(params) ? params : [params];
                const first = list[0] as
                    | { axisValueLabel?: string; value?: number | null }
                    | undefined;

                if (!first) {
                    return '';
                }

                const val =
                    typeof first.value === 'number'
                        ? `${first.value > 0 ? '+' : ''}${first.value}`
                        : '—';

                return `<div><div style="opacity:0.65;margin-bottom:2px">${first.axisValueLabel ?? ''}</div><strong>Performance Condition:</strong> ${val}</div>`;
            },
        },
        xAxis: {
            type: 'category',
            data: streamXAxisLabels.value,
            boundaryGap: false,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: showXAxisLabels
                ? {
                      ...axisLabelStyle,
                      interval: Math.max(
                          0,
                          Math.floor(streamXAxisLabels.value.length / 6) - 1,
                      ),
                  }
                : { show: false },
        },
        yAxis: {
            type: 'value',
            min: -maxAbs,
            max: maxAbs,
            splitNumber: 2,
            axisLabel: {
                ...axisLabelStyle,
                formatter: (v: number) => `${v > 0 ? '+' : ''}${v}`,
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: 'Performance Condition',
                type: 'bar',
                barWidth: '100%',
                data: series.value.map((p) => {
                    const v = p.performance_condition ?? null;

                    return {
                        value: v,
                        itemStyle: {
                            color: v !== null && v < 0 ? '#3b82f6' : '#0ea5e9',
                        },
                    };
                }),
            },
        ],
    };
}

function buildDualPercentOption(config: {
    leftName: string;
    rightName: string;
    leftData: Array<number | null>;
    rightData: Array<number | null>;
    showXAxisLabels: boolean;
}): EChartsOption {
    const monoFont = theme.fontFamilyMono;
    const axisLabelStyle = {
        color: theme.mutedColor.value,
        fontFamily: monoFont,
        fontSize: 10,
    };

    return {
        animation: false,
        grid: {
            left: 56,
            right: 16,
            top: 14,
            bottom: config.showXAxisLabels ? 26 : 12,
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: {
                    color: theme.mutedColor.value,
                    width: 1,
                    type: 'dashed',
                },
            },
        },
        xAxis: {
            type: 'category',
            data: streamXAxisLabels.value,
            boundaryGap: false,
            axisLine: { lineStyle: { color: theme.gridLineColor.value } },
            axisTick: { show: false },
            axisLabel: config.showXAxisLabels
                ? {
                      ...axisLabelStyle,
                      interval: Math.max(
                          0,
                          Math.floor(streamXAxisLabels.value.length / 6) - 1,
                      ),
                  }
                : { show: false },
        },
        yAxis: {
            type: 'value',
            scale: true,
            splitNumber: 3,
            axisLabel: {
                ...axisLabelStyle,
                formatter: (v: number) => `${Math.round(v)}%`,
            },
            splitLine: { lineStyle: { color: theme.gridLineColor.value } },
        },
        series: [
            {
                name: config.leftName,
                type: 'line',
                data: config.leftData,
                showSymbol: false,
                smooth: 0.2,
                connectNulls: true,
                lineStyle: { color: '#0ea5e9', width: 1.75 },
                itemStyle: { color: '#0ea5e9' },
            },
            {
                name: config.rightName,
                type: 'line',
                data: config.rightData,
                showSymbol: false,
                smooth: 0.2,
                connectNulls: true,
                lineStyle: { color: '#10b981', width: 1.75 },
                itemStyle: { color: '#10b981' },
            },
        ],
    };
}

interface TelemetryPanel {
    key: string;
    title: string;
    unit: string;
    dotClass: string;
    option: EChartsOption;
    height: number;
    hasVerticalToggle?: boolean;
    hasPowerToggle?: boolean;
}

const thresholdPowerRef = computed<number | null>(
    () => props.activity.ftp ?? props.activity.threshold_power ?? null,
);

const telemetryPanels = computed<TelemetryPanel[]>(() => {
    if (series.value.length === 0) {
        return [];
    }

    const streamTimes = series.value.map((p) => p.time);

    const activePanels: Array<{
        key: string;
        title: string;
        unit: string;
        dotClass: string;
        height: number;
        hasVerticalToggle?: boolean;
        hasPowerToggle?: boolean;
        build: (isLast: boolean) => EChartsOption;
    }> = [];

    if (hasAltitudeStream.value) {
        const elevUnitLabel = elevationUnit.value;

        activePanels.push({
            key: 'elevation',
            title: 'Elevation',
            unit: elevUnitLabel,
            dotClass: 'bg-emerald-500',
            height: 140,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Elevation',
                    data: series.value.map((p) =>
                        p.altitude !== null
                            ? Number(elevationFromMeters(p.altitude).toFixed(1))
                            : null,
                    ),
                    color: '#10b981',
                    areaColor: theme.isDark.value
                        ? 'rgba(16, 185, 129, 0.16)'
                        : 'rgba(16, 185, 129, 0.14)',
                    unit: elevUnitLabel,
                    valueFormatter: (v) => `${Math.round(v)} ${elevUnitLabel}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasSpeedStream.value) {
        if (usesPace.value) {
            const paceSeries = buildPaceTelemetrySeries({
                times: streamTimes,
                speeds: series.value.map((p) => p.speed),
                paceSecondsFromMps,
                isImperial: isImperial.value,
            });
            const { yMin, yMax } = paceSeries;

            const avgPaceRaw =
                props.activity.avg_speed && props.activity.avg_speed > 0
                    ? paceSecondsFromMps(props.activity.avg_speed)
                    : null;
            const avgPaceSec =
                avgPaceRaw !== null ? Math.round(avgPaceRaw) : null;
            const paceUnitLabel = isImperial.value ? 'min/mi' : 'min/km';

            activePanels.push({
                key: 'pace',
                title: 'Pace',
                unit: paceUnitLabel,
                dotClass: 'bg-amber-500',
                height: 155,
                build: (isLast) =>
                    buildTelemetryChartOption({
                        seriesName: 'Pace',
                        data: paceSeries.solidData,
                        dottedData: paceSeries.dottedData,
                        tooltipValues: paceSeries.tooltipValues,
                        color: '#f59e0b',
                        areaColor: theme.isDark.value
                            ? 'rgba(245, 158, 11, 0.14)'
                            : 'rgba(245, 158, 11, 0.12)',
                        areaOrigin: 'end',
                        unit: isImperial.value ? '/mi' : '/km',
                        inverse: true,
                        min: yMin,
                        max: yMax,
                        avgLine:
                            avgPaceSec &&
                            avgPaceSec >= yMin &&
                            avgPaceSec <= yMax
                                ? {
                                      value: avgPaceSec,
                                      label: `Avg ${formatPaceSeconds(avgPaceSec)}`,
                                  }
                                : null,
                        valueFormatter: (v) => {
                            const total = Math.round(v);
                            const mins = Math.floor(total / 60);
                            const secs = total % 60;

                            return `${mins}:${String(secs).padStart(2, '0')}`;
                        },
                        showXAxisLabels: isLast,
                    }),
            });
        } else {
            const speedUnitLabel = speedUnit.value;
            const avgSpeedVal =
                props.activity.avg_speed !== null
                    ? Number(speedFromMps(props.activity.avg_speed).toFixed(1))
                    : null;

            activePanels.push({
                key: 'speed',
                title: 'Speed',
                unit: speedUnitLabel,
                dotClass: 'bg-sky-500',
                height: 150,
                build: (isLast) =>
                    buildTelemetryChartOption({
                        seriesName: 'Speed',
                        data: series.value.map((p) =>
                            p.speed !== null
                                ? Number(speedFromMps(p.speed).toFixed(1))
                                : null,
                        ),
                        color: '#0ea5e9',
                        areaColor: theme.isDark.value
                            ? 'rgba(14, 165, 233, 0.14)'
                            : 'rgba(14, 165, 233, 0.12)',
                        unit: speedUnitLabel,
                        min: 0,
                        avgLine:
                            avgSpeedVal !== null
                                ? {
                                      value: avgSpeedVal,
                                      label: `Avg ${avgSpeedVal.toFixed(1)} ${speedUnitLabel}`,
                                  }
                                : null,
                        valueFormatter: (v) =>
                            `${v.toFixed(1)} ${speedUnitLabel}`,
                        axisValueFormatter: (v) => `${v.toFixed(1)}`,
                        showXAxisLabels: isLast,
                    }),
            });
        }
    }

    if (hasHrStream.value) {
        activePanels.push({
            key: 'hr',
            title: 'Heart Rate',
            unit: 'bpm',
            dotClass: 'bg-rose-500',
            height: 145,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Heart Rate',
                    data: series.value.map((p) => p.hr),
                    color: '#f43f5e',
                    areaColor: theme.isDark.value
                        ? 'rgba(244, 63, 94, 0.14)'
                        : 'rgba(244, 63, 94, 0.12)',
                    unit: 'bpm',
                    avgLine:
                        props.activity.avg_hr !== null
                            ? {
                                  value: props.activity.avg_hr,
                                  label: `Avg ${props.activity.avg_hr} bpm`,
                              }
                            : null,
                    valueFormatter: (v) => `${Math.round(v)}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasStaminaStream.value) {
        activePanels.push({
            key: 'stamina',
            title: 'Stamina & Stamina Potential',
            unit: '%',
            dotClass: 'bg-orange-500',
            height: 140,
            build: (isLast) => buildStaminaOption(isLast),
        });
    }

    if (hasPerformanceConditionStream.value) {
        activePanels.push({
            key: 'performance_condition',
            title: 'Performance Condition',
            unit: 'score',
            dotClass: 'bg-sky-500',
            height: 120,
            build: (isLast) => buildPerformanceConditionOption(isLast),
        });
    }

    if (hasPowerStream.value) {
        const usePct =
            powerUnitMode.value === 'pct' && thresholdPowerRef.value !== null;
        const ftp = thresholdPowerRef.value;
        const avgPowerVal =
            props.activity.avg_power !== null
                ? usePct && ftp
                    ? Math.round((props.activity.avg_power / ftp) * 100)
                    : Math.round(props.activity.avg_power)
                : null;
        const powerColor = isRunning.value ? '#8b5cf6' : '#0ea5e9';
        const powerArea = isRunning.value
            ? theme.isDark.value
                ? 'rgba(139, 92, 246, 0.20)'
                : 'rgba(139, 92, 246, 0.18)'
            : theme.isDark.value
              ? 'rgba(14, 165, 233, 0.16)'
              : 'rgba(14, 165, 233, 0.14)';

        const rawPowerData = series.value.map((p) => {
            if (p.power === null) {
                return null;
            }

            return usePct && ftp ? Math.round((p.power / ftp) * 100) : p.power;
        });

        const runningPowerSeries = isRunning.value
            ? buildStartupGatedTelemetrySeries({
                  times: streamTimes,
                  values: rawPowerData,
                  step: usePct ? 10 : 25,
                  minMargin: usePct ? 8 : 25,
                  excludeZero: true,
                  minFloor: 0,
              })
            : null;

        const powerYMin = runningPowerSeries ? runningPowerSeries.yMin : 0;
        const powerYMax = runningPowerSeries
            ? runningPowerSeries.yMax
            : undefined;
        const showPowerAvgLine =
            avgPowerVal !== null &&
            (powerYMin === undefined || avgPowerVal >= powerYMin) &&
            (powerYMax === undefined || avgPowerVal <= powerYMax);

        activePanels.push({
            key: 'power',
            title: isRunning.value ? 'Running Power' : 'Power',
            unit: usePct ? '% Threshold' : 'W',
            dotClass: isRunning.value ? 'bg-violet-500' : 'bg-sky-500',
            height: 145,
            hasPowerToggle: thresholdPowerRef.value !== null,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: usePct ? 'Power (% Threshold)' : 'Power',
                    data: runningPowerSeries
                        ? runningPowerSeries.solidData
                        : rawPowerData,
                    dottedData: runningPowerSeries?.dottedData,
                    tooltipValues: runningPowerSeries?.tooltipValues,
                    color: powerColor,
                    areaColor: powerArea,
                    unit: usePct ? '%' : 'W',
                    min: powerYMin,
                    max: powerYMax,
                    avgLine: showPowerAvgLine
                        ? {
                              value: avgPowerVal,
                              label: `Avg ${avgPowerVal}${usePct ? '%' : ' W'}`,
                          }
                        : null,
                    valueFormatter: (v) =>
                        `${Math.round(v)}${usePct ? '%' : ' W'}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasLeftBalanceStream.value) {
        // Filter coasting/single-leg spikes (<25% or >75%, or zero power) and apply rolling smoothing
        const cleanedLeft = series.value.map((p) => {
            const lb = p.left_balance;

            if (lb === null || lb === undefined || !Number.isFinite(lb)) {
                return null;
            }

            if (p.power !== null && p.power <= 0) {
                return null;
            }

            if (lb < 25 || lb > 75) {
                return null;
            }

            return lb;
        });

        const validCount = cleanedLeft.filter((v) => v !== null).length;
        const smoothWindow = Math.max(
            9,
            Math.min(25, Math.floor(validCount * 0.04) * 2 + 1),
        );
        const smoothedLeft = rollingAverage(cleanedLeft, smoothWindow);
        const validSmoothed = smoothedLeft.filter(
            (v): v is number => v !== null && Number.isFinite(v),
        );
        const avgLeft = props.activity.left_balance ?? averageOf(validSmoothed);

        const maxDeviation =
            validSmoothed.length > 0
                ? Math.max(
                      6,
                      Math.ceil(
                          Math.max(
                              ...validSmoothed.map((v) => Math.abs(v - 50)),
                          ) + 2,
                      ),
                  )
                : 10;
        const clampedDev = Math.min(20, maxDeviation);
        const yMin = 50 - clampedDev;
        const yMax = 50 + clampedDev;

        activePanels.push({
            key: 'left_balance',
            title: 'L / R Power Balance (Smoothed)',
            unit: '% Left',
            dotClass: 'bg-cyan-500',
            height: 140,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'L / R Balance',
                    data: smoothedLeft,
                    color: '#06b6d4',
                    areaColor: theme.isDark.value
                        ? 'rgba(6, 182, 212, 0.12)'
                        : 'rgba(6, 182, 212, 0.10)',
                    unit: '% L',
                    min: yMin,
                    max: yMax,
                    avgLine: {
                        value: 50,
                        label:
                            avgLeft !== null
                                ? `50% (Avg ${avgLeft.toFixed(1)}% L / ${(100 - avgLeft).toFixed(1)}% R)`
                                : '50% Even',
                    },
                    valueFormatter: (v) =>
                        `${v.toFixed(1)}% L / ${(100 - v).toFixed(1)}% R`,
                    axisValueFormatter: (v) => `${Math.round(v)}%`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasTorqueEffStream.value) {
        activePanels.push({
            key: 'torque_effectiveness',
            title: 'Torque Effectiveness (L / R)',
            unit: '%',
            dotClass: 'bg-sky-500',
            height: 135,
            build: (isLast) =>
                buildDualPercentOption({
                    leftName: 'Left TE',
                    rightName: 'Right TE',
                    leftData: series.value.map((p) => p.left_te ?? null),
                    rightData: series.value.map((p) => p.right_te ?? null),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasPedalSmoothnessStream.value) {
        activePanels.push({
            key: 'pedal_smoothness',
            title: 'Pedal Smoothness (L / R)',
            unit: '%',
            dotClass: 'bg-emerald-500',
            height: 135,
            build: (isLast) =>
                buildDualPercentOption({
                    leftName: 'Left Smoothness',
                    rightName: 'Right Smoothness',
                    leftData: series.value.map((p) => p.left_ps ?? null),
                    rightData: series.value.map((p) => p.right_ps ?? null),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasGritStream.value) {
        const gritData = series.value.map((p) => p.grit ?? null);
        activePanels.push({
            key: 'grit',
            title: 'Trail Grit',
            unit: 'grit',
            dotClass: 'bg-emerald-500',
            height: 135,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Grit',
                    data: gritData,
                    color: '#10b981',
                    areaColor: theme.isDark.value
                        ? 'rgba(16, 185, 129, 0.16)'
                        : 'rgba(16, 185, 129, 0.14)',
                    unit: '',
                    min: 0,
                    valueFormatter: (v) => v.toFixed(1),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasFlowStream.value) {
        const flowData = series.value.map((p) => p.flow ?? null);
        activePanels.push({
            key: 'flow',
            title: 'Trail Flow',
            unit: 'flow',
            dotClass: 'bg-teal-500',
            height: 135,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Flow',
                    data: flowData,
                    color: '#14b8a6',
                    areaColor: theme.isDark.value
                        ? 'rgba(20, 184, 166, 0.16)'
                        : 'rgba(20, 184, 166, 0.14)',
                    unit: '',
                    min: 0,
                    avgLine:
                        props.activity.avg_flow !== null
                            ? {
                                  value: props.activity.avg_flow,
                                  label: `Avg ${props.activity.avg_flow.toFixed(1)}`,
                              }
                            : null,
                    valueFormatter: (v) => v.toFixed(1),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasCadenceStream.value) {
        const cadUnit = isRunning.value ? 'spm' : 'rpm';
        const avgCad = props.activity.avg_cadence;

        activePanels.push({
            key: 'cadence',
            title: isRunning.value ? 'Run Cadence' : 'Cadence',
            unit: cadUnit,
            dotClass: 'bg-orange-500',
            height: 135,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: isRunning.value ? 'Run Cadence' : 'Cadence',
                    data: series.value.map((p) =>
                        p.cadence !== null && p.cadence > 0 ? p.cadence : null,
                    ),
                    color: '#f97316',
                    unit: cadUnit,
                    visualMapPieces: isRunning.value
                        ? [
                              { lte: 152, color: '#ef4444' },
                              { gt: 152, lte: 163, color: '#f97316' },
                              { gt: 163, lte: 173, color: '#22c55e' },
                              { gt: 173, lte: 183, color: '#3b82f6' },
                              { gt: 183, color: '#8b5cf6' },
                          ]
                        : undefined,
                    avgLine:
                        avgCad !== null
                            ? {
                                  value: Math.round(avgCad),
                                  label: `Avg ${Math.round(avgCad)} ${cadUnit}`,
                              }
                            : null,
                    valueFormatter: (v) => `${Math.round(v)}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasStrideStream.value) {
        const strideUnitLabel = strideUnit.value;
        const strideMetersData = series.value.map((p) => {
            const raw = p.stride_length;

            if (raw === null || raw === undefined || !Number.isFinite(raw)) {
                return null;
            }

            return raw > 10 ? raw / 100 : raw;
        });
        const strideData = strideMetersData.map((meters) =>
            meters !== null
                ? Number(
                      (isImperial.value ? meters * M_TO_FT : meters).toFixed(2),
                  )
                : null,
        );
        const strideSeries = buildStartupGatedTelemetrySeries({
            times: streamTimes,
            values: strideData,
            step: isImperial.value ? 0.25 : 0.1,
            minMargin: isImperial.value ? 0.25 : 0.08,
            excludeZero: true,
            minFloor: 0,
        });
        const rawAvg = props.activity.avg_stride_length;
        const avgMeters =
            rawAvg !== null
                ? rawAvg > 10
                    ? rawAvg / 100
                    : rawAvg
                : averageOf(strideMetersData);
        const avgStrideDisplay =
            avgMeters !== null
                ? isImperial.value
                    ? avgMeters * M_TO_FT
                    : avgMeters
                : null;
        const avgStrideValue =
            avgStrideDisplay !== null
                ? Number(avgStrideDisplay.toFixed(2))
                : null;
        const showStrideAvgLine =
            avgStrideValue !== null &&
            (strideSeries.yMin === undefined ||
                avgStrideValue >= strideSeries.yMin) &&
            (strideSeries.yMax === undefined ||
                avgStrideValue <= strideSeries.yMax);

        activePanels.push({
            key: 'stride_length',
            title: 'Stride Length',
            unit: strideUnitLabel,
            dotClass: 'bg-blue-500',
            height: 130,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Stride Length',
                    data: strideSeries.solidData,
                    dottedData: strideSeries.dottedData,
                    tooltipValues: strideSeries.tooltipValues,
                    color: '#3b82f6',
                    unit: strideUnitLabel,
                    min: strideSeries.yMin,
                    max: strideSeries.yMax,
                    avgLine: showStrideAvgLine
                        ? {
                              value: avgStrideValue,
                              label: `Avg ${avgStrideValue.toFixed(2)} ${strideUnitLabel}`,
                          }
                        : null,
                    valueFormatter: (v) => `${v.toFixed(2)} ${strideUnitLabel}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasVerticalRatioStream.value || hasVerticalOscStream.value) {
        const showOsc =
            verticalMetricMode.value === 'oscillation' &&
            hasVerticalOscStream.value;
        const rawVerticalData = showOsc
            ? series.value.map((p) => p.vertical_oscillation ?? null)
            : series.value.map((p) => p.vertical_ratio ?? null);
        const verticalSeries = buildStartupGatedTelemetrySeries({
            times: streamTimes,
            values: rawVerticalData,
            step: 0.5,
            minMargin: 0.5,
            excludeZero: true,
            minFloor: 0,
        });
        const avgVal = showOsc
            ? (props.activity.avg_vertical_oscillation ??
              averageOf(rawVerticalData))
            : (props.activity.avg_vertical_ratio ?? averageOf(rawVerticalData));
        const avgVerticalValue =
            avgVal !== null ? Number(avgVal.toFixed(1)) : null;
        const showVerticalAvgLine =
            avgVerticalValue !== null &&
            (verticalSeries.yMin === undefined ||
                avgVerticalValue >= verticalSeries.yMin) &&
            (verticalSeries.yMax === undefined ||
                avgVerticalValue <= verticalSeries.yMax);

        activePanels.push({
            key: 'vertical_dynamics',
            title: showOsc ? 'Vertical Oscillation' : 'Vertical Ratio',
            unit: showOsc ? 'cm' : '%',
            dotClass: 'bg-violet-500',
            height: 135,
            hasVerticalToggle:
                hasVerticalRatioStream.value && hasVerticalOscStream.value,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: showOsc
                        ? 'Vertical Oscillation'
                        : 'Vertical Ratio',
                    data: verticalSeries.solidData,
                    dottedData: verticalSeries.dottedData,
                    tooltipValues: verticalSeries.tooltipValues,
                    color: '#8b5cf6',
                    unit: showOsc ? 'cm' : '%',
                    min: verticalSeries.yMin,
                    max: verticalSeries.yMax,
                    visualMapPieces: showOsc
                        ? [
                              { lte: 6.7, color: '#8b5cf6' },
                              { gt: 6.7, lte: 8.3, color: '#3b82f6' },
                              { gt: 8.3, lte: 10.0, color: '#22c55e' },
                              { gt: 10.0, lte: 11.7, color: '#f97316' },
                              { gt: 11.7, color: '#ef4444' },
                          ]
                        : [
                              { lte: 6.1, color: '#8b5cf6' },
                              { gt: 6.1, lte: 7.4, color: '#3b82f6' },
                              { gt: 7.4, lte: 8.6, color: '#22c55e' },
                              { gt: 8.6, lte: 10.1, color: '#f97316' },
                              { gt: 10.1, color: '#ef4444' },
                          ],
                    avgLine: showVerticalAvgLine
                        ? {
                              value: avgVerticalValue,
                              label: `Avg ${avgVerticalValue.toFixed(1)} ${showOsc ? 'cm' : '%'}`,
                          }
                        : null,
                    valueFormatter: (v) =>
                        `${v.toFixed(1)} ${showOsc ? 'cm' : '%'}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasGctStream.value) {
        const rawGctData = series.value.map((p) => p.gct ?? null);
        const gctSeries = buildStartupGatedTelemetrySeries({
            times: streamTimes,
            values: rawGctData,
            step: 10,
            minMargin: 10,
            excludeZero: true,
            minFloor: 0,
        });
        const avgGct =
            props.activity.avg_ground_contact_time ?? averageOf(rawGctData);
        const avgGctValue = avgGct !== null ? Math.round(avgGct) : null;
        const showGctAvgLine =
            avgGctValue !== null &&
            (gctSeries.yMin === undefined || avgGctValue >= gctSeries.yMin) &&
            (gctSeries.yMax === undefined || avgGctValue <= gctSeries.yMax);

        activePanels.push({
            key: 'gct',
            title: 'Ground Contact Time',
            unit: 'ms',
            dotClass: 'bg-blue-500',
            height: 135,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Ground Contact Time',
                    data: gctSeries.solidData,
                    dottedData: gctSeries.dottedData,
                    tooltipValues: gctSeries.tooltipValues,
                    color: '#3b82f6',
                    unit: 'ms',
                    min: gctSeries.yMin,
                    max: gctSeries.yMax,
                    visualMapPieces: [
                        { lte: 208, color: '#8b5cf6' },
                        { gt: 208, lte: 240, color: '#3b82f6' },
                        { gt: 240, lte: 272, color: '#22c55e' },
                        { gt: 272, lte: 305, color: '#f97316' },
                        { gt: 305, color: '#ef4444' },
                    ],
                    avgLine: showGctAvgLine
                        ? {
                              value: avgGctValue,
                              label: `Avg ${avgGctValue} ms`,
                          }
                        : null,
                    valueFormatter: (v) => `${Math.round(v)} ms`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasRespirationStream.value) {
        const respData = series.value.map((p) => p.respiration ?? null);
        const avgResp =
            props.activity.avg_respiration_rate ?? averageOf(respData);

        activePanels.push({
            key: 'respiration',
            title: 'Respiration Rate',
            unit: 'brpm',
            dotClass: 'bg-cyan-500',
            height: 130,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Respiration Rate',
                    data: respData,
                    color: '#06b6d4',
                    areaColor: theme.isDark.value
                        ? 'rgba(6, 182, 212, 0.18)'
                        : 'rgba(6, 182, 212, 0.18)',
                    unit: 'brpm',
                    avgLine:
                        avgResp !== null
                            ? {
                                  value: Math.round(avgResp),
                                  label: `Avg ${Math.round(avgResp)} brpm`,
                              }
                            : null,
                    valueFormatter: (v) => `${Math.round(v)}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (hasTemperatureStream.value) {
        const tempUnitLabel = tempUnit.value;
        const rawTempData = series.value.map((p) => p.temperature ?? null);
        const tempData = rawTempData.map((tempC) =>
            tempC !== null ? Number(tempFromC(tempC).toFixed(1)) : null,
        );
        const avgTempC =
            props.activity.avg_temperature ?? averageOf(rawTempData);
        const avgTempDisplay =
            avgTempC !== null ? Number(tempFromC(avgTempC).toFixed(1)) : null;

        activePanels.push({
            key: 'temperature',
            title:
                props.weather !== null ? 'Device Temperature' : 'Temperature',
            unit: tempUnitLabel,
            dotClass: 'bg-zinc-400',
            height: 125,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName:
                        props.weather !== null
                            ? 'Device Temperature'
                            : 'Temperature',
                    data: tempData,
                    color: '#71717a',
                    areaColor: theme.isDark.value
                        ? 'rgba(161, 161, 170, 0.18)'
                        : 'rgba(113, 113, 122, 0.16)',
                    unit: tempUnitLabel,
                    step: 'end',
                    avgLine:
                        avgTempDisplay !== null
                            ? {
                                  value: avgTempDisplay,
                                  label: `Avg ${avgTempDisplay.toFixed(1)} ${tempUnitLabel}`,
                              }
                            : null,
                    valueFormatter: (v) => `${v.toFixed(1)} ${tempUnitLabel}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (runWalkSegments.value.length > 0) {
        const runPart =
            props.activity.run_duration_seconds !== null
                ? `Run ${formatDurationClock(props.activity.run_duration_seconds)}`
                : '';
        const walkPart =
            props.activity.walk_duration_seconds !== null
                ? `Walk ${formatDurationClock(props.activity.walk_duration_seconds)}`
                : '';
        const summaryUnit =
            [runPart, walkPart].filter(Boolean).join(' · ') || 'Run / Walk';

        activePanels.push({
            key: 'run_walk',
            title: 'Run / Walk Detection',
            unit: summaryUnit,
            dotClass: 'bg-sky-500',
            height: 110,
            build: (isLast) => buildRunWalkOption(isLast),
        });
    }

    return activePanels.map((panel, index) => ({
        key: panel.key,
        title: panel.title,
        unit: panel.unit,
        dotClass: panel.dotClass,
        height: panel.height,
        hasVerticalToggle: panel.hasVerticalToggle,
        hasPowerToggle: panel.hasPowerToggle,
        option: panel.build(index === activePanels.length - 1),
    }));
});

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

// Road Power Curve
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
    const raw = props.activity.power_curve;

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

    return {
        animation: false,
        grid: {
            left: 16,
            right: 16,
            top: 20,
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
            axisLabel: axisLabelStyle,
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
                lineStyle: { color: '#0ea5e9', width: 2.25 },
                itemStyle: { color: '#0ea5e9' },
                areaStyle: {
                    color: theme.isDark.value
                        ? 'rgba(14, 165, 233, 0.16)'
                        : 'rgba(14, 165, 233, 0.14)',
                },
                data: powerCurvePoints.value.map((p) => p.watts),
            },
        ],
    };
});

// Splits & Laps
const kmSplits = computed<KmSplitItem[]>(() => props.stream?.km_splits ?? []);
const splits = computed<SplitItem[]>(() =>
    computeSplitsFromSeries(series.value, kmSplits.value, units.value),
);
const laps = computed<Array<Record<string, number | null>>>(
    () => props.stream?.laps ?? [],
);

const fullSplitThresholdMeters = computed<number>(() =>
    isImperial.value ? 1528 : 950,
);

const splitsHavePower = computed<boolean>(() =>
    splits.value.some((s) => s.avg_power !== null && s.avg_power > 0),
);
const splitsHaveCadence = computed<boolean>(() =>
    splits.value.some((s) => s.avg_cadence !== null && s.avg_cadence > 0),
);
const splitsHaveGap = computed<boolean>(
    () => isRunning.value && splits.value.some((s) => s.gap_seconds !== null),
);
const lapsHaveGap = computed<boolean>(
    () =>
        isRunning.value &&
        laps.value.some(
            (l) => pickLapNumber(l, ['gap_seconds_per_km']) !== null,
        ),
);

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
    if (splitsTab.value === 'laps' && laps.value.length > 0) {
        return 'laps';
    }

    if (splits.value.length > 0) {
        return 'splits';
    }

    return 'laps';
});

const tabs = computed<
    Array<{ key: DetailTab; label: string; badge?: string | number }>
>(() => {
    const list: Array<{
        key: DetailTab;
        label: string;
        badge?: string | number;
    }> = [
        {
            key: 'overview',
            label: 'Overview & Streams',
            badge:
                telemetryPanels.value.length > 0
                    ? telemetryPanels.value.length
                    : undefined,
        },
        {
            key: 'pace',
            label: usesPace.value
                ? 'Pace, Splits & Zones'
                : 'Speed, Splits & Zones',
        },
    ];

    if (props.bestEfforts.length > 0) {
        list.push({
            key: 'efforts',
            label: 'Best Efforts',
            badge: props.bestEfforts.length,
        });
    }

    if (props.matchedRoute) {
        list.push({
            key: 'route',
            label: 'Matched Route',
            badge: props.matchedRoute.route.activity_count,
        });
    }

    list.push(
        {
            key: 'effort',
            label: 'Effort Context',
            badge: props.effortContext?.trimp?.activity_score ?? undefined,
        },
        {
            key: 'stats',
            label: 'All Stats',
        },
    );

    return list;
});
</script>

<template>
    <div class="space-y-6">
        <Head :title="activity.name" />

        <!-- Top Back & Prev/Next Bar -->
        <div class="flex items-center justify-between gap-3">
            <Link
                :href="activitiesIndex.url()"
                class="inline-flex items-center gap-1.5 font-mono text-xs text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            >
                <ArrowLeft class="size-3.5" />
                All Activities
            </Link>

            <div class="flex items-center gap-1.5">
                <Link
                    v-if="prevActivity"
                    :href="showActivity.url(prevActivity.id)"
                    :title="`Previous: ${prevActivity.name}`"
                    class="inline-flex items-center gap-1 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-600 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                >
                    <ChevronLeft class="size-3.5" />
                    <span class="hidden max-w-36 truncate sm:inline">
                        {{ prevActivity.name }}
                    </span>
                </Link>
                <span
                    v-else
                    class="inline-flex items-center rounded-lg border border-zinc-200/60 bg-white/50 px-2 py-1.5 text-zinc-300 dark:border-zinc-800/60 dark:bg-[#141417]/50 dark:text-zinc-700"
                >
                    <ChevronLeft class="size-3.5" />
                </span>

                <Link
                    v-if="nextActivity"
                    :href="showActivity.url(nextActivity.id)"
                    :title="`Next: ${nextActivity.name}`"
                    class="inline-flex items-center gap-1 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-600 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                >
                    <span class="hidden max-w-36 truncate sm:inline">
                        {{ nextActivity.name }}
                    </span>
                    <ChevronRight class="size-3.5" />
                </Link>
                <span
                    v-else
                    class="inline-flex items-center rounded-lg border border-zinc-200/60 bg-white/50 px-2 py-1.5 text-zinc-300 dark:border-zinc-800/60 dark:bg-[#141417]/50 dark:text-zinc-700"
                >
                    <ChevronRight class="size-3.5" />
                </span>
            </div>
        </div>

        <!-- Activity Header -->
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 font-mono text-[11px] font-medium"
                        :class="sportMeta.badgeClass"
                    >
                        <span
                            class="size-1.5 rounded-full"
                            :style="{ backgroundColor: sportMeta.accentHex }"
                        />
                        {{ sportMeta.label }}
                    </span>

                    <span
                        v-if="activity.is_indoor"
                        class="inline-flex items-center rounded-md border border-zinc-200 bg-zinc-100 px-2 py-0.5 font-mono text-[10px] tracking-wider text-zinc-600 uppercase dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                    >
                        Indoor
                    </span>

                    <span
                        v-if="activity.is_pr"
                        class="inline-flex items-center gap-1 rounded-md border border-amber-500/40 bg-amber-50 px-2 py-0.5 font-mono text-[10px] font-medium tracking-wider text-amber-700 uppercase dark:bg-amber-500/15 dark:text-amber-300"
                    >
                        <Trophy class="size-3 text-amber-500" />
                        PR
                    </span>
                </div>

                <h1
                    class="mt-2 text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    {{ activity.name }}
                </h1>

                <div
                    class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-zinc-500 dark:text-zinc-400"
                >
                    <span class="tabular-nums">{{ formattedStartedAt }}</span>
                    <span
                        v-if="activity.location_name"
                        class="inline-flex items-center gap-1"
                    >
                        <MapPin class="size-3 text-zinc-400" />
                        {{ activity.location_name }}
                    </span>
                </div>
            </div>

            <!-- Action Controls -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Favorite Star Toggle -->
                <button
                    type="button"
                    :disabled="isTogglingFavorite"
                    :aria-label="
                        activity.is_favorite
                            ? 'Remove from favorites'
                            : 'Mark as favorite'
                    "
                    class="inline-flex min-w-[108px] cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-3 py-2 font-mono text-xs font-medium transition-colors disabled:opacity-60"
                    :class="
                        activity.is_favorite
                            ? 'border-amber-500/50 bg-amber-50 text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-300'
                            : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                    "
                    @click="toggleFavorite"
                >
                    <Star
                        class="size-3.5"
                        :class="
                            activity.is_favorite
                                ? 'fill-amber-500 text-amber-500'
                                : 'text-zinc-400'
                        "
                    />
                    <span>{{
                        activity.is_favorite ? 'Favorited' : 'Favorite'
                    }}</span>
                </button>

                <!-- Discipline Override Reclassification Select -->
                <label
                    v-if="(discipline_options?.length ?? 0) > 0"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-500 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400"
                >
                    <span class="text-[10px] tracking-wider uppercase">
                        Discipline
                    </span>
                    <select
                        v-model="selectedDiscipline"
                        :disabled="isUpdatingDiscipline"
                        aria-label="Activity discipline"
                        class="cursor-pointer rounded bg-transparent pr-1 font-mono text-xs font-medium text-zinc-900 focus:outline-none dark:bg-[#141417] dark:text-zinc-100"
                        @change="updateDiscipline"
                    >
                        <option
                            v-for="option in discipline_options"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <!-- Assigned Gear Selector -->
                <label
                    v-if="gear && gear.available.length > 0"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-500 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400"
                >
                    <span class="text-[10px] tracking-wider uppercase">
                        Gear
                    </span>
                    <select
                        v-model="selectedGearId"
                        :disabled="isUpdatingGear"
                        aria-label="Assigned gear"
                        class="cursor-pointer rounded bg-transparent pr-1 font-mono text-xs font-medium text-zinc-900 focus:outline-none dark:bg-[#141417] dark:text-zinc-100"
                        @change="updateGear"
                    >
                        <option value="">None</option>
                        <option
                            v-for="item in gear.available"
                            :key="item.id"
                            :value="String(item.id)"
                        >
                            {{ item.name
                            }}{{ item.is_retired ? ' (Retired)' : '' }}
                        </option>
                    </select>
                </label>

                <!-- Sync FIT Streams Button -->
                <button
                    type="button"
                    :disabled="isSyncingStreams"
                    class="inline-flex min-w-[178px] cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-3 py-2 font-mono text-xs font-medium text-zinc-800 transition-colors hover:border-zinc-300 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-100 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80"
                    @click="syncStreams"
                >
                    <RefreshCw
                        :class="[
                            'size-3.5',
                            isSyncingStreams && 'animate-spin',
                        ]"
                    />
                    <span>
                        {{
                            isSyncingStreams
                                ? 'Syncing FIT...'
                                : activity.streams_synced_at
                                  ? 'Re-sync FIT Streams'
                                  : 'Sync FIT Streams'
                        }}
                    </span>
                </button>
            </div>
        </header>

        <!-- Primary KPI Strip -->
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            <div
                v-for="kpi in primaryKpis"
                :key="kpi.label"
                class="relative overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-3.5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <span
                    class="absolute top-0 left-0 h-0.5 w-8"
                    :class="kpi.accentClass"
                    :style="
                        kpi.accentHex
                            ? { backgroundColor: kpi.accentHex }
                            : undefined
                    "
                />
                <div
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ kpi.label }}
                </div>
                <div
                    class="mt-1 text-lg font-semibold tracking-tight text-zinc-900 tabular-nums sm:text-xl dark:text-zinc-100"
                >
                    {{ kpi.value }}
                </div>
                <div
                    v-if="kpi.sub"
                    class="mt-0.5 truncate font-mono text-[10px] text-zinc-400 tabular-nums dark:text-zinc-500"
                >
                    {{ kpi.sub }}
                </div>
            </div>
        </section>

        <!-- Discipline-Specific Telemetry Strip -->
        <section
            v-if="
                disciplineTelemetryTitle && disciplineTelemetryCells.length > 0
            "
            class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="mb-3 flex items-center gap-2">
                <span
                    class="size-1.5 rounded-full"
                    :style="{ backgroundColor: sportMeta.accentHex }"
                />
                <h2
                    class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    {{ disciplineTelemetryTitle }}
                </h2>
            </div>

            <div
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
            >
                <div
                    v-for="cell in disciplineTelemetryCells"
                    :key="cell.label"
                    class="rounded-lg border border-zinc-200/70 bg-[#f7f7f5]/70 px-3 py-2.5 dark:border-zinc-800/70 dark:bg-zinc-900/40"
                >
                    <div
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        {{ cell.label }}
                    </div>
                    <div
                        class="mt-1 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
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

        <!-- Interactive Route Map -->
        <section
            v-if="polyline.length > 0"
            class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="mb-2.5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Route
                    </span>

                    <!-- Active Best Effort Highlight Pill -->
                    <span
                        v-if="highlightedRange"
                        class="inline-flex items-center gap-1.5 rounded-md border border-orange-500/40 bg-orange-50 px-2 py-0.5 font-mono text-[11px] font-medium text-orange-700 dark:bg-orange-500/15 dark:text-orange-300"
                    >
                        {{ highlightedRange.label }}
                        <button
                            type="button"
                            class="cursor-pointer rounded hover:text-orange-950 dark:hover:text-orange-100"
                            aria-label="Clear segment highlight"
                            @click="highlightedRange = null"
                        >
                            <X class="size-3" />
                        </button>
                    </span>
                </div>

                <!-- Color-Mode Pills & Wind Arrows Toggle -->
                <div class="flex flex-wrap items-center gap-2">
                    <div
                        class="inline-flex flex-wrap rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                    >
                        <button
                            v-for="mode in mapColorOptions"
                            :key="mode.value"
                            type="button"
                            class="cursor-pointer rounded-md px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                            :class="
                                mapColorMode === mode.value
                                    ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                    : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="selectMapColorMode(mode.value)"
                        >
                            {{ mode.label }}
                        </button>
                    </div>

                    <button
                        v-if="hasWeatherSamples"
                        type="button"
                        :aria-pressed="showWindArrows"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            showWindArrows
                                ? 'border-sky-500/50 bg-sky-50 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-300'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="showWindArrows = !showWindArrows"
                    >
                        <Wind class="size-3.5" />
                        <span>Wind Arrows</span>
                    </button>
                </div>
            </div>

            <!-- Reserved Single-Line Live Hover Telemetry Strip -->
            <div
                class="mb-3 flex h-7 items-center overflow-x-auto rounded-md border border-zinc-200/80 bg-[#f7f7f5] px-2.5 font-mono text-[11px] whitespace-nowrap tabular-nums dark:border-zinc-800 dark:bg-zinc-900/70"
            >
                <div
                    v-if="hoveredPoint"
                    class="flex items-center gap-2.5 text-zinc-700 dark:text-zinc-300"
                >
                    <span v-if="hoveredPoint.distance !== null">
                        {{ formatDistance(hoveredPoint.distance, 2) }}
                    </span>
                    <span>
                        {{ formatDurationClock(hoveredPoint.time) }}
                    </span>
                    <span
                        v-if="hoveredPoint.speed !== null"
                        class="text-amber-600 dark:text-amber-400"
                    >
                        {{ formatPrimaryPaceOrSpeed(hoveredPoint.speed) }}
                    </span>
                    <span
                        v-if="hoveredPoint.hr !== null"
                        class="text-rose-600 dark:text-rose-400"
                    >
                        {{ Math.round(hoveredPoint.hr) }} bpm
                    </span>
                    <span
                        v-if="hoveredPoint.altitude !== null"
                        class="text-emerald-600 dark:text-emerald-400"
                    >
                        {{ formatElevation(hoveredPoint.altitude) }}
                    </span>
                    <span
                        v-if="hoveredPoint.power !== null"
                        class="text-sky-600 dark:text-sky-400"
                    >
                        {{ Math.round(hoveredPoint.power) }} W
                    </span>
                    <span
                        v-if="
                            hoveredPoint.stamina !== null &&
                            hoveredPoint.stamina !== undefined
                        "
                        class="text-orange-600 dark:text-orange-400"
                    >
                        {{ Math.round(hoveredPoint.stamina) }}% stam
                    </span>
                    <span
                        v-if="hoveredWeatherReadout"
                        class="inline-flex items-center gap-1.5 text-sky-600 dark:text-sky-400"
                    >
                        <Wind class="size-3 shrink-0" />
                        <span v-if="hoveredWeatherReadout.windText">
                            {{ hoveredWeatherReadout.windText }}
                        </span>
                        <template v-if="hoveredWeatherReadout.effectiveText">
                            <span class="text-zinc-400 dark:text-zinc-500">
                                ·
                            </span>
                            <span
                                :class="
                                    hoveredWeatherReadout.effectiveToneClass
                                "
                            >
                                {{ hoveredWeatherReadout.effectiveText }}
                            </span>
                        </template>
                        <template v-if="hoveredWeatherReadout.tempText">
                            <span
                                v-if="
                                    hoveredWeatherReadout.windText ||
                                    hoveredWeatherReadout.effectiveText
                                "
                                class="text-zinc-400 dark:text-zinc-500"
                            >
                                ·
                            </span>
                            <span class="text-zinc-600 dark:text-zinc-300">
                                {{ hoveredWeatherReadout.tempText }}
                            </span>
                        </template>
                    </span>
                </div>
                <span v-else class="text-zinc-400 dark:text-zinc-500">
                    Hover the route or telemetry charts to inspect point metrics
                </span>
            </div>

            <ActivityMap
                :polyline="polyline"
                :series="series"
                :color-mode="mapColorMode"
                :accent-color="sportMeta.accentHex"
                :active-index="activeStreamIndex"
                :highlight-range="highlightedRange"
                :jumps="jumps"
                :weather="weather"
                :show-wind-arrows="showWindArrows"
                :height="380"
                @hover-index="activeStreamIndex = $event"
            />
        </section>

        <!-- Missing Stream Notice -->
        <section
            v-if="!stream"
            class="flex flex-col items-center justify-between gap-4 rounded-xl border border-dashed border-zinc-200/90 bg-white/70 p-6 text-center sm:flex-row sm:text-left dark:border-zinc-800/90 dark:bg-[#141417]/70"
        >
            <div>
                <p
                    class="font-mono text-xs font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Detailed FIT streams not synced yet
                </p>
                <p
                    class="mt-1 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                >
                    Sync FIT data to view the route map, charts, and splits.
                </p>
            </div>
            <button
                type="button"
                :disabled="isSyncingStreams"
                class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 font-mono text-xs font-medium text-zinc-800 transition-colors hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                @click="syncStreams"
            >
                <RefreshCw
                    :class="['size-3.5', isSyncingStreams && 'animate-spin']"
                />
                {{ isSyncingStreams ? 'Syncing FIT...' : 'Sync FIT Streams' }}
            </button>
        </section>

        <!-- 5-Tab Analysis Navigation -->
        <nav
            aria-label="Activity analysis sections"
            class="flex flex-wrap items-center gap-1.5 border-b border-zinc-200/90 pb-3 dark:border-zinc-800/90"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                class="inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 font-mono text-xs font-medium transition-colors"
                :class="
                    activeTab === tab.key
                        ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                        : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                "
                @click="activeTab = tab.key"
            >
                <span>{{ tab.label }}</span>
                <span
                    v-if="tab.badge !== undefined"
                    class="rounded px-1.5 py-0.5 text-[10px] tabular-nums"
                    :class="
                        activeTab === tab.key
                            ? 'bg-white/20 text-white dark:bg-zinc-900/15 dark:text-zinc-900'
                            : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'
                    "
                >
                    {{ tab.badge }}
                </span>
            </button>
        </nav>

        <!-- TAB 1: Overview & Streams -->
        <div v-if="activeTab === 'overview'" class="space-y-6">
            <!-- Historical Weather & Directional Wind Summary -->
            <ActivityWeatherCard
                :weather="weather"
                :is-indoor="activity.is_indoor"
                :has-coordinates="hasCoordinates"
                :syncing="isSyncingWeather"
                @sync="syncActivityWeather"
            />

            <!-- Synchronized ECharts Telemetry Panels -->
            <section
                v-if="telemetryPanels.length > 0"
                class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="mb-4 flex items-center justify-between gap-2">
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Streams
                    </span>
                    <span
                        class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ formatNumber(series.length, 0) }} samples
                    </span>
                </div>

                <div class="space-y-4">
                    <div
                        v-for="panel in telemetryPanels"
                        :key="panel.key"
                        class="rounded-lg border border-zinc-100 bg-[#f7f7f5]/40 px-3 pt-2.5 pb-1 dark:border-zinc-800/70 dark:bg-zinc-900/30"
                    >
                        <div
                            class="mb-1 flex items-center justify-between gap-2"
                        >
                            <span
                                class="inline-flex items-center gap-1.5 font-mono text-[11px] font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :class="panel.dotClass"
                                />
                                {{ panel.title }}
                            </span>

                            <div class="flex items-center gap-2">
                                <div
                                    v-if="panel.hasVerticalToggle"
                                    class="inline-flex rounded-md border border-zinc-200/80 bg-white p-0.5 text-[10px] dark:border-zinc-800 dark:bg-[#141417]"
                                >
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded px-1.5 py-0.5 font-mono"
                                        :class="
                                            verticalMetricMode === 'ratio'
                                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                                : 'text-zinc-500'
                                        "
                                        @click="verticalMetricMode = 'ratio'"
                                    >
                                        Ratio %
                                    </button>
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded px-1.5 py-0.5 font-mono"
                                        :class="
                                            verticalMetricMode === 'oscillation'
                                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                                : 'text-zinc-500'
                                        "
                                        @click="
                                            verticalMetricMode = 'oscillation'
                                        "
                                    >
                                        Osc cm
                                    </button>
                                </div>

                                <div
                                    v-if="panel.hasPowerToggle"
                                    class="inline-flex rounded-md border border-zinc-200/80 bg-white p-0.5 text-[10px] dark:border-zinc-800 dark:bg-[#141417]"
                                >
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded px-1.5 py-0.5 font-mono"
                                        :class="
                                            powerUnitMode === 'watts'
                                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                                : 'text-zinc-500'
                                        "
                                        @click="powerUnitMode = 'watts'"
                                    >
                                        Watts
                                    </button>
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded px-1.5 py-0.5 font-mono"
                                        :class="
                                            powerUnitMode === 'pct'
                                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                                : 'text-zinc-500'
                                        "
                                        @click="powerUnitMode = 'pct'"
                                    >
                                        % Thresh
                                    </button>
                                </div>

                                <span
                                    class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                                >
                                    {{ panel.unit }}
                                </span>
                            </div>
                        </div>

                        <BaseChart
                            :option="panel.option"
                            :height="panel.height"
                            group="activity-telemetry"
                            @hover-index="activeStreamIndex = $event"
                        />
                    </div>
                </div>
            </section>

            <!-- MTB Jumps Table -->
            <section
                v-if="jumps.length > 0"
                class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="flex items-center justify-between border-b border-zinc-200/80 px-4 py-3 sm:px-5 dark:border-zinc-800/80"
                >
                    <span
                        class="font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Detected Jumps ({{ jumps.length }})
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm tabular-nums">
                        <thead
                            class="border-b border-zinc-200/80 bg-[#f7f7f5]/80 font-mono text-[11px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/50 dark:text-zinc-400"
                        >
                            <tr>
                                <th class="px-4 py-2.5 font-medium">Jump</th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Time
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Distance
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Hang Time
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Speed
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Score
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-zinc-100 font-mono text-xs dark:divide-zinc-800/80"
                        >
                            <tr
                                v-for="(jump, idx) in jumps"
                                :key="idx"
                                class="hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40"
                            >
                                <td
                                    class="px-4 py-2.5 font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    #{{ idx + 1 }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-zinc-600 dark:text-zinc-400"
                                >
                                    {{ formatDurationClock(jump.time) }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        isImperial
                                            ? `${(jump.distance_meters * M_TO_FT).toFixed(1)} ft`
                                            : `${jump.distance_meters.toFixed(1)} m`
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
                                >
                                    {{ jump.hang_time_seconds.toFixed(2) }} s
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
                                >
                                    {{
                                        jump.speed !== null
                                            ? formatSpeed(jump.speed)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-indigo-600 dark:text-indigo-400"
                                >
                                    {{ jump.score.toFixed(1) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- TAB 2: Pace / Speed, Splits & Zones -->
        <div v-else-if="activeTab === 'pace'" class="space-y-6">
            <!-- Combined Pace / Speed Analysis Bar Chart over Elevation -->
            <ActivityPaceAnalysis
                :series="series"
                :km-splits="kmSplits"
                :laps="laps"
                :uses-pace="usesPace"
                :is-running="isRunning"
                :accent-hex="sportMeta.accentHex"
            />

            <!-- Pace Distribution (Running) -->
            <ActivityPaceDistribution
                v-if="paceDistribution"
                :pace-distribution="paceDistribution"
            />

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
                                        v-if="formatZoneBpmRange(zone)"
                                        class="hidden text-[11px] text-zinc-400 sm:inline dark:text-zinc-500"
                                    >
                                        {{ formatZoneBpmRange(zone) }}
                                    </span>
                                </div>

                                <div class="flex shrink-0 items-center gap-3">
                                    <span
                                        class="text-zinc-700 dark:text-zinc-300"
                                    >
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
                            {{
                                isRunning
                                    ? 'Running Power Zones'
                                    : 'Power Zones'
                            }}
                        </span>
                        <span
                            class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                        >
                            Z1 – Z{{ powerZones.length }}
                            <template v-if="thresholdPowerRef">
                                · Thresh {{ Math.round(thresholdPowerRef) }} W
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
                                        v-if="formatZoneWattRange(zone)"
                                        class="hidden text-[11px] text-zinc-400 sm:inline dark:text-zinc-500"
                                    >
                                        {{ formatZoneWattRange(zone) }}
                                    </span>
                                </div>

                                <div class="flex shrink-0 items-center gap-3">
                                    <span
                                        class="text-zinc-700 dark:text-zinc-300"
                                    >
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
                                class="font-semibold text-sky-600 dark:text-sky-400"
                            >
                                {{ pt.watts }}W
                            </span>
                        </span>
                    </div>
                </div>

                <BaseChart :option="powerCurveOption" :height="240" />
            </section>

            <!-- Splits & Laps Card (placed at bottom since it's often the longest) -->
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
                        Splits &amp; Laps
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
                            {{ isImperial ? '1 mi Splits' : '1 km Splits' }} ({{
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
                                <th class="px-4 py-2.5 font-medium">
                                    {{ isImperial ? 'Mile' : 'Km' }}
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    {{ usesPace ? 'Pace' : 'Speed' }}
                                </th>
                                <th
                                    v-if="splitsHaveGap"
                                    class="px-4 py-2.5 text-right font-medium"
                                >
                                    GAP
                                </th>
                                <th
                                    class="hidden w-36 px-4 py-2.5 font-medium sm:table-cell"
                                >
                                    Effort
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Grade
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Elev +/-
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Avg HR
                                </th>
                                <th
                                    v-if="splitsHavePower"
                                    class="px-4 py-2.5 text-right font-medium"
                                >
                                    Power
                                </th>
                                <th
                                    v-if="splitsHaveCadence"
                                    class="px-4 py-2.5 text-right font-medium"
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
                                            formatNumber(
                                                distanceFromMeters(
                                                    split.distance_meters,
                                                ),
                                                2,
                                            )
                                        }}
                                        {{ distanceUnit }})
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-medium text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        usesPace
                                            ? formatPaceSeconds(
                                                  split.pace_seconds,
                                              )
                                            : formatSpeed(split.avg_speed)
                                    }}
                                </td>
                                <td
                                    v-if="splitsHaveGap"
                                    class="px-4 py-2.5 text-right text-amber-600 dark:text-amber-400"
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
                                                backgroundColor:
                                                    sportMeta.accentHex,
                                            }"
                                        />
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-zinc-600 dark:text-zinc-400"
                                >
                                    {{
                                        `${split.avg_grade > 0 ? '+' : ''}${split.avg_grade.toFixed(1)}%`
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right whitespace-nowrap"
                                >
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
                                    <span
                                        class="text-zinc-500 dark:text-zinc-400"
                                    >
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
                                <td class="px-4 py-2.5 text-right">
                                    <span
                                        v-if="split.avg_hr !== null"
                                        class="text-rose-600 dark:text-rose-400"
                                    >
                                        {{ Math.round(split.avg_hr) }}
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
                                    class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
                                >
                                    {{
                                        split.avg_power !== null
                                            ? `${Math.round(split.avg_power)} W`
                                            : '—'
                                    }}
                                </td>
                                <td
                                    v-if="splitsHaveCadence"
                                    class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
                                >
                                    {{
                                        split.avg_cadence !== null
                                            ? `${Math.round(split.avg_cadence)} ${sportMeta.cadenceUnit}`
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
                                <th class="px-4 py-2.5 font-medium">Lap</th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Distance
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Time
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    {{ usesPace ? 'Pace' : 'Speed' }}
                                </th>
                                <th
                                    v-if="lapsHaveGap"
                                    class="px-4 py-2.5 text-right font-medium"
                                >
                                    GAP
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Elev Gain
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Avg HR
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
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
                                            pickLapNumber(lap, [
                                                'gap_seconds_per_km',
                                            ]),
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
                                    class="px-4 py-2.5 text-right text-zinc-700 dark:text-zinc-300"
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
                                            {{ sportMeta.cadenceUnit }}
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
                                        {{ sportMeta.cadenceUnit }}
                                    </template>
                                    <template v-else>—</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- TAB 3: Best Efforts -->
        <ActivityBestEfforts
            v-else-if="activeTab === 'efforts' && bestEfforts.length > 0"
            :best-efforts="bestEfforts"
            :series="series"
            :uses-pace="usesPace"
            @select-range="highlightedRange = $event"
        />

        <!-- TAB 4: Matched Route -->
        <ActivityMatchedRoute
            v-else-if="activeTab === 'route' && matchedRoute"
            :matched-route="matchedRoute"
        />

        <!-- TAB 5: Effort Context -->
        <ActivityEffortContext
            v-else-if="activeTab === 'effort'"
            :effort-context="effortContext"
        />

        <!-- TAB 5: All Stats -->
        <ActivityAllStats
            v-else-if="activeTab === 'stats'"
            :activity="activity"
            :uses-pace="usesPace"
            :weather="weather"
        />
    </div>
</template>
