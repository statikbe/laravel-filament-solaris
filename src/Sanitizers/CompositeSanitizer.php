<?php

namespace Statikbe\FilamentSolaris\Sanitizers;

/**
 * A per-value pipeline: runs an ordered list of sanitizers against a single value,
 * feeding each output into the next (e.g. trim → strip_tags). Is itself a
 * {@see Sanitizer}, so it composes anywhere one is accepted; an array passed to a
 * `->sanitize()`/`->sanitizeField()` setter is wrapped into one.
 */
class CompositeSanitizer implements Sanitizer
{
    /** @var array<int, Sanitizer> */
    protected array $sanitizers;

    /**
     * @param  array<int, Sanitizer>  $sanitizers
     */
    public function __construct(array $sanitizers)
    {
        $this->sanitizers = array_values($sanitizers);
    }

    public function sanitize(string $value): string
    {
        foreach ($this->sanitizers as $sanitizer) {
            $value = $sanitizer->sanitize($value);
        }

        return $value;
    }

    /**
     * @return array<int, Sanitizer>
     */
    public function sanitizers(): array
    {
        return $this->sanitizers;
    }
}
