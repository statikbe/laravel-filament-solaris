<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\RichEditor\DictationRichEditorPlugin;

class DictationToolbarFormComponent extends FormsComponent
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'body' => '',
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                RichEditor::make('body')
                    ->plugins([
                        DictationRichEditorPlugin::make(),
                    ]),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
