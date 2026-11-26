<?php

namespace App\Http\Controllers;

use App\Services\Recap\AnnualRecapService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecapController extends Controller
{
    public function index(Request $request, AnnualRecapService $service): Response
    {
        $rawYear = $request->query('year');
        $year = is_numeric($rawYear) ? (int) $rawYear : null;

        return Inertia::render('Recap/Index', $service->build($year));
    }
}
