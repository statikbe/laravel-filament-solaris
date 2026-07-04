<?php

namespace Statikbe\FilamentSolaris\Sanitizers;

use Closure;

/**
 * The per-field router + executor: holds a default sanitizer plus per-field
 * overrides, and runs the right one over a whole attribute map. This is where the
 * single "only strings" call-site guard lives, so individual sanitizers stay
 * field-agnostic and string-only.
 *
 * Not a pipeline — it routes (a field's sanitizer may itself be a
 * {@see CompositeSanitizer} pipeline). Named for what it does: it executes.
 */
final class SanitizerExecutor
{
    /**
     * @param  array<string, Sanitizer>  $fields
     */
    public function __construct(
        private ?Sanitizer $default = null,
        private array $fields = [],
    ) {}

    /**
     * Build from the public setter union: a closure wraps in {@see CallableSanitizer},
     * an array wraps in {@see CompositeSanitizer}, a Sanitizer passes through.
     *
     * @param  array<string, Closure|Sanitizer|array<int, Closure|Sanitizer>>  $fields
     */
    public static function make(Closure|Sanitizer|array|null $default, array $fields = []): self
    {
        return new self(
            $default === null ? null : self::wrap($default),
            array_map(static fn ($sanitizer): Sanitizer => self::wrap($sanitizer), $fields),
        );
    }

    /**
     * @param  Closure|Sanitizer|array<int, Closure|Sanitizer>  $sanitizer
     */
    public static function wrap(Closure|Sanitizer|array $sanitizer): Sanitizer
    {
        if ($sanitizer instanceof Sanitizer) {
            return $sanitizer;
        }

        if ($sanitizer instanceof Closure) {
            return new CallableSanitizer($sanitizer);
        }

        return new CompositeSanitizer(array_map(static fn ($s): Sanitizer => self::wrap($s), $sanitizer));
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    public function execute(array $attrs): array
    {
        foreach ($attrs as $field => $value) {
            if (! is_string($value)) {
                continue;   // single call-site guard: sanitizers only ever see strings
            }

            $sanitizer = $this->fields[$field] ?? $this->default;

            if ($sanitizer !== null) {
                $attrs[$field] = $sanitizer->sanitize($value);
            }
        }

        return $attrs;
    }

    public function isEmpty(): bool
    {
        return $this->default === null && $this->fields === [];
    }

    /**
     * False if any configured sanitizer is (or contains) a closure — closures can't
     * serialise onto the queue. Used by the runQueued dispatch guard.
     */
    public function isSerializable(): bool
    {
        foreach ([$this->default, ...array_values($this->fields)] as $sanitizer) {
            if ($sanitizer !== null && ! self::sanitizerIsSerializable($sanitizer)) {
                return false;
            }
        }

        return true;
    }

    private static function sanitizerIsSerializable(Sanitizer $sanitizer): bool
    {
        if ($sanitizer instanceof CallableSanitizer) {
            return false;
        }

        if ($sanitizer instanceof CompositeSanitizer) {
            foreach ($sanitizer->sanitizers() as $child) {
                if (! self::sanitizerIsSerializable($child)) {
                    return false;
                }
            }
        }

        return true;
    }
}
