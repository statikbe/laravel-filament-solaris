<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use Illuminate\Support\Facades\URL;
use OpenSpout\Common\Entity\Row;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;

/**
 * Streams a batch run's solaris_batch_problems as a CSV/XLSX report via openspout.
 * Generate-on-demand (no stored files); the query is chunked so large runs stream.
 */
final class BatchFailureReport
{
    private const HEADER = ['identifier', 'type', 'reason', 'input'];

    public const ROUTE = 'filament-solaris.batch-failures.download';

    public function write(SolarisBatchRun $run, BatchReportFormat $format, string $target = 'php://output'): void
    {
        $writer = $format->writer();
        $writer->openToFile($target);
        $writer->addRow(Row::fromValues(self::HEADER));

        $run->problems()
            ->orderBy('id')
            ->lazy()
            ->each(function (SolarisBatchProblem $problem) use ($writer): void {
                $writer->addRow(Row::fromValues([
                    self::neutralize((string) ($problem->identifier ?? '')),
                    self::neutralize($problem->type),
                    self::neutralize($problem->reason),
                    self::neutralize($problem->input === null ? '' : json_encode($problem->input)),
                ]));
            });

        $writer->close();
    }

    /**
     * Defuse CSV/spreadsheet formula injection: a cell starting with a formula
     * trigger (= + - @, tab, CR) is prefixed with a single quote so Excel/Sheets
     * render it as literal text rather than evaluating it. The values here are
     * model- and source-derived, so they're untrusted.
     */
    private static function neutralize(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$value
            : $value;
    }

    /**
     * Signed, non-expiring download URL for a run + format (lives in a per-user
     * notification, so no expiry needed).
     */
    public static function downloadUrl(string $runId, BatchReportFormat $format): string
    {
        return URL::signedRoute(self::ROUTE, ['run' => $runId, 'format' => $format->value]);
    }
}
