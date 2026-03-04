<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Support\UserInput;

class AiFormComponent extends FormsComponent
{
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
            'is_featured' => false,
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
                Toggle::make('is_featured'),
            ])
            ->statePath('data');
    }

    /**
     * AI action that generates a summary from title and body.
     */
    public function generateSummaryAction(): AiAction
    {
        return AiAction::make('generateSummary')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize the content in one sentence.');
    }

    /**
     * AI action that fills multiple target fields.
     */
    public function generateAllAction(): AiAction
    {
        return AiAction::make('generateAll')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category', 'is_featured'])
            ->prompt('Analyze the content and fill in the summary, category, and featured flag.');
    }

    /**
     * AI action with source scope that transforms the body to uppercase.
     */
    public function generateWithSourceScopeAction(): AiAction
    {
        return AiAction::make('generateWithSourceScope')
            ->sourceFields(['title', 'body'])
            ->sourceScope('body', fn ($value) => mb_strtoupper($value))
            ->targetField('summary')
            ->prompt('Summarize.');
    }

    /**
     * AI action with a locale override.
     */
    public function generateWithLocaleAction(): AiAction
    {
        return AiAction::make('generateWithLocale')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->locale('nl');
    }

    /**
     * AI action with custom user input.
     */
    public function generateWithUserInputAction(): AiAction
    {
        return AiAction::make('generateWithUserInput')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->userInput(
                UserInput::make()
                    ->prompt('Additional instructions')
                    ->placeholder('Enter details...')
            );
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
