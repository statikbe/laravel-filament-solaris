<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Statikbe\FilamentSolaris\Support\Batch\BatchCompletionHandler;
use Statikbe\FilamentSolaris\Support\Batch\BatchSummary;

class ThrowingHandler implements BatchCompletionHandler
{
    public function handle(BatchSummary $summary): void
    {
        throw new \RuntimeException('handler boom');
    }
}
