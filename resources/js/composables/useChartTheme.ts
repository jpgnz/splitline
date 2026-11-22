import type { ComputedRef } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

export const CHART_FONT_FAMILY_MONO =
    "'IBM Plex Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace";

export type UseChartThemeReturn = {
    isDark: ComputedRef<boolean>;
    textColor: ComputedRef<string>;
    mutedColor: ComputedRef<string>;
    gridLineColor: ComputedRef<string>;
    tooltipBg: ComputedRef<string>;
    tooltipBorder: ComputedRef<string>;
    tooltipText: ComputedRef<string>;
    fontFamilyMono: string;
};

const readDocumentDarkClass = (): boolean => {
    if (typeof document === 'undefined') {
        return false;
    }

    return document.documentElement.classList.contains('dark');
};

export function useChartTheme(): UseChartThemeReturn {
    const { resolvedAppearance } = useAppearance();
    const domDark = ref<boolean>(readDocumentDarkClass());

    let observer: MutationObserver | null = null;

    watch(resolvedAppearance, (nextAppearance) => {
        domDark.value =
            typeof document !== 'undefined'
                ? readDocumentDarkClass()
                : nextAppearance === 'dark';
    });

    onMounted(() => {
        domDark.value = readDocumentDarkClass();

        if (typeof MutationObserver === 'undefined') {
            return;
        }

        observer = new MutationObserver(() => {
            domDark.value = readDocumentDarkClass();
        });

        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
        observer = null;
    });

    const isDark = computed<boolean>(() => domDark.value);
    const textColor = computed<string>(() =>
        isDark.value ? '#e4e4e7' : '#27272a',
    );
    const mutedColor = computed<string>(() => '#71717a');
    const gridLineColor = computed<string>(() =>
        isDark.value ? '#27272a' : '#e4e4e7',
    );
    const tooltipBg = computed<string>(() =>
        isDark.value ? '#18181b' : '#ffffff',
    );
    const tooltipBorder = computed<string>(() =>
        isDark.value ? '#27272a' : '#e4e4e7',
    );
    const tooltipText = computed<string>(() =>
        isDark.value ? '#f4f4f5' : '#18181b',
    );

    return {
        isDark,
        textColor,
        mutedColor,
        gridLineColor,
        tooltipBg,
        tooltipBorder,
        tooltipText,
        fontFamilyMono: CHART_FONT_FAMILY_MONO,
    };
}
