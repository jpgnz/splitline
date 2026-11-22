<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSportsSettingsRequest;
use App\Http\Requests\UpdateUnitsRequest;
use App\Models\SyncRun;
use App\Support\AppSettings;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const int ACTIVE_BACKFILL_WINDOW_MINUTES = 10;

    public function index(AppSettings $settings, SportRegistry $sports): Response
    {
        return Inertia::render('settings/Index', [
            'unit_system' => $settings->unitSystem(),
            'timezone' => $settings->displayTimezone(),
            'provider' => [
                'name' => 'Garmin Connect',
                'configured' => trim((string) config('services.garmin.key', '')) !== '',
            ],
            'backfill' => $this->backfillSummary(),
            'sports_settings' => $sports->settingsPayload(),
        ]);
    }

    public function updateUnits(UpdateUnitsRequest $request, AppSettings $settings): RedirectResponse
    {
        $validated = $request->validated();
        $unitSystem = is_string($validated['unit_system'] ?? null)
            ? $validated['unit_system']
            : AppSettings::UNIT_METRIC;

        $settings->set('unit_system', $unitSystem);

        return back()->with('status', 'Unit preferences updated.');
    }

    public function updateSports(UpdateSportsSettingsRequest $request, SportRegistry $sports): RedirectResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $sports->saveConfig($validated);

        return back()->with('status', 'Sports configuration updated.');
    }

    /**
     * @return array{
     *     last_completed_at: ?string,
     *     can_resume: bool,
     *     resume_cursor_date: ?string,
     *     last_error: ?string
     * }
     */
    private function backfillSummary(): array
    {
        if (! Schema::hasTable('sync_runs')) {
            return [
                'last_completed_at' => null,
                'can_resume' => false,
                'resume_cursor_date' => null,
                'last_error' => null,
            ];
        }

        $latestBackfill = SyncRun::query()
            ->where('type', 'backfill')
            ->orderByDesc('id')
            ->first();

        $lastCompleted = SyncRun::query()
            ->where('type', 'backfill')
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->latest('id')
            ->first();

        $activeCutoff = CarbonImmutable::now()->subMinutes(self::ACTIVE_BACKFILL_WINDOW_MINUTES);
        $isActivelyRunning = $latestBackfill !== null
            && in_array($latestBackfill->status, ['running', 'queued'], true)
            && (
                ($latestBackfill->updated_at !== null && $latestBackfill->updated_at->greaterThanOrEqualTo($activeCutoff))
                || $latestBackfill->started_at->greaterThanOrEqualTo($activeCutoff)
            );

        $canResume = $latestBackfill !== null
            && ! $isActivelyRunning
            && in_array($latestBackfill->status, ['failed', 'running'], true)
            && is_string($latestBackfill->cursor_date)
            && $latestBackfill->cursor_date !== '';

        return [
            'last_completed_at' => $lastCompleted?->finished_at?->toIso8601String(),
            'can_resume' => $canResume,
            'resume_cursor_date' => $canResume ? $latestBackfill->cursor_date : null,
            'last_error' => $latestBackfill?->status === 'failed' ? $latestBackfill->error_message : null,
        ];
    }
}
