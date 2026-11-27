export interface StreamPoint {
    time: number;
    distance: number | null;
    altitude: number | null;
    speed: number | null;
    hr: number | null;
    cadence: number | null;
    power: number | null;
    grade: number | null;
    lat: number | null;
    lng: number | null;
    gap_speed?: number | null;
    stamina?: number | null;
    stamina_potential?: number | null;
    performance_condition?: number | null;
    stride_length?: number | null;
    vertical_oscillation?: number | null;
    vertical_ratio?: number | null;
    gct?: number | null;
    respiration?: number | null;
    temperature?: number | null;
    left_balance?: number | null;
    left_te?: number | null;
    right_te?: number | null;
    left_ps?: number | null;
    right_ps?: number | null;
    grit?: number | null;
    flow?: number | null;
}

export type MapColorMode =
    | 'solid'
    | 'speed'
    | 'hr'
    | 'altitude'
    | 'power'
    | 'stamina'
    | 'grit'
    | 'flow'
    | 'wind';

export interface HighlightRange {
    startDistance: number;
    endDistance: number;
    label: string;
}

export interface RunWalkSegment {
    type: 'run' | 'walk' | 'idle' | (string & {});
    start_time: number;
    end_time: number;
    duration_seconds: number;
    distance_meters: number;
}

export interface JumpEvent {
    time: number;
    distance_meters: number;
    hang_time_seconds: number;
    score: number;
    speed: number | null;
    lat: number | null;
    lng: number | null;
}

export interface KmSplitItem {
    km: number;
    distance_meters: number;
    duration_seconds: number;
    avg_speed: number;
    pace_seconds_per_km: number;
    gap_seconds_per_km: number | null;
    avg_hr: number | null;
    avg_cadence: number | null;
    avg_power: number | null;
    elevation_gain: number;
    elevation_loss: number;
    avg_grade: number;
}

export interface SplitItem {
    index: number;
    label: string;
    distance_meters: number;
    duration_seconds: number;
    avg_speed: number;
    pace_seconds: number;
    gap_seconds: number | null;
    avg_hr: number | null;
    avg_cadence: number | null;
    avg_power: number | null;
    elevation_gain: number;
    elevation_loss: number;
    avg_grade: number;
}

export interface ActivityWeatherSample {
    time_offset_s: number;
    distance_m: number | null;
    lat: number;
    lng: number;
    wmo_code: number | null;
    temperature_c: number | null;
    apparent_temperature_c: number | null;
    humidity_pct: number | null;
    dew_point_c: number | null;
    wind_speed_kmh: number | null;
    wind_gusts_kmh: number | null;
    wind_direction_deg: number | null;
    wind_direction_compass: string | null;
    precipitation_mm: number | null;
    cloud_cover_pct: number | null;
    surface_pressure_hpa: number | null;
}

export interface ActivityWeatherPayload {
    provider: string;
    model_endpoint:
        | 'forecast'
        | 'historical_forecast'
        | 'archive'
        | (string & {});
    sampling_mode: 'route' | 'start_point' | (string & {});
    waypoint_count: number;
    wmo_code: number | null;
    condition: string;
    base_condition: string | null;
    icon: string;
    temperature_c: number | null;
    min_temperature_c: number | null;
    max_temperature_c: number | null;
    apparent_temperature_c: number | null;
    humidity_pct: number | null;
    dew_point_c: number | null;
    wind_speed_kmh: number | null;
    wind_gusts_kmh: number | null;
    wind_direction_deg: number | null;
    wind_direction_compass: string | null;
    precipitation_mm: number | null;
    cloud_cover_pct: number | null;
    surface_pressure_hpa: number | null;
    headwind_pct: number | null;
    crosswind_pct: number | null;
    tailwind_pct: number | null;
    avg_effective_wind_kmh: number | null;
    samples: ActivityWeatherSample[];
    fetched_at: string | null;
}

export interface WorkoutMeta {
    name?: string;
    target_label?: string;
    duration_seconds?: number;
    target_hr_low?: number;
    target_hr_high?: number;
}

export interface ActivityDetail {
    id: number;
    provider: string;
    external_id: string | null;
    garmin_activity_id: number | null;
    name: string;
    activity_type_key: string;
    sport_category: string;
    sport_discipline: string | null;
    biking_discipline?: string | null;
    discipline_override: string | null;
    effective_discipline: string | null;
    is_indoor: boolean;
    started_at_local: string;
    location_name: string | null;
    start_lat: number | null;
    start_lng: number | null;
    distance_meters: number;
    duration_seconds: number;
    moving_duration_seconds: number | null;
    elapsed_duration_seconds: number | null;
    elevation_gain: number | null;
    elevation_loss: number | null;
    min_elevation: number | null;
    max_elevation: number | null;
    avg_speed: number | null;
    max_speed: number | null;
    avg_grade_adjusted_speed: number | null;
    calories: number | null;
    bmr_calories: number | null;
    sweat_loss_ml: number | null;
    avg_hr: number | null;
    max_hr: number | null;
    hr_max_configured: number | null;
    avg_cadence: number | null;
    max_cadence: number | null;
    avg_stride_length: number | null;
    avg_vertical_oscillation: number | null;
    avg_vertical_ratio: number | null;
    avg_ground_contact_time: number | null;
    steps: number | null;
    aerobic_te: number | null;
    anaerobic_te: number | null;
    training_load: number | null;
    training_effect_label: string | null;
    aerobic_te_message: string | null;
    anaerobic_te_message: string | null;
    body_battery_diff: number | null;
    begin_body_battery: number | null;
    end_body_battery: number | null;
    avg_power: number | null;
    max_power: number | null;
    normalized_power: number | null;
    ftp: number | null;
    threshold_power: number | null;
    tss: number | null;
    intensity_factor: number | null;
    total_work: number | null;
    left_balance: number | null;
    avg_left_torque_effectiveness: number | null;
    avg_right_torque_effectiveness: number | null;
    avg_left_pedal_smoothness: number | null;
    avg_right_pedal_smoothness: number | null;
    max_20min_power: number | null;
    power_curve: Record<string, number> | null;
    wind_enabled: boolean | null;
    grit: number | null;
    avg_flow: number | null;
    jump_count: number | null;
    unpaved_pct: number | null;
    workout_feel: number | null;
    workout_rpe: number | null;
    execution_score: number | null;
    workout_meta: WorkoutMeta | null;
    begin_stamina: number | null;
    end_stamina: number | null;
    min_stamina: number | null;
    avg_respiration_rate: number | null;
    min_respiration_rate: number | null;
    max_respiration_rate: number | null;
    avg_temperature: number | null;
    min_temperature: number | null;
    max_temperature: number | null;
    moderate_intensity_minutes: number | null;
    vigorous_intensity_minutes: number | null;
    run_duration_seconds: number | null;
    walk_duration_seconds: number | null;
    idle_duration_seconds: number | null;
    is_favorite: boolean;
    is_pr: boolean;
    is_manual: boolean;
    streams_synced_at: string | null;
}

export interface HrZoneItem {
    zone: number;
    label: string;
    min_hr: number | null;
    max_hr: number | null;
    seconds: number;
    pct: number;
}

export interface PowerZoneItem {
    zone: number;
    label: string;
    min_watts: number | null;
    max_watts: number | null;
    seconds: number;
    pct: number;
}

export interface PaceZoneBucket {
    zone: number;
    label: string;
    min_pace_sec: number | null;
    max_pace_sec: number | null;
    seconds: number;
    pct: number;
}

export interface PaceDistributionPayload {
    anchor_label: string;
    anchor_5k_seconds: number;
    anchor_source: string;
    anchor_date: string | null;
    gap_zones: PaceZoneBucket[];
    pace_zones: PaceZoneBucket[];
}

export interface EffortSummaryBlock {
    activity_score: number | null;
    rating: string | null;
    summary: string;
    week_total: number;
    week_status: string;
    week_status_detail: string;
    typical_low: number;
    typical_high: number;
}

export interface DailyEffortBar {
    date: string;
    day_label: string;
    trimp: number;
    load: number;
    is_activity_day: boolean;
}

export interface WeeklyEffortTrendPoint {
    week_start: string;
    label: string;
    trimp: number;
    load: number;
    trimp_band_low: number;
    trimp_band_high: number;
    load_band_low: number;
    load_band_high: number;
    is_current_week: boolean;
}

export interface WeekActivityEffortRow {
    id: number;
    name: string;
    sport_category: string;
    effective_discipline: string | null;
    started_at_local: string;
    duration_seconds: number;
    trimp: number | null;
    load: number | null;
    is_current: boolean;
}

export interface EffortContextPayload {
    week_start: string;
    week_end: string;
    week_label: string;
    trimp: EffortSummaryBlock;
    garmin_load: EffortSummaryBlock;
    daily_bars: DailyEffortBar[];
    twelve_week_trend: WeeklyEffortTrendPoint[];
    week_activities: WeekActivityEffortRow[];
}

export interface ActivityStreamPayload {
    series: StreamPoint[];
    polyline: [number, number][];
    laps: Array<Record<string, number | null>>;
    km_splits: KmSplitItem[];
    run_walk_segments: RunWalkSegment[];
    jumps: JumpEvent[];
}

export interface ActivityBestEffortItem {
    id: number;
    sport: string;
    distance_key: string;
    label: string;
    distance_meters: number;
    elapsed_seconds: number;
    effective_seconds: number;
    moving_seconds: number | null;
    start_offset_seconds: number | null;
    avg_hr: number | null;
    elevation_delta: number | null;
    start_distance_meters: number | null;
    end_distance_meters: number | null;
    all_time_rank: number | null;
    source: string;
    override_seconds: number | null;
    is_excluded: boolean;
}

export type DecouplingMetric = 'power' | 'gap' | 'speed';

export type DecouplingRating = 'well_coupled' | 'moderate_drift' | 'high_drift';

export type PacingStrategy = 'negative_split' | 'even_split' | 'positive_split';

export interface ActivityDerivedMetricsPayload {
    decoupling_pct: number | null;
    decoupling_metric: DecouplingMetric | null;
    decoupling_rating: DecouplingRating | null;
    efficiency_factor: number | null;
    first_half_ef: number | null;
    second_half_ef: number | null;
    pacing_strategy: PacingStrategy | null;
    split_ratio: number | null;
    pace_cv_pct: number | null;
    hr_low_pct: number | null;
    hr_moderate_pct: number | null;
    hr_high_pct: number | null;
    computed_at: string;
}
