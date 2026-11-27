import type { Ref } from 'vue';
import { ref } from 'vue';
import type { EnrichmentQueueJobs } from '@/types/global';

const latestPolledEnrichments = ref<EnrichmentQueueJobs | null>(null);

/**
 * Shares the queued-job snapshot from the layout's sync status poller with pages,
 * so pages can show live queue counts without running a second poller.
 */
export function useEnrichmentQueueFeed(): {
    latestPolledEnrichments: Ref<EnrichmentQueueJobs | null>;
    publishEnrichments: (next: EnrichmentQueueJobs) => void;
} {
    function publishEnrichments(next: EnrichmentQueueJobs): void {
        latestPolledEnrichments.value = next;
    }

    return {
        latestPolledEnrichments,
        publishEnrichments,
    };
}
