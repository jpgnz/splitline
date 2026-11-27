<script setup lang="ts">
import {
    Activity,
    Database,
    Download,
    FileArchive,
    FileJson,
    FileSpreadsheet,
} from '@lucide/vue';
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { activities, backup } from '@/routes/exports';

export interface DataExportSummary {
    is_sqlite: boolean;
    database_size_bytes: number | null;
    activities_count: number;
    cached_archives_count: number;
    cached_archives_size_bytes: number;
}

const props = defineProps<{
    summary: DataExportSummary;
}>();

const backupDownloadUrl = computed<string>(() => backup.url());
const csvExportUrl = computed<string>(() =>
    activities.url({ query: { format: 'csv' } }),
);
const jsonExportUrl = computed<string>(() =>
    activities.url({ query: { format: 'json' } }),
);

function formatBytes(bytes: number | null | undefined): string {
    if (
        bytes === null ||
        bytes === undefined ||
        !Number.isFinite(bytes) ||
        bytes < 0
    ) {
        return '—';
    }

    if (bytes === 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    const value = bytes / 1024 ** exponent;
    const decimals =
        exponent === 0 ? 0 : value >= 100 ? 0 : value >= 10 ? 1 : 2;

    return `${value.toFixed(decimals)} ${units[exponent]}`;
}
</script>

<template>
    <section
        class="rounded-xl border border-zinc-200/90 bg-white p-6 dark:border-zinc-800/90 dark:bg-[#141417]"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <Database
                        class="size-4 text-emerald-600 dark:text-emerald-400"
                    />
                    <h2
                        class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100"
                    >
                        Backup &amp; Data Portability
                    </h2>
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Download a consistent live SQLite snapshot or export your
                    activity history in open, portable formats.
                </p>
            </div>

            <span
                class="inline-flex items-center rounded-full px-2.5 py-0.5 font-mono text-[10px] font-medium"
                :class="
                    props.summary.is_sqlite
                        ? 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-400'
                        : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'
                "
            >
                {{
                    props.summary.is_sqlite
                        ? 'Live Backup Ready'
                        : 'External Database'
                }}
            </span>
        </div>

        <!-- Storage & Inventory Stats -->
        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <div
                class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40"
            >
                <div
                    class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    <Database class="size-3" />
                    Database Size
                </div>
                <p
                    class="mt-2 font-mono text-lg font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatBytes(props.summary.database_size_bytes) }}
                </p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{
                        props.summary.is_sqlite
                            ? 'Consistent point-in-time database snapshot'
                            : 'Live backup requires a local SQLite database'
                    }}
                </p>
            </div>

            <div
                class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40"
            >
                <div
                    class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    <Activity class="size-3" />
                    Activities
                </div>
                <p
                    class="mt-2 font-mono text-lg font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                >
                    {{ formatNumber(props.summary.activities_count) }}
                </p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Includes metrics, derived analytics, and weather
                </p>
            </div>

            <div
                class="rounded-xl border border-zinc-200/80 bg-[#f7f7f5]/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40"
            >
                <div
                    class="flex items-center gap-1.5 font-mono text-[10px] tracking-wider text-zinc-500 uppercase dark:text-zinc-400"
                >
                    <FileArchive class="size-3" />
                    Original FIT Files
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <p
                        class="font-mono text-lg font-semibold text-zinc-900 tabular-nums dark:text-zinc-100"
                    >
                        {{ formatNumber(props.summary.cached_archives_count) }}
                    </p>
                    <span
                        class="font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400"
                    >
                        ({{
                            formatBytes(
                                props.summary.cached_archives_size_bytes,
                            )
                        }})
                    </span>
                </div>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Original device archives on local storage
                </p>
            </div>
        </div>

        <!-- Export Actions -->
        <div
            class="mt-5 flex flex-wrap items-center gap-2.5 border-t border-zinc-100 pt-4 dark:border-zinc-800/80"
        >
            <a
                v-if="props.summary.is_sqlite"
                :href="backupDownloadUrl"
                download
                class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-zinc-900 px-3.5 py-2 text-xs font-medium text-white transition-colors hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-950 dark:hover:bg-white"
            >
                <Download class="size-3.5" />
                <span>Download SQLite Backup</span>
            </a>
            <button
                v-else
                type="button"
                disabled
                title="Live SQLite backups are only available on a local SQLite database."
                class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-100 px-3.5 py-2 text-xs font-medium text-zinc-400 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-500"
            >
                <Download class="size-3.5" />
                <span>Download SQLite Backup</span>
            </button>

            <a
                v-if="props.summary.activities_count > 0"
                :href="csvExportUrl"
                download
                class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-medium text-zinc-800 transition-colors hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-100 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80"
            >
                <FileSpreadsheet
                    class="size-3.5 text-emerald-600 dark:text-emerald-400"
                />
                <span>Export All Activities (CSV)</span>
            </a>
            <button
                v-else
                type="button"
                disabled
                title="No activities available to export yet."
                class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-100 px-3.5 py-2 text-xs font-medium text-zinc-400 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-500"
            >
                <FileSpreadsheet class="size-3.5" />
                <span>Export All Activities (CSV)</span>
            </button>

            <a
                v-if="props.summary.activities_count > 0"
                :href="jsonExportUrl"
                download
                class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-medium text-zinc-800 transition-colors hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800/90 dark:bg-[#141417] dark:text-zinc-100 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/80"
            >
                <FileJson class="size-3.5 text-sky-600 dark:text-sky-400" />
                <span>Export All Activities (JSON)</span>
            </a>
            <button
                v-else
                type="button"
                disabled
                title="No activities available to export yet."
                class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-100 px-3.5 py-2 text-xs font-medium text-zinc-400 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-500"
            >
                <FileJson class="size-3.5" />
                <span>Export All Activities (JSON)</span>
            </button>
        </div>
    </section>
</template>
