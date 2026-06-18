<?php

namespace Statikbe\FilamentSolaris\Support\Batch;

use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

enum BatchReportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Csv;
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function writer(): WriterInterface
    {
        return match ($this) {
            self::Csv => new CsvWriter,
            self::Xlsx => new XlsxWriter,
        };
    }
}
