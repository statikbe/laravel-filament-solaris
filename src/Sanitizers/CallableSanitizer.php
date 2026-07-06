<?php

namespace Statikbe\FilamentSolaris\Sanitizers;

use Closure;

/**
 * Adapts a `Closure(string): string` to the {@see Sanitizer} interface, so a
 * closure and a Sanitizer are interchangeable at the `->sanitize()` /
 * `->sanitizeField()` setters. A closure sanitizer is inline-only — it cannot
 * serialise onto the queue (see the runQueued guard).
 */
class CallableSanitizer implements Sanitizer
{
    /** @var Closure(string): string */
    protected Closure $callback;

    /**
     * @param  Closure(string): string  $callback
     */
    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    public function sanitize(string $value): string
    {
        return ($this->callback)($value);
    }
}
