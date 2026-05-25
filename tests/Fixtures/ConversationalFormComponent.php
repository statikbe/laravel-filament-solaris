<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;

class ConversationalFormComponent extends FormsComponent
{
    use InteractsWithSolarisPreview;

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Mount the component with initial form data.
     */
    public function mount(): void
    {
        $this->form->fill([
            'title' => '',
            'body' => '',
            'summary' => '',
            'category' => null,
        ]);
    }

    /**
     * Define the form schema.
     */
    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('body')
                    ->required(),
                Textarea::make('summary'),
                Select::make('category')
                    ->options([
                        'tech' => 'Technology',
                        'science' => 'Science',
                        'art' => 'Art',
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * AI action with conversational refinement.
     */
    public function generateSummaryAction(): AiFormAction
    {
        return AiFormAction::make('generateSummary')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize the content in one sentence.')
            ->conversational();
    }

    /**
     * AI action with conversational refinement and multiple targets.
     */
    public function generateAllAction(): AiFormAction
    {
        return AiFormAction::make('generateAll')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category'])
            ->prompt('Analyze and classify.')
            ->conversational();
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
