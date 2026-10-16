<?php

namespace Statikbe\FilamentSolaris\Support;

/**
 * Selection rules for a preview modal whose items can be ticked (spec 44).
 *
 * Items are keyed by preview-item key — for AiFormAction the target field
 * name. "Fixed" items are present but not selectable: always written.
 * `selected` comes from the client, so every read treats it as untrusted.
 */
final class PreviewSelection
{
    /**
     * The configured keys that are present among the preview items, in item order.
     *
     * @param  array<int, string>  $itemKeys
     * @param  array<int, string>  $configured
     * @return array<int, string>
     */
    public static function selectable(array $itemKeys, array $configured): array
    {
        return array_values(array_intersect($itemKeys, $configured));
    }

    /**
     * Carry the user's choices over a refinement: a key they unticked stays
     * unticked, a newly selectable key starts ticked, a vanished key drops out.
     *
     * @param  array<int, string>  $previousSelectable
     * @param  array<int, string>  $selectable
     * @return array<int, string>
     */
    public static function reseed(mixed $selected, array $previousSelectable, array $selectable): array
    {
        $selected = is_array($selected) ? $selected : [];

        return array_values(array_filter(
            $selectable,
            fn (string $key): bool => in_array($key, $selected, true) || ! in_array($key, $previousSelectable, true),
        ));
    }

    /**
     * The values to write: every fixed item, plus the selectable items that are
     * ticked. A tick for a key outside `$selectable` is ignored.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $selectable
     * @return array<string, mixed>
     */
    public static function filter(array $values, array $selectable, mixed $selected): array
    {
        $selected = is_array($selected) ? $selected : [];

        return array_filter(
            $values,
            fn (string $key): bool => ! in_array($key, $selectable, true) || in_array($key, $selected, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Whether accepting needs at least one tick — true when every item is
     * selectable, so an empty selection would write nothing.
     *
     * @param  array<int, string>  $itemKeys
     * @param  array<int, string>  $selectable
     */
    public static function requiresSelection(array $itemKeys, array $selectable): bool
    {
        return $selectable !== [] && array_diff($itemKeys, $selectable) === [];
    }
}
