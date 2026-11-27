<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompareActivitiesRequest;
use App\Services\Activities\ActivityComparisonService;
use Inertia\Inertia;
use Inertia\Response;

class ActivityComparisonController extends Controller
{
    public function __construct(
        private readonly ActivityComparisonService $comparisonService,
    ) {}

    public function index(CompareActivitiesRequest $request): Response
    {
        return Inertia::render(
            'Activities/Compare',
            $this->comparisonService->compare(
                $request->activityIds(),
                $request->align(),
            ),
        );
    }
}
