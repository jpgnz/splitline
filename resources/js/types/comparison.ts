export type ComparisonAlignMode = 'distance' | 'time';

export interface ComparisonWeather {
    condition: string;
    icon: string;
    temperature_c: number | null;
    apparent_temperature_c: number | null;
    humidity_pct: number | null;
    wind_speed_kmh: number | null;
    headwind_pct: number | null;
    tailwind_pct: number | null;
    avg_effective_wind_kmh: number | null;
}

export interface ComparisonDeltas {
    duration_delta_s: number | null;
    moving_duration_delta_s: number | null;
    distance_delta_m: number | null;
    elevation_gain_delta_m: number | null;
    speed_delta: number | null;
    gap_speed_delta: number | null;
    hr_delta: number | null;
    max_hr_delta: number | null;
    power_delta: number | null;
    normalized_power_delta: number | null;
    cadence_delta: number | null;
    training_load_delta: number | null;
    tss_delta: number | null;
    calories_delta: number | null;
    temp_delta_c: number | null;
    wind_delta_kmh: number | null;
    effective_wind_delta_kmh: number | null;
}

export interface ComparisonActivity {
    id: number;
    name: string;
    started_at_local: string;
    sport_category: string;
    effective_discipline: string | null;
    is_indoor: boolean;
    location_name: string | null;
    distance_meters: number;
    duration_seconds: number;
    moving_duration_seconds: number | null;
    elevation_gain: number | null;
    avg_speed: number | null;
    avg_grade_adjusted_speed: number | null;
    avg_hr: number | null;
    max_hr: number | null;
    avg_power: number | null;
    normalized_power: number | null;
    avg_cadence: number | null;
    training_load: number | null;
    tss: number | null;
    calories: number | null;
    has_streams: boolean;
    is_baseline: boolean;
    weather: ComparisonWeather | null;
    deltas: ComparisonDeltas | null;
}

export interface ComparisonCandidate {
    id: number;
    name: string;
    started_at_local: string;
    sport_category: string;
    effective_discipline: string | null;
    is_indoor: boolean;
    location_name: string | null;
    distance_meters: number;
    duration_seconds: number;
    avg_speed: number | null;
    avg_hr: number | null;
    has_streams: boolean;
}

export interface AlignedSeriesSummary {
    final_time_delta_seconds: number | null;
    max_ahead_seconds: number | null;
    max_behind_seconds: number | null;
    final_distance_delta_meters: number | null;
}

export interface AlignedTelemetrySeries {
    activity_id: number;
    is_baseline: boolean;
    has_stream: boolean;
    elapsed_seconds: Array<number | null>;
    distance_meters: Array<number | null>;
    time_delta_seconds: Array<number | null>;
    distance_delta_meters: Array<number | null>;
    speed: Array<number | null>;
    gap_speed: Array<number | null>;
    hr: Array<number | null>;
    power: Array<number | null>;
    altitude: Array<number | null>;
    cadence: Array<number | null>;
    summary: AlignedSeriesSummary;
}

export interface AlignedTelemetryPayload {
    mode: ComparisonAlignMode;
    can_align_distance: boolean;
    axis_max: number;
    bins: number[];
    has_speed: boolean;
    has_gap: boolean;
    has_hr: boolean;
    has_power: boolean;
    has_altitude: boolean;
    has_cadence: boolean;
    series: AlignedTelemetrySeries[];
}

export interface AlignedSplitEntry {
    activity_id: number;
    distance_meters: number | null;
    duration_seconds: number | null;
    avg_speed: number | null;
    pace_seconds_per_km: number | null;
    gap_seconds_per_km: number | null;
    avg_hr: number | null;
    avg_power: number | null;
    elevation_gain: number | null;
    elevation_loss: number | null;
    elevation_delta: number | null;
    time_delta_seconds: number | null;
    hr_delta: number | null;
    is_partial: boolean;
}

export interface AlignedSplitRow {
    split_index: number;
    label: string;
    entries: AlignedSplitEntry[];
}

export interface ActivityCompareProps {
    align: ComparisonAlignMode;
    requested_align: ComparisonAlignMode;
    fallback_reason: string | null;
    selected_ids: number[];
    activities: ComparisonActivity[];
    candidates: ComparisonCandidate[];
    aligned: AlignedTelemetryPayload;
    splits: AlignedSplitRow[];
}
