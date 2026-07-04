<?php

namespace Statikbe\FilamentSolaris\Sanitizers;

/**
 * Trims leading/trailing whitespace — common hygiene on AI output that tends to
 * arrive with stray spaces or newlines.
 */
class TrimSanitizer implements Sanitizer
{
    public function sanitize(string $value): string
    {
        return trim($value);
    }
}
