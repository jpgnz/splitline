<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronUp,
    Flag,
    MapPin,
    Mountain,
    Plus,
    Star,
    TrendingUp,
    Trophy,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { formatDurationClock } from '@/lib/format';
import { climbCategoryMeta, formatGrade, formatVam } from '@/lib/segments';
import { useUnits } from '@/lib/units';
import { show as showSegment, store as storeSegment } from '@/routes/segments';
import type { HighlightRange, StreamPoint } from '@/types/activity';
import type { ActivitySegmentEffortItem } from '@/types/segments';

const props = withDefaults(
    defineProps<{
        activityId: number;
        isIndoor: boolean;
        segmentEfforts: ActivitySegmentEffortItem[];
        series: StreamPoint[];
        usesPace: boolean;
        isSwimming?: boolean;
    }>(),
    {
        isSwimming: false,
    },
);

const emit = defineEmits<{
    selectRange: [range: HighlightRange | null];
}>();

const page = usePage();
const {
    formatDistance,
    formatElevation,
    formatPace,
    formatPace100m,
    formatSpeed,
} = useUnits();

interface GnssEntry {
    origIndex: number;
    distance: number;
    time: number;
    altitude: number | null;
}

const EARTH_RADIUS_METERS = 6371000;

function haversineMeters(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    const phi1 = (lat1 * Math.PI) / 180;
    const phi2 = (lat2 * Math.PI) / 180;
    const dPhi = ((lat2 - lat1) * Math.PI) / 180;
    const dLambda = ((lng2 - lng1) * Math.PI) / 180;
    const a =
        Math.sin(dPhi / 2) * Math.sin(dPhi / 2) +
        Math.cos(phi1) *
            Math.cos(phi2) *
            Math.sin(dLambda / 2) *
            Math.sin(dLambda / 2);

    return 2 * EARTH_RADIUS_METERS * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

const gnssEntries = computed<GnssEntry[]>(() => {
    const entries: GnssEntry[] = [];
    let fallbackDist = 0;
    let prevLat: number | null = null;
    let prevLng: number | null = null;

    for (let i = 0; i < props.series.length; i += 1) {
        const pt = props.series[i];

        if (
            pt.lat === null ||
            pt.lng === null ||
            !Number.isFinite(pt.lat) ||
            !Number.isFinite(pt.lng)
        ) {
            continue;
        }

        if (prevLat !== null && prevLng !== null) {
            fallbackDist += haversineMeters(prevLat, prevLng, pt.lat, pt.lng);
        }

        prevLat = pt.lat;
        prevLng = pt.lng;

        const dist =
            pt.distance !== null && Number.isFinite(pt.distance)
                ? pt.distance
                : fallbackDist;

        entries.push({
            origIndex: i,
            distance: dist,
            time: Number.isFinite(pt.time) ? pt.time : i,
            altitude:
                pt.altitude !== null && Number.isFinite(pt.altitude)
                    ? pt.altitude
                    : null,
        });
    }

    return entries;
});

const canCreateCustom = computed<boolean>(
    () => !props.isIndoor && gnssEntries.value.length >= 5,
);

const selectedEffortId = ref<number | null>(null);
const creatorOpen = ref<boolean>(false);
const startPos = ref<number>(0);
const endPos = ref<number>(0);

const createForm = useForm({
    activity_id: props.activityId,
    start_index: 0,
    end_index: 0,
    name: '',
});

watch(
    () => props.activityId,
    (nextId) => {
        selectedEffortId.value = null;
        creatorOpen.value = false;
        createForm.activity_id = nextId;
        createForm.name = '';
        createForm.clearErrors();
        emit('selectRange', null);
    },
);

function initSliderBounds(): void {
    const len = gnssEntries.value.length;

    if (len < 5) {
        startPos.value = 0;
        endPos.value = 0;

        return;
    }

    startPos.value = Math.min(len - 5, Math.floor(len * 0.15));
    endPos.value = Math.max(
        startPos.value + 4,
        Math.min(len - 1, Math.floor(len * 0.45)),
    );
}

const customPreview = computed<{
    startOrigIndex: number;
    endOrigIndex: number;
    startDistance: number;
    endDistance: number;
    distanceMeters: number;
    elapsedSeconds: number;
    elevationGain: number | null;
    avgGradePct: number | null;
    pointCount: number;
    isValid: boolean;
}>(() => {
    const entries = gnssEntries.value;

    if (entries.length < 5 || endPos.value <= startPos.value) {
        return {
            startOrigIndex: 0,
            endOrigIndex: 0,
            startDistance: 0,
            endDistance: 0,
            distanceMeters: 0,
            elapsedSeconds: 0,
            elevationGain: null,
            avgGradePct: null,
            pointCount: 0,
            isValid: false,
        };
    }

    const lo = Math.max(0, Math.min(startPos.value, entries.length - 2));
    const hi = Math.max(lo + 1, Math.min(endPos.value, entries.length - 1));

    const first = entries[lo];
    const last = entries[hi];
    const distanceMeters = Math.max(0, last.distance - first.distance);
    const elapsedSeconds = Math.max(1, last.time - first.time);
    const pointCount = hi - lo + 1;

    let gain = 0;
    let prevAlt: number | null = null;
    let firstAlt: number | null = null;
    let lastAlt: number | null = null;

    for (let k = lo; k <= hi; k += 1) {
        const alt = entries[k].altitude;

        if (alt === null) {
            continue;
        }

        if (firstAlt === null) {
            firstAlt = alt;
        }

        lastAlt = alt;

        if (prevAlt !== null && alt - prevAlt > 0.2) {
            gain += alt - prevAlt;
        }

        prevAlt = alt;
    }

    const hasAlt = firstAlt !== null && lastAlt !== null;
    const netGain = hasAlt ? lastAlt! - firstAlt! : 0;
    const elevationGain = hasAlt ? Math.max(gain, Math.max(0, netGain)) : null;
    const avgGradePct =
        hasAlt && distanceMeters > 0 ? (netGain / distanceMeters) * 100 : null;

    return {
        startOrigIndex: first.origIndex,
        endOrigIndex: last.origIndex,
        startDistance: first.distance,
        endDistance: last.distance,
        distanceMeters,
        elapsedSeconds,
        elevationGain,
        avgGradePct,
        pointCount,
        isValid: pointCount >= 5 && distanceMeters >= 100,
    };
});

function emitCustomRange(): void {
    if (!creatorOpen.value || !customPreview.value.isValid) {
        return;
    }

    const label = createForm.name.trim()
        ? `New Segment: ${createForm.name.trim()}`
        : `New Segment (${formatDistance(customPreview.value.distanceMeters, 2)})`;

    emit('selectRange', {
        startDistance: customPreview.value.startDistance,
        endDistance: customPreview.value.endDistance,
        label,
    });
}

function toggleCreator(): void {
    creatorOpen.value = !creatorOpen.value;

    if (creatorOpen.value) {
        selectedEffortId.value = null;
        initSliderBounds();
        emitCustomRange();
    } else {
        emit('selectRange', null);
    }
}

function onStartSliderInput(event: Event): void {
    const val = Number((event.target as HTMLInputElement).value);
    const maxStart = Math.max(0, endPos.value - 4);
    startPos.value = Math.min(val, maxStart);
    emitCustomRange();
}

function onEndSliderInput(event: Event): void {
    const val = Number((event.target as HTMLInputElement).value);
    const minEnd = Math.min(gnssEntries.value.length - 1, startPos.value + 4);
    endPos.value = Math.max(val, minEnd);
    emitCustomRange();
}

function submitCustomSegment(): void {
    if (!customPreview.value.isValid || createForm.processing) {
        return;
    }

    createForm.activity_id = props.activityId;
    createForm.start_index = customPreview.value.startOrigIndex;
    createForm.end_index = customPreview.value.endOrigIndex;

    createForm.post(storeSegment.url(), {
        onSuccess: () => {
            creatorOpen.value = false;
            emit('selectRange', null);
            toast.success(
                page.props.flash?.status ?? 'Custom segment created.',
            );
        },
        onError: () => {
            toast.error('Please adjust the segment bounds and try again.');
        },
    });
}

function effortToRange(effort: ActivitySegmentEffortItem): HighlightRange {
    return {
        startDistance: effort.start_distance_meters,
        endDistance: effort.end_distance_meters,
        label: `${effort.segment_name} (${formatDurationClock(effort.elapsed_seconds)})`,
    };
}

function toggleEffortSelection(effort: ActivitySegmentEffortItem): void {
    if (creatorOpen.value) {
        creatorOpen.value = false;
    }

    if (selectedEffortId.value === effort.id) {
        selectedEffortId.value = null;
        emit('selectRange', null);

        return;
    }

    selectedEffortId.value = effort.id;
    emit('selectRange', effortToRange(effort));
}

function onEffortHover(effort: ActivitySegmentEffortItem): void {
    if (creatorOpen.value || selectedEffortId.value !== null) {
        return;
    }

    emit('selectRange', effortToRange(effort));
}

function onEffortLeave(): void {
    if (creatorOpen.value || selectedEffortId.value !== null) {
        return;
    }

    emit('selectRange', null);
}

function clearSelection(): void {
    selectedEffortId.value = null;
    emit('selectRange', null);
}

function formatEffortSpeedOrPace(speedMps: number | null): string {
    if (props.isSwimming) {
        return formatPace100m(speedMps);
    }

    return props.usesPace ? formatPace(speedMps) : formatSpeed(speedMps);
}

function rankBadgeClass(rank: number | null): string {
    if (rank === 1) {
        return 'border-amber-500/40 bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300';
    }

    if (rank === 2) {
        return 'border-zinc-300 bg-zinc-100 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
    }

    if (rank === 3) {
        return 'border-orange-500/35 bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300';
    }

    return 'border-zinc-200/80 bg-zinc-50 text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400';
}
</script>

<template>
    <div class="space-y-4">
        <!-- Header Bar -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200/90 bg-white p-4 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div>
                <h2
                    class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                >
                    Segments &amp; Climbs
                </h2>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                    Hover or click any matched climb or segment to highlight it
                    on the route map.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    v-if="selectedEffortId !== null"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-zinc-200 px-2.5 py-1.5 font-mono text-[11px] text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="clearSelection"
                >
                    <X class="size-3" />
                    Clear Highlight
                </button>

                <button
                    v-if="canCreateCustom"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 font-mono text-xs font-medium transition-colors"
                    :class="
                        creatorOpen
                            ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                            : 'border-emerald-500/35 bg-emerald-500/10 text-emerald-700 hover:bg-emerald-500/15 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300'
                    "
                    @click="toggleCreator"
                >
                    <Plus class="size-3.5" />
                    <span>Create Custom Segment</span>
                    <ChevronUp v-if="creatorOpen" class="size-3.5" />
                    <ChevronDown v-else class="size-3.5" />
                </button>
            </div>
        </div>

        <!-- Collapsible Create Custom Segment Drawer -->
        <section
            v-if="creatorOpen && canCreateCustom"
            class="space-y-4 rounded-xl border border-emerald-500/30 bg-white p-5 dark:border-emerald-500/25 dark:bg-[#141417]"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3
                        class="font-mono text-xs font-semibold tracking-wider text-emerald-700 uppercase dark:text-emerald-400"
                    >
                        Define Custom Segment Slice
                    </h3>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Drag the start and end sliders to preview the segment on
                        the map above. Historical activities will match
                        automatically.
                    </p>
                </div>
                <span
                    class="font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                >
                    {{ customPreview.pointCount }} GNSS pts
                </span>
            </div>

            <!-- Dual Range Sliders -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <div
                        class="mb-1.5 flex items-center justify-between font-mono text-[11px]"
                    >
                        <span
                            class="text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Start Point
                        </span>
                        <span
                            class="font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatDistance(customPreview.startDistance, 2) }}
                        </span>
                    </div>
                    <input
                        type="range"
                        :min="0"
                        :max="Math.max(0, gnssEntries.length - 5)"
                        :value="startPos"
                        aria-label="Segment start point"
                        class="w-full accent-emerald-600 dark:accent-emerald-400"
                        @input="onStartSliderInput"
                    />
                </div>

                <div>
                    <div
                        class="mb-1.5 flex items-center justify-between font-mono text-[11px]"
                    >
                        <span
                            class="text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            End Point
                        </span>
                        <span
                            class="font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatDistance(customPreview.endDistance, 2) }}
                        </span>
                    </div>
                    <input
                        type="range"
                        :min="4"
                        :max="gnssEntries.length - 1"
                        :value="endPos"
                        aria-label="Segment end point"
                        class="w-full accent-emerald-600 dark:accent-emerald-400"
                        @input="onEndSliderInput"
                    />
                </div>
            </div>

            <!-- Live Readout Strip -->
            <div
                class="grid grid-cols-2 gap-2.5 rounded-lg bg-[#f7f7f5] p-3 sm:grid-cols-4 dark:bg-zinc-900/70"
            >
                <div>
                    <p
                        class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                    >
                        Distance
                    </p>
                    <p
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatDistance(customPreview.distanceMeters, 2) }}
                    </p>
                </div>
                <div>
                    <p
                        class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                    >
                        Elevation Gain
                    </p>
                    <p
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{
                            customPreview.elevationGain !== null
                                ? formatElevation(customPreview.elevationGain)
                                : '—'
                        }}
                    </p>
                </div>
                <div>
                    <p
                        class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                    >
                        Avg Grade
                    </p>
                    <p
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatGrade(customPreview.avgGradePct) }}
                    </p>
                </div>
                <div>
                    <p
                        class="font-mono text-[10px] text-zinc-400 uppercase dark:text-zinc-500"
                    >
                        Elapsed Time
                    </p>
                    <p
                        class="mt-0.5 font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatDurationClock(customPreview.elapsedSeconds) }}
                    </p>
                </div>
            </div>

            <!-- Name & Submit -->
            <form
                class="flex flex-col gap-3 sm:flex-row sm:items-end"
                @submit.prevent="submitCustomSegment"
            >
                <div class="flex-1">
                    <label
                        for="custom-segment-name"
                        class="mb-1 block font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Segment Name (optional — auto-named via OSM if left
                        blank)
                    </label>
                    <input
                        id="custom-segment-name"
                        v-model="createForm.name"
                        type="text"
                        maxlength="160"
                        placeholder="e.g. Park Hill Sprint"
                        class="w-full rounded-lg border border-zinc-200/90 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                        @input="emitCustomRange"
                    />
                    <p
                        v-if="
                            createForm.errors.start_index ||
                            createForm.errors.end_index ||
                            createForm.errors.name
                        "
                        class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                    >
                        {{
                            createForm.errors.start_index ||
                            createForm.errors.end_index ||
                            createForm.errors.name
                        }}
                    </p>
                    <p
                        v-else-if="!customPreview.isValid"
                        class="mt-1 text-xs text-amber-600 dark:text-amber-400"
                    >
                        Select at least 100 m and 5 GNSS points to create a
                        segment.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="submit"
                        :disabled="
                            !customPreview.isValid || createForm.processing
                        "
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 font-mono text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                    >
                        <Flag class="size-3.5" />
                        <span>
                            {{
                                createForm.processing
                                    ? 'Creating...'
                                    : 'Save Segment'
                            }}
                        </span>
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer rounded-lg border border-zinc-200 px-3 py-2 font-mono text-xs text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="toggleCreator"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>

        <!-- Matched Segment Efforts Table -->
        <section
            v-if="segmentEfforts.length > 0"
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr
                            class="border-b border-zinc-100 bg-zinc-50/70 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:text-zinc-400"
                        >
                            <th class="px-4 py-2.5">Segment / Climb</th>
                            <th class="px-4 py-2.5 text-right">Rank</th>
                            <th class="px-4 py-2.5 text-right">Time</th>
                            <th class="px-4 py-2.5 text-right">Pace / Speed</th>
                            <th class="px-4 py-2.5 text-right">VAM</th>
                            <th class="px-4 py-2.5 text-right">Power / W/kg</th>
                            <th class="px-4 py-2.5 text-right">Avg HR</th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-zinc-100 dark:divide-zinc-800/70"
                    >
                        <tr
                            v-for="effort in segmentEfforts"
                            :key="effort.id"
                            class="cursor-pointer transition-colors"
                            :class="
                                selectedEffortId === effort.id
                                    ? 'bg-emerald-500/[0.08] dark:bg-emerald-500/[0.12]'
                                    : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/50'
                            "
                            @click="toggleEffortSelection(effort)"
                            @mouseenter="onEffortHover(effort)"
                            @mouseleave="onEffortLeave"
                        >
                            <td class="px-4 py-3">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <Star
                                        v-if="effort.is_starred"
                                        class="size-3.5 shrink-0 fill-amber-400 text-amber-500"
                                    />
                                    <Link
                                        :href="
                                            showSegment.url(effort.segment_id)
                                        "
                                        class="font-medium text-zinc-900 transition-colors hover:text-emerald-600 dark:text-zinc-100 dark:hover:text-emerald-400"
                                        @click.stop
                                    >
                                        {{ effort.segment_name }}
                                    </Link>
                                    <span
                                        v-if="
                                            climbCategoryMeta(
                                                effort.climb_category,
                                            )
                                        "
                                        :class="[
                                            'rounded border px-1.5 py-0.25 font-mono text-[10px] font-semibold',
                                            climbCategoryMeta(
                                                effort.climb_category,
                                            )!.badgeClass,
                                        ]"
                                    >
                                        {{
                                            climbCategoryMeta(
                                                effort.climb_category,
                                            )!.shortLabel
                                        }}
                                    </span>
                                    <span
                                        v-if="effort.kind === 'custom'"
                                        class="rounded border border-sky-500/30 bg-sky-500/10 px-1.5 py-0.25 font-mono text-[10px] font-medium text-sky-700 dark:text-sky-300"
                                    >
                                        Custom
                                    </span>
                                </div>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 font-mono text-[11px] text-zinc-500 tabular-nums dark:text-zinc-400"
                                >
                                    <span>
                                        {{
                                            formatDistance(
                                                effort.distance_meters,
                                                2,
                                            )
                                        }}
                                    </span>
                                    <span
                                        v-if="effort.elevation_gain !== null"
                                        class="inline-flex items-center gap-0.5"
                                    >
                                        <Mountain class="size-3" />
                                        {{
                                            formatElevation(
                                                effort.elevation_gain,
                                            )
                                        }}
                                    </span>
                                    <span
                                        v-if="effort.avg_grade_pct !== null"
                                        class="inline-flex items-center gap-0.5"
                                    >
                                        <TrendingUp class="size-3" />
                                        {{ formatGrade(effort.avg_grade_pct) }}
                                    </span>
                                    <span
                                        v-if="effort.place_label"
                                        class="inline-flex items-center gap-0.5 truncate"
                                    >
                                        <MapPin class="size-3" />
                                        {{ effort.place_label }}
                                    </span>
                                </div>
                            </td>

                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <span
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-[11px] font-semibold tabular-nums',
                                        rankBadgeClass(effort.rank),
                                    ]"
                                >
                                    <Trophy
                                        v-if="effort.is_pr"
                                        class="size-3"
                                    />
                                    {{
                                        effort.is_pr
                                            ? 'PR'
                                            : `#${effort.rank ?? '—'}`
                                    }}
                                    <span
                                        class="font-normal opacity-70"
                                        v-if="effort.effort_count > 1"
                                    >
                                        /{{ effort.effort_count }}
                                    </span>
                                </span>
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap tabular-nums"
                            >
                                <p
                                    class="font-semibold text-zinc-900 dark:text-zinc-100"
                                >
                                    {{
                                        formatDurationClock(
                                            effort.elapsed_seconds,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="
                                        !effort.is_pr &&
                                        effort.delta_pr_seconds >= 0.5
                                    "
                                    class="text-[10px] text-zinc-400 dark:text-zinc-500"
                                >
                                    +{{
                                        formatDurationClock(
                                            effort.delta_pr_seconds,
                                        )
                                    }}
                                </p>
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 tabular-nums dark:text-zinc-300"
                            >
                                {{ formatEffortSpeedOrPace(effort.avg_speed) }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 tabular-nums dark:text-zinc-300"
                            >
                                {{ formatVam(effort.vam) }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-700 tabular-nums dark:text-zinc-300"
                            >
                                <template v-if="effort.avg_power !== null">
                                    {{ Math.round(effort.avg_power) }} W
                                    <span
                                        v-if="effort.w_per_kg !== null"
                                        class="text-zinc-400 dark:text-zinc-500"
                                    >
                                        · {{ effort.w_per_kg.toFixed(2) }}
                                    </span>
                                </template>
                                <template v-else>—</template>
                            </td>

                            <td
                                class="px-4 py-3 text-right font-mono text-xs whitespace-nowrap text-zinc-600 tabular-nums dark:text-zinc-400"
                            >
                                {{
                                    effort.avg_hr !== null
                                        ? `${effort.avg_hr} bpm`
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Empty State (outdoor activity with GNSS stream but no climbs/segments yet) -->
        <section
            v-else
            class="rounded-xl border border-dashed border-zinc-300/90 bg-white px-5 py-10 text-center dark:border-zinc-800 dark:bg-[#141417]"
        >
            <Mountain class="mx-auto size-6 text-zinc-400 dark:text-zinc-500" />
            <p
                class="mt-2.5 text-sm font-medium text-zinc-800 dark:text-zinc-200"
            >
                No auto-detected climbs or matched segments on this activity yet
            </p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Use "Create Custom Segment" above to carve out any sprint, hill,
                or benchmark stretch from this route.
            </p>
        </section>
    </div>
</template>
