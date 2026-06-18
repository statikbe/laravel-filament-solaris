<?php

use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Statikbe\FilamentSolaris\Support\Batch\BatchReportFormat;

it('maps to extension, mime and writer', function () {
    expect(BatchReportFormat::Csv->extension())->toBe('csv')
        ->and(BatchReportFormat::Csv->mimeType())->toBe('text/csv')
        ->and(BatchReportFormat::Csv->writer())->toBeInstanceOf(CsvWriter::class)
        ->and(BatchReportFormat::Xlsx->extension())->toBe('xlsx')
        ->and(BatchReportFormat::Xlsx->writer())->toBeInstanceOf(XlsxWriter::class);
});

it('fromRequest defaults to Csv for absent/unknown values', function () {
    expect(BatchReportFormat::fromRequest(null))->toBe(BatchReportFormat::Csv)
        ->and(BatchReportFormat::fromRequest('bogus'))->toBe(BatchReportFormat::Csv)
        ->and(BatchReportFormat::fromRequest('xlsx'))->toBe(BatchReportFormat::Xlsx);
});
