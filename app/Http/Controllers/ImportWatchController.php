<?php

namespace App\Http\Controllers;

use App\Services\Ingestion\ImportWatchService;
use Illuminate\Http\RedirectResponse;

class ImportWatchController extends Controller
{
    public function scan(ImportWatchService $watcher): RedirectResponse
    {
        $directory = $watcher->configuredDirectory();

        if ($directory === null) {
            return back()->with('status', 'Import watch folder is not configured. Set IMPORT_WATCH_DIR in your environment.');
        }

        if (! is_dir($directory)) {
            return back()->with('status', "Import watch folder does not exist: {$directory}");
        }

        if (! is_readable($directory)) {
            return back()->with('status', "Import watch folder is not readable: {$directory}");
        }

        $result = $watcher->scan(minFileAgeSeconds: 0);

        $message = sprintf(
            'Scanned import folder: %d imported, %d skipped, %d failed.',
            $result['created'],
            $result['skipped'],
            $result['failed'],
        );

        return back()->with('status', $message);
    }
}
