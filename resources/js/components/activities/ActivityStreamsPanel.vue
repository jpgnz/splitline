<script lang="ts">
import type { RunWalkSegment, StreamPoint } from '@/types/activity';

export interface AvailableStreams {
    altitude: boolean;
    speed: boolean;
    hr: boolean;
    cadence: boolean;
    power: boolean;
    stamina: boolean;
    performanceCondition: boolean;
    stride: boolean;
    verticalRatio: boolean;
    verticalOsc: boolean;
    gct: boolean;
    respiration: boolean;
    temperature: boolean;
    leftBalance: boolean;
    torqueEff: boolean;
    pedalSmoothness: boolean;
    grit: boolean;
    flow: boolean;
}

export function detectAvailableStreams(
    series: StreamPoint[],
): AvailableStreams {
    return {
        altitude: series.some((p) => p.altitude !== null),
        speed: series.some((p) => p.speed !== null && p.speed > 0),
        hr: series.some((p) => p.hr !== null),
        cadence: series.some((p) => p.cadence !== null && p.cadence > 0),
        power: series.some((p) => p.power !== null && p.power > 0),
        stamina: series.some(
            (p) =>
                (p.stamina !== null && p.stamina !== undefined) ||
                (p.stamina_potential !== null &&
                    p.stamina_potential !== undefined),
        ),
        performanceCondition: series.some(
            (p) =>
                p.performance_condition !== null &&
                p.performance_condition !== undefined,
        ),
        stride: series.some(
            (p) =>
                p.stride_length !== null &&
                p.stride_length !== undefined &&
                p.stride_length > 0,
        ),
        verticalRatio: series.some(
            (p) =>
                p.vertical_ratio !== null &&
                p.vertical_ratio !== undefined &&
                p.vertical_ratio > 0,
        ),
        verticalOsc: series.some(
            (p) =>
                p.vertical_oscillation !== null &&
                p.vertical_oscillation !== undefined &&
                p.vertical_oscillation > 0,
        ),
        gct: series.some(
            (p) => p.gct !== null && p.gct !== undefined && p.gct > 0,
        ),
        respiration: series.some(
            (p) =>
                p.respiration !== null &&
                p.respiration !== undefined &&
                p.respiration > 0,
        ),
        temperature: series.some(
            (p) => p.temperature !== null && p.temperature !== undefined,
        ),
        leftBalance: series.some(
            (p) => p.left_balance !== null && p.left_balance !== undefined,
        ),
        torqueEff: series.some(
            (p) =>
                (p.left_te !== null && p.left_te !== undefined) ||
                (p.right_te !== null && p.right_te !== undefined),
        ),
        pedalSmoothness: series.some(
            (p) =>
                (p.left_ps !== null && p.left_ps !== undefined) ||
                (p.right_ps !== null && p.right_ps !== undefined),
        ),
        grit: series.some(
            (p) => p.grit !== null && p.grit !== undefined && p.grit > 0,
        ),
        flow: series.some(
            (p) => p.flow !== null && p.flow !== undefined && p.flow > 0,
        ),
    };
}

export type TelemetryPanelKey =
    | 'altitude'
    | 'speed'
    | 'hr'
    | 'stamina'
    | 'performanceCondition'
    | 'power'
    | 'leftBalance'
    | 'torqueEff'
    | 'pedalSmoothness'
    | 'grit'
    | 'flow'
    | 'cadence'
    | 'stride'
    | 'vertical'
    | 'gct'
    | 'respiration'
    | 'temperature'
    | 'runWalk';

const DIRECT_TELEMETRY_PANEL_KEYS = [
    'altitude',
    'speed',
    'hr',
    'stamina',
    'performanceCondition',
    'power',
    'leftBalance',
    'torqueEff',
    'pedalSmoothness',
    'grit',
    'flow',
    'cadence',
    'stride',
    'gct',
    'respiration',
    'temperature',
] as const satisfies ReadonlyArray<TelemetryPanelKey & keyof AvailableStreams>;

/**
 * Single source of truth for which telemetry panels render, shared by the panel
 * builder and the parent tab badge count.
 */
export function telemetryPanelKeys(
    series: StreamPoint[],
    runWalkSegments: RunWalkSegment[],
): Set<TelemetryPanelKey> {
    const keys = new Set<TelemetryPanelKey>();

    if (series.length === 0) {
        return keys;
    }

    const available = detectAvailableStreams(series);

    for (const key of DIRECT_TELEMETRY_PANEL_KEYS) {
        if (available[key]) {
            keys.add(key);
        }
    }

    if (available.verticalRatio || available.verticalOsc) {
        keys.add('vertical');
    }

    if (runWalkSegments.length > 0) {
        keys.add('runWalk');
    }

    return keys;
}

export function countTelemetryPanels(
    series: StreamPoint[],
    runWalkSegments: RunWalkSegment[],
): number {
    return telemetryPanelKeys(series, runWalkSegments).size;
}
</script>

<script setup lang="ts">
import type { EChartsOption, SeriesOption } from 'echarts';
import { Crosshair } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue';
import ActivityStreamSelectionCard from '@/components/activities/ActivityStreamSelectionCard.vue';
import BaseChart, {
    type ChartPointClickPayload,
} from '@/components/charts/BaseChart.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { formatDurationClock, formatNumber } from '@/lib/format';
import {
    detectWorkIntervals,
    type DetectedInterval,
} from '@/lib/streamSelection';
import {
    buildPaceTelemetrySeries,
    buildStartupGatedTelemetrySeries,
    percentileOf,
    rollingAverage,
} from '@/lib/telemetry';
import { M_TO_FT, useUnits } from '@/lib/units';
import type {
    ActivityDetail,
    ActivityWeatherPayload,
    HighlightRange,
    JumpEvent,
} from '@/types/activity';

const props = withDefaults(
    defineProps<{
        activity: ActivityDetail;
        series: StreamPoint[];
        runWalkSegments: RunWalkSegment[];
        jumps: JumpEvent[];
        usesPace: boolean;
        isRunning: boolean;
        isSwimming?: boolean;
        cadenceUnit?: string | null;
        hasAmbientWeather: boolean;
        weather?: ActivityWeatherPayload | null;
        highlightRange?: HighlightRange | null;
    }>(),
    {
        isSwimming: false,
        cadenceUnit: null,
        weather: null,
        highlightRange: null,
    },
);

const emit = defineEmits<{
    hoverIndex: [index: number | null];
    selectRange: [range: HighlightRange | null];
}>();

const theme = useChartTheme();
const {
    isImperial,
    distanceUnit,
    elevationUnit,
    speedUnit,
    tempUnit,
    strideUnit,
    oscillationUnit,
    distanceFromMeters,
    elevationFromMeters,
    speedFromMps,
    paceSecondsFromMps,
    tempFromC,
    oscillationFromCm,
    formatDistance,
    formatPace100m,
    formatPaceSeconds,
    formatSpeed,
} = useUnits();

const verticalMetricMode = defineModel<'ratio' | 'oscillation'>(
    'verticalMetricMode',
    { default: 'ratio' },
);
const powerUnitMode = defineModel<'watts' | 'pct'>('powerUnitMode', {
    default: 'watts',
});

const resolvedCadenceUnit = computed<string>(
    () => props.cadenceUnit || (props.isRunning ? 'spm' : 'rpm'),
);

const inspectMode = ref(false);
const selectionStartIndex = ref<number | null>(null);
const selectionEndIndex = ref<number | null>(null);
const selectionLabel = ref('Selected Slice');
const activeIntervalId = ref<string | null>(null);
const pendingClickStart = ref<number | null>(null);

let hoveredStreamIndex: number | null = null;
let pointerPanelKey: string | null = null;
let isPointerDown = false;
let dragAnchorIndex: number | null = null;
let didDragSelect = false;
let pendingDragIndex: number | null = null;
let dragFrameId: number | null = null;
let lastPointClickAt = 0;
let suppressNextClickUntil = 0;

/** Ranges this panel emitted, so external highlight changes can be told apart from our own. */
const emittedRanges = new WeakSet<HighlightRange>();
let lastEmittedRange: HighlightRange | null | undefined = undefined;

const isCycling = computed<boolean>(
    () => props.activity.sport_category === 'biking',
);

const detectedIntervals = computed<DetectedInterval[]>(() =>
    detectWorkIntervals(props.series, isCycling.value, props.weather ?? null),
);

const hasActiveSelection = computed<boolean>(
    () =>
        selectionStartIndex.value !== null &&
        selectionEndIndex.value !== null &&
        selectionEndIndex.value > selectionStartIndex.value &&
        props.series.length >= 2,
);

function resolveHighlightRange(
    startIdx: number,
    endIdx: number,
    label: string,
): HighlightRange | null {
    let startDistance: number | null = null;
    let endDistance: number | null = null;

    for (let i = startIdx; i <= endIdx; i += 1) {
        const dist = props.series[i]?.distance;

        if (typeof dist === 'number' && Number.isFinite(dist)) {
            startDistance = dist;

            break;
        }
    }

    for (let i = endIdx; i >= startIdx; i -= 1) {
        const dist = props.series[i]?.distance;

        if (typeof dist === 'number' && Number.isFinite(dist)) {
            endDistance = dist;

            break;
        }
    }

    if (
        startDistance !== null &&
        endDistance !== null &&
        endDistance > startDistance
    ) {
        return {
            startDistance,
            endDistance,
            label,
        };
    }

    return null;
}

function emitSelectRange(range: HighlightRange | null): void {
    if (range !== null) {
        emittedRanges.add(range);
    }

    lastEmittedRange = range;
    emit('selectRange', range);
}

function isOwnHighlight(range: HighlightRange | null | undefined): boolean {
    const raw = range ? toRaw(range) : null;

    return raw === null ? lastEmittedRange === null : emittedRanges.has(raw);
}

function resetLocalSelection(): void {
    selectionStartIndex.value = null;
    selectionEndIndex.value = null;
    selectionLabel.value = 'Selected Slice';
    activeIntervalId.value = null;
    pendingClickStart.value = null;
}

function setSelectionRange(
    startIdx: number,
    endIdx: number,
    label = 'Custom Slice',
    intervalId: string | null = null,
): void {
    if (props.series.length < 2) {
        return;
    }

    const maxIdx = props.series.length - 1;
    const clampedStart = Math.max(
        0,
        Math.min(maxIdx - 1, Math.floor(startIdx)),
    );
    const clampedEnd = Math.max(
        clampedStart + 1,
        Math.min(maxIdx, Math.floor(endIdx)),
    );

    if (
        selectionStartIndex.value === clampedStart &&
        selectionEndIndex.value === clampedEnd &&
        selectionLabel.value === label &&
        activeIntervalId.value === intervalId &&
        pendingClickStart.value === null
    ) {
        return;
    }

    selectionStartIndex.value = clampedStart;
    selectionEndIndex.value = clampedEnd;
    selectionLabel.value = label;
    activeIntervalId.value = intervalId;
    pendingClickStart.value = null;

    emitSelectRange(resolveHighlightRange(clampedStart, clampedEnd, label));
}

function clearSelection(): void {
    const hadSelection = hasActiveSelection.value;

    resetLocalSelection();

    if (hadSelection && isOwnHighlight(props.highlightRange)) {
        emitSelectRange(null);
    }
}

function toggleInspectMode(): void {
    inspectMode.value = !inspectMode.value;

    if (!inspectMode.value) {
        pendingClickStart.value = null;
    }
}

function closeInspectPanel(): void {
    inspectMode.value = false;
    clearSelection();
}

/**
 * Approximate the series index under the pointer from its horizontal position, for touch drags
 * where ECharts does not report axis-pointer hover indices.
 */
function resolveIndexFromPointerEvent(event: PointerEvent): number | null {
    const el = event.currentTarget as HTMLElement | null;

    if (!el || props.series.length < 2) {
        return null;
    }

    const rect = el.getBoundingClientRect();

    if (rect.width <= 0) {
        return null;
    }

    const leftPad = rect.width > 96 ? 48 : 12;
    const rightPad = 16;
    const usableWidth = Math.max(1, rect.width - leftPad - rightPad);
    const ratio = Math.max(
        0,
        Math.min(1, (event.clientX - rect.left - leftPad) / usableWidth),
    );

    return Math.round(ratio * (props.series.length - 1));
}

function handleSelectionCardUpdate(payload: {
    startIndex: number;
    endIndex: number;
    label?: string;
    intervalId?: string | null;
}): void {
    setSelectionRange(
        payload.startIndex,
        payload.endIndex,
        payload.label ?? 'Custom Slice',
        payload.intervalId ?? null,
    );
}

function handleInspectClick(index: number): void {
    if (
        !inspectMode.value ||
        props.series.length < 2 ||
        Date.now() < suppressNextClickUntil
    ) {
        return;
    }

    const clamped = Math.max(
        0,
        Math.min(props.series.length - 1, Math.floor(index)),
    );

    if (pendingClickStart.value === null) {
        if (hasActiveSelection.value) {
            clearSelection();
        }

        pendingClickStart.value = clamped;

        return;
    }

    if (clamped === pendingClickStart.value) {
        pendingClickStart.value = null;

        return;
    }

    const start = Math.min(pendingClickStart.value, clamped);
    const end = Math.max(pendingClickStart.value, clamped);

    setSelectionRange(start, end, 'Custom Slice', null);
}

function applyPendingDrag(): void {
    dragFrameId = null;

    const index = pendingDragIndex;
    pendingDragIndex = null;

    if (
        !isPointerDown ||
        !inspectMode.value ||
        dragAnchorIndex === null ||
        index === null ||
        Math.abs(index - dragAnchorIndex) < 2
    ) {
        return;
    }

    didDragSelect = true;
    setSelectionRange(
        Math.min(dragAnchorIndex, index),
        Math.max(dragAnchorIndex, index),
        'Custom Slice',
        null,
    );
}

function queueDragIndex(index: number): void {
    pendingDragIndex = index;

    if (dragFrameId === null) {
        dragFrameId = requestAnimationFrame(applyPendingDrag);
    }
}

function cancelPendingDrag(): void {
    if (dragFrameId !== null) {
        cancelAnimationFrame(dragFrameId);
        dragFrameId = null;
    }

    pendingDragIndex = null;
}

/** Returns false when the index is unchanged so callers can skip redundant work. */
function setHoveredIndex(index: number | null): boolean {
    if (index === hoveredStreamIndex) {
        return false;
    }

    hoveredStreamIndex = index;
    emit('hoverIndex', index);

    return true;
}

/**
 * Connected charts all report the shared axis pointer, so only the chart under the pointer
 * drives hover and brushing, unchanged indices are skipped, and drag updates are batched per frame.
 */
function handleChartHover(panelKey: string, index: number | null): void {
    if (pointerPanelKey !== null && panelKey !== pointerPanelKey) {
        return;
    }

    if (!setHoveredIndex(index)) {
        return;
    }

    if (isPointerDown && inspectMode.value && index !== null) {
        queueDragIndex(index);
    }
}

function handleChartPointerEnter(panelKey: string): void {
    pointerPanelKey = panelKey;
}

function handleChartPointerLeave(panelKey: string): void {
    if (pointerPanelKey !== panelKey) {
        return;
    }

    pointerPanelKey = null;

    if (!isPointerDown) {
        setHoveredIndex(null);
    }
}

function handleChartPointerDown(event: PointerEvent): void {
    if (
        !inspectMode.value ||
        (event.pointerType !== 'touch' && event.button !== 0)
    ) {
        return;
    }

    const startIdx =
        event.pointerType === 'touch'
            ? resolveIndexFromPointerEvent(event)
            : (hoveredStreamIndex ?? resolveIndexFromPointerEvent(event));

    if (startIdx === null) {
        return;
    }

    isPointerDown = true;
    dragAnchorIndex = startIdx;
    didDragSelect = false;
}

/**
 * Touch drags (and mouse moves before ECharts reports an index) emit no axis-pointer hover, so
 * derive the index from the pointer position and feed the same batched drag path.
 */
function handleChartPointerMove(event: PointerEvent): void {
    if (!isPointerDown || !inspectMode.value || dragAnchorIndex === null) {
        return;
    }

    if (event.pointerType !== 'touch' && hoveredStreamIndex !== null) {
        return;
    }

    const index = resolveIndexFromPointerEvent(event);

    if (index !== null && setHoveredIndex(index)) {
        queueDragIndex(index);
    }
}

function handleWindowPointerUp(): void {
    if (!isPointerDown) {
        return;
    }

    if (dragFrameId !== null) {
        cancelAnimationFrame(dragFrameId);
        applyPendingDrag();
    }

    if (didDragSelect) {
        suppressNextClickUntil = Date.now() + 150;
    }

    isPointerDown = false;
    dragAnchorIndex = null;
    didDragSelect = false;
}

function handleWindowKeyDown(event: KeyboardEvent): void {
    if (event.key !== 'Escape') {
        return;
    }

    if (pendingClickStart.value !== null) {
        pendingClickStart.value = null;

        return;
    }

    if (inspectMode.value) {
        inspectMode.value = false;
    }
}

function handleChartPointClick(payload: ChartPointClickPayload): void {
    if (!inspectMode.value) {
        return;
    }

    lastPointClickAt = Date.now();
    handleInspectClick(payload.dataIndex);
}

function handleChartContainerClick(): void {
    if (!inspectMode.value || Date.now() - lastPointClickAt < 100) {
        return;
    }

    if (hoveredStreamIndex !== null) {
        handleInspectClick(hoveredStreamIndex);
    }
}
onMounted(() => {
    window.addEventListener('pointerup', handleWindowPointerUp);
    window.addEventListener('pointercancel', handleWindowPointerUp);
    window.addEventListener('keydown', handleWindowKeyDown);
});

onBeforeUnmount(() => {
    window.removeEventListener('pointerup', handleWindowPointerUp);
    window.removeEventListener('pointercancel', handleWindowPointerUp);
    window.removeEventListener('keydown', handleWindowKeyDown);
    cancelPendingDrag();
});

watch(
    () => props.activity.id,
    () => {
        inspectMode.value = false;
        cancelPendingDrag();
        clearSelection();
    },
);

watch(
    () => props.series.length,
    (len) => {
        if (
            selectionEndIndex.value !== null &&
            selectionEndIndex.value >= len
        ) {
            clearSelection();
        }
    },
);

watch(
    () => props.highlightRange,
    (range) => {
        if (
            (hasActiveSelection.value || pendingClickStart.value !== null) &&
            !isOwnHighlight(range)
        ) {
            resetLocalSelection();
        }
    },
);

const availableStreams = computed<AvailableStreams>(() =>
    detectAvailableStreams(props.series),
);

const streamHasDistance = computed<boolean>(() =>
    props.series.some((p) => p.distance !== null && p.distance > 0),
);

const streamXAxisLabels = computed<string[]>(() =>
    props.series.map((p) => {
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

    const segs = props.runWalkSegments;
    const pointMeta: Array<{
        type: string;
        value: number;
        seg: RunWalkSegment | null;
    }> = [];

    for (const pt of props.series) {
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
        const level = type === 'run' ? 3 : type === 'walk' ? 2 : 1;

        pointMeta.push({
            type,
            value: level,
            seg: matched,
        });
    }

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 10,
            bottom: showXAxisLabels ? 26 : 10,
            containLabel: true,
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
                      hideOverlap: true,
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
                    if (val === 3) {
                        return 'Run';
                    }

                    if (val === 2) {
                        return 'Walk';
                    }

                    if (val === 1) {
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
            left: 12,
            right: 16,
            top: 14,
            bottom: config.showXAxisLabels ? 26 : 12,
            containLabel: true,
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
                      hideOverlap: true,
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
        legend: {
            top: 2,
            right: 8,
            itemWidth: 12,
            itemHeight: 8,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 10,
            },
        },
        grid: {
            left: 12,
            right: 16,
            top: 32,
            bottom: showXAxisLabels ? 26 : 12,
            containLabel: true,
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
                      hideOverlap: true,
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
                data: props.series.map((p) => p.stamina ?? null),
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
                data: props.series.map((p) => p.stamina_potential ?? null),
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

    const vals = props.series
        .map((p) => p.performance_condition)
        .filter(
            (v): v is number => typeof v === 'number' && Number.isFinite(v),
        );
    const maxAbs = Math.max(2, ...vals.map((v) => Math.abs(v)));

    return {
        animation: false,
        grid: {
            left: 12,
            right: 16,
            top: 14,
            bottom: showXAxisLabels ? 26 : 12,
            containLabel: true,
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
                      hideOverlap: true,
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
                data: props.series.map((p) => {
                    const v = p.performance_condition ?? null;

                    return {
                        value: v,
                        itemStyle: {
                            color: v !== null && v < 0 ? '#f43f5e' : '#10b981',
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
        legend: {
            top: 2,
            right: 8,
            itemWidth: 12,
            itemHeight: 8,
            textStyle: {
                color: theme.mutedColor.value,
                fontFamily: monoFont,
                fontSize: 10,
            },
        },
        grid: {
            left: 12,
            right: 16,
            top: 32,
            bottom: config.showXAxisLabels ? 26 : 12,
            containLabel: true,
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
                            typeof p.value === 'number' &&
                            Number.isFinite(p.value)
                                ? `${p.value.toFixed(1)}%`
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
            axisLabel: config.showXAxisLabels
                ? {
                      ...axisLabelStyle,
                      hideOverlap: true,
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
    if (props.series.length === 0) {
        return [];
    }

    const available = availableStreams.value;
    const panelKeys = telemetryPanelKeys(props.series, props.runWalkSegments);
    const streamTimes = props.series.map((p) => p.time);

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

    if (panelKeys.has('altitude')) {
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
                    data: props.series.map((p) =>
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
                    axisValueFormatter: (v) => `${Math.round(v)}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('speed')) {
        if (props.isSwimming) {
            const rawSwimPace = props.series.map((p) => {
                if (p.speed === null || p.speed <= 0.2) {
                    return null;
                }

                const secPer100m = 100 / p.speed;

                return secPer100m >= 40 && secPer100m <= 360
                    ? Math.round(secPer100m)
                    : null;
            });
            const validSwim = rawSwimPace
                .filter((v): v is number => v !== null)
                .sort((a, b) => a - b);
            const p02 =
                validSwim.length > 5
                    ? percentileOf(validSwim, 0.02)
                    : (validSwim[0] ?? 80);
            const p98 =
                validSwim.length > 5
                    ? percentileOf(validSwim, 0.98)
                    : (validSwim[validSwim.length - 1] ?? 180);
            const yMin = Math.max(40, Math.floor((p02 - 10) / 5) * 5);
            const yMax = Math.max(
                yMin + 15,
                Math.min(360, Math.ceil((p98 + 10) / 5) * 5),
            );
            const smoothedSwim = rollingAverage(
                rawSwimPace.map((v) =>
                    v !== null ? Math.max(yMin, Math.min(yMax, v)) : null,
                ),
                3,
            );
            const avgSwimSec =
                props.activity.avg_speed && props.activity.avg_speed > 0
                    ? Math.round(100 / props.activity.avg_speed)
                    : null;

            activePanels.push({
                key: 'pace',
                title: 'Pace',
                unit: 'min/100m',
                dotClass: 'bg-sky-500',
                height: 155,
                build: (isLast) =>
                    buildTelemetryChartOption({
                        seriesName: 'Pace',
                        data: smoothedSwim,
                        color: '#0ea5e9',
                        areaColor: theme.isDark.value
                            ? 'rgba(14, 165, 233, 0.14)'
                            : 'rgba(14, 165, 233, 0.12)',
                        areaOrigin: 'end',
                        unit: '/100m',
                        inverse: true,
                        min: yMin,
                        max: yMax,
                        avgLine:
                            avgSwimSec &&
                            avgSwimSec >= yMin &&
                            avgSwimSec <= yMax
                                ? {
                                      value: avgSwimSec,
                                      label: `Avg ${formatPace100m(props.activity.avg_speed)}`,
                                  }
                                : null,
                        valueFormatter: (v) => {
                            const total = Math.round(v);
                            const mins = Math.floor(total / 60);
                            const secs = total % 60;

                            return `${mins}:${String(secs).padStart(2, '0')} /100m`;
                        },
                        axisValueFormatter: (v) => {
                            const total = Math.round(v);
                            const mins = Math.floor(total / 60);
                            const secs = total % 60;

                            return `${mins}:${String(secs).padStart(2, '0')}`;
                        },
                        showXAxisLabels: isLast,
                    }),
            });
        } else if (props.usesPace) {
            const paceSeries = buildPaceTelemetrySeries({
                times: streamTimes,
                speeds: props.series.map((p) => p.speed),
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
            const paceUnitSuffix = isImperial.value ? '/mi' : '/km';

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
                        unit: paceUnitSuffix,
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

                            return `${mins}:${String(secs).padStart(2, '0')} ${paceUnitSuffix}`;
                        },
                        axisValueFormatter: (v) => {
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
                        data: props.series.map((p) =>
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

    if (panelKeys.has('hr')) {
        activePanels.push({
            key: 'hr',
            title: 'Heart Rate',
            unit: 'bpm',
            dotClass: 'bg-rose-500',
            height: 145,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Heart Rate',
                    data: props.series.map((p) => p.hr),
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
                    valueFormatter: (v) => `${Math.round(v)} bpm`,
                    axisValueFormatter: (v) => `${Math.round(v)}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('stamina')) {
        activePanels.push({
            key: 'stamina',
            title: 'Stamina & Stamina Potential',
            unit: '%',
            dotClass: 'bg-orange-500',
            height: 140,
            build: (isLast) => buildStaminaOption(isLast),
        });
    }

    if (panelKeys.has('performanceCondition')) {
        activePanels.push({
            key: 'performance_condition',
            title: 'Performance Condition',
            unit: 'score',
            dotClass: 'bg-sky-500',
            height: 120,
            build: (isLast) => buildPerformanceConditionOption(isLast),
        });
    }

    if (panelKeys.has('power')) {
        const usePct =
            powerUnitMode.value === 'pct' && thresholdPowerRef.value !== null;
        const ftp = thresholdPowerRef.value;
        const avgPowerVal =
            props.activity.avg_power !== null
                ? usePct && ftp
                    ? Math.round((props.activity.avg_power / ftp) * 100)
                    : Math.round(props.activity.avg_power)
                : null;
        const powerColor = props.isRunning ? '#8b5cf6' : '#0ea5e9';
        const powerArea = props.isRunning
            ? theme.isDark.value
                ? 'rgba(139, 92, 246, 0.20)'
                : 'rgba(139, 92, 246, 0.18)'
            : theme.isDark.value
              ? 'rgba(14, 165, 233, 0.16)'
              : 'rgba(14, 165, 233, 0.14)';

        const rawPowerData = props.series.map((p) => {
            if (p.power === null) {
                return null;
            }

            return usePct && ftp ? Math.round((p.power / ftp) * 100) : p.power;
        });

        const runningPowerSeries = props.isRunning
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
            title: props.isRunning ? 'Running Power' : 'Power',
            unit: usePct ? '% Threshold' : 'W',
            dotClass: props.isRunning ? 'bg-violet-500' : 'bg-sky-500',
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
                    axisValueFormatter: (v) =>
                        `${Math.round(v)}${usePct ? '%' : ''}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('leftBalance')) {
        // Filter coasting/single-leg spikes (<25% or >75%, or zero power) and apply rolling smoothing
        const cleanedLeft = props.series.map((p) => {
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
            title: 'Power Balance (L / R)',
            unit: '% Left · Smoothed',
            dotClass: 'bg-cyan-500',
            height: 140,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: 'Power Balance (L / R)',
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

    if (panelKeys.has('torqueEff')) {
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
                    leftData: props.series.map((p) => p.left_te ?? null),
                    rightData: props.series.map((p) => p.right_te ?? null),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('pedalSmoothness')) {
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
                    leftData: props.series.map((p) => p.left_ps ?? null),
                    rightData: props.series.map((p) => p.right_ps ?? null),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('grit')) {
        const gritData = props.series.map((p) => p.grit ?? null);
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

    if (panelKeys.has('flow')) {
        const flowData = props.series.map((p) => p.flow ?? null);
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

    if (panelKeys.has('cadence')) {
        const cadUnit = resolvedCadenceUnit.value;
        const avgCad = props.activity.avg_cadence;

        activePanels.push({
            key: 'cadence',
            title: props.isRunning ? 'Run Cadence' : 'Cadence',
            unit: cadUnit,
            dotClass: 'bg-orange-500',
            height: 135,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: props.isRunning ? 'Run Cadence' : 'Cadence',
                    data: props.series.map((p) =>
                        p.cadence !== null && p.cadence > 0 ? p.cadence : null,
                    ),
                    color: '#f97316',
                    unit: cadUnit,
                    visualMapPieces: props.isRunning
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
                    valueFormatter: (v) => `${Math.round(v)} ${cadUnit}`,
                    axisValueFormatter: (v) => `${Math.round(v)}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('stride')) {
        const strideUnitLabel = strideUnit.value;
        const strideMetersData = props.series.map((p) => {
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
                    axisValueFormatter: (v) => v.toFixed(2),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('vertical')) {
        const showOsc =
            verticalMetricMode.value === 'oscillation' && available.verticalOsc;
        const oscUnitLabel = oscillationUnit.value;
        const rawVerticalData = showOsc
            ? props.series.map((p) => {
                  const cm = p.vertical_oscillation;

                  if (cm === null || cm === undefined || !Number.isFinite(cm)) {
                      return null;
                  }

                  return Number(oscillationFromCm(cm).toFixed(2));
              })
            : props.series.map((p) => p.vertical_ratio ?? null);
        const verticalSeries = buildStartupGatedTelemetrySeries({
            times: streamTimes,
            values: rawVerticalData,
            step: showOsc && isImperial.value ? 0.2 : 0.5,
            minMargin: showOsc && isImperial.value ? 0.2 : 0.5,
            excludeZero: true,
            minFloor: 0,
        });
        const avgVal = showOsc
            ? props.activity.avg_vertical_oscillation !== null
                ? oscillationFromCm(props.activity.avg_vertical_oscillation)
                : averageOf(rawVerticalData)
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
            unit: showOsc ? oscUnitLabel : '%',
            dotClass: 'bg-violet-500',
            height: 135,
            hasVerticalToggle: available.verticalRatio && available.verticalOsc,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: showOsc
                        ? 'Vertical Oscillation'
                        : 'Vertical Ratio',
                    data: verticalSeries.solidData,
                    dottedData: verticalSeries.dottedData,
                    tooltipValues: verticalSeries.tooltipValues,
                    color: '#8b5cf6',
                    unit: showOsc ? oscUnitLabel : '%',
                    min: verticalSeries.yMin,
                    max: verticalSeries.yMax,
                    visualMapPieces: showOsc
                        ? [
                              { lte: oscillationFromCm(6.7), color: '#8b5cf6' },
                              {
                                  gt: oscillationFromCm(6.7),
                                  lte: oscillationFromCm(8.3),
                                  color: '#3b82f6',
                              },
                              {
                                  gt: oscillationFromCm(8.3),
                                  lte: oscillationFromCm(10.0),
                                  color: '#22c55e',
                              },
                              {
                                  gt: oscillationFromCm(10.0),
                                  lte: oscillationFromCm(11.7),
                                  color: '#f97316',
                              },
                              { gt: oscillationFromCm(11.7), color: '#ef4444' },
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
                              label: `Avg ${avgVerticalValue.toFixed(1)} ${showOsc ? oscUnitLabel : '%'}`,
                          }
                        : null,
                    valueFormatter: (v) =>
                        `${v.toFixed(1)} ${showOsc ? oscUnitLabel : '%'}`,
                    axisValueFormatter: (v) => v.toFixed(1),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('gct')) {
        const rawGctData = props.series.map((p) => p.gct ?? null);
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
                    axisValueFormatter: (v) => `${Math.round(v)}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('respiration')) {
        const respData = props.series.map((p) => p.respiration ?? null);
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
                    valueFormatter: (v) => `${Math.round(v)} brpm`,
                    axisValueFormatter: (v) => `${Math.round(v)}`,
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('temperature')) {
        const tempUnitLabel = tempUnit.value;
        const rawTempData = props.series.map((p) => p.temperature ?? null);
        const tempData = rawTempData.map((tempC) =>
            tempC !== null ? Number(tempFromC(tempC).toFixed(1)) : null,
        );
        const avgTempC =
            props.activity.avg_temperature ?? averageOf(rawTempData);
        const avgTempDisplay =
            avgTempC !== null ? Number(tempFromC(avgTempC).toFixed(1)) : null;

        activePanels.push({
            key: 'temperature',
            title: props.hasAmbientWeather
                ? 'Device Temperature'
                : 'Temperature',
            unit: tempUnitLabel,
            dotClass: 'bg-zinc-400',
            height: 125,
            build: (isLast) =>
                buildTelemetryChartOption({
                    seriesName: props.hasAmbientWeather
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
                    axisValueFormatter: (v) => v.toFixed(1),
                    showXAxisLabels: isLast,
                }),
        });
    }

    if (panelKeys.has('runWalk')) {
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

/**
 * Partial option merged onto a panel's chart (without rebuilding its full option) to draw the
 * brushed slice and the pending click anchor on the first series.
 */
function buildSelectionOverlay(
    option: EChartsOption,
    range: { start: number; end: number } | null,
    anchorIndex: number | null,
): EChartsOption | null {
    if (!range && anchorIndex === null) {
        return null;
    }

    if (!Array.isArray(option.series) || option.series.length === 0) {
        return null;
    }

    const existingMarkLine = (option.series[0] as Record<string, unknown>)
        .markLine as { data?: unknown[] } | undefined;
    const existingData = Array.isArray(existingMarkLine?.data)
        ? existingMarkLine.data
        : [];
    const anchorLineItems =
        anchorIndex !== null
            ? [
                  {
                      xAxis: anchorIndex,
                      lineStyle: {
                          color: theme.isDark.value ? '#818cf8' : '#4f46e5',
                          width: 1.5,
                          type: 'solid',
                      },
                      label: {
                          show: true,
                          position: 'insideStartTop',
                          formatter: 'Start',
                          color: '#ffffff',
                          backgroundColor: theme.isDark.value
                              ? '#6366f1'
                              : '#4f46e5',
                          padding: [2, 5],
                          borderRadius: 3,
                          fontFamily: theme.fontFamilyMono,
                          fontSize: 10,
                      },
                  },
              ]
            : [];

    return {
        series: [
            {
                markArea: {
                    silent: true,
                    itemStyle: {
                        color: theme.isDark.value
                            ? 'rgba(99, 102, 241, 0.20)'
                            : 'rgba(99, 102, 241, 0.14)',
                        borderColor: theme.isDark.value
                            ? 'rgba(129, 140, 248, 0.65)'
                            : 'rgba(79, 70, 229, 0.55)',
                        borderWidth: 1,
                        borderType: 'dashed',
                    },
                    data: range
                        ? [[{ xAxis: range.start }, { xAxis: range.end }]]
                        : [],
                },
                markLine: {
                    ...(existingMarkLine
                        ? {}
                        : { silent: true, symbol: ['none', 'none'] }),
                    data: [...existingData, ...anchorLineItems],
                },
            } as SeriesOption,
        ],
    };
}

const CORE_STREAM_KEYS = new Set([
    'elevation',
    'pace',
    'speed',
    'hr',
    'power',
    'grit',
    'flow',
    'cadence',
]);

const showExtraStreams = ref<boolean>(false);

watch(
    () => props.activity.id,
    () => {
        showExtraStreams.value = false;
    },
);

const coreTelemetryPanels = computed<TelemetryPanel[]>(() =>
    telemetryPanels.value.filter((panel) => CORE_STREAM_KEYS.has(panel.key)),
);

const extraTelemetryPanels = computed<TelemetryPanel[]>(() =>
    telemetryPanels.value.filter((panel) => !CORE_STREAM_KEYS.has(panel.key)),
);

const visibleTelemetryPanels = computed<TelemetryPanel[]>(() => {
    if (
        showExtraStreams.value ||
        coreTelemetryPanels.value.length === 0 ||
        extraTelemetryPanels.value.length === 0
    ) {
        return telemetryPanels.value;
    }

    return coreTelemetryPanels.value;
});

const selectionOverlays = computed<Record<string, EChartsOption | null>>(() => {
    const range =
        hasActiveSelection.value &&
        selectionStartIndex.value !== null &&
        selectionEndIndex.value !== null
            ? {
                  start: selectionStartIndex.value,
                  end: selectionEndIndex.value,
              }
            : null;
    const anchor = pendingClickStart.value;
    const overlays: Record<string, EChartsOption | null> = {};

    for (const panel of visibleTelemetryPanels.value) {
        overlays[panel.key] = buildSelectionOverlay(
            panel.option,
            range,
            anchor,
        );
    }

    return overlays;
});
</script>

<template>
    <div
        v-if="telemetryPanels.length > 0 || jumps.length > 0"
        class="space-y-6"
    >
        <!-- Synchronized ECharts Telemetry Panels -->
        <section
            v-if="telemetryPanels.length > 0"
            class="rounded-xl border border-zinc-200/90 bg-white p-4 sm:p-5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2.5">
                    <span class="size-1.5 rounded-full bg-emerald-500" />
                    <span
                        class="font-mono text-[11px] font-medium tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Streams
                    </span>
                    <span
                        class="font-mono text-[11px] text-zinc-400 tabular-nums dark:text-zinc-500"
                    >
                        {{ formatNumber(series.length, 0) }}
                        {{ series.length === 1 ? 'sample' : 'samples' }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="
                            coreTelemetryPanels.length > 0 &&
                            extraTelemetryPanels.length > 0
                        "
                        type="button"
                        :aria-expanded="showExtraStreams"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-zinc-200/90 bg-[#f7f7f5]/80 px-2.5 py-1 font-mono text-[11px] text-zinc-600 transition hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                        @click="showExtraStreams = !showExtraStreams"
                    >
                        {{
                            showExtraStreams
                                ? 'Hide Extra Streams'
                                : `+ ${extraTelemetryPanels.length} More ${extraTelemetryPanels.length === 1 ? 'Stream' : 'Streams'}`
                        }}
                    </button>

                    <button
                        v-if="series.length >= 2"
                        type="button"
                        :aria-pressed="inspectMode"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1 font-mono text-[11px] tracking-wider uppercase transition"
                        :class="
                            inspectMode
                                ? 'border-indigo-500/70 bg-indigo-500/10 text-indigo-700 dark:border-indigo-400/60 dark:bg-indigo-500/15 dark:text-indigo-300'
                                : hasActiveSelection
                                  ? 'border-indigo-500/40 bg-indigo-500/5 text-indigo-600 hover:border-indigo-500/70 dark:border-indigo-400/40 dark:text-indigo-300'
                                  : 'border-zinc-200/90 bg-[#f7f7f5]/80 text-zinc-600 hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="toggleInspectMode"
                    >
                        <Crosshair class="size-3.5" />
                        <span>
                            {{
                                inspectMode
                                    ? 'Done Inspecting'
                                    : 'Inspect Slice'
                            }}
                        </span>
                        <span
                            v-if="hasActiveSelection && !inspectMode"
                            class="size-1.5 rounded-full bg-indigo-500 dark:bg-indigo-400"
                        />
                    </button>
                </div>
            </div>

            <ActivityStreamSelectionCard
                :series="series"
                :start-index="selectionStartIndex"
                :end-index="selectionEndIndex"
                :selection-label="selectionLabel"
                :pending-start-index="pendingClickStart"
                :inspect-mode="inspectMode"
                :detected-intervals="detectedIntervals"
                :active-interval-id="activeIntervalId"
                :uses-pace="usesPace"
                :is-running="isRunning"
                :is-swimming="isSwimming"
                :cadence-unit="resolvedCadenceUnit"
                :threshold-power="thresholdPowerRef"
                :weather="weather"
                @update-range="handleSelectionCardUpdate"
                @clear="clearSelection"
                @close="closeInspectPanel"
                @toggle-inspect-mode="toggleInspectMode"
            />

            <div class="space-y-4">
                <div
                    v-for="panel in visibleTelemetryPanels"
                    :key="panel.key"
                    class="rounded-lg border border-zinc-100 bg-[#f7f7f5]/40 px-3 pt-2.5 pb-1 dark:border-zinc-800/70 dark:bg-zinc-900/30"
                >
                    <div class="mb-1 flex items-center justify-between gap-2">
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
                                    class="cursor-pointer rounded px-1.5 py-0.5 font-mono transition-colors"
                                    :class="
                                        verticalMetricMode === 'ratio'
                                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="verticalMetricMode = 'ratio'"
                                >
                                    Ratio %
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded px-1.5 py-0.5 font-mono transition-colors"
                                    :class="
                                        verticalMetricMode === 'oscillation'
                                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="verticalMetricMode = 'oscillation'"
                                >
                                    Osc {{ oscillationUnit }}
                                </button>
                            </div>

                            <div
                                v-if="panel.hasPowerToggle"
                                class="inline-flex rounded-md border border-zinc-200/80 bg-white p-0.5 text-[10px] dark:border-zinc-800 dark:bg-[#141417]"
                            >
                                <button
                                    type="button"
                                    class="cursor-pointer rounded px-1.5 py-0.5 font-mono transition-colors"
                                    :class="
                                        powerUnitMode === 'watts'
                                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="powerUnitMode = 'watts'"
                                >
                                    Watts
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded px-1.5 py-0.5 font-mono transition-colors"
                                    :class="
                                        powerUnitMode === 'pct'
                                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                    "
                                    @click="powerUnitMode = 'pct'"
                                >
                                    % Threshold
                                </button>
                            </div>

                            <span
                                class="font-mono text-[10px] text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                {{ panel.unit }}
                            </span>
                        </div>
                    </div>

                    <div
                        :class="
                            inspectMode
                                ? 'cursor-crosshair touch-none select-none'
                                : ''
                        "
                        @pointerenter="handleChartPointerEnter(panel.key)"
                        @pointerleave="handleChartPointerLeave(panel.key)"
                        @pointerdown="handleChartPointerDown"
                        @pointermove="handleChartPointerMove"
                        @click="handleChartContainerClick"
                    >
                        <BaseChart
                            :option="panel.option"
                            :overlay-option="
                                selectionOverlays[panel.key] ?? null
                            "
                            :height="panel.height"
                            group="activity-telemetry"
                            @hover-index="handleChartHover(panel.key, $event)"
                            @point-click="handleChartPointClick"
                        />
                    </div>
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
</template>
