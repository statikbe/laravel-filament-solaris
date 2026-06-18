<?php

use Statikbe\FilamentSolaris\Enums\BatchRunStatus;
use Statikbe\FilamentSolaris\Models\SolarisBatchProblem;
use Statikbe\FilamentSolaris\Models\SolarisBatchRun;
use Statikbe\FilamentSolaris\Support\Batch\BatchFailureReport;
use Statikbe\FilamentSolaris\Support\Batch\BatchReportFormat;

beforeEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        $migration = include $file;
        $migration->down();   // drop any table leaked by an earlier test (idempotent)
        $migration->up();
    }
});

afterEach(function () {
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $file) {
        (include $file)->down();
    }
});

function downloadableRun(): SolarisBatchRun
{
    $run = SolarisBatchRun::create(['action_name' => 'x', 'status' => BatchRunStatus::Completed]);
    SolarisBatchProblem::create(['batch_run_id' => $run->id, 'type' => 'failure', 'identifier' => '7', 'reason' => 'boom', 'input' => ['name' => 'A']]);

    return $run;
}

it('streams a CSV download from a signed URL', function () {
    $run = downloadableRun();

    $this->get(BatchFailureReport::downloadUrl($run->id, BatchReportFormat::Csv))
        ->assertOk()
        ->assertDownload("failures-{$run->id}.csv");
});

it('switches to xlsx via the format param', function () {
    $run = downloadableRun();

    $this->get(BatchFailureReport::downloadUrl($run->id, BatchReportFormat::Xlsx))
        ->assertOk()
        ->assertDownload("failures-{$run->id}.xlsx");
});

it('rejects an unsigned request', function () {
    $run = downloadableRun();

    $this->get(route(BatchFailureReport::ROUTE, ['run' => $run->id]))->assertForbidden();
});

it('404s for a missing run', function () {
    $this->get(BatchFailureReport::downloadUrl('00000000-0000-0000-0000-000000000000', BatchReportFormat::Csv))
        ->assertNotFound();
});
