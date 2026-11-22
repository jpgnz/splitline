<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartBackfillRequest;
use App\Jobs\SyncGarminBackfillJob;
use App\Jobs\SyncGarminJob;
use App\Models\SyncRun;
use App\Support\AppSettings;
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
            return $this->unconfiguredResponse($request);
        }

        $runningRun = $this->findActiveSyncRun();

        if ($runningRun !== null) {
            return $this->alreadyRunningResponse($request, $runningRun);
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

    public function backfill(StartBackfillRequest $request, AppSettings $settings): RedirectResponse|JsonResponse
    {
        if (! $this->isProviderConfigured()) {
            return $this->unconfiguredResponse($request);
        }

        $runningRun = $this->findActiveSyncRun();

        if ($runningRun !== null) {
            return $this->alreadyRunningResponse($request, $runningRun);
        }

        $range = is_string($request->validated('range')) ? $request->validated('range') : 'all';
        $timezone = $settings->displayTimezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $cursorDate = $today->format('Y-m-d');
        $untilDate = match ($range) {
            '90d' => $today->subDays(89)->format('Y-m-d'),
            '1y' => $today->subDays(364)->format('Y-m-d'),
            '3y' => $today->subYears(3)->format('Y-m-d'),
            default => null,
        };

        if ($range === 'resume') {
            $latestBackfill = SyncRun::query()
                ->where('type', 'backfill')
                ->orderByDesc('id')
                ->first();

            if (
                $latestBackfill !== null
                && in_array($latestBackfill->status, ['failed', 'running'], true)
                && is_string($latestBackfill->cursor_date)
                && $latestBackfill->cursor_date !== ''
            ) {
                $cursorDate = $latestBackfill->cursor_date;
            }
        }

        $syncRun = SyncRun::query()->create([
            'type' => 'backfill',
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
            'cursor_date' => $cursorDate,
            'items_synced' => 0,
        ]);

        SyncGarminBackfillJob::dispatch(
            syncRunId: $syncRun->id,
            untilDate: $untilDate,
        );

        $syncRun->refresh();
        $message = $range === 'resume'
            ? "Resuming Garmin backfill from {$cursorDate} in the background."
            : 'Garmin historical backfill started in the background.';

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

    private function findActiveSyncRun(): ?SyncRun
    {
        $cutoff = CarbonImmutable::now()->subMinutes(self::CONCURRENT_SYNC_WINDOW_MINUTES);

        return SyncRun::query()
            ->whereIn('status', ['running', 'queued'])
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where('updated_at', '>=', $cutoff)
                    ->orWhere('started_at', '>=', $cutoff);
            })
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    private function unconfiguredResponse(Request $request): RedirectResponse|JsonResponse
    {
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

    private function alreadyRunningResponse(Request $request, SyncRun $runningRun): RedirectResponse|JsonResponse
    {
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
     *     cursor_date: ?string,
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
            'cursor_date' => $latestRun?->cursor_date !== null ? (string) $latestRun->cursor_date : null,
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
