<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;

class PreviewFormComponent extends FormsComponent
{
    use InteractsWithSolarisPreview;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<int, array{values: array<string, mixed>, metadata: array<string, mixed>}> */
    public static array $written = [];

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
     * AI action with a selectable preview (every target field selectable).
     */
    public function generateSelectableAction(): AiFormAction
    {
        return AiFormAction::make('generateSelectable')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category', 'is_featured'])
            ->prompt('Analyze the content and fill in the summary, category, and featured flag.')
            ->withSelectablePreview();
    }

    /**
     * AI action with a selectable preview limited to a field subset.
     */
    public function generateSubsetAction(): AiFormAction
    {
        return AiFormAction::make('generateSubset')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category', 'is_featured'])
            ->prompt('Analyze the content and fill in the summary, category, and featured flag.')
            ->withSelectablePreview(['summary', 'category']);
    }

    /**
     * Misconfigured: the subset names a field that is not a target field.
     */
    public function generateUnknownSubsetAction(): AiFormAction
    {
        return AiFormAction::make('generateUnknownSubset')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category', 'is_featured'])
            ->prompt('Analyze.')
            ->withSelectablePreview(['summary', 'nope']);
    }

    /**
     * Misconfigured: an empty subset.
     */
    public function generateEmptySubsetAction(): AiFormAction
    {
        return AiFormAction::make('generateEmptySubset')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category', 'is_featured'])
            ->prompt('Analyze.')
            ->withSelectablePreview([]);
    }

    /**
     * AI action with per-field metadata, no preview.
     */
    public function generateWithMetadataAction(): AiFormAction
    {
        return $this->metadataAction('generateWithMetadata');
    }

    /**
     * Metadata + a preset whose instructions are overridden at action level.
     */
    public function generateWithPresetOverrideAction(): AiFormAction
    {
        return AiFormAction::make('generateWithPresetOverride')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category'])
            ->prompt('Classify.')
            ->fieldMetadata(EvidenceMetadata::make())
            ->fieldMetadataInstructions('Override');
    }

    /**
     * Metadata + a field sanitizer: only the value may be sanitized.
     */
    public function generateWithMetadataSanitizedAction(): AiFormAction
    {
        return $this->metadataAction('generateWithMetadataSanitized')
            ->sanitizeField('summary', fn (mixed $value) => is_string($value) ? mb_strtoupper($value) : $value);
    }

    /**
     * afterFieldsWritten without metadata.
     */
    public function generatePlainCallbackAction(): AiFormAction
    {
        return AiFormAction::make('generatePlainCallback')
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category'])
            ->prompt('Classify.')
            ->afterFieldsWritten(fn (array $values, array $metadata) => static::$written[] = compact('values', 'metadata'));
    }

    protected function metadataAction(string $name): AiFormAction
    {
        return AiFormAction::make($name)
            ->sourceFields(['title', 'body'])
            ->targetFields(['summary', 'category'])
            ->prompt('Classify.')
            ->fieldMetadata(fn (JsonSchemaTypeFactory $s) => [
                'evidence' => $s->string()->enum(['verbatim', 'derived'])->required(),
            ])
            ->fieldMetadataInstructions('Explain evidence.')
            ->afterFieldsWritten(fn (array $values, array $metadata) => static::$written[] = compact('values', 'metadata'));
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
