<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;

class DictationFormComponent extends FormsComponent
{
    use InteractsWithSolarisPreview;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'title' => '',
            'body' => '',
            'summary' => '',
            'category' => null,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                // Plain dictation — hint actions on a Textarea write the
                // transcript into 'body' (auto-resolved from the host field).
                // Append-mode variant is a second hint action on the same field.
                // The `dictateBodyWithPreview` variant pairs the recorder with
                // `->withPreview()` to exercise the dictation/preview interaction
                // (the component carries the InteractsWithSolarisPreview trait so
                // validatePreviewConfiguration() passes).
                Textarea::make('body')
                    ->required()
                    ->hintActions([
                        DictationFieldAction::make('dictateBody'),
                        DictationFieldAction::make('dictateBodyAppend')->append(),
                        DictationFieldAction::make('dictateBodyWithPreview')->withPreview(),
                    ]),

                // AI-chained dictation — records, transcribes, pipes the
                // transcript through the AI pipeline, writes the resulting
                // summary into 'summary'. Multi-target variant also writes
                // 'category' via explicit ->targetFields().
                Textarea::make('summary')
                    ->hintActions([
                        DictationFieldAction::make('dictateAndSummarize')
                            ->prompt('Summarize the transcription in one sentence.'),
                        DictationFieldAction::make('dictateAndClassify')
                            ->targetFields(['summary', 'category'])
                            ->prompt('Summarize and classify the transcription.'),
                    ]),

                Select::make('category')
                    ->options([
                        'tech' => 'Technology',
                        'science' => 'Science',
                        'art' => 'Art',
                    ]),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
