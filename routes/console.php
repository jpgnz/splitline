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
