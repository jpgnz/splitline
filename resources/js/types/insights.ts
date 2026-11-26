import type { DateWindowMeta } from '@/components/DateRangeControl.vue';

export interface InsightsAvailableSport {
    key: string;
    label: string;
    short_label: string;
    accent_hex: string;
}

export interface CorrelationStat {
    r: number | null;
    n: number;
    strength: 'strong' | 'moderate' | 'weak' | 'negligible' | null;
}

export interface PerformanceBucket {
    key: string;
    label: string;
    count: number;
    avg_ef: number | null;
    avg_decoupling: number | null;
    avg_hr: number | null;
    avg_speed: number | null;
}

export interface HrvBucket {
    key: string;
    label: string;
    count: number;
    avg_ef: number | null;
    avg_decoupling: number | null;
    avg_hr: number | null;
    avg_hrv: number | null;
    avg_rhr: number | null;
}

export interface TempBucket {
    key: string;
    label: string;
    min_c: number | null;
    max_c: number | null;
    count: number;
    avg_ef: number | null;
    avg_decoupling: number | null;
    avg_speed: number | null;
    avg_hr: number | null;
}

export interface WindBucket {
    key: string;
    label: string;
    count: number;
    avg_ef: number | null;
    avg_decoupling: number | null;
    avg_speed: number | null;
    avg_hr: number | null;
    avg_effective_wind_kmh: number | null;
}

export interface RecoveryBucket {
    key: string;
    label: string;
    count: number;
    avg_sleep_score: number | null;
    avg_deep_minutes: number | null;
    avg_hrv: number | null;
    avg_hrv_delta: number | null;
}

export interface RecoveryMatrixCell {
    time_key: string;
    time_label: string;
    intensity_key: string;
    intensity_label: string;
    count: number;
    avg_sleep_score: number | null;
    avg_deep_minutes: number | null;
    avg_hrv: number | null;
    avg_hrv_delta: number | null;
}

export interface ScheduleBucket {
    key: string;
    label: string;
    count: number;
    total_distance_meters: number;
    avg_ef: number | null;
    avg_decoupling: number | null;
    avg_hr: number | null;
    avg_speed: number | null;
}

export interface SleepScatterPoint {
    date: string;
    activity_id: number;
    activity_name: string;
    sleep_hours: number | null;
    sleep_score: number | null;
    efficiency_factor: number | null;
    decoupling_pct: number | null;
    avg_hr: number | null;
}

export interface HrvScatterPoint {
    date: string;
    activity_id: number;
    activity_name: string;
    hrv: number | null;
    rhr: number | null;
    body_battery_high: number | null;
    status: string | null;
    baseline_zone: string | null;
    efficiency_factor: number | null;
    decoupling_pct: number | null;
    avg_hr: number | null;
}

export interface WeatherScatterPoint {
    date: string;
    activity_id: number;
    activity_name: string;
    temperature_c: number;
    dew_point_c: number | null;
    apparent_temperature_c: number | null;
    efficiency_factor: number | null;
    decoupling_pct: number | null;
    avg_speed: number | null;
    avg_hr: number | null;
}

export interface HeadlineBucket {
    key: string;
    label: string;
    avg_ef: number | null;
    avg_decoupling: number | null;
    count: number;
}

export interface HeadlineTempBucket extends HeadlineBucket {
    min_c: number | null;
    max_c: number | null;
}

export interface CorrelationSummary {
    total_activities: number;
    with_derived_metrics: number;
    with_sleep: number;
    with_hrv: number;
    with_weather: number;
    best_sleep_bucket: HeadlineBucket | null;
    optimal_temp_bucket: HeadlineTempBucket | null;
    peak_time_of_day: HeadlineBucket | null;
}

export interface SleepVsPerformanceAnalysis {
    n: number;
    duration_buckets: PerformanceBucket[];
    score_buckets: PerformanceBucket[];
    scatter: SleepScatterPoint[];
    correlations: {
        score_vs_ef: CorrelationStat;
        hours_vs_decoupling: CorrelationStat;
        hours_vs_ef: CorrelationStat;
    };
    takeaway: string | null;
}

export interface HrvVsPerformanceAnalysis {
    n: number;
    status_buckets: HrvBucket[];
    baseline_buckets: HrvBucket[];
    scatter: HrvScatterPoint[];
    correlations: {
        hrv_vs_ef: CorrelationStat;
        hrv_vs_decoupling: CorrelationStat;
        rhr_vs_ef: CorrelationStat;
    };
    takeaway: string | null;
}

export interface WeatherImpactAnalysis {
    n: number;
    wind_n: number;
    temperature_buckets: TempBucket[];
    wind_buckets: WindBucket[];
    scatter: WeatherScatterPoint[];
    correlations: {
        temp_vs_ef: CorrelationStat;
        temp_vs_decoupling: CorrelationStat;
    };
    takeaway: string | null;
}

export interface WorkoutToRecoveryAnalysis {
    n: number;
    by_time_of_day: RecoveryBucket[];
    by_intensity: RecoveryBucket[];
    matrix: RecoveryMatrixCell[];
    correlations: {
        start_hour_vs_hrv_delta: CorrelationStat;
        start_hour_vs_sleep_score: CorrelationStat;
        load_vs_hrv_delta: CorrelationStat;
    };
    takeaway: string | null;
}

export interface SchedulePatternsAnalysis {
    n: number;
    by_day_of_week: ScheduleBucket[];
    by_time_of_day: ScheduleBucket[];
    takeaway: string | null;
}

export interface InsightsPageProps {
    filters: {
        sport: string;
        range: string;
    };
    dateWindow: DateWindowMeta;
    today: string;
    available_sports: InsightsAvailableSport[];
    has_data: boolean;
    summary: CorrelationSummary;
    sleep_vs_performance: SleepVsPerformanceAnalysis;
    hrv_vs_performance: HrvVsPerformanceAnalysis;
    weather_impact: WeatherImpactAnalysis;
    workout_to_recovery: WorkoutToRecoveryAnalysis;
    schedule_patterns: SchedulePatternsAnalysis;
}
