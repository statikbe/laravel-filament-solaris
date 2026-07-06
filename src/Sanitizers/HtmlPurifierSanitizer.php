<?php

namespace Statikbe\FilamentSolaris\Sanitizers;

use Closure;
use Mews\Purifier\Facades\Purifier;
use RuntimeException;

/**
 * Safe-HTML sanitizer for rich-text columns — keeps allowed formatting, strips
 * scripts and dangerous attributes. Thin wrapper over a purifier library; the lib
 * is an **optional** dependency (not required by the package). Pass a custom
 * `purify` callback, or install `mews/purifier` and let it resolve the default.
 *
 * A default instance (no callback) serialises fine onto the queue; the purifier is
 * resolved at run time on the worker.
 */
class HtmlPurifierSanitizer implements Sanitizer
{
    /** @var ?Closure(string): string */
    protected ?Closure $purify;

    /**
     * @param  ?Closure(string): string  $purify
     */
    public function __construct(?Closure $purify = null)
    {
        $this->purify = $purify;
    }

    public function sanitize(string $value): string
    {
        return ($this->purify ?? $this->defaultPurifier())($value);
    }

    /**
     * @return Closure(string): string
     */
    protected function defaultPurifier(): Closure
    {
        if (! class_exists(Purifier::class)) {
            throw new RuntimeException(
                'HtmlPurifierSanitizer needs a purifier library. Run `composer require mews/purifier`, or pass a custom purify callback to the constructor.'
            );
        }

        return static fn (string $value): string => Purifier::clean($value);
    }
}
