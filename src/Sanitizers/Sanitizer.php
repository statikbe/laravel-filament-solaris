<?php

namespace Statikbe\FilamentSolaris\Sanitizers;

/**
 * Transforms a single AI-generated string value into a safe/clean form before it
 * is written back (to a record column or a form field). Pure and context-free:
 * field routing and the "only strings" guard live at the call site
 * ({@see SanitizerExecutor}), so an implementation only ever sees a string.
 */
interface Sanitizer
{
    public function sanitize(string $value): string;
}
