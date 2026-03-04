<?php

if (! function_exists('filament_solaris_trans')) {
    /**
     * Translate a filament-solaris key.
     *
     * @param  array<string, mixed>  $replace
     */
    /**
     * @return ($replace is non-empty-array ? string : string|array<string, string>)
     */
    function filament_solaris_trans(string $key, array $replace = []): string|array
    {
        return __('filament-solaris::filament-solaris.'.$key, $replace);
    }
}

if (! function_exists('filament_solaris_trans_choice')) {
    /**
     * Translate a filament-solaris key with pluralization.
     *
     * @param  array<string, mixed>  $replace
     */
    function filament_solaris_trans_choice(string $key, int $number, array $replace = []): string
    {
        return trans_choice('filament-solaris::filament-solaris.'.$key, $number, $replace);
    }
}
