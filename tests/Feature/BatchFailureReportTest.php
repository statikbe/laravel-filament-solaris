<?php

use OpenSpout\Reader\XLSX\Reader as XlsxReader;
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

function runWithProblems(): SolarisBatchRun
{
    $run = SolarisBatchRun::create(['action_name' => 'x', 'status' => BatchRunStatus::Completed]);
    SolarisBatchProblem::create(['batch_run_id' => $run->id, 'type' => 'failure', 'identifier' => '7', 'reason' => 'boom', 'input' => ['name' => 'A']]);
    SolarisBatchProblem::create(['batch_run_id' => $run->id, 'type' => 'discard', 'identifier' => null, 'reason' => 'dropped', 'input' => ['_index' => 9]]);

    return $run;
}

it('writes a CSV with a header and a row per problem', function () {
    $run = runWithProblems();
    $path = tempnam(sys_get_temp_dir(), 'rep').'.csv';

    (new BatchFailureReport)->write($run, BatchReportFormat::Csv, $path);

    $csv = file_get_contents($path);
    expect($csv)->toContain('identifier,type,reason,input')
        ->and($csv)->toContain('7,failure,boom')
        ->and($csv)->toContain('dropped');
    @unlink($path);
});

it('writes a readable XLSX', function () {
    $run = runWithProblems();
    $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';

    (new BatchFailureReport)->write($run, BatchReportFormat::Xlsx, $path);

    $reader = new XlsxReader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();

    expect($rows[0])->toBe(['identifier', 'type', 'reason', 'input'])
        ->and($rows)->toHaveCount(3);
    @unlink($path);
});

it('only includes the given run\'s problems', function () {
    $run = runWithProblems();
    $other = SolarisBatchRun::create(['action_name' => 'y', 'status' => BatchRunStatus::Completed]);
    SolarisBatchProblem::create(['batch_run_id' => $other->id, 'type' => 'failure', 'reason' => 'nope', 'input' => null]);

    $path = tempnam(sys_get_temp_dir(), 'rep').'.csv';
    (new BatchFailureReport)->write($run, BatchReportFormat::Csv, $path);

    expect(file_get_contents($path))->not->toContain('nope');
    @unlink($path);
});
