import type { RouteAttemptWeather, RouteAvailableSport } from '@/types/routes';

export type ClimbCategory =
    | 'hc'
    | 'cat1'
    | 'cat2'
    | 'cat3'
    | 'cat4'
    | 'uncategorized';

export type SegmentKind = 'climb' | 'custom';

export interface SegmentCard {
    id: number;
    name: string;
    auto_name: string;
    name_override: string | null;
    place_label: string | null;
    kind: SegmentKind;
    sport_category: string;
    sport_key: string;
    accent_hex: string;
    metric_mode: 'pace' | 'speed' | 'pace_100m' | 'duration' | (string & {});
    climb_category: ClimbCategory | null;
    distance_meters: number;
    elevation_gain: number | null;
    elevation_loss: number | null;
    avg_grade_pct: number | null;
    max_grade_pct: number | null;
    fiets_score: number | null;
    encoded_polyline: string | null;
    is_starred: boolean;
    effort_count: number;
    best_elapsed_seconds: number | null;
    best_speed_mps: number | null;
    best_vam: number | null;
    best_avg_power: number | null;
    best_w_per_kg: number | null;
    best_effort_date: string | null;
    last_effort_at: string | null;
}

export interface SegmentsIndexSummary {
    total_segments: number;
    climbs_count: number;
    custom_count: number;
    starred_count: number;
    total_efforts: number;
    unscanned_count: number;
    min_efforts: number;
}

export interface SegmentsIndexProps {
    filters: {
        kind: 'all' | 'climb' | 'custom' | 'starred' | (string & {});
        sport: string;
        category: 'all' | ClimbCategory | (string & {});
        search: string;
        sort:
            | 'efforts'
            | 'recent'
            | 'climb_score'
            | 'elevation'
            | 'distance'
            | (string & {});
    };
    available_sports: RouteAvailableSport[];
    summary: SegmentsIndexSummary;
    segments: SegmentCard[];
}

export interface SegmentDetail {
    id: number;
    name: string;
    auto_name: string;
    name_override: string | null;
    place_label: string | null;
    kind: SegmentKind;
    sport_category: string;
    sport_key: string;
    accent_hex: string;
    metric_mode: 'pace' | 'speed' | 'pace_100m' | 'duration' | (string & {});
    climb_category: ClimbCategory | null;
    distance_meters: number;
    elevation_gain: number | null;
    elevation_loss: number | null;
    avg_grade_pct: number | null;
    max_grade_pct: number | null;
    fiets_score: number | null;
    is_starred: boolean;
    effort_count: number;
    last_effort_at: string | null;
    source_activity_id: number | null;
    source_activity_name: string | null;
}

export interface SegmentElevationPoint {
    distance_meters: number;
    altitude: number;
    grade_pct: number | null;
}

export interface SegmentAvailableSport {
    key: string;
    label: string;
    short_label: string;
    accent_hex: string;
    count: number;
}

export interface SegmentEffortItem {
    id: number;
    activity_id: number;
    activity_name: string;
    sport_key: string;
    date: string;
    started_at_local: string;
    year: number;
    start_index: number;
    end_index: number;
    start_distance_meters: number;
    end_distance_meters: number;
    distance_meters: number;
    elapsed_seconds: number;
    moving_seconds: number | null;
    avg_speed: number | null;
    elevation_gain: number | null;
    avg_grade_pct: number | null;
    vam: number | null;
    avg_hr: number | null;
    max_hr: number | null;
    avg_power: number | null;
    w_per_kg: number | null;
    avg_cadence: number | null;
    rank: number;
    display_rank: number;
    is_pr: boolean;
    is_display_pr: boolean;
    delta_pr_seconds: number;
    weather: RouteAttemptWeather | null;
}

export interface SegmentShowSummary {
    effort_count: number;
    all_time_effort_count: number;
    pr_elapsed_seconds: number | null;
    pr_speed_mps: number | null;
    pr_vam: number | null;
    pr_avg_power: number | null;
    pr_w_per_kg: number | null;
    pr_activity_id: number | null;
    pr_date: string | null;
    best_vam: number | null;
    best_avg_power: number | null;
    best_w_per_kg: number | null;
    median_elapsed_seconds: number | null;
    latest_elapsed_seconds: number | null;
    latest_date: string | null;
}

export interface SegmentShowProps {
    segment: SegmentDetail;
    polyline: [number, number][];
    elevation_profile: SegmentElevationPoint[];
    summary: SegmentShowSummary;
    available_years: number[];
    selected_year: number | null;
    available_sports: SegmentAvailableSport[];
    selected_sport: string | null;
    progression: SegmentEffortItem[];
    efforts: SegmentEffortItem[];
}

export interface ActivitySegmentEffortItem {
    id: number;
    segment_id: number;
    segment_name: string;
    place_label: string | null;
    kind: SegmentKind;
    climb_category: ClimbCategory | null;
    is_starred: boolean;
    effort_count: number;
    rank: number | null;
    is_pr: boolean;
    start_index: number;
    end_index: number;
    start_distance_meters: number;
    end_distance_meters: number;
    distance_meters: number;
    elapsed_seconds: number;
    moving_seconds: number | null;
    avg_speed: number | null;
    elevation_gain: number | null;
    avg_grade_pct: number | null;
    vam: number | null;
    avg_hr: number | null;
    max_hr: number | null;
    avg_power: number | null;
    w_per_kg: number | null;
    avg_cadence: number | null;
    delta_pr_seconds: number;
}
