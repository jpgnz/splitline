<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSegmentRequest;
use App\Http\Requests\UpdateSegmentRequest;
use App\Jobs\RefreshSubClimbFlagsJob;
use App\Jobs\ScanActivitySegmentsJob;
use App\Models\Activity;
use App\Models\Segment;
use App\Services\Segments\SegmentMatchingService;
use App\Services\Segments\SegmentQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SegmentController extends Controller
{
    private const int SYNC_SCAN_LIMIT = 10;

    public function index(Request $request, SegmentQueryService $service): Response
    {
        return Inertia::render(
            'Segments/Index',
            $service->index($request->only(['kind', 'sport', 'category', 'search', 'sort'])),
        );
    }

    public function show(Request $request, Segment $segment, SegmentQueryService $service): Response
    {
        $rawYear = $request->query('year');
        $year = is_string($rawYear) && ctype_digit($rawYear) ? (int) $rawYear : null;
        $rawSport = $request->query('sport');
        $sport = is_string($rawSport) ? $rawSport : null;

        return Inertia::render(
            'Segments/Show',
            $service->show($segment, $year, $sport),
        );
    }

    public function store(StoreSegmentRequest $request, SegmentMatchingService $matcher): RedirectResponse
    {
        $validated = $request->validated();
        $activity = Activity::query()->findOrFail((int) $validated['activity_id']);
        $startIndex = (int) $validated['start_index'];
        $endIndex = (int) $validated['end_index'];
        $name = isset($validated['name']) && is_string($validated['name']) ? $validated['name'] : null;

        try {
            $segment = $matcher->createCustomSegment($activity, $startIndex, $endIndex, $name);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'start_index' => $exception->getMessage(),
            ]);
        }

        return to_route('segments.show', $segment)->with(
            'status',
            "Custom segment \"{$segment->displayName()}\" created ({$segment->effort_count} effort(s) matched).",
        );
    }

    public function update(UpdateSegmentRequest $request, Segment $segment): RedirectResponse
    {
        $validated = $request->validated();
        $updates = [];
        $messages = [];

        if (array_key_exists('name_override', $validated)) {
            $rawName = $validated['name_override'];
            $trimmed = is_string($rawName) ? trim($rawName) : '';
            $updates['name_override'] = $trimmed !== '' ? $trimmed : null;
            $messages[] = $trimmed !== '' ? 'Segment name updated.' : 'Segment name reset to auto-detected name.';
        }

        if (array_key_exists('is_starred', $validated)) {
            $starred = (bool) $validated['is_starred'];
            $updates['is_starred'] = $starred;
            $messages[] = $starred ? 'Segment starred.' : 'Segment unstarred.';
        }

        if ($updates !== []) {
            $segment->update($updates);
        }

        return back()->with('status', implode(' ', $messages) ?: 'Segment updated.');
    }

    /**
     * Custom segments are deleted outright. Auto-detected climbs are dismissed (soft-deleted) instead so
     * later scans still deduplicate against the hill and never re-create it.
     */
    public function destroy(Segment $segment): RedirectResponse
    {
        $label = $segment->displayName();

        if ($segment->kind !== Segment::KIND_CLIMB) {
            $segment->forceDelete();

            return to_route('segments.index')->with('status', "Segment \"{$label}\" deleted.");
        }

        DB::transaction(function () use ($segment): void {
            $segment->efforts()->delete();
            $segment->delete();
        });

        RefreshSubClimbFlagsJob::dispatch($segment->sport_category);

        return to_route('segments.index')->with(
            'status',
            "Climb \"{$label}\" removed. It will not be re-detected by future scans.",
        );
    }

    public function scan(SegmentMatchingService $matcher): RedirectResponse
    {
        $pending = $matcher->unscannedCount();

        if ($pending === 0) {
            return back()->with('status', 'All outdoor streamed activities have already been scanned for climbs & segments.');
        }

        if ($pending > self::SYNC_SCAN_LIMIT) {
            ScanActivitySegmentsJob::dispatch(rebuild: false);

            return back()->with(
                'status',
                "Queued background climb & segment scan for {$pending} activities. Refresh shortly to see results.",
            );
        }

        $result = $matcher->scanUnscanned();

        if ($result['scanned'] === 0) {
            return back()->with('status', 'All outdoor streamed activities have already been scanned for climbs & segments.');
        }

        return back()->with(
            'status',
            "Scanned {$result['scanned']} activity(ies); {$result['climbs_created']} climb(s) detected and {$result['efforts_recorded']} effort(s) recorded.",
        );
    }
}
