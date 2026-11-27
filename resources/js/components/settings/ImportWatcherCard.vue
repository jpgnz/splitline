<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowUpRight,
    FolderSync,
    RefreshCw,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { formatNumber, formatRelativeTime } from '@/lib/format';
import { show as showActivity } from '@/routes/activities';
import { scan as scanImportWatch } from '@/routes/settings/import-watch';

export interface ImportWatchRecentItem {
    activity_id: number;
    name: string;
    filename: string;
    imported_at: string;
}

export interface ImportWatchLastRun {
    ran_at: string;
    directory: string;
    mode: 'keep' | 'archive';
    created: number;
    skipped: number;
    failed: number;
    deferred_recent: number;
    last_error: string | null;
    recent_imports: ImportWatchRecentItem[];
}

export interface ImportWatchStatus {
    configured: boolean;
    directory: string | null;
    directory_exists: boolean;
    directory_readable: boolean;
    mode: 'keep' | 'archive';
    pending_files: number;
    imported_total: number;
    last_run: ImportWatchLastRun | null;
}

const props = defineProps<{
    importWatch: ImportWatchStatus;
}>();

const page = usePage();
const isScanning = ref(false);

const canScan = computed<boolean>(
    () =>
        props.importWatch.configured &&
        props.importWatch.directory_exists &&
        props.importWatch.directory_readable,
);

const statusBadge = computed<{
    label: string;
    classes: string;
    dotClass: string | null;
}>(() => {
    if (!props.importWatch.configured) {
        return {
            label: 'Not Configured',
            classes:
                'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            dotClass: null,
        };
    }

    if (
        !props.importWatch.directory_exists ||
        !props.importWatch.directory_readable
    ) {
        return {
            label: props.importWatch.directory_exists
                ? 'Not Readable'
                : 'Directory Missing',
            classes: 'bg-rose-500/12 text-rose-700 dark:text-rose-400',
            dotClass: 'bg-rose-500',
        };
    }

    return {
        label: 'Watching',
        classes: 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-400',
        dotClass: 'bg-emerald-500',
    };
});

const modeLabel = computed<string>(() =>
    props.importWatch.mode === 'archive'
        ? 'Archive to processed/'
        : 'Keep / Read-only safe',
);

const lastScannedLabel = computed<string>(() =>
    props.importWatch.last_run?.ran_at
        ? formatRelativeTime(props.importWatch.last_run.ran_at)
        : 'Never',
);

const recentImports = computed<ImportWatchRecentItem[]>(
    () => props.importWatch.last_run?.recent_imports ?? [],
);

const errorMessage = computed<string | null>(() => {
    if (
        props.importWatch.configured &&
        !props.importWatch.directory_exists &&
        props.importWatch.directory
    ) {
        return `Configured directory not found on disk: ${props.importWatch.directory}`;
    }

    if (
        props.importWatch.configured &&
        !props.importWatch.directory_readable &&
        props.importWatch.directory
    ) {
        return `Configured directory is not readable by the app process: ${props.importWatch.directory}`;
    }

    return props.importWatch.last_run?.last_error ?? null;
});

function triggerScan(): void {
    if (!canScan.value || isScanning.value) {
        return;
    }

    router.post(
        scanImportWatch.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isScanning.value = true;
            },
            onSuccess: () => {
                const status = page.props.flash?.status;
                toast.success(status || 'Import folder scan completed.');
            },
            onError: () => {
                toast.error('Unable to scan import folder.');
            },
            onFinish: () => {
                isScanning.value = false;
            },
        },
    );
}
</script>

<template>
    <section
        class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <FolderSync
                        class="size-4 text-emerald-600 dark:text-emerald-400"
                    />
                    <h2
                        class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        Drop-Folder Import Watcher
                    </h2>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-mono text-[10px] font-medium"
                        :class="statusBadge.classes"
                    >
                        <span
                            v-if="statusBadge.dotClass"
                            class="size-1.5 rounded-full"
                            :class="statusBadge.dotClass"
                        />
                        {{ statusBadge.label }}
                    </span>
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Automatically ingests standalone
                    <code
                        class="rounded bg-zinc-100 px-1 py-0.5 font-mono text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                        >.fit</code
                    >
                    and
                    <code
                        class="rounded bg-zinc-100 px-1 py-0.5 font-mono text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                        >.zip</code
                    >
                    files every 5 minutes, deduplicating by inner-FIT SHA-256.
                </p>
            </div>

            <button
                type="button"
                :disabled="!canScan || isScanning"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-zinc-900 px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-55 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
                @click="triggerScan"
            >
                <RefreshCw
                    :class="['size-3.5', isScanning && 'animate-spin']"
                />
                {{ isScanning ? 'Scanning...' : 'Scan Folder Now' }}
            </button>
        </div>

        <!-- Unconfigured Self-Hosting Tip -->
        <div
            v-if="!importWatch.configured"
            class="mt-5 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900/40 dark:text-zinc-400"
        >
            <p class="leading-relaxed">
                Set
                <code
                    class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] font-medium text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100"
                    >IMPORT_WATCH_DIR=/data/imports</code
                >
                in your environment to automatically ingest activities synced
                from Syncthing, HealthFit, RunGap, or NFS mounts.
            </p>
            <p class="mt-1.5 text-zinc-500 dark:text-zinc-400">
                Optional: set
                <code
                    class="rounded bg-zinc-200/70 px-1 py-0.5 font-mono text-[11px] text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
                    >IMPORT_WATCH_MODE=archive</code
                >
                to move processed files into
                <span class="font-mono">processed/</span> and errors into
                <span class="font-mono">failed/</span> (defaults to read-only
                safe <span class="font-mono">keep</span>).
            </p>
        </div>

        <!-- Configured Status Body -->
        <div v-else class="mt-5 space-y-4">
            <!-- Key Details Grid -->
            <div class="grid gap-3 sm:grid-cols-3">
                <div
                    class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-3.5 sm:col-span-2 dark:border-zinc-800 dark:bg-zinc-900/40"
                >
                    <p
                        class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                    >
                        Watch Directory
                    </p>
                    <p
                        class="mt-1.5 font-mono text-xs font-medium break-all text-zinc-900 dark:text-zinc-100"
                    >
                        {{ importWatch.directory }}
                    </p>
                    <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Mode:
                        <span
                            class="font-mono text-[11px] font-medium text-zinc-700 dark:text-zinc-300"
                        >
                            {{ modeLabel }}
                        </span>
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-1">
                    <div
                        class="flex items-center justify-between rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 px-3.5 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/40"
                    >
                        <span
                            class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Imported
                        </span>
                        <span
                            class="font-mono text-sm font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                        >
                            {{ formatNumber(importWatch.imported_total) }}
                        </span>
                    </div>

                    <div
                        class="flex items-center justify-between rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 px-3.5 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/40"
                    >
                        <span
                            class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                        >
                            Pending
                        </span>
                        <span
                            class="font-mono text-sm font-semibold tabular-nums"
                            :class="
                                importWatch.pending_files > 0
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-zinc-900 dark:text-zinc-100'
                            "
                        >
                            {{ formatNumber(importWatch.pending_files) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Error Banner -->
            <div
                v-if="errorMessage"
                class="flex items-start gap-2 rounded-lg border border-rose-500/30 bg-rose-500/8 px-3.5 py-2.5 text-xs text-rose-700 dark:border-rose-500/25 dark:bg-rose-500/10 dark:text-rose-300"
            >
                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                <span class="break-words">{{ errorMessage }}</span>
            </div>

            <!-- Last Scan Summary -->
            <div
                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200/80 bg-[#f7f7f5]/60 px-3.5 py-2.5 text-xs dark:border-zinc-800 dark:bg-zinc-900/40"
            >
                <span
                    class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400"
                >
                    Last scanned:
                    <strong
                        class="font-medium text-zinc-800 dark:text-zinc-200"
                    >
                        {{ lastScannedLabel }}
                    </strong>
                </span>

                <div
                    v-if="importWatch.last_run"
                    class="flex flex-wrap items-center gap-2 font-mono text-[11px] tabular-nums"
                >
                    <span class="text-emerald-700 dark:text-emerald-400">
                        {{ formatNumber(importWatch.last_run.created) }}
                        imported
                    </span>
                    <span class="text-zinc-300 dark:text-zinc-700">·</span>
                    <span class="text-zinc-600 dark:text-zinc-400">
                        {{ formatNumber(importWatch.last_run.skipped) }}
                        skipped
                    </span>
                    <span class="text-zinc-300 dark:text-zinc-700">·</span>
                    <span
                        :class="
                            importWatch.last_run.failed > 0
                                ? 'text-rose-700 dark:text-rose-400'
                                : 'text-zinc-600 dark:text-zinc-400'
                        "
                    >
                        {{ formatNumber(importWatch.last_run.failed) }} failed
                    </span>
                    <template v-if="importWatch.last_run.deferred_recent > 0">
                        <span class="text-zinc-300 dark:text-zinc-700">·</span>
                        <span class="text-amber-700 dark:text-amber-400">
                            {{
                                formatNumber(
                                    importWatch.last_run.deferred_recent,
                                )
                            }}
                            deferred
                        </span>
                    </template>
                </div>
            </div>

            <!-- Recent Imports -->
            <div v-if="recentImports.length > 0" class="space-y-2">
                <p
                    class="font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Recent Watch Imports
                </p>
                <div
                    class="divide-y divide-zinc-200/80 rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/40 dark:divide-zinc-800/80 dark:border-zinc-800 dark:bg-zinc-900/30"
                >
                    <Link
                        v-for="item in recentImports"
                        :key="item.activity_id"
                        :href="showActivity.url(item.activity_id)"
                        class="group flex items-center justify-between gap-3 px-3.5 py-2.5 text-xs transition-colors hover:bg-zinc-100/70 dark:hover:bg-zinc-800/50"
                    >
                        <div class="min-w-0">
                            <p
                                class="truncate font-medium text-zinc-900 group-hover:text-emerald-700 dark:text-zinc-100 dark:group-hover:text-emerald-400"
                            >
                                {{ item.name }}
                            </p>
                            <p
                                class="mt-0.5 truncate font-mono text-[10px] text-zinc-500 dark:text-zinc-400"
                            >
                                {{ item.filename }}
                            </p>
                        </div>

                        <div
                            class="flex shrink-0 items-center gap-1.5 font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                        >
                            <span>{{
                                formatRelativeTime(item.imported_at)
                            }}</span>
                            <ArrowUpRight
                                class="size-3.5 text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-emerald-600 dark:text-zinc-500 dark:group-hover:text-emerald-400"
                            />
                        </div>
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>
