import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import StudioLayout from '@/layouts/StudioLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName =
    (typeof document !== 'undefined'
        ? document
              .querySelector<HTMLMetaElement>('meta[name="app-name"]')
              ?.content.trim()
        : undefined) ||
    import.meta.env.VITE_APP_NAME ||
    'Splitline';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: () => StudioLayout,
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
