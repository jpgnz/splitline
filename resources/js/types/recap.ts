import type { AnnualActivitySummaryPayload } from '@/components/charts/annualSummaryTypes';

export interface RecapTotals {
    activities: number;
    active_days: number;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number;
    calories: number;
    training_load: number;
}

export interface RecapYoy {
    previous_year: number;
    is_ytd_comparison: boolean;
    ytd_cutoff_date: string | null;
    has_previous_data: boolean;
    distance_pct: number | null;
    duration_pct: number | null;
    elevation_pct: number | null;
    activities_pct: number | null;
}

export interface RecapSportBreakdownItem {
    key: string;
    label: string;
    accent_hex: string;
    count: number;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number;
    share_pct: number;
}

export interface RecapActivityHighlight {
    id: number;
    name: string;
    sport_category: string;
    effective_discipline: string | null;
    sport_label: string;
    accent_hex: string;
    started_at_local: string;
    date: string;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number;
}

export interface RecapBiggestWeek {
    week_start: string;
    week_end: string;
    label: string;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number;
    count: number;
}

export interface RecapBiggestMonth {
    month: number;
    label: string;
    short_label: string;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number;
    count: number;
}

export interface RecapPeaks {
    biggest_week: RecapBiggestWeek | null;
    biggest_month: RecapBiggestMonth | null;
    longest_activity: RecapActivityHighlight | null;
    longest_duration_activity: RecapActivityHighlight | null;
    biggest_climb_activity: RecapActivityHighlight | null;
}

export interface RecapConsistency {
    longest_daily_streak: {
        days: number;
        start_date: string | null;
        end_date: string | null;
    };
    longest_weekly_streak: {
        weeks: number;
        start_week: string | null;
        end_week: string | null;
    };
    busiest_weekday: {
        day: string;
        count: number;
        share_pct: number;
    } | null;
    favorite_time_of_day: {
        bucket: string;
        label: string;
        count: number;
        share_pct: number;
    } | null;
}

export interface RecapBestEffortItem {
    id: number;
    activity_id: number;
    activity_name: string;
    sport: string;
    sport_label: string;
    accent_hex: string;
    distance_key: string;
    distance_label: string;
    distance_meters: number;
    elapsed_seconds: number;
    achieved_at: string;
    is_all_time_pr: boolean;
}

export interface RecapRepeatedRoute {
    id: number;
    name: string;
    place_label: string | null;
    sport_category: string;
    sport_label: string;
    accent_hex: string;
    distance_meters: number;
    elevation_gain: number | null;
    visits_in_year: number;
    total_visits: number;
    is_loop: boolean;
    encoded_polyline: string | null;
}

export interface RecapSegmentPrItem {
    id: number;
    segment_id: number;
    segment_name: string;
    climb_category: string | null;
    activity_id: number;
    activity_name: string;
    elapsed_seconds: number;
    distance_meters: number;
    elevation_gain: number | null;
    date: string;
}

export interface RecapMilestones {
    all_time_prs_count: number;
    best_efforts: RecapBestEffortItem[];
    matched_routes_completed: number;
    most_repeated_route: RecapRepeatedRoute | null;
    segment_prs_count: number;
    segment_efforts_count: number;
    segment_prs: RecapSegmentPrItem[];
}

export interface RecapWeatherExtreme {
    activity_id: number;
    activity_name: string;
    date: string;
    sport_category: string;
    accent_hex: string;
    condition: string;
    temperature_c: number | null;
    wind_speed_kmh: number | null;
    precipitation_mm: number | null;
}

export interface RecapWeather {
    sample_count: number;
    rainy_sessions_count: number;
    coldest_activity: RecapWeatherExtreme | null;
    hottest_activity: RecapWeatherExtreme | null;
    windiest_activity: RecapWeatherExtreme | null;
    wettest_activity: RecapWeatherExtreme | null;
}

export interface RecapHealth {
    has_data: boolean;
    avg_sleep_hours: number | null;
    avg_sleep_score: number | null;
    sleep_nights: number;
    avg_resting_hr: number | null;
    avg_hrv: number | null;
    total_steps: number;
    avg_daily_steps: number | null;
    peak_vo2_max: number | null;
    peak_vo2_max_sport: string | null;
    peak_vo2_max_date: string | null;
}

export interface RecapMonthlyBucket {
    month: number;
    label: string;
    full_label: string;
    activities: number;
    distance_meters: number;
    duration_seconds: number;
    elevation_gain: number;
    training_load: number;
    calories: number;
}

export interface AnnualRecapPageProps {
    selected_year: number;
    available_years: number[];
    today: string;
    is_current_year: boolean;
    has_data: boolean;
    totals: RecapTotals;
    yoy: RecapYoy;
    sport_breakdown: RecapSportBreakdownItem[];
    peaks: RecapPeaks;
    consistency: RecapConsistency;
    milestones: RecapMilestones;
    weather: RecapWeather;
    health: RecapHealth;
    monthly: RecapMonthlyBucket[];
    annual_summary: AnnualActivitySummaryPayload;
}

export type MonthlyMetricKey = 'distance' | 'duration' | 'elevation' | 'load';
