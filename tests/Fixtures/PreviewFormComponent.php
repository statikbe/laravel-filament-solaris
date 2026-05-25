<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;

class PreviewFormComponent extends FormsComponent
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
     * AI action with preview enabled (single target).
     */
    public function generateSummaryAction(): AiFormAction
    {
        return AiFormAction::make('generateSummary')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize the content in one sentence.')
            ->withPreview();
    }

    /**
     * AI action with preview enabled (multiple targets).
     */
    public function generateAllAction(): AiFormAction
    {
        return AiFormAction::make('generateAll')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category', 'is_featured'])
            ->prompt('Analyze the content and fill in the summary, category, and featured flag.')
            ->withPreview();
    }

    /**
     * AI action without preview (for regression testing).
     */
    public function generateDirectAction(): AiFormAction
    {
        return AiFormAction::make('generateDirect')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.');
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
