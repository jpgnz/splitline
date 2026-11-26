<script setup lang="ts">
import { ChevronDown, Download, FileArchive, MapPin } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { gpx, original } from '@/routes/activities/export';

const props = defineProps<{
    activityId: number;
    hasGps: boolean;
    hasOriginalArchive: boolean;
}>();

const isOpen = ref<boolean>(false);
const containerRef = ref<HTMLElement | null>(null);

const hasAnyExport = computed<boolean>(
    () => props.hasGps || props.hasOriginalArchive,
);

const gpxDownloadUrl = computed<string>(() => gpx.url(props.activityId));
const originalDownloadUrl = computed<string>(() =>
    original.url(props.activityId),
);

function toggleMenu(): void {
    if (!hasAnyExport.value) {
        return;
    }

    isOpen.value = !isOpen.value;
}

function closeMenu(): void {
    isOpen.value = false;
}

function handlePointerDown(event: PointerEvent): void {
    if (!isOpen.value) {
        return;
    }

    const target = event.target;

    if (
        containerRef.value &&
        target instanceof Node &&
        !containerRef.value.contains(target)
    ) {
        closeMenu();
    }
}

function handleKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && isOpen.value) {
        closeMenu();
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    document.removeEventListener('pointerdown', handlePointerDown);
    document.removeEventListener('keydown', handleKeyDown);
});
</script>

<template>
    <div ref="containerRef" class="relative inline-block">
        <button
            type="button"
            :disabled="!hasAnyExport"
            :aria-expanded="isOpen"
            aria-haspopup="menu"
            :title="
                hasAnyExport
                    ? 'Export activity files'
                    : 'No GPX route or cached FIT archive available'
            "
            class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-3 py-2 font-mono text-xs font-medium text-zinc-800 transition-colors hover:border-zinc-300 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-100 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80"
            @click="toggleMenu"
        >
            <Download class="size-3.5" />
            <span>Export</span>
            <ChevronDown
                class="size-3.5 text-zinc-400 transition-transform"
                :class="isOpen && 'rotate-180'"
            />
        </button>

        <div
            v-if="isOpen && hasAnyExport"
            role="menu"
            aria-label="Export activity"
            class="absolute right-0 z-30 mt-1.5 w-56 origin-top-right rounded-xl border border-zinc-200/90 bg-white p-1.5 shadow-lg dark:border-zinc-800/90 dark:bg-[#141417]"
        >
            <a
                v-if="props.hasGps"
                :href="gpxDownloadUrl"
                download
                role="menuitem"
                class="flex items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-800/80"
                @click="closeMenu"
            >
                <MapPin
                    class="mt-0.5 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
                <div class="min-w-0">
                    <p
                        class="font-mono text-xs font-medium text-zinc-900 dark:text-zinc-100"
                    >
                        Download GPX
                    </p>
                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                        GPX 1.1 route &amp; telemetry
                    </p>
                </div>
            </a>

            <a
                v-if="props.hasOriginalArchive"
                :href="originalDownloadUrl"
                download
                role="menuitem"
                class="flex items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-800/80"
                @click="closeMenu"
            >
                <FileArchive
                    class="mt-0.5 size-3.5 shrink-0 text-sky-600 dark:text-sky-400"
                />
                <div class="min-w-0">
                    <p
                        class="font-mono text-xs font-medium text-zinc-900 dark:text-zinc-100"
                    >
                        Download Original FIT
                    </p>
                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                        Cached device archive
                    </p>
                </div>
            </a>
        </div>
    </div>
</template>
