import type { SportFilterOption } from '@/types/sports';

export interface RouteAvailableSport {
    key: string;
    label: string;
    short_label: string;
    accent_hex: string;
}

export interface RouteAttemptWeather {
    condition: string;
    icon: string;
    temperature_c: number | null;
    apparent_temperature_c: number | null;
    wind_speed_kmh: number | null;
    wind_direction_compass: string | null;
    headwind_pct: number | null;
    crosswind_pct: number | null;
    tailwind_pct: number | null;
    avg_effective_wind_kmh: number | null;
    wind_label: 'Headwind' | 'Tailwind' | 'Crosswind' | 'Calm';
}

export interface RouteAttemptItem {
    activity_id: number;
    name: string;
    sport_key: string;
    date: string;
    started_at_local: string;
    distance_meters: number;
    duration_seconds: number;
    /** Elapsed time scaled to the route's median attempt distance (used for ranking and deltas). */
    normalized_duration_seconds: number;
    moving_duration_seconds: number | null;
    elevation_gain: number | null;
    avg_speed: number | null;
    avg_hr: number | null;
    max_hr: number | null;
    avg_power: number | null;
    normalized_power: number | null;
    delta_seconds: number;
    delta_median_seconds: number;
    rank: number;
    is_pr: boolean;
    is_current: boolean;
    weather: RouteAttemptWeather | null;
}

export interface WindImpactBucket {
    count: number;
    avg_duration_seconds: number;
    avg_speed: number | null;
    avg_hr: number | null;
}

export interface TempImpactBucket {
    label: string;
    count: number;
    avg_temp_c: number;
    avg_duration_seconds: number;
    avg_speed: number | null;
    avg_hr: number | null;
}

export interface RouteWeatherImpact {
    sample_count: number;
    wind: {
        headwind: WindImpactBucket | null;
        neutral: WindImpactBucket | null;
        tailwind: WindImpactBucket | null;
        headwind_vs_tailwind_delta_seconds: number | null;
    } | null;
    temperature: {
        threshold_c: number;
        cool: TempImpactBucket;
        warm: TempImpactBucket;
        warm_vs_cool_delta_seconds: number;
    } | null;
}

export interface MatchedRouteCard {
    id: number;
    name: string;
    auto_name: string;
    name_override: string | null;
    place_label: string | null;
    sport_category: string;
    effective_discipline: string | null;
    sport_key: string;
    accent_hex: string;
    metric_mode: 'pace' | 'speed' | 'pace_100m' | 'duration' | (string & {});
    location_name: string | null;
    is_loop: boolean;
    distance_meters: number;
    elevation_gain: number | null;
    encoded_polyline: string | null;
    activity_count: number;
    last_activity_at: string | null;
    pr_duration_seconds: number | null;
    pr_speed_mps: number | null;
    pr_activity_id: number | null;
    pr_date: string | null;
    median_duration_seconds: number | null;
    median_speed_mps: number | null;
    latest_duration_seconds: number | null;
    latest_speed_mps: number | null;
    latest_activity_id: number | null;
    latest_date: string | null;
    trend_delta_seconds: number | null;
}

export interface MatchedRoutesIndexSummary {
    route_count: number;
    total_attempts: number;
    most_repeated_count: number;
    most_repeated_name: string | null;
    total_distance_meters: number;
    unfingerprinted_count: number;
}

export interface MatchedRoutesIndexProps {
    filters: {
        sport: string;
        search: string;
        sort: 'count' | 'recent' | 'distance' | 'elevation' | (string & {});
    };
    available_sports: RouteAvailableSport[];
    summary: MatchedRoutesIndexSummary;
    routes: MatchedRouteCard[];
}

export interface MatchedRouteDetail {
    id: number;
    name: string;
    auto_name: string;
    name_override: string | null;
    place_label: string | null;
    sport_category: string;
    effective_discipline: string | null;
    sport_key: string;
    accent_hex: string;
    metric_mode: 'pace' | 'speed' | 'pace_100m' | 'duration' | (string & {});
    location_name: string | null;
    is_loop: boolean;
    distance_meters: number;
    elevation_gain: number | null;
    activity_count: number;
    last_activity_at: string | null;
}

export interface MatchedRouteDetailSummary {
    attempt_count: number;
    pr_duration_seconds: number | null;
    pr_speed_mps: number | null;
    pr_activity_id: number | null;
    pr_date: string | null;
    median_duration_seconds: number | null;
    median_speed_mps: number | null;
    avg_hr: number | null;
    avg_power: number | null;
    latest_duration_seconds: number | null;
    latest_date: string | null;
    trend_delta_seconds: number | null;
}

export interface MatchedRouteShowProps {
    route: MatchedRouteDetail;
    polyline: [number, number][];
    summary: MatchedRouteDetailSummary;
    available_sports: SportFilterOption[];
    selected_sport: string | null;
    progression: RouteAttemptItem[];
    attempts: RouteAttemptItem[];
    weather_impact: RouteWeatherImpact | null;
}

export interface ActivityMatchedRoutePayload {
    route: {
        id: number;
        name: string;
        place_label: string | null;
        sport_category: string;
        effective_discipline: string | null;
        sport_key: string;
        accent_hex: string;
        metric_mode:
            | 'pace'
            | 'speed'
            | 'pace_100m'
            | 'duration'
            | (string & {});
        location_name: string | null;
        is_loop: boolean;
        distance_meters: number;
        elevation_gain: number | null;
        activity_count: number;
    };
    current: {
        activity_id: number;
        rank: number;
        total_attempts: number;
        is_pr: boolean;
        duration_seconds: number;
        avg_speed: number | null;
        delta_pr_seconds: number;
        delta_median_seconds: number;
    };
    summary: MatchedRouteDetailSummary;
    progression: RouteAttemptItem[];
    attempts: RouteAttemptItem[];
    weather_impact: RouteWeatherImpact | null;
}
