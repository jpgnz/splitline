// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

export type UnitSystem = 'metric' | 'imperial';

export type EnrichmentQueueSummary = {
    jobs: {
        default: number;
        streams: number;
        weather: number;
        total: number;
    };
    pending: {
        streams: number;
        weather: number;
        routes: number;
    };
    has_active_work: boolean;
};

export type SportNavItem = {
    key: string;
    label: string;
    short_label: string;
    href: string;
    icon: string;
    color_token: string;
    accent_hex: string;
    parent: string | null;
    children?: SportNavItem[];
};

export type SharedSportsMeta = {
    mode: 'auto' | 'custom';
    pin_sub_disciplines: boolean;
    nav: SportNavItem[];
    catalog: Record<
        string,
        {
            key: string;
            parent: string | null;
            label: string;
            short_label: string;
            slug: string;
            icon: string;
            color_token: string;
            accent_hex: string;
            metric_mode: 'pace' | 'speed' | 'pace_100m' | 'duration';
            cadence_unit: 'spm' | 'rpm' | 'strokes/min' | null;
            hub_type: 'overview' | 'endurance' | 'general';
        }
    >;
};

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            appVersion: string;
            releaseTag: string | null;
            units: UnitSystem;
            timezone: string;
            sports?: SharedSportsMeta;
            sync?: {
                id: number | null;
                status: string;
                type: string | null;
                items_synced: number;
                started_at: string | null;
                finished_at: string | null;
                cursor_date: string | null;
                until_date: string | null;
                last_synced_at: string | null;
                error_message: string | null;
                provider_configured?: boolean;
                enrichments?: EnrichmentQueueSummary;
            };
            flash?: {
                syncStatus?: string | null;
                status?: string | null;
            };
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
