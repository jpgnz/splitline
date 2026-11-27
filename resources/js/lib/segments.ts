import { formatVam as formatVamUnits, type UnitSystem } from '@/lib/units';
import type { ClimbCategory } from '@/types/segments';

export interface ClimbCategoryMeta {
    label: string;
    shortLabel: string;
    badgeClass: string;
}

export const CLIMB_CATEGORY_META: Record<ClimbCategory, ClimbCategoryMeta> = {
    hc: {
        label: 'Hors Catégorie',
        shortLabel: 'HC',
        badgeClass:
            'border-rose-500/35 bg-rose-500/12 text-rose-700 dark:border-rose-500/35 dark:bg-rose-500/20 dark:text-rose-300',
    },
    cat1: {
        label: 'Category 1',
        shortLabel: 'Cat 1',
        badgeClass:
            'border-orange-500/35 bg-orange-500/12 text-orange-700 dark:border-orange-500/35 dark:bg-orange-500/20 dark:text-orange-300',
    },
    cat2: {
        label: 'Category 2',
        shortLabel: 'Cat 2',
        badgeClass:
            'border-amber-500/35 bg-amber-500/12 text-amber-700 dark:border-amber-500/35 dark:bg-amber-500/20 dark:text-amber-300',
    },
    cat3: {
        label: 'Category 3',
        shortLabel: 'Cat 3',
        badgeClass:
            'border-yellow-500/35 bg-yellow-500/12 text-yellow-700 dark:border-yellow-500/35 dark:bg-yellow-500/20 dark:text-yellow-300',
    },
    cat4: {
        label: 'Category 4',
        shortLabel: 'Cat 4',
        badgeClass:
            'border-emerald-500/35 bg-emerald-500/12 text-emerald-700 dark:border-emerald-500/35 dark:bg-emerald-500/20 dark:text-emerald-300',
    },
    uncategorized: {
        label: 'Uncategorized',
        shortLabel: 'Uncat',
        badgeClass:
            'border-zinc-300/80 bg-zinc-100/90 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-300',
    },
};

export function climbCategoryMeta(
    category: ClimbCategory | null | undefined,
): ClimbCategoryMeta | null {
    if (!category) {
        return null;
    }

    return CLIMB_CATEGORY_META[category] ?? null;
}

export function formatVam(
    vam: number | null | undefined,
    units: UnitSystem = 'metric',
): string {
    return formatVamUnits(vam, units);
}

export function formatGrade(gradePct: number | null | undefined): string {
    if (
        gradePct === null ||
        gradePct === undefined ||
        !Number.isFinite(gradePct)
    ) {
        return '—';
    }

    return `${gradePct.toFixed(1)}%`;
}
