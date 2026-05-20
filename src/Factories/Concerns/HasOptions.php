<?php

namespace Statikbe\FilamentSolaris\Factories\Concerns;

use Statikbe\FilamentSolaris\Concerns\HasTargetFields;
use Statikbe\FilamentSolaris\Events\SolarisOptionMatched;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;

trait HasOptions
{
    /**
     * Per-factory fuzzy override (null = fall back to config).
     */
    protected ?bool $fuzzyEnabled = null;

    protected ?float $fuzzyThreshold = null;

    /**
     * Enable or disable the Levenshtein fuzzy fallback for this field.
     *
     * Set false on high-stakes Selects where a wrong-but-plausible match is
     * worse than leaving the value unmatched. Applied by the action via
     * {@see HasTargetFields::targetFuzzyMatching()}.
     */
    public function fuzzyMatching(?bool $enabled = true): static
    {
        $this->fuzzyEnabled = $enabled;

        return $this;
    }

    /**
     * Override the fuzzy-match threshold (edit distance as a fraction of the
     * longer string) for this field.
     */
    public function fuzzyThreshold(?float $ratio): static
    {
        $this->fuzzyThreshold = $ratio;

        return $this;
    }

    protected function resolvedFuzzyEnabled(): bool
    {
        return $this->fuzzyEnabled ?? FilamentSolaris::config()->isOptionFuzzyMatchingEnabled();
    }

    protected function resolvedFuzzyThreshold(): float
    {
        return $this->fuzzyThreshold ?? FilamentSolaris::config()->getOptionFuzzyThreshold();
    }

    /**
     * Resolve options from the component.
     *
     * @return array<string|int, string> Key => label pairs
     */
    protected function resolveOptions(): array
    {
        $component = $this->component;

        if (! method_exists($component, 'getOptions')) {
            return [];
        }

        /** @var array<string|int, string> $options */
        $options = $component->getOptions();

        if (! empty($options)) {
            return $options;
        }

        // Try relationship-based options
        if (method_exists($component, 'getRelationship') && $component->getRelationship()) {
            $relationship = $component->getRelationship();
            $query = $relationship->getQuery();

            if ($this->scope) {
                $query = ($this->scope)($query);
            }

            /** @var string $titleAttribute */
            $titleAttribute = $component->getRelationshipTitleAttribute(); // @phpstan-ignore method.notFound

            return $query->pluck($titleAttribute, $query->getModel()->getKeyName())->all();
        }

        return [];
    }

    /**
     * Resolve an AI value to the best matching option key.
     *
     * Uses a 6-step priority chain: exact key → exact label → case-insensitive
     * → substring → length-relative Levenshtein → raw value. The two inexact
     * steps (substring, fuzzy) dispatch {@see SolarisOptionMatched} so apps can
     * detect misclassification in production.
     *
     * @param  array<string|int, string>  $options  Key => label pairs
     */
    protected function resolveOptionKey(mixed $aiValue, array $options): mixed
    {
        if ($aiValue === null || $aiValue === '' || is_array($aiValue)) {
            return $aiValue;
        }

        // 1. Exact key match (before string cast to preserve type)
        if (array_key_exists($aiValue, $options)) {
            return $aiValue;
        }

        $aiValue = (string) $aiValue;

        // Exact key match after string cast (e.g. int key as string)
        if (array_key_exists($aiValue, $options)) {
            return $aiValue;
        }

        // Also check numeric keys (string "42" → int 42)
        if (is_numeric($aiValue) && array_key_exists((int) $aiValue, $options)) {
            return (int) $aiValue;
        }

        // 2. Exact label match (reverse lookup)
        $flipped = array_flip($options);
        if (isset($flipped[$aiValue])) {
            return $flipped[$aiValue];
        }

        // 3. Case-insensitive label match
        $lowerValue = mb_strtolower($aiValue);
        foreach ($options as $key => $label) {
            if (mb_strtolower($label) === $lowerValue) {
                return $key;
            }
        }

        // 4. Substring match
        foreach ($options as $key => $label) {
            if (str_contains(mb_strtolower($label), $lowerValue)) {
                $this->reportInexactMatch($aiValue, $key, $label, 'substring', null);

                return $key;
            }
        }

        // 5. Length-relative Levenshtein fuzzy match
        [$fuzzyKey, $distance] = $this->fuzzyMatch($aiValue, $options);
        if ($fuzzyKey !== null) {
            $this->reportInexactMatch($aiValue, $fuzzyKey, $options[$fuzzyKey], 'fuzzy', $distance);

            return $fuzzyKey;
        }

        // 6. No match → return raw value
        return $aiValue;
    }

    /**
     * Perform fuzzy matching using a length-relative Levenshtein distance.
     *
     * The allowed edit distance scales with the longer string: a short label
     * needs a near-exact match (one edit on "cat" → "car" flips meaning),
     * while a long label tolerates proportionally more typos. Values/labels
     * shorter than the configured minimum are skipped entirely, and the whole
     * step is a no-op when fuzzy matching is disabled for this field.
     *
     * @param  array<string|int, string>  $options  Key => label pairs
     * @return array{0: string|int|null, 1: int|null} [matched key, distance] or [null, null]
     */
    protected function fuzzyMatch(string $aiValue, array $options): array
    {
        if (! $this->resolvedFuzzyEnabled()) {
            return [null, null];
        }

        $lowerValue = mb_strtolower($aiValue);
        $ratio = $this->resolvedFuzzyThreshold();
        $minLength = FilamentSolaris::config()->getOptionFuzzyMinLength();
        $bestKey = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($options as $key => $label) {
            $lowerLabel = mb_strtolower($label);
            $maxLength = max(mb_strlen($lowerValue), mb_strlen($lowerLabel));

            if ($maxLength < $minLength) {
                continue;
            }

            $distance = levenshtein($lowerValue, $lowerLabel);
            $allowed = max(1, (int) floor($maxLength * $ratio));

            if ($distance <= $allowed && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestKey = $key;
            }
        }

        return [$bestKey, $bestKey === null ? null : $bestDistance];
    }

    /**
     * Dispatch a {@see SolarisOptionMatched} event (and dev log) for an inexact
     * resolution so consumers can detect potential misclassification.
     *
     * @param  'substring'|'fuzzy'  $strategy
     */
    protected function reportInexactMatch(
        string $aiValue,
        string|int $matchedKey,
        string $matchedLabel,
        string $strategy,
        ?int $distance,
    ): void {
        $field = method_exists($this->component, 'getName')
            ? $this->component->getName()
            : null;

        SolarisOptionMatched::dispatch(
            $field,
            $this->component::class,
            $aiValue,
            $matchedKey,
            $matchedLabel,
            $strategy,
            $distance,
        );

        SolarisPromptLogger::logOptionMatch($field, $aiValue, $matchedKey, $matchedLabel, $strategy, $distance);
    }
}
