<?php

namespace App\Http\Controllers;

use Aldeebhasan\Panorama\History\RecordedRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadReportRunController extends Controller
{
    public function __invoke(Request $request, RecordedRun $run): StreamedResponse
    {
        // Stored files belong to the user who ran them, whoever else can see the report.
        abort_unless((string) $run->user_id === (string) $request->user()->getAuthIdentifier(), 403);

        $disk = Storage::disk(config('panorama.storage.disk'));
        abort_if($run->path === null || ! $disk->exists($run->path), 404);

        return $disk->download($run->path, $run->filename);
    }
}
