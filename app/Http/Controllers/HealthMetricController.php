<?php

namespace App\Http\Controllers;

use App\Services\Health\HealthMetricService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HealthMetricController extends Controller
{
    public function show(Request $request, string $metric, HealthMetricService $service): Response
    {
        if (! $service->isSupported($metric)) {
            abort(404);
        }

        $range = $request->query('range');
        $end = $request->query('end');
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render(
            'Health/Show',
            $service->forMetric(
                $metric,
                is_string($range) ? $range : null,
                is_string($end) ? $end : null,
                is_string($from) ? $from : null,
                is_string($to) ? $to : null,
            ),
        );
    }
}
