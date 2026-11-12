<?php

namespace Statikbe\FilamentSolaris\Support\Metadata;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Tooltip text for one field's metadata in the preview modal: one escaped
 * `key: value` line per key, joined with <br> so the tooltip keeps real lines.
 * text() gives the same lines as plain text for an aria-label.
 */
final class MetadataTooltip
{
    private const MAX_LENGTH = 150;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function html(array $metadata): HtmlString
    {
        return new HtmlString(implode('<br>', array_map(e(...), self::lines($metadata))));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function text(array $metadata): string
    {
        return implode('; ', self::lines($metadata));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    private static function lines(array $metadata): array
    {
        $lines = [];

        foreach ($metadata as $key => $value) {
            $lines[] = $key.': '.self::format($value);
        }

        return $lines;
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) || is_object($value) => Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::MAX_LENGTH),
            default => Str::limit((string) $value, self::MAX_LENGTH),
        };
    }
}
