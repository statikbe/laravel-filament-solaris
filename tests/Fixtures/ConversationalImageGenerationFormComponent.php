<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;

class ConversationalImageGenerationFormComponent extends FormsComponent
{
    use InteractsWithSolarisPreview;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'title' => '',
            'poster' => '',
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('title')->required(),
                TextInput::make('poster'),
            ])
            ->statePath('data');
    }

    public function generatePosterAction(): ImageGenerationAction
    {
        return ImageGenerationAction::make('generatePoster')
            ->prompt('Generate a poster')
            ->sourceFields(['title'])
            ->targetField('poster')
            ->conversational();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
