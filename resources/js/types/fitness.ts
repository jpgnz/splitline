import type { DateWindowMeta } from '@/components/DateRangeControl.vue';

export type LoadSource = 'tss' | 'garmin_load' | 'trimp';

export type SportBucket = 'running' | 'cycling' | 'other';

export type ProjectionMode = 'rest' | 'maintenance' | 'off';

export type HealthOverlayKey = 'none' | 'hrv' | 'resting_hr' | 'sleep_score';

export interface FitnessAvailableSport {
    key: string;
    label: string;
    short_label: string;
    accent_hex: string;
}

export interface SportLoadBreakdown {
    running: number;
    cycling: number;
    other: number;
}

export interface DailyTrainingLoadPoint {
    date: string;
    load: number;
    by_sport: SportLoadBreakdown;
    activity_count: number;
    ctl: number;
    atl: number;
    tsb: number;
    acwr: number | null;
    ramp_rate: number;
    hrv: number | null;
    resting_hr: number | null;
    sleep_score: number | null;
}

export interface ProjectionPoint {
    date: string;
    load: number;
    ctl: number;
    atl: number;
    tsb: number;
    acwr: number | null;
}

export interface StatusBadge {
    key: string;
    label: string;
    tone: 'emerald' | 'sky' | 'amber' | 'rose' | 'neutral' | (string & {});
    description: string;
}

export interface LoadSourceMix {
    total: number;
    tss_count: number;
    garmin_load_count: number;
    trimp_count: number;
    tss_pct: number;
    garmin_load_pct: number;
    trimp_pct: number;
}

export interface WeeklySportLoadBucket {
    week_start: string;
    label: string;
    running: number;
    cycling: number;
    other: number;
    total: number;
    activity_count: number;
}

export interface RecentLoadSession {
    id: number;
    date: string;
    started_at_local: string;
    name: string;
    sport_category: string;
    sport_key: string;
    sport_label: string;
    sport_bucket: SportBucket;
    duration_seconds: number;
    distance_meters: number;
    avg_hr: number | null;
    load: number;
    load_source: LoadSource;
}

export interface TrainingLoadSummary {
    ctl: number;
    atl: number;
    tsb: number;
    acwr: number | null;
    ramp_rate_7d: number;
    load_7d: number;
    load_28d: number;
    form_zone: StatusBadge;
    acwr_status: StatusBadge;
    load_source_mix: LoadSourceMix;
    maintenance_daily_load: number;
    projected_peak_date: string | null;
    projected_peak_dates: {
        rest: string | null;
        maintenance: string | null;
    };
}

export interface FitnessPageProps {
    filters: {
        sport: string;
        range: string;
        projection_days: number;
    };
    dateWindow: DateWindowMeta;
    today: string;
    available_sports: FitnessAvailableSport[];
    has_data: boolean;
    summary: TrainingLoadSummary;
    health_availability: {
        hrv: boolean;
        resting_hr: boolean;
        sleep_score: boolean;
    };
    daily: DailyTrainingLoadPoint[];
    projections: {
        rest: ProjectionPoint[];
        maintenance: ProjectionPoint[];
    };
    weekly_sport_load: WeeklySportLoadBucket[];
    recent_sessions: RecentLoadSession[];
}
