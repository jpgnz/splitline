<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowLeftRight,
    ChevronLeft,
    ChevronRight,
    CloudSun,
    MapPin,
    RefreshCw,
    Route as RouteIcon,
    Share2,
    Star,
    Trophy,
    Wind,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InfoPopover from '@/components/InfoPopover.vue';
import type { MetricGlossaryTopic } from '@/lib/metricGlossary';
import {
    syncWeather,
    toggleFavorite as toggleFavoriteAction,
} from '@/actions/App/Http/Controllers/ActivityController';
import ActivityAllStats from '@/components/activities/ActivityAllStats.vue';
import ActivityBestEfforts from '@/components/activities/ActivityBestEfforts.vue';
import ActivityDerivedMetricsCard from '@/components/activities/ActivityDerivedMetricsCard.vue';
import ActivityEffortContext from '@/components/activities/ActivityEffortContext.vue';
import ActivityExportMenu from '@/components/activities/ActivityExportMenu.vue';
import ActivityHeaderActions, {
    type ActivityGearPayload,
    type DisciplineOption,
} from '@/components/activities/ActivityHeaderActions.vue';
import ActivityMatchedRoute from '@/components/activities/ActivityMatchedRoute.vue';
import ActivityPaceAnalysis from '@/components/activities/ActivityPaceAnalysis.vue';
import ActivityPaceDistribution from '@/components/activities/ActivityPaceDistribution.vue';
import ActivitySegmentsPanel from '@/components/activities/ActivitySegmentsPanel.vue';
import ActivitySessionSnapshotRail from '@/components/activities/ActivitySessionSnapshotRail.vue';
import ActivityShareCardModal from '@/components/activities/ActivityShareCardModal.vue';
import ActivitySplitsAndLaps from '@/components/activities/ActivitySplitsAndLaps.vue';
import ActivityStreamsPanel, {
    countTelemetryPanels,
    detectAvailableStreams,
} from '@/components/activities/ActivityStreamsPanel.vue';
import ActivityWeatherCard from '@/components/activities/ActivityWeatherCard.vue';
import ActivityZonesAndPower from '@/components/activities/ActivityZonesAndPower.vue';
import ActivityMap from '@/components/maps/ActivityMap.vue';
import {
    formatDurationClock,
    formatNumber,
    formatTimeOfDay,
} from '@/lib/format';
import { hasAnyDurationPeakEfforts } from '@/lib/peakEfforts';
import { useSports } from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    computeEffectiveWind,
    computeSegmentBearing,
    formatCompass,
    interpolateWeatherAt,
} from '@/lib/wind';
import {
    compare as compareActivities,
    index as activitiesIndex,
    show as showActivity,
} from '@/routes/activities';
import type {
    ActivityBestEffortItem,
    ActivityDerivedMetricsPayload,
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
    StreamPoint,
} from '@/types/activity';
import type { ActivityMatchedRoutePayload } from '@/types/routes';
import type { ActivitySegmentEffortItem } from '@/types/segments';

interface AdjacentActivity {
    id: number;
    name: string;
}

interface ActivityShowProps {
    activity: ActivityDetail;
    hrZones: HrZoneItem[];
    powerZones: PowerZoneItem[];
    paceDistribution: PaceDistributionPayload | null;
    effortContext: EffortContextPayload;
    stream: ActivityStreamPayload | null;
    weather: ActivityWeatherPayload | null;
    derivedMetrics?: ActivityDerivedMetricsPayload | null;
    bestEfforts: ActivityBestEffortItem[];
    powerEffortRanks?: Record<string, number> | null;
    athleteWeightKg?: number | null;
    prevActivity: AdjacentActivity | null;
    nextActivity: AdjacentActivity | null;
    gear?: ActivityGearPayload;
    matchedRoute?: ActivityMatchedRoutePayload | null;
    segmentEfforts?: ActivitySegmentEffortItem[];
    hasOriginalArchive?: boolean;
    discipline_options?: DisciplineOption[];
}

type DetailTab =
    | 'streams'
    | 'splits'
    | 'efforts_segments'
    | 'context'
    | 'stats';

const props = defineProps<ActivityShowProps>();
const { getSportMeta, resolveActivitySportKey, sportBadgeClasses } =
    useSports();
const {
    elevationFromMeters,
    formatDistance,
    formatElevation,
    formatFluidVolume,
    formatOscillation,
    formatPace,
    formatPace100m,
    formatSpeed,
    formatSpeedKmh,
    formatStride,
    formatTemp,
} = useUnits();

const headerActionsRef = ref<InstanceType<typeof ActivityHeaderActions> | null>(
    null,
);
const activeTab = ref<DetailTab>('streams');
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
const shareModalOpen = ref<boolean>(false);

watch(
    () => props.activity.id,
    () => {
        activeTab.value = 'streams';
        activeStreamIndex.value = null;
        mapColorMode.value = 'solid';
        showWindArrows.value = false;
        highlightedRange.value = null;
        splitsTab.value =
            (props.stream?.km_splits?.length ?? 0) > 0 ? 'splits' : 'laps';
        shareModalOpen.value = false;
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
const isCycling = computed<boolean>(
    () => props.activity.sport_category === 'biking',
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

function syncStreams(): void {
    headerActionsRef.value?.syncStreams();
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
        infoTopic?: MetricGlossaryTopic;
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
            ? `${formatNumber(Math.round(elevationFromMeters(a.min_elevation)), 0)}–${formatElevation(a.max_elevation)}`
            : a.elevation_loss !== null
              ? `-${formatElevation(a.elevation_loss)}`
              : null;

    const calSub =
        a.sweat_loss_ml !== null
            ? `Sweat ${formatFluidVolume(a.sweat_loss_ml)}`
            : null;

    const trimpScore = props.effortContext?.trimp?.activity_score;
    const hasTrimp = trimpScore !== null && trimpScore !== undefined;
    const teLabel = formatTrainingEffectLabel(a.training_effect_label);

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
            label: hasTrimp ? 'TRIMP' : 'Training Load',
            value: hasTrimp
                ? `${formatNumber(trimpScore, 0)}`
                : a.training_load !== null
                  ? formatNumber(a.training_load, 0)
                  : '—',
            sub:
                hasTrimp && a.training_load !== null
                    ? teLabel
                        ? `Load ${formatNumber(a.training_load, 0)} · ${teLabel}`
                        : `Load ${formatNumber(a.training_load, 0)}`
                    : teLabel,
            accentClass: 'bg-indigo-500',
            infoTopic: 'trimp',
        },
    ];
});

// Discipline-specific Telemetry Cards (now surfacing Stamina, Power, Dynamics, Feel/RPE, Execution Score across sports)
interface TelemetryCell {
    label: string;
    value: string;
    sub?: string | null;
    infoTopic?: MetricGlossaryTopic;
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
            infoTopic: 'body_battery',
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
                    [
                        a.avg_vertical_oscillation !== null
                            ? `Osc ${formatOscillation(a.avg_vertical_oscillation)}`
                            : null,
                        a.avg_vertical_ratio !== null &&
                        a.avg_ground_contact_time === null
                            ? `Vert Ratio ${a.avg_vertical_ratio.toFixed(1)}%`
                            : null,
                    ]
                        .filter((part): part is string => part !== null)
                        .join(' · ') || null,
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
                infoTopic: 'normalized_power',
            });
        }

        if (a.tss !== null || a.ftp !== null) {
            cells.push({
                label: 'TSS / FTP',
                value: a.tss !== null ? formatNumber(a.tss, 0) : '—',
                sub: a.ftp !== null ? `FTP ${Math.round(a.ftp)} W` : null,
                infoTopic: 'tss',
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
                infoTopic: 'grit_flow',
            });
        }

        if (a.avg_flow !== null && a.avg_flow > 0) {
            cells.push({
                label: 'Avg Flow',
                value: formatNumber(a.avg_flow, 1),
                sub: 'Lower is smoother',
                infoTopic: 'grit_flow',
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
const kmSplits = computed<KmSplitItem[]>(() => props.stream?.km_splits ?? []);
const laps = computed<Array<Record<string, number | null>>>(
    () => props.stream?.laps ?? [],
);

const availableStreams = computed(() => detectAvailableStreams(series.value));
const telemetryPanelCount = computed<number>(() =>
    countTelemetryPanels(series.value, runWalkSegments.value),
);

const hasWeatherSamples = computed<boolean>(
    () => props.weather !== null && props.weather.samples.length > 0,
);

const hasGpsTrack = computed<boolean>(
    () =>
        polyline.value.length > 0 ||
        series.value.some((p) => p.lat !== null && p.lng !== null),
);

const hasCoordinates = computed<boolean>(
    () =>
        (props.activity.start_lat !== null &&
            props.activity.start_lng !== null) ||
        hasGpsTrack.value,
);

function selectMapColorMode(mode: MapColorMode): void {
    mapColorMode.value = mode;

    if (mode === 'wind' && hasWeatherSamples.value) {
        showWindArrows.value = true;
    }
}

const mapColorOptions = computed<Array<{ value: MapColorMode; label: string }>>(
    () => {
        const available = availableStreams.value;
        const opts: Array<{ value: MapColorMode; label: string }> = [
            { value: 'solid', label: 'Route' },
        ];

        if (available.speed) {
            opts.push({
                value: 'speed',
                label: usesPace.value ? 'Pace' : 'Speed',
            });
        }

        if (available.hr) {
            opts.push({ value: 'hr', label: 'HR' });
        }

        if (available.altitude) {
            opts.push({ value: 'altitude', label: 'Elevation' });
        }

        if (available.power) {
            opts.push({ value: 'power', label: 'Power' });
        }

        if (available.stamina) {
            opts.push({ value: 'stamina', label: 'Stamina' });
        }

        if (available.grit) {
            opts.push({ value: 'grit', label: 'Grit' });
        }

        if (available.flow) {
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
                } else if (effective.classification === 'crosswind') {
                    effectiveText = `${formatSpeedKmh(effective.crosswindKmh, 0)} cross`;
                    effectiveToneClass = 'text-amber-600 dark:text-amber-400';
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
                            : 'tail';

                    effectiveText = `${signedSpeed} ${kind}`;
                    effectiveToneClass =
                        effective.classification === 'headwind'
                            ? 'text-rose-600 dark:text-rose-400'
                            : 'text-emerald-600 dark:text-emerald-400';
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

const segmentEffortsList = computed<ActivitySegmentEffortItem[]>(
    () => props.segmentEfforts ?? [],
);

const hasGnssStreamForSegments = computed<boolean>(() => {
    if (props.activity.is_indoor) {
        return false;
    }

    let gnssCount = 0;

    for (const pt of series.value) {
        if (
            pt.lat !== null &&
            pt.lng !== null &&
            Number.isFinite(pt.lat) &&
            Number.isFinite(pt.lng)
        ) {
            gnssCount += 1;

            if (gnssCount >= 5) {
                return true;
            }
        }
    }

    return false;
});

const showSegmentsTab = computed<boolean>(
    () => segmentEffortsList.value.length > 0 || hasGnssStreamForSegments.value,
);

const hasBestEffortsCard = computed<boolean>(
    () =>
        props.bestEfforts.length > 0 ||
        hasAnyDurationPeakEfforts(series.value, {
            isRunning: isRunning.value,
            isCycling: isCycling.value,
            isIndoor: props.activity.is_indoor,
        }),
);

const showEffortsSegmentsTab = computed<boolean>(
    () => hasBestEffortsCard.value || showSegmentsTab.value,
);

const effortsSegmentsCount = computed<number>(
    () => props.bestEfforts.length + segmentEffortsList.value.length,
);

const compareUrl = computed<string>(() =>
    compareActivities.url({ query: { ids: String(props.activity.id) } }),
);

const canSyncWeather = computed<boolean>(
    () =>
        !props.activity.is_indoor &&
        (props.weather !== null || hasCoordinates.value),
);

const hasDerivedMetrics = computed<boolean>(() => {
    const dm = props.derivedMetrics;

    if (!dm) {
        return false;
    }

    return (
        (dm.decoupling_pct !== null && Number.isFinite(dm.decoupling_pct)) ||
        (dm.efficiency_factor !== null &&
            Number.isFinite(dm.efficiency_factor)) ||
        dm.pacing_strategy !== null ||
        (dm.hr_low_pct !== null &&
            dm.hr_moderate_pct !== null &&
            dm.hr_high_pct !== null)
    );
});

const hasSnapshotRail = computed<boolean>(
    () =>
        hasDerivedMetrics.value ||
        canSyncWeather.value ||
        (disciplineTelemetryTitle.value !== null &&
            disciplineTelemetryCells.value.length > 0),
);

const headerWeatherSummary = computed<string | null>(() => {
    const w = props.weather;

    if (!w || props.activity.is_indoor) {
        return null;
    }

    const parts: string[] = [];

    if (w.temperature_c !== null) {
        parts.push(formatTemp(w.temperature_c, 0));
    }

    if (w.wind_speed_kmh !== null) {
        const compass =
            w.wind_direction_compass ??
            (w.wind_direction_deg !== null
                ? formatCompass(w.wind_direction_deg)
                : null);
        parts.push(
            compass
                ? `${formatSpeedKmh(w.wind_speed_kmh, 0)} ${compass}`
                : formatSpeedKmh(w.wind_speed_kmh, 0),
        );
    }

    return parts.length > 0 ? parts.join(' · ') : w.condition;
});

const primaryMapColorOptions = computed(() =>
    mapColorOptions.value.slice(0, 5),
);

const overflowMapColorOptions = computed(() => mapColorOptions.value.slice(5));

const activeOverflowColorMode = computed<string>(() =>
    overflowMapColorOptions.value.some((o) => o.value === mapColorMode.value)
        ? mapColorMode.value
        : '',
);

function onOverflowMapColorChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value as
        | MapColorMode
        | '';

    if (value) {
        selectMapColorMode(value);
    }
}

const tabs = computed<
    Array<{ key: DetailTab; label: string; badge?: string | number }>
>(() => {
    const list: Array<{
        key: DetailTab;
        label: string;
        badge?: string | number;
    }> = [
        {
            key: 'streams',
            label: 'Streams',
        },
        {
            key: 'splits',
            label: 'Splits & Zones',
        },
    ];

    if (showEffortsSegmentsTab.value) {
        list.push({
            key: 'efforts_segments',
            label: 'Efforts & Segments',
            badge:
                effortsSegmentsCount.value > 0
                    ? effortsSegmentsCount.value
                    : undefined,
        });
    }

    list.push(
        {
            key: 'context',
            label: props.matchedRoute ? 'Route & Effort' : 'Effort Context',
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
                    :aria-label="`Previous activity: ${prevActivity.name}`"
                    class="inline-flex items-center gap-1 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-600 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                >
                    <ChevronLeft class="size-3.5" />
                    <span class="hidden max-w-36 truncate sm:inline">
                        {{ prevActivity.name }}
                    </span>
                </Link>
                <span
                    v-else
                    aria-disabled="true"
                    aria-label="No previous activity"
                    class="inline-flex items-center rounded-lg border border-zinc-200/60 bg-white/50 px-2 py-1.5 text-zinc-300 dark:border-zinc-800/60 dark:bg-[#141417]/50 dark:text-zinc-700"
                >
                    <ChevronLeft class="size-3.5" />
                </span>

                <Link
                    v-if="nextActivity"
                    :href="showActivity.url(nextActivity.id)"
                    :title="`Next: ${nextActivity.name}`"
                    :aria-label="`Next activity: ${nextActivity.name}`"
                    class="inline-flex items-center gap-1 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs text-zinc-600 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                >
                    <span class="hidden max-w-36 truncate sm:inline">
                        {{ nextActivity.name }}
                    </span>
                    <ChevronRight class="size-3.5" />
                </Link>
                <span
                    v-else
                    aria-disabled="true"
                    aria-label="No next activity"
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

                    <button
                        v-if="matchedRoute"
                        type="button"
                        class="inline-flex max-w-full cursor-pointer items-center gap-1.5 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 font-mono text-[10px] font-medium text-emerald-700 transition-colors hover:bg-emerald-500/15 dark:text-emerald-300"
                        @click="activeTab = 'context'"
                    >
                        <RouteIcon class="size-3 shrink-0" />
                        <span class="max-w-52 truncate">
                            {{ matchedRoute.route.name }}
                        </span>
                        <span class="shrink-0 tabular-nums opacity-80">
                            · #{{ matchedRoute.current.rank }}/{{
                                matchedRoute.current.total_attempts
                            }}
                        </span>
                    </button>

                    <span
                        v-if="headerWeatherSummary"
                        class="inline-flex items-center gap-1 rounded-md border border-zinc-200/80 bg-white px-2 py-0.5 font-mono text-[10px] text-zinc-600 tabular-nums dark:border-zinc-800 dark:bg-[#141417] dark:text-zinc-400"
                    >
                        <CloudSun
                            class="size-3 shrink-0 text-sky-500 dark:text-sky-400"
                        />
                        {{ headerWeatherSummary }}
                    </span>
                </div>

                <div class="mt-2 flex items-center gap-2.5">
                    <h1
                        class="min-w-0 text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                    >
                        {{ activity.name }}
                    </h1>
                    <button
                        type="button"
                        :disabled="isTogglingFavorite"
                        :aria-pressed="activity.is_favorite"
                        :title="
                            activity.is_favorite
                                ? 'Remove from favorites'
                                : 'Mark as favorite'
                        "
                        :aria-label="
                            activity.is_favorite
                                ? 'Remove from favorites'
                                : 'Mark as favorite'
                        "
                        class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg border transition-colors disabled:opacity-60"
                        :class="
                            activity.is_favorite
                                ? 'border-amber-500/50 bg-amber-50 text-amber-500 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-400'
                                : 'border-zinc-200/90 bg-white text-zinc-400 hover:border-zinc-300 hover:text-amber-500 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-500 dark:hover:border-zinc-700 dark:hover:text-amber-400'
                        "
                        @click="toggleFavorite"
                    >
                        <Star
                            class="size-4"
                            :class="
                                activity.is_favorite
                                    ? 'fill-amber-400 text-amber-500 dark:fill-amber-400 dark:text-amber-400'
                                    : ''
                            "
                        />
                    </button>
                </div>

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
                    <span
                        v-if="gear?.assigned_name"
                        class="text-zinc-400 dark:text-zinc-500"
                    >
                        · {{ gear.assigned_name }}
                    </span>
                </div>
            </div>

            <!-- Action Controls -->
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    :href="compareUrl"
                    prefetch
                    class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-emerald-500/35 bg-emerald-500/10 px-2.5 py-1.5 font-mono text-xs font-medium whitespace-nowrap text-emerald-700 transition-colors hover:bg-emerald-500/15 dark:border-emerald-500/30 dark:text-emerald-300 dark:hover:bg-emerald-500/20"
                >
                    <ArrowLeftRight class="size-3.5" />
                    <span>Compare</span>
                </Link>
                <button
                    type="button"
                    class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-xs font-medium whitespace-nowrap text-zinc-700 transition-colors hover:border-zinc-300 hover:bg-zinc-50 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80 dark:hover:text-zinc-100"
                    @click="shareModalOpen = true"
                >
                    <Share2 class="size-3.5" />
                    <span>Share</span>
                </button>
                <ActivityExportMenu
                    :activity-id="activity.id"
                    :has-gps="hasGpsTrack"
                    :has-original-archive="Boolean(hasOriginalArchive)"
                />
                <ActivityHeaderActions
                    ref="headerActionsRef"
                    v-model:syncing-streams="isSyncingStreams"
                    :activity="activity"
                    :gear="gear"
                    :discipline-options="discipline_options"
                    :can-sync-weather="canSyncWeather"
                    :has-weather="weather !== null"
                    :syncing-weather="isSyncingWeather"
                    @sync-weather="syncActivityWeather"
                />
            </div>
        </header>

        <!-- Primary KPI Strip (7 Cards) -->
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
                    class="flex items-center gap-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    <span class="truncate">{{ kpi.label }}</span>
                    <InfoPopover v-if="kpi.infoTopic" :topic="kpi.infoTopic" />
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

        <!-- Option B: Hero Split (Route Map + Session Snapshot Rail) -->
        <section
            v-if="polyline.length > 0"
            class="grid grid-cols-1 gap-6 xl:grid-cols-12"
        >
            <!-- Interactive Route Map -->
            <div
                :class="hasSnapshotRail ? 'xl:col-span-7' : 'xl:col-span-12'"
                class="flex h-full flex-col rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div
                    class="mb-2.5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex flex-wrap items-center gap-2.5">
                        <div class="flex items-center gap-2">
                            <span
                                class="size-1.5 rounded-full"
                                :style="{
                                    backgroundColor: sportMeta.accentHex,
                                }"
                            />
                            <h2
                                class="font-mono text-[11px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                Route Map
                            </h2>
                        </div>

                        <!-- Active Best Effort / Segment Highlight Pill -->
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

                    <!-- Compact Color-Mode Switcher & Wind Arrows Toggle -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <div
                            class="inline-flex flex-wrap items-center rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                        >
                            <button
                                v-for="mode in primaryMapColorOptions"
                                :key="mode.value"
                                type="button"
                                class="cursor-pointer rounded-md px-2 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    mapColorMode === mode.value
                                        ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="selectMapColorMode(mode.value)"
                            >
                                {{ mode.label }}
                            </button>

                            <select
                                v-if="overflowMapColorOptions.length > 0"
                                :value="activeOverflowColorMode"
                                aria-label="More map color modes"
                                class="cursor-pointer rounded-md px-1.5 py-1 font-mono text-[11px] font-medium transition-colors focus:outline-none"
                                :class="
                                    activeOverflowColorMode
                                        ? 'bg-white text-zinc-950 dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'bg-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @change="onOverflowMapColorChange"
                            >
                                <option value="">More…</option>
                                <option
                                    v-for="mode in overflowMapColorOptions"
                                    :key="mode.value"
                                    :value="mode.value"
                                >
                                    {{ mode.label }}
                                </option>
                            </select>
                        </div>

                        <button
                            v-if="hasWeatherSamples"
                            type="button"
                            :aria-pressed="showWindArrows"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2 py-1.5 font-mono text-[11px] font-medium transition-colors"
                            :class="
                                showWindArrows
                                    ? 'border-sky-500/50 bg-sky-50 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-300'
                                    : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                            "
                            @click="showWindArrows = !showWindArrows"
                        >
                            <Wind class="size-3.5" />
                            <span>Wind</span>
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
                            {{ Math.round(hoveredPoint.stamina) }}% stamina
                        </span>
                        <span
                            v-if="hoveredWeatherReadout"
                            class="inline-flex items-center gap-1.5 text-sky-600 dark:text-sky-400"
                        >
                            <Wind class="size-3 shrink-0" />
                            <span v-if="hoveredWeatherReadout.windText">
                                {{ hoveredWeatherReadout.windText }}
                            </span>
                            <template
                                v-if="hoveredWeatherReadout.effectiveText"
                            >
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
                        Hover the route or telemetry charts to inspect point
                        metrics
                    </span>
                </div>

                <div class="min-h-[380px] flex-1">
                    <ActivityMap
                        :polyline="polyline"
                        :series="series"
                        :color-mode="mapColorMode"
                        :uses-pace="usesPace"
                        :accent-color="sportMeta.accentHex"
                        :active-index="activeStreamIndex"
                        :highlight-range="highlightedRange"
                        :jumps="jumps"
                        :weather="weather"
                        :show-wind-arrows="showWindArrows"
                        height="100%"
                        @hover-index="activeStreamIndex = $event"
                    />
                </div>
            </div>

            <!-- Session Snapshot Rail (Derived Metrics + Weather + Top Discipline Highlights) -->
            <div v-if="hasSnapshotRail" class="xl:col-span-5">
                <ActivitySessionSnapshotRail
                    :derived-metrics="derivedMetrics ?? null"
                    :weather="weather"
                    :is-indoor="activity.is_indoor"
                    :has-coordinates="hasCoordinates"
                    :syncing-weather="isSyncingWeather"
                    :discipline-title="disciplineTelemetryTitle"
                    :discipline-cells="disciplineTelemetryCells"
                    :accent-hex="sportMeta.accentHex"
                    @sync-weather="syncActivityWeather"
                    @view-all-stats="activeTab = 'stats'"
                />
            </div>
        </section>

        <!-- Non-GPS / Indoor Fallback Summary Stack -->
        <template v-else>
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
                        :class="[
                            'size-3.5',
                            isSyncingStreams && 'animate-spin',
                        ]"
                    />
                    {{
                        isSyncingStreams ? 'Syncing FIT...' : 'Sync FIT Streams'
                    }}
                </button>
            </section>

            <ActivityDerivedMetricsCard
                :derived-metrics="derivedMetrics ?? null"
            />

            <ActivityWeatherCard
                :weather="weather"
                :is-indoor="activity.is_indoor"
                :has-coordinates="hasCoordinates"
                :syncing="isSyncingWeather"
                @sync="syncActivityWeather"
            />

            <section
                v-if="
                    disciplineTelemetryTitle &&
                    disciplineTelemetryCells.length > 0
                "
                class="rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
            >
                <div class="mb-3 flex items-center gap-2">
                    <span
                        class="size-1.5 rounded-full"
                        :style="{ backgroundColor: sportMeta.accentHex }"
                    />
                    <h2
                        class="font-mono text-[11px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
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
                            class="flex items-center gap-1 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <span class="truncate">{{ cell.label }}</span>
                            <InfoPopover
                                v-if="cell.infoTopic"
                                :topic="cell.infoTopic"
                            />
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
        </template>

        <!-- Underlined Analysis Tabs Navigation (5 Consolidated Tabs) -->
        <nav
            role="tablist"
            aria-label="Activity analysis sections"
            class="flex flex-nowrap items-center gap-6 overflow-x-auto border-b border-zinc-200/90 dark:border-zinc-800/90"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="activeTab === tab.key"
                class="relative inline-flex shrink-0 cursor-pointer items-center gap-2 pb-3 font-mono text-xs font-medium whitespace-nowrap transition-colors"
                :class="
                    activeTab === tab.key
                        ? 'text-zinc-950 dark:text-zinc-50'
                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'
                "
                @click="activeTab = tab.key"
            >
                <span>{{ tab.label }}</span>
                <span
                    v-if="tab.badge !== undefined"
                    class="rounded px-1.5 py-0.5 text-[10px] tabular-nums"
                    :class="
                        activeTab === tab.key
                            ? 'bg-zinc-900/10 text-zinc-900 dark:bg-white/15 dark:text-zinc-100'
                            : 'bg-zinc-200/70 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'
                    "
                >
                    {{ tab.badge }}
                </span>
                <span
                    v-if="activeTab === tab.key"
                    class="absolute right-0 bottom-0 left-0 h-0.5 rounded-full"
                    :style="{ backgroundColor: sportMeta.accentHex }"
                />
            </button>
        </nav>

        <!-- TAB 1: Streams -->
        <div v-if="activeTab === 'streams'" class="space-y-6">
            <ActivityStreamsPanel
                v-model:vertical-metric-mode="verticalMetricMode"
                v-model:power-unit-mode="powerUnitMode"
                :activity="activity"
                :series="series"
                :run-walk-segments="runWalkSegments"
                :jumps="jumps"
                :uses-pace="usesPace"
                :is-running="isRunning"
                :is-swimming="isSwimming"
                :cadence-unit="sportMeta.cadenceUnit"
                :has-ambient-weather="weather !== null"
                :weather="weather"
                :highlight-range="highlightedRange"
                @hover-index="activeStreamIndex = $event"
                @select-range="highlightedRange = $event"
            />

            <section
                v-if="stream && telemetryPanelCount === 0 && jumps.length === 0"
                class="rounded-xl border border-zinc-200/90 bg-white p-8 text-center font-mono text-xs text-zinc-500 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-400"
            >
                No telemetry stream channels were recorded for this activity.
            </section>
        </div>

        <!-- TAB 2: Splits & Zones -->
        <div v-else-if="activeTab === 'splits'" class="space-y-6">
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

            <!-- HR Zones, Power Zones & Mean Maximal Power Curve -->
            <ActivityZonesAndPower
                :hr-zones="hrZones"
                :power-zones="powerZones"
                :power-curve="activity.power_curve"
                :is-running="isRunning"
                :threshold-power="
                    activity.ftp ?? activity.threshold_power ?? null
                "
            />

            <!-- Splits & Laps Card -->
            <ActivitySplitsAndLaps
                v-model:splits-tab="splitsTab"
                :series="series"
                :km-splits="kmSplits"
                :laps="laps"
                :uses-pace="usesPace"
                :is-running="isRunning"
                :is-swimming="isSwimming"
                :accent-hex="sportMeta.accentHex"
                :cadence-unit="sportMeta.cadenceUnit"
            />
        </div>

        <!-- TAB 3: Efforts & Segments -->
        <div
            v-else-if="
                activeTab === 'efforts_segments' && showEffortsSegmentsTab
            "
            class="space-y-6"
        >
            <ActivityBestEfforts
                v-if="hasBestEffortsCard"
                :best-efforts="bestEfforts"
                :series="series"
                :uses-pace="usesPace"
                :is-running="isRunning"
                :is-cycling="isCycling"
                :is-indoor="activity.is_indoor"
                :athlete-weight-kg="athleteWeightKg ?? null"
                :power-effort-ranks="powerEffortRanks ?? null"
                :highlighted-range="highlightedRange"
                @select-range="highlightedRange = $event"
            />

            <ActivitySegmentsPanel
                v-if="showSegmentsTab"
                :activity-id="activity.id"
                :is-indoor="activity.is_indoor"
                :segment-efforts="segmentEffortsList"
                :series="series"
                :uses-pace="usesPace"
                :is-swimming="isSwimming"
                :highlighted-range="highlightedRange"
                @select-range="highlightedRange = $event"
            />
        </div>

        <!-- TAB 4: Route & Effort Context -->
        <div v-else-if="activeTab === 'context'" class="space-y-6">
            <ActivityMatchedRoute
                v-if="matchedRoute"
                :matched-route="matchedRoute"
            />

            <ActivityEffortContext :effort-context="effortContext" />
        </div>

        <!-- TAB 5: All Stats -->
        <ActivityAllStats
            v-else-if="activeTab === 'stats'"
            :activity="activity"
            :uses-pace="usesPace"
            :weather="weather"
        />

        <!-- Shareable Summary Card Modal -->
        <ActivityShareCardModal
            :open="shareModalOpen"
            :activity="{
                id: activity.id,
                name: activity.name,
                started_at_local: activity.started_at_local,
                sport_category: activity.sport_category,
                effective_discipline: effectiveDiscipline,
                location_name: activity.location_name,
                distance_meters: activity.distance_meters,
                duration_seconds: activity.duration_seconds,
                elevation_gain: activity.elevation_gain,
                avg_speed: activity.avg_speed,
                avg_hr: activity.avg_hr,
                avg_power: activity.avg_power,
                accentHex: sportMeta.accentHex,
                sportLabel: sportMeta.label,
                usesPace,
            }"
            :polyline="polyline"
            :series="series"
            :weather="weather"
            @close="shareModalOpen = false"
        />
    </div>
</template>
