<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\DictationAction;

class DictationFormComponent extends FormsComponent
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
     * Pure transcription — writes directly to body field.
     */
    public function dictateBodyAction(): DictationAction
    {
        return DictationAction::make('dictateBody')
            ->targetField('body');
    }

    /**
     * Transcription with append mode — appends to body field.
     */
    public function dictateBodyAppendAction(): DictationAction
    {
        return DictationAction::make('dictateBodyAppend')
            ->targetField('body')
            ->append();
    }

    /**
     * Transcription + AI processing — transcribes and generates a summary.
     */
    public function dictateAndSummarizeAction(): DictationAction
    {
        return DictationAction::make('dictateAndSummarize')
            ->targetField('summary')
            ->prompt('Summarize the transcription in one sentence.');
    }

    /**
     * Transcription + AI processing with multiple target fields.
     */
    public function dictateAndClassifyAction(): DictationAction
    {
        return DictationAction::make('dictateAndClassify')
            ->targetFields(['summary', 'category'])
            ->prompt('Summarize and classify the transcription.');
    }

    /**
     * Dictation with no target fields — should throw.
     */
    public function dictateInvalidAction(): DictationAction
    {
        return DictationAction::make('dictateInvalid');
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
