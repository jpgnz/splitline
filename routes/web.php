<?php

use App\Http\Controllers\ActivityComparisonController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BestEffortController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FitnessController;
use App\Http\Controllers\GearController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HealthMetricController;
use App\Http\Controllers\HeatmapController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\RecapController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SportsController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::redirect('/dashboard', '/');

Route::get('/health/{metric}', [HealthMetricController::class, 'show'])->name('health.show');
Route::get('/fitness', [FitnessController::class, 'index'])->name('fitness.index');
Route::get('/insights', [InsightsController::class, 'index'])->name('insights.index');
Route::get('/recap', [RecapController::class, 'index'])->name('recap.index');

Route::get('/exports/backup', [ExportController::class, 'backup'])->middleware('throttle:6,1')->name('exports.backup');
Route::get('/exports/activities', [ExportController::class, 'activities'])->name('exports.activities');

Route::get('/activities', [ActivityController::class, 'index'])->name('activities.index');
Route::get('/activities/compare', [ActivityComparisonController::class, 'index'])->name('activities.compare');
Route::get('/activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');
Route::get('/activities/{activity}/export/gpx', [ExportController::class, 'activityGpx'])->name('activities.export.gpx');
Route::get('/activities/{activity}/export/original', [ExportController::class, 'activityOriginal'])->name('activities.export.original');
Route::patch('/activities/{activity}/discipline', [ActivityController::class, 'updateDiscipline'])->name('activities.discipline.update');
Route::post('/activities/{activity}/favorite', [ActivityController::class, 'toggleFavorite'])->name('activities.favorite.toggle');
Route::post('/activities/{activity}/sync-streams', [ActivityController::class, 'syncStreams'])->name('activities.streams.sync');
Route::post('/activities/{activity}/sync-weather', [ActivityController::class, 'syncWeather'])->name('activities.weather.sync');
Route::post('/activities/{activity}/exclude', [ActivityController::class, 'exclude'])->name('activities.exclude');
Route::post('/activities/{activity}/restore', [ActivityController::class, 'restore'])->name('activities.restore');
Route::patch('/activities/{activity}/gear', [GearController::class, 'assignToActivity'])->name('activities.gear.assign');

Route::get('/running', [SportsController::class, 'running'])->name('running.index');
Route::get('/running/{discipline}', [SportsController::class, 'runningDiscipline'])->name('running.discipline');
Route::get('/cycling', [SportsController::class, 'cycling'])->name('cycling.index');
Route::get('/cycling/{discipline}', [SportsController::class, 'cyclingDiscipline'])->name('cycling.discipline');
Route::get('/sports/{sport}', [SportsController::class, 'show'])->name('sports.show');
Route::post('/sports/reclassify', [SportsController::class, 'bulkReclassify'])->name('sports.reclassify');
Route::patch('/best-efforts/{bestEffort}', [BestEffortController::class, 'update'])->name('best-efforts.update');

Route::get('/heatmap', [HeatmapController::class, 'index'])->name('heatmap.index');
Route::get('/heatmap/explorer', [HeatmapController::class, 'explorer'])->name('heatmap.explorer');
Route::post('/heatmap/sync-streams', [HeatmapController::class, 'syncStreams'])->name('heatmap.streams.sync');

Route::get('/routes', [RouteController::class, 'index'])->name('routes.index');
Route::post('/routes/scan', [RouteController::class, 'scan'])->name('routes.scan');
Route::get('/routes/{routeCluster}', [RouteController::class, 'show'])->name('routes.show');
Route::patch('/routes/{routeCluster}', [RouteController::class, 'update'])->name('routes.update');
Route::delete('/routes/{routeCluster}/activities/{activity}', [RouteController::class, 'unlinkActivity'])->name('routes.activities.unlink');

Route::get('/segments', [SegmentController::class, 'index'])->name('segments.index');
Route::post('/segments', [SegmentController::class, 'store'])->name('segments.store');
Route::post('/segments/scan', [SegmentController::class, 'scan'])->name('segments.scan');
Route::get('/segments/{segment}', [SegmentController::class, 'show'])->name('segments.show');
Route::patch('/segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
Route::delete('/segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');

Route::get('/goals', [GoalController::class, 'index'])->name('goals.index');
Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');
Route::patch('/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
Route::post('/goals/{goal}/archive', [GoalController::class, 'toggleArchive'])->name('goals.archive');
Route::delete('/goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');

Route::get('/gear', [GearController::class, 'index'])->name('gear.index');
Route::post('/gear', [GearController::class, 'store'])->name('gear.store');
Route::patch('/gear/{gearItem}', [GearController::class, 'update'])->name('gear.update');
Route::post('/gear/{gearItem}/retire', [GearController::class, 'toggleRetire'])->name('gear.retire');
Route::delete('/gear/{gearItem}', [GearController::class, 'destroy'])->name('gear.destroy');

Route::post('/sync', [SyncController::class, 'store'])->name('sync.store');
Route::post('/sync/backfill', [SyncController::class, 'backfill'])->name('sync.backfill');
Route::post('/sync/enrichments', [SyncController::class, 'queueEnrichments'])->name('sync.enrichments');
Route::get('/sync/status', [SyncController::class, 'status'])->name('sync.status');

require __DIR__.'/settings.php';
