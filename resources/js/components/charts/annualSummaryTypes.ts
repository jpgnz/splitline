import { formatDurationHuman, formatNumber } from '@/lib/format';
import type { UnitSystem } from '@/lib/units';
import {
    distanceFromMeters,
    distanceUnit,
    elevationFromMeters,
    elevationUnit,
} from '@/lib/units';

export type AnnualSummaryMetricKey =
    | 'distance'
    | 'elevation'
    | 'time'
    | 'activities'
    | 'calories'
    | 'load';

export type AnnualSummaryMode = 'sport' | 'health';

export interface AnnualSummaryDayActivity {
    id: number;
    name: string;
    sport_category: string;
    effective_discipline: string | null;
    distance_meters: number;
    duration_seconds: number;
}

export interface AnnualSummaryDailyEntry {
    date: string;
    activities: number;
    distance_meters: number;
    elevation_gain: number;
    duration_seconds: number;
    calories: number;
    training_load: number;
    items?: AnnualSummaryDayActivity[];
}

export interface AnnualActivitySummaryPayload {
    today: string;
    years: number[];
    daily: AnnualSummaryDailyEntry[];
}

export interface AnnualSummaryMetricOption {
    key: AnnualSummaryMetricKey;
    label: string;
}

export const SPORT_METRIC_OPTIONS: AnnualSummaryMetricOption[] = [
    { key: 'distance', label: 'Distance' },
    { key: 'elevation', label: 'Elevation' },
    { key: 'time', label: 'Time' },
    { key: 'activities', label: 'Activities' },
    { key: 'calories', label: 'Calories' },
    { key: 'load', label: 'Training Load' },
];

export const HEALTH_METRIC_OPTIONS: AnnualSummaryMetricOption[] = [
    { key: 'time', label: 'Time' },
    { key: 'calories', label: 'Calories Burnt' },
];

export const YEAR_SERIES_PALETTE = [
    '#2563eb', // blue (newest / current year)
    '#60a5fa', // sky blue
    '#f97316', // orange
    '#eab308', // gold
    '#10b981', // emerald
    '#8b5cf6', // violet
    '#f43f5e', // rose
    '#14b8a6', // teal
    '#ec4899', // pink
    '#6366f1', // indigo
];

export const YEAR_SERIES_PALETTE_DARK = [
    '#3b82f6', // brighter blue on dark cards
    '#7dd3fc', // light sky
    '#fb923c', // warm orange
    '#facc15', // bright gold
    '#34d399', // emerald
    '#a78bfa', // violet
    '#fb7185', // rose
    '#2dd4bf', // teal
    '#f472b6', // pink
    '#818cf8', // indigo
];

export const MONTH_SHORT_NAMES = [
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
] as const;

const REF_MONTH_DAYS = [
    31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31,
] as const;

const REF_MONTH_OFFSETS: number[] = (() => {
    const offsets: number[] = [];
    let running = 0;

    for (const days of REF_MONTH_DAYS) {
        offsets.push(running);
        running += days;
    }

    return offsets;
})();

export const REF_YEAR_LENGTH = 366;

export const REF_DAY_LABELS: string[] = (() => {
    const labels: string[] = [];

    for (let m = 0; m < 12; m++) {
        for (let d = 1; d <= REF_MONTH_DAYS[m]; d++) {
            labels.push(
                `${String(d).padStart(2, '0')} ${MONTH_SHORT_NAMES[m]}`,
            );
        }
    }

    return labels;
})();

export function parseIsoDateParts(iso: string): {
    year: number;
    month: number;
    day: number;
} | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso.trim());

    if (!match) {
        return null;
    }

    return {
        year: Number(match[1]),
        month: Number(match[2]),
        day: Number(match[3]),
    };
}

export function monthDayToRefIndex(month: number, day: number): number {
    const monthOffset = REF_MONTH_OFFSETS[month - 1] ?? 0;

    return Math.min(REF_YEAR_LENGTH - 1, Math.max(0, monthOffset + (day - 1)));
}

export function isLeapYear(year: number): boolean {
    return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

export function metricOptionsForMode(
    mode: AnnualSummaryMode,
): AnnualSummaryMetricOption[] {
    return mode === 'health' ? HEALTH_METRIC_OPTIONS : SPORT_METRIC_OPTIONS;
}

export function extractMetricValue(
    entry: AnnualSummaryDailyEntry,
    metric: AnnualSummaryMetricKey,
    units: UnitSystem,
): number {
    switch (metric) {
        case 'distance':
            return distanceFromMeters(entry.distance_meters, units);
        case 'elevation':
            return elevationFromMeters(entry.elevation_gain, units);
        case 'time':
            return entry.duration_seconds / 3600;
        case 'activities':
            return entry.activities;
        case 'calories':
            return entry.calories;
        case 'load':
            return entry.training_load;
    }
}

export function formatMetricTotal(
    value: number,
    metric: AnnualSummaryMetricKey,
    units: UnitSystem,
): string {
    if (!Number.isFinite(value)) {
        return '—';
    }

    switch (metric) {
        case 'distance':
            return `${formatNumber(value, value >= 100 ? 0 : 1)} ${distanceUnit(units)}`;
        case 'elevation':
            return `${formatNumber(Math.round(value), 0)} ${elevationUnit(units)}`;
        case 'time':
            return formatDurationHuman(Math.round(value * 3600));
        case 'activities':
            return `${formatNumber(Math.round(value), 0)}`;
        case 'calories':
            return `${formatNumber(Math.round(value), 0)} kcal`;
        case 'load':
            return `${formatNumber(Math.round(value), 0)} load`;
    }
}

export function formatMetricDaily(
    value: number,
    metric: AnnualSummaryMetricKey,
    units: UnitSystem,
): string {
    if (!Number.isFinite(value) || value <= 0) {
        return formatZeroMetric(metric, units);
    }

    switch (metric) {
        case 'distance':
            return `${formatNumber(value, value >= 100 ? 0 : 1)} ${distanceUnit(units)}`;
        case 'elevation':
            return `${formatNumber(Math.round(value), 0)} ${elevationUnit(units)}`;
        case 'time':
            return formatDurationHuman(Math.round(value * 3600));
        case 'activities': {
            const count = Math.round(value);

            return `${formatNumber(count, 0)} ${count === 1 ? 'activity' : 'activities'}`;
        }
        case 'calories':
            return `${formatNumber(Math.round(value), 0)} kcal`;
        case 'load':
            return `${formatNumber(Math.round(value), 0)} load`;
    }
}

export function formatMetricAxisTick(
    value: number,
    metric: AnnualSummaryMetricKey,
    units: UnitSystem,
): string {
    if (!Number.isFinite(value)) {
        return '';
    }

    switch (metric) {
        case 'distance':
            return `${formatNumber(Math.round(value), 0)} ${distanceUnit(units)}`;
        case 'elevation':
            return `${formatNumber(Math.round(value), 0)} ${elevationUnit(units)}`;
        case 'time':
            return `${formatNumber(Math.round(value), 0)}h`;
        case 'activities':
            return `${formatNumber(Math.round(value), 0)}`;
        case 'calories':
            if (value >= 1000) {
                return `${formatNumber(Math.round(value / 1000), 0)}k kcal`;
            }

            return `${formatNumber(Math.round(value), 0)} kcal`;
        case 'load':
            return `${formatNumber(Math.round(value), 0)}`;
    }
}

export function formatZeroMetric(
    metric: AnnualSummaryMetricKey,
    units: UnitSystem,
): string {
    switch (metric) {
        case 'distance':
            return `0 ${distanceUnit(units)}`;
        case 'elevation':
            return `0 ${elevationUnit(units)}`;
        case 'time':
            return '0m';
        case 'activities':
            return '0';
        case 'calories':
            return '0 kcal';
        case 'load':
            return '0 load';
    }
}

export function getYearColor(index: number, isDark: boolean): string {
    const palette = isDark ? YEAR_SERIES_PALETTE_DARK : YEAR_SERIES_PALETTE;

    return palette[index % palette.length];
}
