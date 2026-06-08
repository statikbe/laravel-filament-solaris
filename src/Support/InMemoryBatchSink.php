<?php

namespace Statikbe\FilamentSolaris\Support;

use Statikbe\FilamentSolaris\Support\Contracts\BatchSink;

final class InMemoryBatchSink implements BatchSink
{
    protected int $succeeded = 0;

    /** @var array<int, FailedRecord> */
    protected array $failures = [];

    /** @var array<int, DiscardedOutput> */
    protected array $discarded = [];

    public function record(BatchOutcome $outcome): void
    {
        $this->succeeded += $outcome->succeeded;
        $this->failures = array_merge($this->failures, $outcome->failures);
        $this->discarded = array_merge($this->discarded, $outcome->discarded);
    }

    public function succeeded(): int
    {
        return $this->succeeded;
    }

    /** @return array<int, FailedRecord> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return array<int, DiscardedOutput> */
    public function discarded(): array
    {
        return $this->discarded;
    }
}
