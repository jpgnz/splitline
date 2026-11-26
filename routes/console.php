<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('garmin:sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

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
    ->withoutOverlapping();

Schedule::command('import:watch')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn (): bool => trim((string) config('services.import_watch.dir', '')) !== '');
