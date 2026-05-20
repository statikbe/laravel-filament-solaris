<?php

namespace Statikbe\FilamentSolaris\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Statikbe\FilamentSolaris\Factories\Concerns\HasOptions;

/**
 * Dispatched when an AI value resolves to a Select/CheckboxList option via an
 * **inexact** strategy — substring containment or Levenshtein fuzzy match.
 *
 * Exact strategies (exact key, exact label, case-insensitive label) are safe
 * and do NOT fire this event. The inexact ones can produce a wrong-but-plausible
 * match (e.g. AI says "Reno", the only close option is "Renault"), so this
 * event exists so apps can detect/sample/alert on potential misclassification
 * in production without enabling verbose prompt logging.
 *
 * The factory has no back-reference to the action, so the payload is scoped to
 * the field + match, not the action name. The field name + ai value is enough
 * to locate the source of a bad match.
 *
 * @see HasOptions::resolveOptionKey()
 */
final class SolarisOptionMatched
{
    use Dispatchable;

    /**
     * @param  string  $field  The Select/CheckboxList field name (or null when unavailable)
     * @param  class-string  $componentClass  The Filament component class
     * @param  string  $aiValue  The raw value the AI returned
     * @param  string|int  $matchedKey  The option key it resolved to
     * @param  string  $matchedLabel  The option label that key maps to
     * @param  'substring'|'fuzzy'  $strategy  Which inexact strategy matched
     * @param  int|null  $distance  Levenshtein distance (fuzzy only; null for substring)
     */
    public function __construct(
        public readonly ?string $field,
        public readonly string $componentClass,
        public readonly string $aiValue,
        public readonly string|int $matchedKey,
        public readonly string $matchedLabel,
        public readonly string $strategy,
        public readonly ?int $distance = null,
    ) {}
}
