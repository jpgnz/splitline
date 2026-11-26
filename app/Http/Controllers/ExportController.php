<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Services\Export\DataExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public function __construct(
        private readonly DataExportService $exportService,
    ) {}

    public function backup(): Response
    {
        try {
            $snapshot = $this->exportService->createSqliteBackupSnapshot();
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()
            ->download($snapshot['path'], $snapshot['filename'], [
                'Content-Type' => 'application/x-sqlite3',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend(true);
    }

    public function activities(Request $request): Response
    {
        $validated = $request->validate([
            'format' => ['sometimes', 'string', 'in:csv,json'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sport' => ['sometimes', 'nullable', 'string', 'max:64'],
            'range' => ['sometimes', 'nullable', 'string', 'max:32'],
            'end' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'pr' => ['sometimes', 'nullable'],
            'favorite' => ['sometimes', 'nullable'],
        ]);

        $format = is_string($validated['format'] ?? null) ? $validated['format'] : 'csv';

        if ($format === 'json') {
            return $this->exportService->streamActivitiesJson($validated);
        }

        return $this->exportService->streamActivitiesCsv($validated);
    }

    public function activityGpx(Activity $activity): Response
    {
        $gpx = $this->exportService->buildActivityGpx($activity);

        if ($gpx === null) {
            abort(404, 'No GPS route data is available for this activity.');
        }

        return response($gpx['xml'], 200, [
            'Content-Type' => 'application/gpx+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$gpx['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function activityOriginal(Activity $activity): Response
    {
        $archive = $this->exportService->resolveOriginalArchive($activity);

        if ($archive === null) {
            abort(404, 'No cached FIT archive is available for this activity.');
        }

        return Storage::disk('local')->download(
            $archive['disk_path'],
            $archive['filename'],
            [
                'Content-Type' => $archive['content_type'],
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
