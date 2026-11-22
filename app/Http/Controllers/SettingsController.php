<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSportsSettingsRequest;
use App\Http\Requests\UpdateUnitsRequest;
use App\Support\AppSettings;
use App\Support\SportRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(AppSettings $settings, SportRegistry $sports): Response
    {
        return Inertia::render('settings/Index', [
            'unit_system' => $settings->unitSystem(),
            'timezone' => $settings->displayTimezone(),
            'provider' => [
                'name' => 'Garmin Connect',
                'configured' => trim((string) config('services.garmin.key', '')) !== '',
            ],
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
}
