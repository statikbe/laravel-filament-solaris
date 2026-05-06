<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\AiAction;

class SpatieAttachmentFormComponent extends FormsComponent
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'title' => '',
            'reference_image' => null,
            'summary' => '',
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('title')->required(),
                SpatieMediaLibraryFileUpload::make('reference_image')->image(),
                Textarea::make('summary'),
            ])
            ->statePath('data');
    }

    public function summarizeWithSpatieAction(): AiAction
    {
        return AiAction::make('summarizeWithSpatie')
            ->sourceFields(['title'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->attachmentField('reference_image');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
