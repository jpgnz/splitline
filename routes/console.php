<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('garmin:sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping();
