import type { FeatureCollection } from 'geojson';
import { setWorkerUrl } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';

let workerConfigured = false;

export const MAP_STYLE_URLS = {
    light: 'https://tiles.openfreemap.org/styles/positron',
    dark: 'https://tiles.openfreemap.org/styles/dark',
} as const;

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
