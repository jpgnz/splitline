<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBestEffortRequest;
use App\Models\BestEffort;
use Illuminate\Http\RedirectResponse;

class BestEffortController extends Controller
{
    public function update(UpdateBestEffortRequest $request, BestEffort $bestEffort): RedirectResponse
    {
        $validated = $request->validated();

        /** @var array<string, float|bool|null> $updates */
        $updates = [];

        if (array_key_exists('override_seconds', $validated)) {
            $override = $validated['override_seconds'];
            $updates['override_seconds'] = is_numeric($override) ? (float) $override : null;
        }

        if (array_key_exists('is_excluded', $validated)) {
            $updates['is_excluded'] = (bool) $validated['is_excluded'];
        }

        if ($updates !== []) {
            $bestEffort->update($updates);
        }

        return back()->with('status', 'Best effort updated.');
    }
}
