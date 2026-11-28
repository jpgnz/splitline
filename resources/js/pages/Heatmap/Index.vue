<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Crosshair,
    Grid2x2,
    Layers,
    Map as MapIcon,
    Mountain,
    RefreshCw,
    RotateCcw,
    X,
} from '@lucide/vue';
import type {
    Feature,
    FeatureCollection,
    LineString,
    Point,
    Polygon,
} from 'geojson';
import {
    AttributionControl,
    FullscreenControl,
    LngLatBounds,
    Map as MapLibreMap,
    NavigationControl,
    type GeoJSONSource,
    type MapMouseEvent,
    type Point as MapLibrePoint,
} from 'maplibre-gl';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import InfoPopover from '@/components/InfoPopover.vue';
import DateRangeControl, {
    type DateRangePreset,
    type DateWindowMeta,
} from '@/components/DateRangeControl.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import {
    formatDurationClock,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import {
    emptyFeatureCollection,
    ensureMapLibreWorker,
    getMapStyle,
    resolveMapConfig,
    syncMapterhornTerrain,
    toLngLat,
} from '@/lib/maplibre';
import { decodePolyline } from '@/lib/polyline';
import {
    resolveActivitySportKey,
    sportAccentHex as defaultSportAccentHex,
    useSports,
} from '@/lib/sports';
import { useUnits } from '@/lib/units';
import {
    index as activitiesIndex,
    show as activitiesShow,
} from '@/routes/activities';
import {
    explorer as heatmapExplorer,
    index as heatmapIndex,
} from '@/routes/heatmap';
import { sync as syncStreams } from '@/routes/heatmap/streams';

type HeatmapSport = string;
type HeatmapTimeframe =
    | '30d'
    | '12w'
    | '6m'
    | '1y'
    | 'this_year'
    | 'all'
    | 'custom';
type HeatmapOrder = 'newest_on_top' | 'oldest_on_top' | 'activity_type';

interface HeatmapAvailableSport {
    key: string;
    label: string;
    short_label: string;
    accent_hex: string;
}

interface HeatmapTrack {
    activity_id: number;
    name: string;
    sport_category: string;
    effective_discipline: string | null;
    sport_key?: string;
    accent_hex?: string;
    date: string;
    location_name: string | null;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number | null;
    polyline: string;
}

interface DecodedHeatmapTrack extends Omit<HeatmapTrack, 'polyline'> {
    polyline: [number, number][];
}

interface ExplorerSummary {
    zoom: number;
    total_tiles: number;
    max_square: {
        size: number;
        min_x: number;
        min_y: number;
    };
    max_cluster: {
        size: number;
    };
    /** [x, y, visits, is_max_cluster (0 | 1)] */
    tiles: [number, number, number, 0 | 1][];
}

interface HeatmapProps {
    filters: {
        sport: HeatmapSport;
        timeframe: HeatmapTimeframe;
    };
    order: HeatmapOrder;
    dateWindow: DateWindowMeta;
    available_sports?: HeatmapAvailableSport[];
    summary: {
        track_count: number;
        total_distance_meters: number;
        total_elevation_gain: number;
        unsynced_gnss_count: number;
    };
    tracks: HeatmapTrack[];
}

interface IndexedTrack {
    track: DecodedHeatmapTrack;
    minLat: number;
    maxLat: number;
    minLng: number;
    maxLng: number;
}

const props = defineProps<HeatmapProps>();
const page = usePage();

const {
    getSportMeta,
    sportAccentHex: resolveCatalogAccentHex,
    sportShortLabel,
} = useSports();
const { formatDistanceCompact, formatElevation } = useUnits();

const CLICK_RADIUS_PX = 14;

const EXPLORER_TILES_SOURCE_ID = 'heatmap-explorer-tiles';
const EXPLORER_TILES_FILL_LAYER_ID = 'heatmap-explorer-tiles-fill';
const EXPLORER_TILES_LINE_LAYER_ID = 'heatmap-explorer-tiles-line';
const EXPLORER_SQUARE_SOURCE_ID = 'heatmap-explorer-max-square';
const EXPLORER_SQUARE_LINE_LAYER_ID = 'heatmap-explorer-max-square-line';
const BASE_TRACKS_SOURCE_ID = 'heatmap-base-tracks';
const BASE_TRACKS_LAYER_ID = 'heatmap-base-tracks-line';
const HIGHLIGHT_TRACKS_SOURCE_ID = 'heatmap-highlight-tracks';
const HIGHLIGHT_CASING_LAYER_ID = 'heatmap-highlight-tracks-casing';
const HIGHLIGHT_TRACKS_LAYER_ID = 'heatmap-highlight-tracks-line';
const HOVER_TRACK_SOURCE_ID = 'heatmap-hover-track';
const HOVER_CASING_LAYER_ID = 'heatmap-hover-track-casing';
const HOVER_TRACK_LAYER_ID = 'heatmap-hover-track-line';
const CLICK_MARKER_SOURCE_ID = 'heatmap-click-marker';
const CLICK_MARKER_LAYER_ID = 'heatmap-click-marker-circle';

const TIMEFRAME_PRESETS: DateRangePreset[] = [
    { value: '30d', label: '30d' },
    { value: '12w', label: '12w' },
    { value: '6m', label: '6m' },
    { value: '1y', label: '1y' },
    { value: 'all', label: 'All' },
];

const ORDER_OPTIONS: Array<{
    value: HeatmapOrder;
    label: string;
    title: string;
}> = [
    {
        value: 'newest_on_top',
        label: 'Newest on top',
        title: 'Draw oldest activities first so newest routes render on top',
    },
    {
        value: 'oldest_on_top',
        label: 'Oldest on top',
        title: 'Draw newest activities first so earliest routes render on top',
    },
    {
        value: 'activity_type',
        label: 'Activity type',
        title: 'Group layers by activity type',
    },
];

const { isDark } = useChartTheme();
const mapConfig = computed(() => resolveMapConfig(page.props.maps));
const terrainEnabled = computed<boolean>(() => mapConfig.value.terrain_enabled);
const hasExternalMapAttribution = computed<boolean>(
    () => mapConfig.value.tiles_enabled || mapConfig.value.terrain_enabled,
);
const providerConfigured = computed<boolean>(() =>
    Boolean(page.props.sync?.provider_configured),
);

const mapStageRef = ref<HTMLElement | null>(null);
const mapContainerRef = ref<HTMLElement | null>(null);
const inspectorRef = ref<HTMLElement | null>(null);
const inspectedTracks = ref<DecodedHeatmapTrack[]>([]);
const inspectedLatLng = ref<[number, number] | null>(null);
const hoveredTrackId = ref<number | null>(null);
const isQueueingSync = ref<boolean>(false);
const activeOrder = ref<HeatmapOrder>(props.order ?? 'newest_on_top');
const showExplorerTiles = ref<boolean>(false);
const explorerSummary = ref<ExplorerSummary | null>(null);
const isLoadingExplorer = ref<boolean>(false);
const explorerLoadFailed = ref<boolean>(false);
const viewMode = ref<'2d' | '3d'>('2d');
const showTerrain = ref<boolean>(false);

let map: MapLibreMap | null = null;
let resizeObserver: ResizeObserver | null = null;
let styleReady = false;

const sportFilterChips = computed<
    Array<{
        value: HeatmapSport;
        label: string;
        accentHex: string | null;
    }>
>(() => {
    const chips: Array<{
        value: HeatmapSport;
        label: string;
        accentHex: string | null;
    }> = [{ value: 'all', label: 'All Sports', accentHex: null }];

    const source =
        props.available_sports && props.available_sports.length > 0
            ? props.available_sports
            : ['running', 'road_cycling', 'mtb_cycling'].map((key) => {
                  const meta = getSportMeta(key);

                  return {
                      key: meta.key,
                      label: meta.label,
                      short_label: meta.short_label,
                      accent_hex: meta.accent_hex,
                  };
              });

    for (const sport of source) {
        chips.push({
            value: sport.key,
            label: sport.short_label || sport.label,
            accentHex: sport.accent_hex || resolveCatalogAccentHex(sport.key),
        });
    }

    return chips;
});

const decodedTracks = computed<DecodedHeatmapTrack[]>(() =>
    props.tracks.map((track) => ({
        ...track,
        polyline: decodePolyline(track.polyline),
    })),
);

const hasTracks = computed<boolean>(() => decodedTracks.value.length > 0);
const hasActiveFilters = computed<boolean>(
    () => props.filters.sport !== 'all' || props.filters.timeframe !== 'all',
);
const isInspectorOpen = computed<boolean>(
    () => inspectedTracks.value.length > 0,
);

/**
 * Tracks ordered bottom → top for MapLibre GL rendering (last item draws on top).
 */
const orderedTracksForRender = computed<DecodedHeatmapTrack[]>(() => {
    const tracks = decodedTracks.value;

    if (tracks.length <= 1) {
        return tracks;
    }

    if (activeOrder.value === 'newest_on_top') {
        return [...tracks].reverse();
    }

    if (activeOrder.value === 'oldest_on_top') {
        return tracks;
    }

    // Group by sport key; draw higher-volume sports underneath so lower-volume sports stay visible,
    // and move any user-selected priority sport to the very top.
    const groups = new Map<string, DecodedHeatmapTrack[]>();

    for (const track of tracks) {
        const key = resolveTrackSportKey(track);
        const list = groups.get(key);

        if (list) {
            list.push(track);
        } else {
            groups.set(key, [track]);
        }
    }

    const sortedKeys = [...groups.keys()].sort((a, b) => {
        const countDiff =
            (groups.get(b)?.length ?? 0) - (groups.get(a)?.length ?? 0);

        if (countDiff !== 0) {
            return countDiff;
        }

        return sportShortLabel(a).localeCompare(sportShortLabel(b));
    });

    const ordered: DecodedHeatmapTrack[] = [];

    for (const key of sortedKeys) {
        const groupTracks = groups.get(key) ?? [];

        // Within each sport group, draw oldest first so newer activities of that sport sit on top.
        for (let i = groupTracks.length - 1; i >= 0; i -= 1) {
            ordered.push(groupTracks[i]);
        }
    }

    return ordered;
});

const indexedTracks = computed<IndexedTrack[]>(() =>
    [...orderedTracksForRender.value].reverse().map((track) => {
        let minLat = Number.POSITIVE_INFINITY;
        let maxLat = Number.NEGATIVE_INFINITY;
        let minLng = Number.POSITIVE_INFINITY;
        let maxLng = Number.NEGATIVE_INFINITY;

        for (const [lat, lng] of track.polyline) {
            if (lat < minLat) {
                minLat = lat;
            }

            if (lat > maxLat) {
                maxLat = lat;
            }

            if (lng < minLng) {
                minLng = lng;
            }

            if (lng > maxLng) {
                maxLng = lng;
            }
        }

        return {
            track,
            minLat,
            maxLat,
            minLng,
            maxLng,
        };
    }),
);

function resolveTrackSportKey(track: DecodedHeatmapTrack): string {
    return (
        track.sport_key ??
        resolveActivitySportKey(
            track.sport_category,
            track.effective_discipline,
        )
    );
}

function trackColor(track: DecodedHeatmapTrack): string {
    if (track.accent_hex) {
        return track.accent_hex;
    }

    const key = resolveTrackSportKey(track);

    return resolveCatalogAccentHex(key) || defaultSportAccentHex(key);
}

function sportLabel(track: DecodedHeatmapTrack): string {
    return sportShortLabel(resolveTrackSportKey(track));
}

function formatTrackDate(dateStr: string): string {
    const short = formatShortDate(dateStr);
    const yearMatch = /^(\d{4})-/.exec(dateStr);

    if (!yearMatch) {
        return short;
    }

    const year = Number(yearMatch[1]);
    const currentYear = new Date().getFullYear();

    return year !== currentYear ? `${short} ${year}` : short;
}

function buildFilterQuery(
    overrides: Partial<{
        sport: HeatmapSport;
        timeframe: HeatmapTimeframe;
        end: string | null;
        from: string | null;
        to: string | null;
        order: HeatmapOrder;
    }> = {},
): Record<string, string> {
    const nextSport = overrides.sport ?? props.filters.sport;
    const nextTimeframe = overrides.timeframe ?? props.filters.timeframe;
    const nextEnd =
        overrides.end !== undefined
            ? overrides.end
            : props.dateWindow.isLatest
              ? null
              : props.dateWindow.end;
    const nextFrom =
        overrides.from !== undefined ? overrides.from : props.dateWindow.from;
    const nextTo =
        overrides.to !== undefined ? overrides.to : props.dateWindow.to;
    const nextOrder = overrides.order ?? activeOrder.value;

    const query: Record<string, string> = {};

    if (nextSport !== 'all') {
        query.sport = nextSport;
    }

    if (nextTimeframe !== 'all') {
        query.timeframe = nextTimeframe;
    }

    if (nextTimeframe === 'custom') {
        if (nextFrom) {
            query.from = nextFrom;
        }

        if (nextTo) {
            query.to = nextTo;
        }
    } else if (nextTimeframe !== 'all' && nextEnd) {
        query.end = nextEnd;
    }

    if (nextOrder !== 'newest_on_top') {
        query.order = nextOrder;
    }

    return query;
}

function navigateFilters(
    overrides: Partial<{
        sport: HeatmapSport;
        timeframe: HeatmapTimeframe;
        end: string | null;
        from: string | null;
        to: string | null;
        order: HeatmapOrder;
    }>,
): void {
    const query = buildFilterQuery(overrides);

    router.get(heatmapIndex(), query, {
        preserveState: true,
        preserveScroll: true,
    });
}

function syncOrderUrlParam(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    if (activeOrder.value === 'newest_on_top') {
        url.searchParams.delete('order');
    } else {
        url.searchParams.set('order', activeOrder.value);
    }

    window.history.replaceState(window.history.state, '', url.toString());
}

function selectSport(sport: HeatmapSport): void {
    if (sport === props.filters.sport) {
        return;
    }

    navigateFilters({ sport });
}

function selectTimeframe(timeframe: string): void {
    navigateFilters({
        timeframe: timeframe as HeatmapTimeframe,
        end: null,
        from: null,
        to: null,
    });
}

function stepTimeframe(end: string | null): void {
    navigateFilters({
        timeframe: props.filters.timeframe,
        end,
        from: null,
        to: null,
    });
}

function applyCustomTimeframe(payload: {
    from: string | null;
    to: string | null;
}): void {
    navigateFilters({
        timeframe: 'custom',
        end: null,
        from: payload.from,
        to: payload.to,
    });
}

function selectOrder(order: HeatmapOrder): void {
    if (order === activeOrder.value) {
        return;
    }

    activeOrder.value = order;

    syncOrderUrlParam();
}

function resetFilters(): void {
    navigateFilters({
        sport: 'all',
        timeframe: 'all',
        end: null,
        from: null,
        to: null,
    });
}

function queueGnssSync(): void {
    if (isQueueingSync.value) {
        return;
    }

    router.post(
        syncStreams(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isQueueingSync.value = true;
            },
            onSuccess: () => {
                toast.success(
                    page.props.flash?.status ??
                        'Requested GPS route data sync.',
                );
            },
            onError: () => {
                toast.error('Failed to queue GPS route data sync.');
            },
            onFinish: () => {
                isQueueingSync.value = false;
            },
        },
    );
}

async function loadExplorerSummary(): Promise<void> {
    if (isLoadingExplorer.value || explorerSummary.value !== null) {
        return;
    }

    isLoadingExplorer.value = true;
    explorerLoadFailed.value = false;

    try {
        const response = await fetch(heatmapExplorer.url(), {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            explorerLoadFailed.value = true;

            return;
        }

        explorerSummary.value = (await response.json()) as ExplorerSummary;
    } catch {
        explorerLoadFailed.value = true;
    } finally {
        isLoadingExplorer.value = false;
    }
}

function setExplorerTilesVisible(visible: boolean): void {
    showExplorerTiles.value = visible;

    if (visible) {
        void loadExplorerSummary();
    }
}

function selectViewMode(mode: '2d' | '3d'): void {
    if (mode === '3d' && !terrainEnabled.value) {
        return;
    }

    if (viewMode.value === mode) {
        return;
    }

    viewMode.value = mode;

    if (mode === '3d' && !showTerrain.value) {
        showTerrain.value = true;
    }

    if (!map) {
        return;
    }

    if (mode === '3d') {
        map.easeTo({
            pitch: Math.max(map.getPitch(), 55),
            duration: 500,
        });
    } else {
        map.easeTo({
            pitch: 0,
            bearing: 0,
            duration: 450,
        });
    }
}

function toggleTerrain(): void {
    if (!terrainEnabled.value) {
        return;
    }

    showTerrain.value = !showTerrain.value;
}

function syncTerrainState(): void {
    if (!map || !styleReady) {
        return;
    }

    syncMapterhornTerrain(map, {
        hillshade: showTerrain.value,
        terrain3d: viewMode.value === '3d',
        isDark: isDark.value,
        enabled: terrainEnabled.value,
        tilejsonUrl: mapConfig.value.terrain_tilejson_url,
    });
}

/**
 * Activity IDs of base tracks rendered within the click radius. Uses the rendered geometry so hit-testing
 * stays accurate (and cheap) when the map is pitched or draped over 3D terrain.
 */
function renderedTrackIdsNearPoint(
    mapInstance: MapLibreMap,
    point: MapLibrePoint,
): Set<number> {
    const ids = new Set<number>();

    if (!mapInstance.getLayer(BASE_TRACKS_LAYER_ID)) {
        return ids;
    }

    const features = mapInstance.queryRenderedFeatures(
        [
            [point.x - CLICK_RADIUS_PX, point.y - CLICK_RADIUS_PX],
            [point.x + CLICK_RADIUS_PX, point.y + CLICK_RADIUS_PX],
        ],
        { layers: [BASE_TRACKS_LAYER_ID] },
    );

    for (const feature of features) {
        const activityId = Number(feature.properties?.activity_id);

        if (Number.isFinite(activityId)) {
            ids.add(activityId);
        }
    }

    return ids;
}

function handleMapClick(event: MapMouseEvent): void {
    if (!map || !styleReady || indexedTracks.value.length === 0) {
        return;
    }

    const hitIds = renderedTrackIdsNearPoint(map, event.point);
    const matched: DecodedHeatmapTrack[] = indexedTracks.value
        .filter((item) => hitIds.has(item.track.activity_id))
        .map((item) => item.track);

    if (matched.length === 0) {
        closeInspector();

        return;
    }

    inspectedTracks.value = matched;
    inspectedLatLng.value = [event.lngLat.lat, event.lngLat.lng];
    hoveredTrackId.value = null;
    updateBaseTrackStyle();
    renderHighlights();

    if (typeof window !== 'undefined' && window.innerWidth < 768) {
        nextTick(() => {
            inspectorRef.value?.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
            });
        });
    }
}

function closeInspector(): void {
    if (inspectedTracks.value.length === 0 && inspectedLatLng.value === null) {
        return;
    }

    inspectedTracks.value = [];
    inspectedLatLng.value = null;
    hoveredTrackId.value = null;
    updateBaseTrackStyle();
    renderHighlights();
}

function setHoveredTrack(activityId: number | null): void {
    if (hoveredTrackId.value === activityId) {
        return;
    }

    hoveredTrackId.value = activityId;
    renderHighlights();
}

function tileBounds(
    x: number,
    y: number,
    zoom = 14,
): { north: number; south: number; west: number; east: number } {
    const n = 2 ** zoom;
    const west = (x / n) * 360 - 180;
    const east = ((x + 1) / n) * 360 - 180;
    const north =
        (Math.atan(Math.sinh(Math.PI * (1 - (2 * y) / n))) * 180) / Math.PI;
    const south =
        (Math.atan(Math.sinh(Math.PI * (1 - (2 * (y + 1)) / n))) * 180) /
        Math.PI;

    return { north, south, west, east };
}

function buildExplorerFeatureCollections(summary: ExplorerSummary): {
    tiles: FeatureCollection<Polygon>;
    maxSquare: FeatureCollection<Polygon>;
} {
    const zoom = summary.zoom || 14;
    const tileFeatures: Array<Feature<Polygon>> = [];

    for (const [x, y, visits, isCluster] of summary.tiles) {
        const { north, south, west, east } = tileBounds(x, y, zoom);

        tileFeatures.push({
            type: 'Feature',
            properties: {
                x,
                y,
                visits,
                is_cluster: isCluster === 1,
            },
            geometry: {
                type: 'Polygon',
                coordinates: [
                    [
                        [west, north],
                        [east, north],
                        [east, south],
                        [west, south],
                        [west, north],
                    ],
                ],
            },
        });
    }

    const maxSquareFeatures: Array<Feature<Polygon>> = [];

    if (summary.max_square.size > 0) {
        const topLeft = tileBounds(
            summary.max_square.min_x,
            summary.max_square.min_y,
            zoom,
        );
        const bottomRight = tileBounds(
            summary.max_square.min_x + summary.max_square.size - 1,
            summary.max_square.min_y + summary.max_square.size - 1,
            zoom,
        );

        maxSquareFeatures.push({
            type: 'Feature',
            properties: {
                size: summary.max_square.size,
            },
            geometry: {
                type: 'Polygon',
                coordinates: [
                    [
                        [topLeft.west, topLeft.north],
                        [bottomRight.east, topLeft.north],
                        [bottomRight.east, bottomRight.south],
                        [topLeft.west, bottomRight.south],
                        [topLeft.west, topLeft.north],
                    ],
                ],
            },
        });
    }

    return {
        tiles: {
            type: 'FeatureCollection',
            features: tileFeatures,
        },
        maxSquare: {
            type: 'FeatureCollection',
            features: maxSquareFeatures,
        },
    };
}

function buildTracksFeatureCollection(
    tracks: DecodedHeatmapTrack[],
): FeatureCollection<LineString> {
    const features: Array<Feature<LineString>> = [];

    for (const track of tracks) {
        if (track.polyline.length < 2) {
            continue;
        }

        features.push({
            type: 'Feature',
            properties: {
                activity_id: track.activity_id,
                color: trackColor(track),
            },
            geometry: {
                type: 'LineString',
                coordinates: track.polyline.map(toLngLat),
            },
        });
    }

    return {
        type: 'FeatureCollection',
        features,
    };
}

function baseTrackOpacity(): number {
    return isInspectorOpen.value
        ? isDark.value
            ? 0.16
            : 0.14
        : isDark.value
          ? 0.38
          : 0.32;
}

function casingColor(): string {
    return isDark.value ? '#101012' : '#ffffff';
}

function ensureOverlayLayers(): void {
    if (!map) {
        return;
    }

    const casing = casingColor();

    if (!map.getSource(EXPLORER_TILES_SOURCE_ID)) {
        map.addSource(EXPLORER_TILES_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(EXPLORER_TILES_FILL_LAYER_ID)) {
        map.addLayer({
            id: EXPLORER_TILES_FILL_LAYER_ID,
            type: 'fill',
            source: EXPLORER_TILES_SOURCE_ID,
            paint: {
                'fill-color': [
                    'case',
                    ['==', ['get', 'is_cluster'], true],
                    '#0ea5e9',
                    '#10b981',
                ],
                'fill-opacity': [
                    'case',
                    ['==', ['get', 'is_cluster'], true],
                    isDark.value ? 0.24 : 0.18,
                    isDark.value ? 0.14 : 0.12,
                ],
            },
        });
    }

    if (!map.getLayer(EXPLORER_TILES_LINE_LAYER_ID)) {
        map.addLayer({
            id: EXPLORER_TILES_LINE_LAYER_ID,
            type: 'line',
            source: EXPLORER_TILES_SOURCE_ID,
            paint: {
                'line-color': [
                    'case',
                    ['==', ['get', 'is_cluster'], true],
                    isDark.value ? '#38bdf8' : '#0284c7',
                    isDark.value ? '#34d399' : '#059669',
                ],
                'line-width': 0.85,
                'line-opacity': isDark.value ? 0.55 : 0.45,
            },
        });
    }

    if (!map.getSource(BASE_TRACKS_SOURCE_ID)) {
        map.addSource(BASE_TRACKS_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(BASE_TRACKS_LAYER_ID)) {
        map.addLayer({
            id: BASE_TRACKS_LAYER_ID,
            type: 'line',
            source: BASE_TRACKS_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 2.5,
                'line-opacity': baseTrackOpacity(),
            },
        });
    }

    if (!map.getSource(EXPLORER_SQUARE_SOURCE_ID)) {
        map.addSource(EXPLORER_SQUARE_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(EXPLORER_SQUARE_LINE_LAYER_ID)) {
        map.addLayer({
            id: EXPLORER_SQUARE_LINE_LAYER_ID,
            type: 'line',
            source: EXPLORER_SQUARE_SOURCE_ID,
            layout: {
                'line-cap': 'square',
                'line-join': 'miter',
            },
            paint: {
                'line-color': isDark.value ? '#fbbf24' : '#d97706',
                'line-width': 2.5,
                'line-dasharray': [4, 2],
                'line-opacity': 0.95,
            },
        });
    }

    if (!map.getSource(HIGHLIGHT_TRACKS_SOURCE_ID)) {
        map.addSource(HIGHLIGHT_TRACKS_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(HIGHLIGHT_CASING_LAYER_ID)) {
        map.addLayer({
            id: HIGHLIGHT_CASING_LAYER_ID,
            type: 'line',
            source: HIGHLIGHT_TRACKS_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': casing,
                'line-width': 5,
                'line-opacity': 0.45,
            },
        });
    }

    if (!map.getLayer(HIGHLIGHT_TRACKS_LAYER_ID)) {
        map.addLayer({
            id: HIGHLIGHT_TRACKS_LAYER_ID,
            type: 'line',
            source: HIGHLIGHT_TRACKS_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 3.25,
                'line-opacity': 0.9,
            },
        });
    }

    if (!map.getSource(HOVER_TRACK_SOURCE_ID)) {
        map.addSource(HOVER_TRACK_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(HOVER_CASING_LAYER_ID)) {
        map.addLayer({
            id: HOVER_CASING_LAYER_ID,
            type: 'line',
            source: HOVER_TRACK_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': casing,
                'line-width': 7,
                'line-opacity': 0.85,
            },
        });
    }

    if (!map.getLayer(HOVER_TRACK_LAYER_ID)) {
        map.addLayer({
            id: HOVER_TRACK_LAYER_ID,
            type: 'line',
            source: HOVER_TRACK_SOURCE_ID,
            layout: {
                'line-cap': 'round',
                'line-join': 'round',
            },
            paint: {
                'line-color': ['get', 'color'],
                'line-width': 4.25,
                'line-opacity': 1,
            },
        });
    }

    if (!map.getSource(CLICK_MARKER_SOURCE_ID)) {
        map.addSource(CLICK_MARKER_SOURCE_ID, {
            type: 'geojson',
            data: emptyFeatureCollection(),
        });
    }

    if (!map.getLayer(CLICK_MARKER_LAYER_ID)) {
        map.addLayer({
            id: CLICK_MARKER_LAYER_ID,
            type: 'circle',
            source: CLICK_MARKER_SOURCE_ID,
            paint: {
                'circle-radius': 6,
                'circle-color': isDark.value ? '#f4f4f5' : '#18181b',
                'circle-stroke-width': 2.5,
                'circle-stroke-color': casing,
            },
        });
    }
}

function updateBaseTrackStyle(): void {
    if (!map || !styleReady || !map.getLayer(BASE_TRACKS_LAYER_ID)) {
        return;
    }

    map.setPaintProperty(
        BASE_TRACKS_LAYER_ID,
        'line-opacity',
        baseTrackOpacity(),
    );
}

function renderExplorerLayers(): void {
    if (!map || !styleReady) {
        return;
    }

    ensureOverlayLayers();

    const tilesSource = map.getSource(EXPLORER_TILES_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    const squareSource = map.getSource(EXPLORER_SQUARE_SOURCE_ID) as
        | GeoJSONSource
        | undefined;

    const summary = explorerSummary.value;

    if (
        !showExplorerTiles.value ||
        summary === null ||
        summary.total_tiles === 0
    ) {
        tilesSource?.setData(emptyFeatureCollection());
        squareSource?.setData(emptyFeatureCollection());

        return;
    }

    const { tiles, maxSquare } = buildExplorerFeatureCollections(summary);
    tilesSource?.setData(tiles);
    squareSource?.setData(maxSquare);
}

function renderBaseTracks(): void {
    if (!map || !styleReady) {
        return;
    }

    ensureOverlayLayers();

    const baseSource = map.getSource(BASE_TRACKS_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    baseSource?.setData(
        buildTracksFeatureCollection(orderedTracksForRender.value),
    );
    updateBaseTrackStyle();
}

function renderHighlights(): void {
    if (!map || !styleReady) {
        return;
    }

    ensureOverlayLayers();

    const highlightSource = map.getSource(HIGHLIGHT_TRACKS_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    const hoverSource = map.getSource(HOVER_TRACK_SOURCE_ID) as
        | GeoJSONSource
        | undefined;
    const markerSource = map.getSource(CLICK_MARKER_SOURCE_ID) as
        | GeoJSONSource
        | undefined;

    if (inspectedTracks.value.length === 0) {
        highlightSource?.setData(emptyFeatureCollection());
        hoverSource?.setData(emptyFeatureCollection());
        markerSource?.setData(emptyFeatureCollection());

        return;
    }

    const casing = casingColor();
    const hasHovered = hoveredTrackId.value !== null;
    const otherTracks: DecodedHeatmapTrack[] = [];
    const hoveredTracks: DecodedHeatmapTrack[] = [];

    for (const track of inspectedTracks.value) {
        if (track.activity_id === hoveredTrackId.value) {
            hoveredTracks.push(track);
        } else {
            otherTracks.push(track);
        }
    }

    highlightSource?.setData(buildTracksFeatureCollection(otherTracks));
    hoverSource?.setData(buildTracksFeatureCollection(hoveredTracks));

    map.setPaintProperty(HIGHLIGHT_CASING_LAYER_ID, 'line-color', casing);
    map.setPaintProperty(
        HIGHLIGHT_CASING_LAYER_ID,
        'line-opacity',
        hasHovered ? 0 : 0.45,
    );
    map.setPaintProperty(
        HIGHLIGHT_TRACKS_LAYER_ID,
        'line-width',
        hasHovered ? 2.5 : 3.25,
    );
    map.setPaintProperty(
        HIGHLIGHT_TRACKS_LAYER_ID,
        'line-opacity',
        hasHovered ? 0.35 : 0.9,
    );

    map.setPaintProperty(HOVER_CASING_LAYER_ID, 'line-color', casing);

    const markerData: FeatureCollection<Point> = inspectedLatLng.value
        ? {
              type: 'FeatureCollection',
              features: [
                  {
                      type: 'Feature',
                      properties: {},
                      geometry: {
                          type: 'Point',
                          coordinates: toLngLat(inspectedLatLng.value),
                      },
                  },
              ],
          }
        : { type: 'FeatureCollection', features: [] };

    markerSource?.setData(markerData);
    map.setPaintProperty(
        CLICK_MARKER_LAYER_ID,
        'circle-color',
        isDark.value ? '#f4f4f5' : '#18181b',
    );
    map.setPaintProperty(CLICK_MARKER_LAYER_ID, 'circle-stroke-color', casing);
}

function computeAllTracksBounds(): LngLatBounds | null {
    if (indexedTracks.value.length === 0) {
        return null;
    }

    let minLat = Number.POSITIVE_INFINITY;
    let maxLat = Number.NEGATIVE_INFINITY;
    let minLng = Number.POSITIVE_INFINITY;
    let maxLng = Number.NEGATIVE_INFINITY;

    for (const item of indexedTracks.value) {
        if (item.minLat < minLat) {
            minLat = item.minLat;
        }

        if (item.maxLat > maxLat) {
            maxLat = item.maxLat;
        }

        if (item.minLng < minLng) {
            minLng = item.minLng;
        }

        if (item.maxLng > maxLng) {
            maxLng = item.maxLng;
        }
    }

    if (
        !Number.isFinite(minLat) ||
        !Number.isFinite(maxLat) ||
        !Number.isFinite(minLng) ||
        !Number.isFinite(maxLng)
    ) {
        return null;
    }

    return new LngLatBounds([minLng, minLat], [maxLng, maxLat]);
}

function fitAllTracks(animate = false): void {
    if (!map) {
        return;
    }

    const bounds = computeAllTracksBounds();

    if (bounds) {
        map.fitBounds(bounds, {
            padding: 36,
            maxZoom: 15,
            duration: animate ? 450 : 0,
        });
    }
}

function destroyMap(): void {
    resizeObserver?.disconnect();
    resizeObserver = null;
    styleReady = false;

    if (map) {
        map.off('click', handleMapClick);
        map.remove();
        map = null;
    }
}

function initMap(): void {
    if (!mapContainerRef.value || map) {
        return;
    }

    ensureMapLibreWorker();

    const initialBounds = computeAllTracksBounds();

    map = new MapLibreMap({
        container: mapContainerRef.value,
        style: getMapStyle(isDark.value, mapConfig.value),
        scrollZoom: true,
        maxPitch: 75,
        attributionControl: false,
        ...(initialBounds
            ? {
                  bounds: initialBounds,
                  fitBoundsOptions: {
                      padding: 36,
                      maxZoom: 15,
                  },
              }
            : {
                  center: [0, 0],
                  zoom: 2,
              }),
    });

    map.addControl(
        new NavigationControl({ showCompass: true, visualizePitch: true }),
        'top-left',
    );

    const fullscreenControl = new FullscreenControl({
        container: mapStageRef.value ?? undefined,
    });
    fullscreenControl.on('fullscreenstart', () => {
        map?.resize();
    });
    fullscreenControl.on('fullscreenend', () => {
        map?.resize();
    });
    map.addControl(fullscreenControl, 'top-left');

    if (hasExternalMapAttribution.value) {
        map.addControl(
            new AttributionControl({ compact: true }),
            'bottom-right',
        );
    }

    map.on('style.load', () => {
        styleReady = true;
        syncTerrainState();
        renderBaseTracks();
        renderExplorerLayers();
        renderHighlights();
    });

    map.on('mouseenter', BASE_TRACKS_LAYER_ID, () => {
        if (map) {
            map.getCanvas().style.cursor = 'pointer';
        }
    });

    map.on('mouseleave', BASE_TRACKS_LAYER_ID, () => {
        if (map) {
            map.getCanvas().style.cursor = '';
        }
    });

    map.on('click', handleMapClick);

    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
            map?.resize();
        });
        resizeObserver.observe(mapContainerRef.value);
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && isInspectorOpen.value) {
        closeInspector();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);

    if (hasTracks.value) {
        initMap();
    }
});

watch(isDark, (dark) => {
    if (!map) {
        return;
    }

    styleReady = false;
    map.setStyle(getMapStyle(dark, mapConfig.value), { diff: false });
});

watch(
    () => props.order,
    (nextOrder) => {
        if (nextOrder && nextOrder !== activeOrder.value) {
            activeOrder.value = nextOrder;
        }
    },
);

watch(orderedTracksForRender, () => {
    renderBaseTracks();

    if (inspectedTracks.value.length > 1) {
        const rankById = new Map<number, number>();
        const topDown = [...orderedTracksForRender.value].reverse();

        topDown.forEach((track, index) => {
            rankById.set(track.activity_id, index);
        });

        inspectedTracks.value = [...inspectedTracks.value].sort(
            (a, b) =>
                (rankById.get(a.activity_id) ?? 0) -
                (rankById.get(b.activity_id) ?? 0),
        );
    }
});

watch(showExplorerTiles, () => {
    renderExplorerLayers();
});

watch(explorerSummary, () => {
    renderExplorerLayers();
});

watch([viewMode, showTerrain], () => {
    syncTerrainState();
});

watch(
    () => props.tracks,
    async () => {
        inspectedTracks.value = [];
        inspectedLatLng.value = null;
        hoveredTrackId.value = null;

        if (!hasTracks.value) {
            destroyMap();

            return;
        }

        await nextTick();

        if (!map) {
            initMap();

            return;
        }

        renderBaseTracks();
        renderExplorerLayers();
        renderHighlights();
        fitAllTracks(false);
    },
);

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    destroyMap();
});
</script>

<template>
    <div class="space-y-5">
        <Head title="Route Heatmap" />

        <!-- Page Header -->
        <header
            class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100"
                >
                    Route Heatmap
                </h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Click any route corridor to inspect overlapping activities,
                    or toggle Explorer Tiles (z14 ~2.4 km squares) to track your
                    Max Square and Max Cluster.
                </p>
            </div>
        </header>

        <!-- Compact Filter Bar + Summary Strip -->
        <section
            class="space-y-3 rounded-xl border border-zinc-200/90 bg-white p-3.5 dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <!-- Row 1: Sport Filter Pills + Reset -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="pill in sportFilterChips"
                        :key="pill.value"
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] font-medium transition-colors"
                        :class="
                            filters.sport === pill.value
                                ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950'
                                : 'border-zinc-200/90 bg-white text-zinc-600 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-zinc-900/50 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100'
                        "
                        @click="selectSport(pill.value)"
                    >
                        <span
                            class="size-1.5 rounded-full"
                            :class="
                                pill.accentHex === null
                                    ? 'bg-zinc-400 dark:bg-zinc-500'
                                    : ''
                            "
                            :style="
                                pill.accentHex !== null
                                    ? { backgroundColor: pill.accentHex }
                                    : undefined
                            "
                        />
                        <span>{{ pill.label }}</span>
                    </button>
                </div>

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 font-mono text-[11px] font-medium text-zinc-600 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-zinc-900/50 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                    @click="resetFilters"
                >
                    <RotateCcw class="size-3" />
                    <span>Reset</span>
                </button>
            </div>

            <!-- Row 2: Date Range Controls + Layer Order -->
            <div
                class="flex flex-wrap items-center justify-between gap-2.5 border-t border-zinc-100 pt-3 dark:border-zinc-800/80"
            >
                <DateRangeControl
                    :presets="TIMEFRAME_PRESETS"
                    :window="dateWindow"
                    popover-align="left"
                    aria-label="Heatmap date range"
                    @select-preset="selectTimeframe"
                    @step="stepTimeframe"
                    @apply-custom="applyCustomTimeframe"
                />

                <!-- Explorer Tiles + Layer Render Order -->
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Explorer z14 Grid Toggle -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            class="inline-flex items-center gap-1 font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <Grid2x2 class="size-3" />
                            <span>Explorer</span>
                            <InfoPopover topic="explorer_tiles" />
                        </span>
                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            role="group"
                            aria-label="Explorer tiles overlay"
                        >
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 font-mono text-[11px] font-medium transition-colors"
                                :class="
                                    !showExplorerTiles
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'cursor-pointer text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="setExplorerTilesVisible(false)"
                            >
                                Off
                            </button>
                            <button
                                type="button"
                                :disabled="isLoadingExplorer"
                                class="rounded-md px-2 py-1 font-mono text-[11px] font-medium transition-colors disabled:cursor-wait"
                                :class="
                                    showExplorerTiles
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'cursor-pointer text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="setExplorerTilesVisible(true)"
                            >
                                Tiles
                            </button>
                        </div>
                    </div>

                    <!-- Layer Render Order -->
                    <div
                        class="flex flex-wrap items-center gap-1.5"
                        :class="!hasTracks ? 'opacity-50' : ''"
                    >
                        <span
                            class="inline-flex items-center gap-1 font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            <Layers class="size-3" />
                            <span>Order</span>
                        </span>
                        <div
                            class="inline-flex rounded-lg border border-zinc-200/90 bg-[#f7f7f5] p-0.5 dark:border-zinc-800 dark:bg-zinc-900/60"
                            role="group"
                            aria-label="Route layer order"
                        >
                            <button
                                v-for="opt in ORDER_OPTIONS"
                                :key="opt.value"
                                type="button"
                                :title="opt.title"
                                :disabled="!hasTracks"
                                class="rounded-md px-2 py-1 font-mono text-[11px] font-medium transition-colors disabled:cursor-not-allowed"
                                :class="
                                    activeOrder === opt.value
                                        ? 'bg-white text-zinc-950 shadow-xs dark:bg-zinc-800 dark:text-zinc-100'
                                        : 'cursor-pointer text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'
                                "
                                @click="selectOrder(opt.value)"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 3: Summary Stats Strip -->
            <div
                class="flex flex-col gap-2.5 border-t border-zinc-100 pt-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-zinc-800/80"
            >
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div class="flex items-baseline gap-2">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Tracks
                        </span>
                        <span
                            class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatNumber(summary.track_count, 0) }}
                        </span>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Total Distance
                        </span>
                        <span
                            class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{
                                formatDistanceCompact(
                                    summary.total_distance_meters,
                                    1,
                                )
                            }}
                        </span>
                    </div>

                    <div class="flex items-baseline gap-2">
                        <span
                            class="font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Total Elevation
                        </span>
                        <span
                            class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatElevation(summary.total_elevation_gain) }}
                        </span>
                    </div>

                    <template v-if="showExplorerTiles">
                        <span
                            class="hidden h-4 w-px bg-zinc-200 sm:inline-block dark:bg-zinc-800"
                            aria-hidden="true"
                        />

                        <div
                            v-if="isLoadingExplorer"
                            class="h-6 w-64 animate-pulse rounded-lg bg-zinc-100 dark:bg-zinc-800/70"
                            aria-label="Loading explorer tiles"
                        />

                        <span
                            v-else-if="explorerLoadFailed"
                            class="font-mono text-[11px] text-rose-600 dark:text-rose-400"
                        >
                            Explorer tiles could not be loaded.
                        </span>

                        <div
                            v-else-if="explorerSummary"
                            class="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/80 px-2.5 py-1 dark:border-zinc-800/80 dark:bg-zinc-900/50"
                        >
                            <div class="flex items-center gap-1.5">
                                <span
                                    class="inline-flex items-center gap-1 font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    <span>Lifetime Tiles</span>
                                    <InfoPopover
                                        topic="explorer_lifetime_tiles"
                                    />
                                </span>
                                <span
                                    class="font-mono text-xs font-semibold text-emerald-700 tabular-nums dark:text-emerald-400"
                                >
                                    {{
                                        formatNumber(
                                            explorerSummary.total_tiles,
                                            0,
                                        )
                                    }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1.5">
                                <span
                                    class="inline-flex items-center gap-1 font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    <span>Max Square</span>
                                    <InfoPopover topic="explorer_max_square" />
                                </span>
                                <span
                                    class="font-mono text-xs font-semibold text-amber-700 tabular-nums dark:text-amber-400"
                                >
                                    {{ explorerSummary.max_square.size }}×{{
                                        explorerSummary.max_square.size
                                    }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1.5">
                                <span
                                    class="inline-flex items-center gap-1 font-mono text-[10px] tracking-widest text-zinc-500 uppercase dark:text-zinc-400"
                                >
                                    <span>Max Cluster</span>
                                    <InfoPopover topic="explorer_max_cluster" />
                                </span>
                                <span
                                    class="font-mono text-xs font-semibold text-sky-700 tabular-nums dark:text-sky-400"
                                >
                                    {{
                                        formatNumber(
                                            explorerSummary.max_cluster.size,
                                            0,
                                        )
                                    }}
                                </span>
                            </div>
                        </div>
                    </template>
                </div>

                <div
                    v-if="summary.unsynced_gnss_count > 0 && providerConfigured"
                    class="flex flex-wrap items-center gap-2.5 text-xs text-zinc-500 dark:text-zinc-400"
                >
                    <button
                        type="button"
                        :disabled="isQueueingSync"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-900 bg-zinc-900 px-2.5 py-1 font-mono text-[11px] font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                        @click="queueGnssSync"
                    >
                        <RefreshCw
                            :class="[
                                'size-3',
                                isQueueingSync ? 'animate-spin' : '',
                            ]"
                        />
                        <span>
                            {{
                                isQueueingSync
                                    ? 'Syncing Routes...'
                                    : 'Sync Route Data'
                            }}
                        </span>
                        <span
                            class="rounded bg-white/15 px-1.5 py-0.25 text-[10px] tabular-nums dark:bg-zinc-900/15"
                        >
                            {{ formatNumber(summary.unsynced_gnss_count, 0) }}
                        </span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Heatmap Canvas Stage -->
        <section
            v-if="hasTracks"
            class="overflow-hidden rounded-xl border border-zinc-200/90 bg-white dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                ref="mapStageRef"
                class="map-fullscreen-stage relative isolate"
            >
                <!-- MapLibre GL Canvas Mount -->
                <div
                    ref="mapContainerRef"
                    class="map-fullscreen-canvas h-[calc(100vh-19rem)] max-h-[820px] min-h-[520px] w-full bg-[#f4f4f2] dark:bg-[#191a1a]"
                />

                <!-- Top-Right Map Mode, Terrain & Recenter Controls (shifts left when inspector is open on desktop) -->
                <div
                    :class="[
                        'absolute top-3 z-[500] flex flex-wrap items-center justify-end gap-1.5 transition-all',
                        isInspectorOpen
                            ? 'right-3 md:right-[364px]'
                            : 'right-3',
                    ]"
                >
                    <div
                        v-if="terrainEnabled"
                        class="inline-flex items-center rounded-lg border border-zinc-200/90 bg-white/95 p-0.5 font-mono text-[11px] shadow-xs backdrop-blur-xs dark:border-zinc-800/90 dark:bg-[#141417]/95"
                        role="group"
                        aria-label="Map view mode"
                    >
                        <button
                            type="button"
                            title="Flat 2D map"
                            class="rounded-md px-2 py-1 font-medium transition-colors"
                            :class="
                                viewMode === '2d'
                                    ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                    : 'cursor-pointer text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="selectViewMode('2d')"
                        >
                            2D
                        </button>
                        <button
                            type="button"
                            title="3D terrain view"
                            class="rounded-md px-2 py-1 font-medium transition-colors"
                            :class="
                                viewMode === '3d'
                                    ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-950'
                                    : 'cursor-pointer text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-zinc-100'
                            "
                            @click="selectViewMode('3d')"
                        >
                            3D
                        </button>
                    </div>

                    <button
                        v-if="terrainEnabled"
                        type="button"
                        :aria-pressed="showTerrain"
                        title="Toggle Mapterhorn terrain hillshading"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-[11px] font-medium shadow-xs backdrop-blur-xs transition-colors"
                        :class="
                            showTerrain
                                ? 'border-emerald-500/50 bg-emerald-50/95 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-300'
                                : 'border-zinc-200/90 bg-white/95 text-zinc-700 hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417]/95 dark:text-zinc-200 dark:hover:border-zinc-700 dark:hover:text-white'
                        "
                        @click="toggleTerrain"
                    >
                        <Mountain class="size-3.5" />
                        <span>Terrain</span>
                    </button>

                    <button
                        type="button"
                        title="Fit all routes"
                        aria-label="Fit all routes"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white/95 px-2.5 py-1.5 font-mono text-[11px] font-medium text-zinc-700 shadow-xs backdrop-blur-xs transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800/90 dark:bg-[#141417]/95 dark:text-zinc-200 dark:hover:border-zinc-700 dark:hover:text-white"
                        @click="fitAllTracks(true)"
                    >
                        <Crosshair class="size-3.5" />
                        <span>Fit All</span>
                    </button>
                </div>

                <!-- Floating Corridor Inspector Panel -->
                <aside
                    v-if="isInspectorOpen"
                    ref="inspectorRef"
                    aria-label="Overlapping activities"
                    class="border-t border-zinc-200/90 bg-white md:absolute md:top-3 md:right-3 md:bottom-3 md:z-[500] md:flex md:w-[340px] md:flex-col md:overflow-hidden md:rounded-xl md:border md:bg-white/95 md:backdrop-blur-xs dark:border-zinc-800/90 dark:bg-[#141417] md:dark:bg-[#141417]/95"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800/80"
                    >
                        <h2
                            class="text-sm font-semibold tracking-tight text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatNumber(inspectedTracks.length, 0) }}
                            Overlapping
                            {{
                                inspectedTracks.length === 1
                                    ? 'Activity'
                                    : 'Activities'
                            }}
                        </h2>

                        <button
                            type="button"
                            aria-label="Close inspector"
                            class="inline-flex size-7 cursor-pointer items-center justify-center rounded-lg border border-zinc-200/90 bg-white text-zinc-500 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-400 dark:hover:border-zinc-700 dark:hover:text-zinc-100"
                            @click="closeInspector"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>

                    <div
                        class="max-h-[340px] divide-y divide-zinc-100 overflow-y-auto md:max-h-none md:flex-1 dark:divide-zinc-800/80"
                    >
                        <Link
                            v-for="track in inspectedTracks"
                            :key="track.activity_id"
                            :href="activitiesShow(track.activity_id)"
                            class="group block px-4 py-3 transition-colors hover:bg-[#f7f7f5] dark:hover:bg-zinc-900/70"
                            @mouseenter="setHoveredTrack(track.activity_id)"
                            @mouseleave="setHoveredTrack(null)"
                        >
                            <div
                                class="flex items-start justify-between gap-2.5"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="size-2 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor:
                                                    trackColor(track),
                                            }"
                                        />
                                        <span
                                            class="truncate text-sm font-medium text-zinc-900 group-hover:text-zinc-950 dark:text-zinc-100 dark:group-hover:text-white"
                                        >
                                            {{ track.name }}
                                        </span>
                                    </div>

                                    <div
                                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 pl-4 font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                                    >
                                        <span>{{ sportLabel(track) }}</span>
                                        <span
                                            class="text-zinc-300 dark:text-zinc-700"
                                        >
                                            ·
                                        </span>
                                        <span class="tabular-nums">
                                            {{ formatTrackDate(track.date) }}
                                        </span>
                                        <template v-if="track.location_name">
                                            <span
                                                class="text-zinc-300 dark:text-zinc-700"
                                            >
                                                ·
                                            </span>
                                            <span class="truncate">
                                                {{ track.location_name }}
                                            </span>
                                        </template>
                                    </div>
                                </div>

                                <ArrowUpRight
                                    class="mt-0.5 size-3.5 shrink-0 text-zinc-300 transition-colors group-hover:text-zinc-700 dark:text-zinc-600 dark:group-hover:text-zinc-200"
                                />
                            </div>

                            <div
                                class="mt-2 flex flex-wrap items-center gap-x-3.5 gap-y-1 pl-4 font-mono text-xs text-zinc-700 tabular-nums dark:text-zinc-300"
                            >
                                <span>
                                    {{
                                        formatDistanceCompact(
                                            track.distance_meters,
                                            1,
                                        )
                                    }}
                                </span>
                                <span class="text-zinc-500 dark:text-zinc-400">
                                    {{
                                        formatDurationClock(
                                            track.duration_seconds,
                                        )
                                    }}
                                </span>
                                <span
                                    v-if="track.elevation_gain !== null"
                                    class="text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ formatElevation(track.elevation_gain) }}
                                </span>
                            </div>
                        </Link>
                    </div>
                </aside>
            </div>
        </section>

        <!-- Empty State (matches map canvas height so toggling empty windows never jumps the page) -->
        <section
            v-else
            class="flex h-[calc(100vh-19rem)] max-h-[820px] min-h-[520px] flex-col items-center justify-center rounded-xl border border-zinc-200/90 bg-white px-6 text-center dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <div
                class="flex size-11 items-center justify-center rounded-xl border border-zinc-200/90 bg-[#f7f7f5] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-400"
            >
                <MapIcon class="size-5" />
            </div>

            <h2
                class="mt-4 text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
            >
                No routes to display
            </h2>

            <p class="mt-1.5 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                <template v-if="hasActiveFilters">
                    No routes match these filters.
                </template>
                <template v-else-if="summary.unsynced_gnss_count > 0">
                    You have
                    <span class="font-mono font-medium tabular-nums">
                        {{ formatNumber(summary.unsynced_gnss_count, 0) }}
                    </span>
                    outdoor
                    {{
                        summary.unsynced_gnss_count === 1
                            ? 'activity'
                            : 'activities'
                    }}
                    waiting for route data sync.
                </template>
                <template v-else>
                    Sync outdoor activities with route data to see your routes
                    here.
                </template>
            </p>

            <div
                class="mt-5 flex flex-wrap items-center justify-center gap-2.5"
            >
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-900 bg-zinc-900 px-3.5 py-2 font-mono text-xs font-medium text-white transition-colors hover:bg-zinc-800 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                    @click="resetFilters"
                >
                    <RotateCcw class="size-3.5" />
                    <span>Show All Tracks</span>
                </button>

                <button
                    v-else-if="
                        summary.unsynced_gnss_count > 0 && providerConfigured
                    "
                    type="button"
                    :disabled="isQueueingSync"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-900 bg-zinc-900 px-3.5 py-2 font-mono text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-zinc-200"
                    @click="queueGnssSync"
                >
                    <RefreshCw
                        :class="[
                            'size-3.5',
                            isQueueingSync ? 'animate-spin' : '',
                        ]"
                    />
                    <span>
                        {{
                            isQueueingSync
                                ? 'Syncing Routes...'
                                : 'Sync Route Data'
                        }}
                    </span>
                </button>

                <Link
                    :href="activitiesIndex()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 font-mono text-xs font-medium text-zinc-700 transition-colors hover:border-zinc-300 hover:text-zinc-950 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-200 dark:hover:border-zinc-700 dark:hover:text-white"
                >
                    <span>Browse Activities</span>
                    <ArrowUpRight class="size-3.5" />
                </Link>
            </div>
        </section>
    </div>
</template>
