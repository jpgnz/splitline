<?php

namespace App\Http\Controllers;

use App\Services\Health\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboardService): Response
    {
        $date = $request->query('date');

        return Inertia::render(
            'Dashboard',
            $dashboardService->forDate(is_string($date) ? $date : null),
        );
    }
}
