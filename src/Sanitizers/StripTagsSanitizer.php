<?php

namespace Statikbe\FilamentSolaris\Sanitizers;

/**
 * Removes all HTML tags via strip_tags(). The dependency-free workhorse for
 * plain-text columns — fully closes stored-XSS for values that should carry no
 * markup at all.
 */
class StripTagsSanitizer implements Sanitizer
{
    public function sanitize(string $value): string
    {
        return strip_tags($value);
    }
}
