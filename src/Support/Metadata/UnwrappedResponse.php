<?php

namespace Statikbe\FilamentSolaris\Support\Metadata;

final readonly class UnwrappedResponse
{
    /**
     * @param  array<string, mixed>  $values  plain values, keyed by field
     * @param  array<string, array<string, mixed>>  $metadata  keyed by field
     */
    public function __construct(
        public array $values,
        public array $metadata,
    ) {}
}
