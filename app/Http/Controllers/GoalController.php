<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
use App\Models\Goal;
use App\Services\Goals\GoalProgressService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function index(GoalProgressService $service): Response
    {
        return Inertia::render('Goals/Index', $service->dashboardData());
    }

    public function store(StoreGoalRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $period = is_string($validated['period'] ?? null) ? $validated['period'] : 'weekly';
        $year = $period === 'yearly' && is_numeric($validated['year'] ?? null)
            ? (int) $validated['year']
            : null;

        Goal::query()->create([
            'title' => is_string($validated['title'] ?? null) ? trim($validated['title']) : '',
            'sport' => is_string($validated['sport'] ?? null) ? $validated['sport'] : 'all',
            'metric' => is_string($validated['metric'] ?? null) ? $validated['metric'] : 'distance_km',
            'period' => $period,
            'target_value' => is_numeric($validated['target_value'] ?? null) ? (float) $validated['target_value'] : 0.0,
            'year' => $year,
            'is_active' => true,
        ]);

        return back()->with('status', 'Goal created.');
    }

    public function update(UpdateGoalRequest $request, Goal $goal): RedirectResponse
    {
        $validated = $request->validated();
        $period = is_string($validated['period'] ?? null) ? $validated['period'] : $goal->period;
        $year = $period === 'yearly' && is_numeric($validated['year'] ?? null)
            ? (int) $validated['year']
            : null;

        $goal->update([
            'title' => is_string($validated['title'] ?? null) ? trim($validated['title']) : $goal->title,
            'sport' => is_string($validated['sport'] ?? null) ? $validated['sport'] : $goal->sport,
            'metric' => is_string($validated['metric'] ?? null) ? $validated['metric'] : $goal->metric,
            'period' => $period,
            'target_value' => is_numeric($validated['target_value'] ?? null)
                ? (float) $validated['target_value']
                : $goal->target_value,
            'year' => $year,
        ]);

        return back()->with('status', 'Goal updated.');
    }

    public function toggleArchive(Goal $goal): RedirectResponse
    {
        $goal->is_active = ! $goal->is_active;
        $goal->save();

        return back()->with('status', $goal->is_active ? 'Goal restored.' : 'Goal archived.');
    }

    public function destroy(Goal $goal): RedirectResponse
    {
        $goal->delete();

        return back()->with('status', 'Goal deleted.');
    }
}
