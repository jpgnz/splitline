<?php

namespace App\Http\Controllers;

use App\Services\Fitness\TrainingLoadService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FitnessController extends Controller
{
    public function index(Request $request, TrainingLoadService $service): Response
    {
        $sport = $request->query('sport');
        $range = $request->query('range');
        $end = $request->query('end');
        $from = $request->query('from');
        $to = $request->query('to');
        $projectionDays = $request->query('projection_days');

        $parsedProjectionDays = is_numeric($projectionDays) ? (int) $projectionDays : 14;

        return Inertia::render(
            'Fitness/Index',
            $service->build(
                sport: is_string($sport) ? $sport : null,
                range: is_string($range) ? $range : null,
                end: is_string($end) ? $end : null,
                from: is_string($from) ? $from : null,
                to: is_string($to) ? $to : null,
                projectionDays: $parsedProjectionDays,
            ),
        );
    }
}
