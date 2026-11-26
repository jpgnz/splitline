<?php

namespace App\Http\Controllers;

use App\Services\Insights\CorrelationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InsightsController extends Controller
{
    public function index(Request $request, CorrelationService $service): Response
    {
        $sport = $request->query('sport');
        $range = $request->query('range');
        $end = $request->query('end');
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render(
            'Insights/Index',
            $service->build(
                sport: is_string($sport) ? $sport : null,
                range: is_string($range) ? $range : null,
                end: is_string($end) ? $end : null,
                from: is_string($from) ? $from : null,
                to: is_string($to) ? $to : null,
            ),
        );
    }
}
