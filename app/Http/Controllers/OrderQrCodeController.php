<?php

namespace App\Http\Controllers;

use App\Models\FlowJob;
use App\Services\JobService;
use App\Support\StoredFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderQrCodeController extends Controller
{
    public function download(FlowJob $job): StreamedResponse
    {
        $visibleJob = app(JobService::class)->findVisibleBase(auth()->user(), (int) $job->id);
        $document = $visibleJob->artworkTrackingPdf;

        abort_unless($document, 404, 'The confirmed artwork tracking PDF has not been generated yet.');

        return StoredFileResponse::download(
            (string) $document->path,
            (string) $document->name,
            'application/pdf',
        );
    }
}
