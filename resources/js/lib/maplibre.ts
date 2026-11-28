import type { FeatureCollection } from 'geojson';
import {
    setWorkerUrl,
    type Map as MapLibreMap,
    type SkySpecification,
    type StyleSpecification,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import type { SharedMapsConfig } from '@/types/global';

let workerConfigured = false;

export const MAPTERHORN_HILLSHADE_SOURCE_ID = 'mapterhorn-hillshade-dem';
export const MAPTERHORN_TERRAIN_SOURCE_ID = 'mapterhorn-terrain-dem';
export const MAPTERHORN_HILLSHADE_LAYER_ID = 'mapterhorn-hillshade';
export const DEFAULT_TERRAIN_EXAGGERATION = 1.25;

export interface MapterhornTerrainOptions {
    hillshade: boolean;
    terrain3d: boolean;
    isDark: boolean;
    exaggeration?: number;
    enabled?: boolean;
    tilejsonUrl?: string | null;
}

export function ensureMapLibreWorker(): void {
    if (workerConfigured || typeof window === 'undefined') {
        return;
    }

    setWorkerUrl(workerUrl);
    workerConfigured = true;
}

export function resolveMapConfig(
    shared?: SharedMapsConfig | null,
): SharedMapsConfig {
    if (!shared) {
        return {
            tiles_enabled: false,
            terrain_enabled: false,
            style_light_url: null,
            style_dark_url: null,
            terrain_tilejson_url: null,
        };
    }

    return shared;
}

export function buildBlankMapStyle(isDark: boolean): StyleSpecification {
    return {
        version: 8,
        sources: {},
        layers: [
            {
                id: 'background',
                type: 'background',
                paint: {
                    'background-color': isDark ? '#191a1a' : '#f4f4f2',
                },
            },
        ],
    };
}

export function getMapStyle(
    isDark: boolean,
    config?: SharedMapsConfig | null,
): string | StyleSpecification {
    const resolved = resolveMapConfig(config);

    if (!resolved.tiles_enabled) {
        return buildBlankMapStyle(isDark);
    }

    const url = isDark ? resolved.style_dark_url : resolved.style_light_url;

    if (!url) {
        return buildBlankMapStyle(isDark);
    }

    return url;
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
        enabled = true,
        tilejsonUrl = null,
    } = options;

    const terrainAvailable = enabled && Boolean(tilejsonUrl);
    const effectiveHillshade = terrainAvailable && hillshade;
    const effectiveTerrain3d = terrainAvailable && terrain3d;

    if (effectiveHillshade && tilejsonUrl) {
        if (!map.getSource(MAPTERHORN_HILLSHADE_SOURCE_ID)) {
            map.addSource(MAPTERHORN_HILLSHADE_SOURCE_ID, {
                type: 'raster-dem',
                url: tilejsonUrl,
            });
        }

        const hillshadeExaggeration = isDark
            ? effectiveTerrain3d
                ? 0.5
                : 0.42
            : effectiveTerrain3d
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

    if (effectiveTerrain3d && tilejsonUrl) {
        if (!map.getSource(MAPTERHORN_TERRAIN_SOURCE_ID)) {
            map.addSource(MAPTERHORN_TERRAIN_SOURCE_ID, {
                type: 'raster-dem',
                url: tilejsonUrl,
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
