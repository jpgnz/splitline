<?php

namespace App\Http\Controllers;

use App\Services\Activities\ActivityComparisonService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityComparisonController extends Controller
{
    public function __construct(
        private readonly ActivityComparisonService $comparisonService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render(
            'Activities/Compare',
            $this->comparisonService->compare(
                $request->query('ids'),
                $request->query('align', 'distance'),
            ),
        );
    }
}
