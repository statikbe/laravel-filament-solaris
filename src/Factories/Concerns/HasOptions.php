<?php

namespace Statikbe\FilamentSolaris\Factories\Concerns;

trait HasOptions
{
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
     * → substring → Levenshtein ≤ 3 → raw value.
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
                return $key;
            }
        }

        // 5. Levenshtein distance ≤ 3
        $fuzzyKey = $this->fuzzyMatch($aiValue, $options);
        if ($fuzzyKey !== null) {
            return $fuzzyKey;
        }

        // 6. No match → return raw value
        return $aiValue;
    }

    /**
     * Perform fuzzy matching using Levenshtein distance.
     *
     * Returns the option key whose label is closest to the given value,
     * provided the distance is within the threshold (≤ 3). Returns null
     * if no match is found within the threshold.
     *
     * @param  array<string|int, string>  $options  Key => label pairs
     */
    protected function fuzzyMatch(string $aiValue, array $options): mixed
    {
        $lowerValue = mb_strtolower($aiValue);
        $bestKey = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($options as $key => $label) {
            $distance = levenshtein($lowerValue, mb_strtolower($label));

            if ($distance === 0) {
                return $key;
            }

            if ($distance <= 3 && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestKey = $key;
            }
        }

        return $bestKey;
    }
}
