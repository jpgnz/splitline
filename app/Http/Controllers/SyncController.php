<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGarminJob;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    private const int CONCURRENT_SYNC_WINDOW_MINUTES = 10;

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        if (! $this->isProviderConfigured()) {
            $message = 'Garmin API key is not configured. Set GARMIN_KEY in your environment.';

            if ($request->expectsJson()) {
                $latestRun = SyncRun::query()
                    ->latest('started_at')
                    ->latest('id')
                    ->first();

                return response()->json([
                    'message' => $message,
                    ...$this->buildStatusPayload($latestRun),
                ], 422);
            }

            return back()->with('syncStatus', $message);
        }

        $runningRun = SyncRun::query()
            ->whereIn('status', ['running', 'queued'])
            ->where('started_at', '>=', CarbonImmutable::now()->subMinutes(self::CONCURRENT_SYNC_WINDOW_MINUTES))
            ->latest('started_at')
            ->latest('id')
            ->first();

        if ($runningRun !== null) {
            $notice = 'A Garmin sync is already in progress.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $notice,
                    'already_running' => true,
                    ...$this->buildStatusPayload($runningRun),
                ]);
            }

            return back()->with('syncStatus', $notice);
        }

        $syncRun = SyncRun::query()->create([
            'type' => 'incremental',
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
            'items_synced' => 0,
        ]);

        SyncGarminJob::dispatch($syncRun->id);

        $syncRun->refresh();
        $message = 'Garmin sync started in the background.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                ...$this->buildStatusPayload($syncRun),
            ], 202);
        }

        return back()->with('syncStatus', $message);
    }

    public function status(): JsonResponse
    {
        $latestRun = SyncRun::query()
            ->latest('started_at')
            ->latest('id')
            ->first();

        return response()->json($this->buildStatusPayload($latestRun));
    }

    private function isProviderConfigured(): bool
    {
        return trim((string) config('services.garmin.key', '')) !== '';
    }

    /**
     * @return array{
     *     status: string,
     *     type: ?string,
     *     started_at: ?string,
     *     finished_at: ?string,
     *     items_synced: int,
     *     error_message: ?string,
     *     last_synced_at: ?string,
     *     provider_configured: bool
     * }
     */
    private function buildStatusPayload(?SyncRun $latestRun): array
    {
        $lastCompletedAt = $latestRun !== null && $latestRun->status === 'completed'
            ? $latestRun->finished_at
            : SyncRun::query()
                ->where('status', 'completed')
                ->whereNotNull('finished_at')
                ->latest('finished_at')
                ->latest('id')
                ->value('finished_at');

        return [
            'status' => $latestRun !== null ? (string) $latestRun->status : 'idle',
            'type' => $latestRun?->type !== null ? (string) $latestRun->type : null,
            'started_at' => $this->formatTimestamp($latestRun?->started_at),
            'finished_at' => $this->formatTimestamp($latestRun?->finished_at),
            'items_synced' => $latestRun !== null ? (int) $latestRun->items_synced : 0,
            'error_message' => $latestRun?->error_message !== null ? (string) $latestRun->error_message : null,
            'last_synced_at' => $this->formatTimestamp($lastCompletedAt),
            'provider_configured' => $this->isProviderConfigured(),
        ];
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value)->toIso8601String();
        }

        return null;
    }
}
