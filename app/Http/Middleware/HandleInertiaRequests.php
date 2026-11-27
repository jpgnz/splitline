<?php

namespace App\Http\Middleware;

use App\Models\SyncRun;
use App\Services\Sync\QueueStatusService;
use App\Support\AppSettings;
use App\Support\AppVersion;
use App\Support\SportRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly AppVersion $appVersion,
        private readonly SportRegistry $sports,
        private readonly QueueStatusService $queueStatus,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => fn (): string => $this->appVersion->current(),
            'releaseTag' => fn (): ?string => $this->appVersion->releaseTag(),
            'units' => fn (): string => $this->settings->unitSystem(),
            'timezone' => fn (): string => $this->settings->displayTimezone(),
            'sports' => fn (): array => $this->sports->sharedFrontendPayload(),
            'flash' => [
                'syncStatus' => fn () => $request->session()->get('syncStatus'),
                'status' => fn () => $request->session()->get('status'),
            ],
            'sync' => fn (): array => $this->latestSyncSummary(),
        ];
    }

    /**
     * @return array{
     *     id: ?int,
     *     status: string,
     *     type: ?string,
     *     items_synced: int,
     *     started_at: ?string,
     *     finished_at: ?string,
     *     cursor_date: ?string,
     *     until_date: ?string,
     *     last_synced_at: ?string,
     *     error_message: ?string,
     *     provider_configured: bool,
     *     enrichments: array{
     *         jobs: array{default: int, streams: int, weather: int, total: int},
     *         has_active_work: bool
     *     }
     * }
     */
    private function latestSyncSummary(): array
    {
        $providerConfigured = trim((string) config('services.garmin.key', '')) !== '';
        $enrichments = $this->queueStatus->activeJobs();

        if (! Schema::hasTable('sync_runs')) {
            return [
                'id' => null,
                'status' => 'idle',
                'type' => null,
                'items_synced' => 0,
                'started_at' => null,
                'finished_at' => null,
                'cursor_date' => null,
                'until_date' => null,
                'last_synced_at' => null,
                'error_message' => null,
                'provider_configured' => $providerConfigured,
                'enrichments' => $enrichments,
            ];
        }

        $latest = SyncRun::findLatestForStatus();
        $lastCompleted = SyncRun::query()
            ->where('status', 'completed')
            ->latest('finished_at')
            ->latest('id')
            ->first();

        return [
            'id' => $latest?->id,
            'status' => $latest !== null ? $latest->effectiveStatus() : 'idle',
            'type' => $latest?->type,
            'items_synced' => $latest !== null ? $latest->items_synced : 0,
            'started_at' => $latest?->started_at?->toIso8601String(),
            'finished_at' => $latest?->finished_at?->toIso8601String(),
            'cursor_date' => $latest?->cursor_date,
            'until_date' => $latest?->until_date,
            'last_synced_at' => $lastCompleted?->finished_at?->toIso8601String(),
            'error_message' => $latest?->error_message,
            'provider_configured' => $providerConfigured,
            'enrichments' => $enrichments,
        ];
    }
}
