<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\RichEditor\DictationRichEditorPlugin;

/**
 * Fixture used by DictationToolbarGlobalConfigTest to verify that the global
 * flag + per-instance opt-out interplay works correctly.
 *
 * - `bodyWithOptOut` has a per-instance `->visible(false)` plugin which should
 *   suppress the button even when the global flag is on.
 * - `bodyPlain` has no per-instance plugin; the global flag drives visibility.
 */
class DictationToolbarInterplayFormComponent extends FormsComponent
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'bodyWithOptOut' => '',
            'bodyPlain' => '',
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                RichEditor::make('bodyWithOptOut')
                    ->plugins([
                        DictationRichEditorPlugin::make()->visible(false),
                    ]),
                RichEditor::make('bodyPlain'),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
