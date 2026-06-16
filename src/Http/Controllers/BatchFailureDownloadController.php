<?php

namespace Statikbe\FilamentSolaris\Http\Controllers;

use Illuminate\Http\Request;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchFailureReport;
use Statikbe\FilamentSolaris\Support\Batch\BatchReportFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Signed download endpoint: regenerates a run's failure report on click and streams
 * it (no stored files). Signature is verified by the `signed` middleware; apps that
 * want per-user authorization add their own middleware/gate to the route.
 */
class BatchFailureDownloadController
{
    public function __invoke(Request $request, SolarisBatchRun $run): StreamedResponse
    {
        $format = BatchReportFormat::fromRequest($request->query('format'));
        $report = new BatchFailureReport;

        return response()->streamDownload(
            fn () => $report->write($run, $format),
            "failures-{$run->id}.{$format->extension()}",
            ['Content-Type' => $format->mimeType()],
        );
    }
}
