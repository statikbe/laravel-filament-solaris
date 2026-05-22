<?php

use Filament\Forms\Components\RichEditor;
use Statikbe\FilamentSolaris\RichEditor\DictationRichEditorPlugin;

function richEditorHasDictationPlugin(RichEditor $editor): bool
{
    // Access the raw $plugins array directly to avoid calling evaluate() which
    // requires a container (unavailable in unit tests with standalone components).
    $reflection = new ReflectionClass($editor);
    $property = $reflection->getProperty('plugins');
    $property->setAccessible(true);
    $plugins = $property->getValue($editor);

    return collect($plugins)
        ->contains(fn ($plugin): bool => $plugin instanceof DictationRichEditorPlugin);
}

it('adds the dictation plugin to every RichEditor when the flag is on', function () {
    config()->set('filament-solaris.transcription.enable_rich_editor_toolbar_btn', true);

    expect(richEditorHasDictationPlugin(RichEditor::make('body')))->toBeTrue();
});

it('does not add the dictation plugin when the flag is off', function () {
    config()->set('filament-solaris.transcription.enable_rich_editor_toolbar_btn', false);

    expect(richEditorHasDictationPlugin(RichEditor::make('body')))->toBeFalse();
});
