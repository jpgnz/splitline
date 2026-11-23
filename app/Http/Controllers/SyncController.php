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
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        if (! $this->isProviderConfigured()) {
            return $this->unconfiguredResponse($request);
        }

        $runningRun = SyncRun::findActive();

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

        $runningRun = SyncRun::findActive();

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
        $initialSetupCompleted = false;

        if ($range === 'resume') {
            $latestBackfill = SyncRun::query()
                ->where('type', 'backfill')
                ->orderByDesc('id')
                ->first();

            if ($latestBackfill !== null && $latestBackfill->isResumableBackfill()) {
                $cursorDate = (string) $latestBackfill->cursor_date;
                $untilDate = $latestBackfill->until_date;
                $initialSetupCompleted = $latestBackfill->initial_setup_completed;
            }
        }

        SyncRun::supersedePendingBackfills();

        $syncRun = SyncRun::query()->create([
            'type' => 'backfill',
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
            'cursor_date' => $cursorDate,
            'until_date' => $untilDate,
            'initial_setup_completed' => $initialSetupCompleted,
            'items_synced' => 0,
        ]);

        SyncGarminBackfillJob::dispatch(
            syncRunId: $syncRun->id,
            untilDate: $untilDate,
            includeInitialSetup: ! $initialSetupCompleted,
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

    public function status(Request $request): JsonResponse
    {
        $runId = $request->query('run_id');
        $targetRun = null;

        if (is_numeric($runId) && (int) $runId > 0) {
            $targetRun = SyncRun::query()->find((int) $runId);
        }

        $targetRun ??= SyncRun::findLatestForStatus();

        return response()->json($this->buildStatusPayload($targetRun));
    }

    private function unconfiguredResponse(Request $request): RedirectResponse|JsonResponse
    {
        $message = 'Garmin API key is not configured. Set GARMIN_KEY in your environment.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                ...$this->buildStatusPayload(SyncRun::findLatestForStatus()),
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
     *     id: ?int,
     *     status: string,
     *     type: ?string,
     *     started_at: ?string,
     *     finished_at: ?string,
     *     cursor_date: ?string,
     *     until_date: ?string,
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
            'id' => $latestRun?->id,
            'status' => $latestRun !== null ? $latestRun->effectiveStatus() : 'idle',
            'type' => $latestRun?->type !== null ? (string) $latestRun->type : null,
            'started_at' => $this->formatTimestamp($latestRun?->started_at),
            'finished_at' => $this->formatTimestamp($latestRun?->finished_at),
            'cursor_date' => $latestRun?->cursor_date !== null ? (string) $latestRun->cursor_date : null,
            'until_date' => $latestRun?->until_date !== null ? (string) $latestRun->until_date : null,
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
