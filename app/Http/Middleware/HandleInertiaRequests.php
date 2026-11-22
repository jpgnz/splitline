<?php

namespace App\Http\Middleware;

use App\Models\SyncRun;
use App\Support\AppSettings;
use App\Support\SportRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly SportRegistry $sports,
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
     *     status: string,
     *     type: ?string,
     *     items_synced: int,
     *     started_at: ?string,
     *     finished_at: ?string,
     *     cursor_date: ?string,
     *     last_synced_at: ?string,
     *     error_message: ?string,
     *     provider_configured: bool
     * }
     */
    private function latestSyncSummary(): array
    {
        $providerConfigured = trim((string) config('services.garmin.key', '')) !== '';

        if (! Schema::hasTable('sync_runs')) {
            return [
                'status' => 'idle',
                'type' => null,
                'items_synced' => 0,
                'started_at' => null,
                'finished_at' => null,
                'cursor_date' => null,
                'last_synced_at' => null,
                'error_message' => null,
                'provider_configured' => $providerConfigured,
            ];
        }

        $latest = SyncRun::query()->latest('started_at')->latest('id')->first();
        $lastCompleted = SyncRun::query()
            ->where('status', 'completed')
            ->latest('finished_at')
            ->latest('id')
            ->first();

        return [
            'status' => $latest !== null ? $latest->status : 'idle',
            'type' => $latest?->type,
            'items_synced' => $latest !== null ? $latest->items_synced : 0,
            'started_at' => $latest?->started_at?->toIso8601String(),
            'finished_at' => $latest?->finished_at?->toIso8601String(),
            'cursor_date' => $latest?->cursor_date,
            'last_synced_at' => $lastCompleted?->finished_at?->toIso8601String(),
            'error_message' => $latest?->error_message,
            'provider_configured' => $providerConfigured,
        ];
    }
}
