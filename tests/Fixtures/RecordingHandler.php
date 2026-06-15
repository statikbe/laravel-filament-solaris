<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Statikbe\FilamentSolaris\Support\Batch\BatchCompletionHandler;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;

class RecordingHandler implements BatchCompletionHandler
{
    /** @var array<int, BatchSummary> */
    public static array $received = [];

    public function handle(BatchSummary $summary): void
    {
        self::$received[] = $summary;
    }
}
