<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;
use Statikbe\FilamentSolaris\Support\Metadata\Presets\EvidenceMetadata;

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
     * Conversational refinement with a selectable preview.
     */
    public function generateSelectableAction(): AiFormAction
    {
        return AiFormAction::make('generateSelectable')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category'])
            ->prompt('Analyze and classify.')
            ->conversational()
            ->withSelectablePreview();
    }

    /**
     * Conversational refinement with per-field metadata.
     */
    public function generateWithMetadataAction(): AiFormAction
    {
        return AiFormAction::make('generateWithMetadata')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category'])
            ->prompt('Analyze and classify.')
            ->conversational()
            ->fieldMetadata(fn (JsonSchemaTypeFactory $s) => [
                'evidence' => $s->string()->enum(['verbatim', 'derived'])->required(),
            ]);
    }

    /**
     * Conversational refinement with a selectable preview and the evidence preset.
     */
    public function generateEvidenceSelectableAction(): AiFormAction
    {
        return AiFormAction::make('generateEvidenceSelectable')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category'])
            ->prompt('Analyze and classify.')
            ->conversational()
            ->fieldMetadata(EvidenceMetadata::make())
            ->withSelectablePreview();
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
