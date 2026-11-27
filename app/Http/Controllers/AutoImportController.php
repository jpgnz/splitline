<?php

namespace App\Http\Controllers;

use App\Services\Ingestion\AutoImportService;
use Illuminate\Http\RedirectResponse;

class AutoImportController extends Controller
{
    public function scan(AutoImportService $autoImport): RedirectResponse
    {
        $directory = $autoImport->configuredDirectory();

        if ($directory === null) {
            return back()->with('status', 'Auto-import folder is not configured. Set AUTO_IMPORT_DIR in your environment.');
        }

        if (! is_dir($directory)) {
            return back()->with('status', "Auto-import folder does not exist: {$directory}");
        }

        if (! is_readable($directory)) {
            return back()->with('status', "Auto-import folder is not readable: {$directory}");
        }

        $result = $autoImport->scan();

        if ($result['busy']) {
            return back()->with('status', 'An auto-import folder scan is already running. Try again in a moment.');
        }

        $message = sprintf(
            'Scanned import folder: %d imported, %d skipped, %d failed.',
            $result['created'],
            $result['skipped'],
            $result['failed'],
        );

        if ($result['deferred_recent'] > 0) {
            $message .= sprintf(
                ' %d %s still settling and will be imported on the next scan.',
                $result['deferred_recent'],
                $result['deferred_recent'] === 1 ? 'file is' : 'files are',
            );
        }

        return back()->with('status', $message);
    }
}
