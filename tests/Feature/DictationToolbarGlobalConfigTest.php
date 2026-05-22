<?php

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\ToolbarButtonGroup;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\RichEditor\DictationRichEditorPlugin;
use Statikbe\FilamentSolaris\Tests\Fixtures\DictationToolbarInterplayFormComponent;

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

// ──────────────────────────────────────────────────────────────
//  Toolbar button interplay — global flag + per-instance opt-out
//
//  getToolbarButtons() needs a mounted Schema container, so these tests mount
//  the fixture via Livewire and resolve editor instances from the form.
// ──────────────────────────────────────────────────────────────

/**
 * Recursively collect all toolbar button name strings from the structure
 * returned by RichEditor::getToolbarButtons().
 *
 * The outer array contains groups; each group is an array of items that are
 * either a plain string (button name) or a ToolbarButtonGroup whose
 * getButtons() returns an array<string> of names.
 *
 * @param  array<array<string|ToolbarButtonGroup>>  $items
 * @return array<string>
 */
function collectToolbarButtonNames(array $items): array
{
    $names = [];

    foreach ($items as $group) {
        if (! is_array($group)) {
            // Scalar at the top level (shouldn't normally occur, but handle it)
            if (is_string($group)) {
                $names[] = $group;
            }

            continue;
        }

        foreach ($group as $item) {
            if (is_string($item)) {
                $names[] = $item;
            } elseif ($item instanceof ToolbarButtonGroup) {
                foreach ($item->getButtons() as $button) {
                    if (is_string($button)) {
                        $names[] = $button;
                    }
                }
            }
        }
    }

    return $names;
}

it('omits the toolbar button on an editor that opts out while the global flag is on', function () {
    config()->set('filament-solaris.transcription.enable_rich_editor_toolbar_btn', true);

    $livewire = Livewire::test(DictationToolbarInterplayFormComponent::class);
    /** @var DictationToolbarInterplayFormComponent $instance */
    $instance = $livewire->instance();

    /** @var RichEditor $editor */
    $editor = $instance->form->getFlatComponents(withHidden: true)['bodyWithOptOut'];

    expect(collectToolbarButtonNames($editor->getToolbarButtons()))
        ->not->toContain('solarisDictation');
});

it('includes the toolbar button on a plain editor while the global flag is on', function () {
    config()->set('filament-solaris.transcription.enable_rich_editor_toolbar_btn', true);

    $livewire = Livewire::test(DictationToolbarInterplayFormComponent::class);
    /** @var DictationToolbarInterplayFormComponent $instance */
    $instance = $livewire->instance();

    /** @var RichEditor $editor */
    $editor = $instance->form->getFlatComponents(withHidden: true)['bodyPlain'];

    expect(collectToolbarButtonNames($editor->getToolbarButtons()))
        ->toContain('solarisDictation');
});
