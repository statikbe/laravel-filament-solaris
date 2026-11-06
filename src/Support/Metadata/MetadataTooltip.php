<?php

namespace Statikbe\FilamentSolaris\Support\Metadata;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Tooltip text for one field's metadata in the preview modal: one escaped
 * `key: value` line per key, joined with <br> so the tooltip keeps real lines.
 */
final class MetadataTooltip
{
    private const MAX_LENGTH = 150;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function html(array $metadata): HtmlString
    {
        $lines = [];

        foreach ($metadata as $key => $value) {
            $lines[] = e($key.': '.self::format($value));
        }

        return new HtmlString(implode('<br>', $lines));
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) || is_object($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => Str::limit((string) $value, self::MAX_LENGTH),
        };
    }
}
