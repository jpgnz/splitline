<?php

use App\Http\Controllers\ImportWatchController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::patch('/settings/units', [SettingsController::class, 'updateUnits'])->name('settings.units.update');
Route::patch('/settings/sports', [SettingsController::class, 'updateSports'])->name('settings.sports.update');
Route::patch('/settings/health-sources', [SettingsController::class, 'updateHealthSources'])->name('settings.health-sources.update');
Route::patch('/settings/segments', [SettingsController::class, 'updateSegments'])->name('settings.segments.update');
Route::post('/settings/import-watch/scan', [ImportWatchController::class, 'scan'])->name('settings.import-watch.scan');

Route::redirect('/settings/appearance', '/settings')->name('appearance.edit');
