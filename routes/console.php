<?php

use App\Services\Export\DataExportService;
use Illuminate\Support\Facades\Schedule;

Schedule::command('garmin:sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->when(fn (): bool => trim((string) config('services.garmin.key', '')) !== '');

Schedule::command('routes:match --queue')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('segments:scan --queue')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('activities:compute-derived-metrics --queue')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('geocode:sync --queue')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('services.nominatim.enabled', true));

Schedule::command('import:scan')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn (): bool => trim((string) config('services.auto_import.dir', '')) !== '');

Schedule::call(fn (DataExportService $exports): int => $exports->pruneStaleBackupSnapshots())
    ->name('exports:prune-backup-snapshots')
    ->hourly()
    ->withoutOverlapping();
