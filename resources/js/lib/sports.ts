import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedSportsMeta, SportNavItem } from '@/types/global';

export type SportMeta = SharedSportsMeta['catalog'][string];

export const DEFAULT_SPORT_CATALOG: Record<string, SportMeta> = {
    running: {
        key: 'running',
        parent: null,
        label: 'Running',
        short_label: 'Run',
        slug: 'running',
        icon: 'flame',
        color_token: 'amber',
        accent_hex: '#f59e0b',
        metric_mode: 'pace',
        cadence_unit: 'spm',
        hub_type: 'endurance',
    },
    road_running: {
        key: 'road_running',
        parent: 'running',
        label: 'Road Running',
        short_label: 'Road Run',
        slug: 'running/road',
        icon: 'flame',
        color_token: 'amber',
        accent_hex: '#f59e0b',
        metric_mode: 'pace',
        cadence_unit: 'spm',
        hub_type: 'endurance',
    },
    trail_running: {
        key: 'trail_running',
        parent: 'running',
        label: 'Trail Running',
        short_label: 'Trail',
        slug: 'running/trail',
        icon: 'mountain',
        color_token: 'orange',
        accent_hex: '#f97316',
        metric_mode: 'pace',
        cadence_unit: 'spm',
        hub_type: 'endurance',
    },
    track_running: {
        key: 'track_running',
        parent: 'running',
        label: 'Track Running',
        short_label: 'Track',
        slug: 'running/track',
        icon: 'timer',
        color_token: 'rose',
        accent_hex: '#f43f5e',
        metric_mode: 'pace',
        cadence_unit: 'spm',
        hub_type: 'endurance',
    },
    treadmill_running: {
        key: 'treadmill_running',
        parent: 'running',
        label: 'Treadmill Running',
        short_label: 'Treadmill',
        slug: 'running/treadmill',
        icon: 'footprints',
        color_token: 'yellow',
        accent_hex: '#eab308',
        metric_mode: 'pace',
        cadence_unit: 'spm',
        hub_type: 'endurance',
    },
    cycling: {
        key: 'cycling',
        parent: null,
        label: 'Cycling',
        short_label: 'Cycle',
        slug: 'cycling',
        icon: 'bike',
        color_token: 'sky',
        accent_hex: '#0ea5e9',
        metric_mode: 'speed',
        cadence_unit: 'rpm',
        hub_type: 'overview',
    },
    road_cycling: {
        key: 'road_cycling',
        parent: 'cycling',
        label: 'Road Cycling',
        short_label: 'Road',
        slug: 'cycling/road',
        icon: 'bike',
        color_token: 'sky',
        accent_hex: '#0ea5e9',
        metric_mode: 'speed',
        cadence_unit: 'rpm',
        hub_type: 'endurance',
    },
    gravel_cycling: {
        key: 'gravel_cycling',
        parent: 'cycling',
        label: 'Gravel Cycling',
        short_label: 'Gravel',
        slug: 'cycling/gravel',
        icon: 'compass',
        color_token: 'teal',
        accent_hex: '#14b8a6',
        metric_mode: 'speed',
        cadence_unit: 'rpm',
        hub_type: 'endurance',
    },
    mtb_cycling: {
        key: 'mtb_cycling',
        parent: 'cycling',
        label: 'Mountain Biking',
        short_label: 'MTB',
        slug: 'cycling/mtb',
        icon: 'mountain',
        color_token: 'emerald',
        accent_hex: '#10b981',
        metric_mode: 'speed',
        cadence_unit: 'rpm',
        hub_type: 'endurance',
    },
    indoor_cycling: {
        key: 'indoor_cycling',
        parent: 'cycling',
        label: 'Indoor Cycling',
        short_label: 'Indoor',
        slug: 'cycling/indoor',
        icon: 'zap',
        color_token: 'indigo',
        accent_hex: '#6366f1',
        metric_mode: 'speed',
        cadence_unit: 'rpm',
        hub_type: 'endurance',
    },
    ebike_cycling: {
        key: 'ebike_cycling',
        parent: 'cycling',
        label: 'E-Bike',
        short_label: 'E-Bike',
        slug: 'cycling/ebike',
        icon: 'battery-charging',
        color_token: 'lime',
        accent_hex: '#84cc16',
        metric_mode: 'speed',
        cadence_unit: 'rpm',
        hub_type: 'endurance',
    },
    commute_cycling: {
        key: 'commute_cycling',
        parent: 'cycling',
        label: 'Commute Cycling',
        short_label: 'Commute',
        slug: 'cycling/commute',
        icon: 'navigation',
        color_token: 'slate',
        accent_hex: '#64748b',
        metric_mode: 'speed',
        cadence_unit: 'rpm',
        hub_type: 'general',
    },
    hiking: {
        key: 'hiking',
        parent: null,
        label: 'Hiking',
        short_label: 'Hike',
        slug: 'sports/hiking',
        icon: 'mountain-snow',
        color_token: 'lime',
        accent_hex: '#65a30d',
        metric_mode: 'pace',
        cadence_unit: 'spm',
        hub_type: 'endurance',
    },
    walking: {
        key: 'walking',
        parent: null,
        label: 'Walking',
        short_label: 'Walk',
        slug: 'sports/walking',
        icon: 'footprints',
        color_token: 'cyan',
        accent_hex: '#06b6d4',
        metric_mode: 'pace',
        cadence_unit: 'spm',
        hub_type: 'general',
    },
    swimming: {
        key: 'swimming',
        parent: null,
        label: 'Swimming',
        short_label: 'Swim',
        slug: 'sports/swimming',
        icon: 'waves',
        color_token: 'blue',
        accent_hex: '#3b82f6',
        metric_mode: 'pace_100m',
        cadence_unit: 'strokes/min',
        hub_type: 'general',
    },
    cardio: {
        key: 'cardio',
        parent: null,
        label: 'Strength & Cardio',
        short_label: 'Cardio',
        slug: 'sports/cardio',
        icon: 'heart-pulse',
        color_token: 'violet',
        accent_hex: '#8b5cf6',
        metric_mode: 'duration',
        cadence_unit: null,
        hub_type: 'general',
    },
    other: {
        key: 'other',
        parent: null,
        label: 'Other',
        short_label: 'Other',
        slug: 'activities',
        icon: 'activity',
        color_token: 'zinc',
        accent_hex: '#71717a',
        metric_mode: 'duration',
        cadence_unit: null,
        hub_type: 'general',
    },
};

const SPORT_KEY_ALIASES: Record<string, string> = {
    biking: 'cycling',
    road_biking: 'road_cycling',
    mtb_biking: 'mtb_cycling',
    gravel_biking: 'gravel_cycling',
    indoor_biking: 'indoor_cycling',
    ebike_biking: 'ebike_cycling',
    commute_biking: 'commute_cycling',
    road: 'road_cycling',
    mtb: 'mtb_cycling',
    gravel: 'gravel_cycling',
    trail: 'trail_running',
    track: 'track_running',
    treadmill: 'treadmill_running',
    ebike: 'ebike_cycling',
    commute: 'commute_cycling',
    indoor: 'indoor_cycling',
    run: 'running',
    hike: 'hiking',
    walk: 'walking',
    swim: 'swimming',
    strength: 'cardio',
};

const REVERSE_CATALOG_ALIASES: Record<string, string> = {
    cycling: 'biking',
    road_cycling: 'road_biking',
    mtb_cycling: 'mtb_biking',
    gravel_cycling: 'gravel_biking',
    indoor_cycling: 'indoor_biking',
    ebike_cycling: 'ebike_biking',
    commute_cycling: 'commute_biking',
};

const BADGE_CLASS_MAP: Record<string, string> = {
    amber: 'border-amber-500/25 bg-amber-500/12 text-amber-700 dark:text-amber-300',
    orange: 'border-orange-500/25 bg-orange-500/12 text-orange-700 dark:text-orange-300',
    rose: 'border-rose-500/25 bg-rose-500/12 text-rose-700 dark:text-rose-300',
    yellow: 'border-yellow-500/25 bg-yellow-500/12 text-yellow-700 dark:text-yellow-300',
    sky: 'border-sky-500/25 bg-sky-500/12 text-sky-700 dark:text-sky-300',
    teal: 'border-teal-500/25 bg-teal-500/12 text-teal-700 dark:text-teal-300',
    emerald:
        'border-emerald-500/25 bg-emerald-500/12 text-emerald-700 dark:text-emerald-300',
    indigo: 'border-indigo-500/25 bg-indigo-500/12 text-indigo-700 dark:text-indigo-300',
    lime: 'border-lime-500/25 bg-lime-500/12 text-lime-700 dark:text-lime-300',
    cyan: 'border-cyan-500/25 bg-cyan-500/12 text-cyan-700 dark:text-cyan-300',
    blue: 'border-blue-500/25 bg-blue-500/12 text-blue-700 dark:text-blue-300',
    violet: 'border-violet-500/25 bg-violet-500/12 text-violet-700 dark:text-violet-300',
    slate: 'border-slate-500/25 bg-slate-500/12 text-slate-700 dark:text-slate-300',
    zinc: 'border-zinc-500/25 bg-zinc-500/12 text-zinc-700 dark:text-zinc-300',
};

function normalizeSportKey(key: string | null | undefined): string {
    const cleaned = (key ?? '').trim().toLowerCase();

    if (!cleaned) {
        return 'other';
    }

    return SPORT_KEY_ALIASES[cleaned] ?? cleaned;
}

export function getSportMeta(
    key: string | null | undefined,
    catalog?: Record<string, SportMeta>,
): SportMeta {
    const rawKey = (key ?? '').trim().toLowerCase();

    if (rawKey && catalog?.[rawKey]) {
        return catalog[rawKey];
    }

    const canonical = normalizeSportKey(rawKey);

    if (catalog?.[canonical]) {
        return catalog[canonical];
    }

    const reverseAlias = REVERSE_CATALOG_ALIASES[canonical];

    if (reverseAlias && catalog?.[reverseAlias]) {
        return catalog[reverseAlias];
    }

    if (DEFAULT_SPORT_CATALOG[canonical]) {
        return DEFAULT_SPORT_CATALOG[canonical];
    }

    return catalog?.other ?? DEFAULT_SPORT_CATALOG.other;
}

export function resolveActivitySportKey(
    sportCategory: string | null | undefined,
    effectiveDiscipline?: string | null,
    isIndoor?: boolean,
): string {
    const category = (sportCategory ?? '').trim().toLowerCase();
    const discipline = (effectiveDiscipline ?? '').trim().toLowerCase();

    if (category === 'running') {
        if (discipline === 'trail') {
            return 'trail_running';
        }

        if (discipline === 'track') {
            return 'track_running';
        }

        if (discipline === 'treadmill') {
            return 'treadmill_running';
        }

        if (
            isIndoor &&
            (discipline === '' ||
                discipline === 'road' ||
                discipline === 'other')
        ) {
            return 'treadmill_running';
        }

        if (discipline === 'road') {
            return 'road_running';
        }

        return 'running';
    }

    if (category === 'biking' || category === 'cycling') {
        if (discipline === 'gravel') {
            return 'gravel_cycling';
        }

        if (discipline === 'mtb') {
            return 'mtb_cycling';
        }

        if (discipline === 'ebike') {
            return 'ebike_cycling';
        }

        if (discipline === 'commute') {
            return 'commute_cycling';
        }

        if (discipline === 'indoor') {
            return 'indoor_cycling';
        }

        if (
            isIndoor &&
            (discipline === '' ||
                discipline === 'road' ||
                discipline === 'other')
        ) {
            return 'indoor_cycling';
        }

        if (discipline === 'road') {
            return 'road_cycling';
        }

        return 'cycling';
    }

    const canonical = normalizeSportKey(category);

    return DEFAULT_SPORT_CATALOG[canonical] ? canonical : 'other';
}

export function sportBadgeClasses(
    colorToken: string | null | undefined,
): string {
    const token = (colorToken ?? '').trim().toLowerCase();

    return BADGE_CLASS_MAP[token] ?? BADGE_CLASS_MAP.zinc;
}

export function sportAccentHex(
    key: string | null | undefined,
    catalog?: Record<string, SportMeta>,
): string {
    return getSportMeta(key, catalog).accent_hex;
}

export function sportLabel(
    key: string | null | undefined,
    catalog?: Record<string, SportMeta>,
): string {
    return getSportMeta(key, catalog).label;
}

export function sportShortLabel(
    key: string | null | undefined,
    catalog?: Record<string, SportMeta>,
): string {
    return getSportMeta(key, catalog).short_label;
}

export function useSports() {
    const page = usePage();

    const sports = computed<SharedSportsMeta | undefined>(
        () => page.props.sports,
    );
    const mode = computed<'auto' | 'custom'>(
        () => sports.value?.mode ?? 'auto',
    );
    const pinSubDisciplines = computed<boolean>(
        () => sports.value?.pin_sub_disciplines ?? false,
    );
    const nav = computed<SportNavItem[]>(() => sports.value?.nav ?? []);
    const catalog = computed<Record<string, SportMeta>>(() => ({
        ...DEFAULT_SPORT_CATALOG,
        ...sports.value?.catalog,
    }));

    return {
        sports,
        mode,
        pinSubDisciplines,
        nav,
        catalog,
        getSportMeta: (key: string | null | undefined) =>
            getSportMeta(key, catalog.value),
        resolveActivitySportKey,
        sportBadgeClasses,
        sportAccentHex: (key: string | null | undefined) =>
            sportAccentHex(key, catalog.value),
        sportLabel: (key: string | null | undefined) =>
            sportLabel(key, catalog.value),
        sportShortLabel: (key: string | null | undefined) =>
            sportShortLabel(key, catalog.value),
    };
}
