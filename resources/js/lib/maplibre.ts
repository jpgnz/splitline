import type { FeatureCollection } from 'geojson';
import {
    setWorkerUrl,
    type Map as MapLibreMap,
    type SkySpecification,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

let workerConfigured = false;

export const MAP_STYLE_URLS = {
    light: 'https://tiles.openfreemap.org/styles/positron',
    dark: 'https://tiles.openfreemap.org/styles/dark',
} as const;

export const MAPTERHORN_TILEJSON_URL =
    'https://tiles.mapterhorn.com/tilejson.json';
export const MAPTERHORN_HILLSHADE_SOURCE_ID = 'mapterhorn-hillshade-dem';
export const MAPTERHORN_TERRAIN_SOURCE_ID = 'mapterhorn-terrain-dem';
export const MAPTERHORN_HILLSHADE_LAYER_ID = 'mapterhorn-hillshade';
export const DEFAULT_TERRAIN_EXAGGERATION = 1.25;

export interface MapterhornTerrainOptions {
    hillshade: boolean;
    terrain3d: boolean;
    isDark: boolean;
    exaggeration?: number;
}

export function ensureMapLibreWorker(): void {
    if (workerConfigured || typeof window === 'undefined') {
        return;
    }

    setWorkerUrl(workerUrl);
    workerConfigured = true;
}

export function getMapStyleUrl(isDark: boolean): string {
    return isDark ? MAP_STYLE_URLS.dark : MAP_STYLE_URLS.light;
}

export function toLngLat(point: [number, number]): [number, number] {
    return [point[1], point[0]];
}

export function emptyFeatureCollection(): FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: [],
    };
}

function findHillshadeBeforeLayerId(map: MapLibreMap): string | undefined {
    const layers = map.getStyle()?.layers ?? [];

    const waterway = layers.find((layer) => layer.id === 'waterway');

    if (waterway) {
        return waterway.id;
    }

    const firstOverlay = layers.find(
        (layer) => layer.type === 'line' || layer.type === 'symbol',
    );

    return firstOverlay?.id;
}

function buildSkySpecification(isDark: boolean): SkySpecification {
    if (isDark) {
        return {
            'sky-color': '#09090b',
            'sky-horizon-blend': 0.5,
            'horizon-color': '#18181b',
            'horizon-fog-blend': 0.5,
            'fog-color': '#18181b',
            'fog-ground-blend': 0.5,
        };
    }

    return {
        'sky-color': '#e2e8f0',
        'sky-horizon-blend': 0.5,
        'horizon-color': '#f4f4f2',
        'horizon-fog-blend': 0.5,
        'fog-color': '#f4f4f2',
        'fog-ground-blend': 0.5,
    };
}

export function syncMapterhornTerrain(
    map: MapLibreMap,
    options: MapterhornTerrainOptions,
): void {
    const {
        hillshade,
        terrain3d,
        isDark,
        exaggeration = DEFAULT_TERRAIN_EXAGGERATION,
    } = options;

    if (hillshade) {
        if (!map.getSource(MAPTERHORN_HILLSHADE_SOURCE_ID)) {
            map.addSource(MAPTERHORN_HILLSHADE_SOURCE_ID, {
                type: 'raster-dem',
                url: MAPTERHORN_TILEJSON_URL,
            });
        }

        const hillshadeExaggeration = isDark
            ? terrain3d
                ? 0.5
                : 0.42
            : terrain3d
              ? 0.44
              : 0.36;
        const shadowColor = isDark
            ? 'rgba(0, 0, 0, 0.88)'
            : 'rgba(42, 50, 62, 0.58)';
        const highlightColor = isDark
            ? 'rgba(186, 200, 220, 0.28)'
            : 'rgba(255, 255, 255, 0.72)';

        if (!map.getLayer(MAPTERHORN_HILLSHADE_LAYER_ID)) {
            map.addLayer(
                {
                    id: MAPTERHORN_HILLSHADE_LAYER_ID,
                    type: 'hillshade',
                    source: MAPTERHORN_HILLSHADE_SOURCE_ID,
                    layout: {
                        visibility: 'visible',
                    },
                    paint: {
                        'hillshade-method': 'igor',
                        'hillshade-exaggeration': hillshadeExaggeration,
                        'hillshade-shadow-color': shadowColor,
                        'hillshade-highlight-color': highlightColor,
                    },
                },
                findHillshadeBeforeLayerId(map),
            );
        } else {
            map.setLayoutProperty(
                MAPTERHORN_HILLSHADE_LAYER_ID,
                'visibility',
                'visible',
            );
            map.setPaintProperty(
                MAPTERHORN_HILLSHADE_LAYER_ID,
                'hillshade-exaggeration',
                hillshadeExaggeration,
            );
            map.setPaintProperty(
                MAPTERHORN_HILLSHADE_LAYER_ID,
                'hillshade-shadow-color',
                shadowColor,
            );
            map.setPaintProperty(
                MAPTERHORN_HILLSHADE_LAYER_ID,
                'hillshade-highlight-color',
                highlightColor,
            );
        }
    } else if (map.getLayer(MAPTERHORN_HILLSHADE_LAYER_ID)) {
        map.setLayoutProperty(
            MAPTERHORN_HILLSHADE_LAYER_ID,
            'visibility',
            'none',
        );
    }

    if (terrain3d) {
        if (!map.getSource(MAPTERHORN_TERRAIN_SOURCE_ID)) {
            map.addSource(MAPTERHORN_TERRAIN_SOURCE_ID, {
                type: 'raster-dem',
                url: MAPTERHORN_TILEJSON_URL,
            });
        }

        map.setTerrain({
            source: MAPTERHORN_TERRAIN_SOURCE_ID,
            exaggeration,
        });
        map.setSky(buildSkySpecification(isDark));
    } else {
        if (map.getTerrain()) {
            map.setTerrain(null);
        }

        map.setSky(buildSkySpecification(isDark));
    }
}
