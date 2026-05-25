<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\AiFormAction;

/**
 * Deliberately omits the `InteractsWithSolarisPreview` trait so the
 * `withPreview()` runtime check throws when the action is invoked.
 *
 * Mirror of {@see PreviewFormComponent} minus the trait — used to verify
 * `SolarisAction::validatePreviewConfiguration()` fails loud as intended.
 */
class MissingPreviewTraitComponent extends FormsComponent
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'title' => '',
            'body' => '',
            'summary' => '',
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('title'),
                Textarea::make('body'),
                Textarea::make('summary'),
            ])
            ->statePath('data');
    }

    public function generateSummaryAction(): AiFormAction
    {
        return AiFormAction::make('generateSummary')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarise the body.')
            ->withPreview();
    }

    public function refineSummaryAction(): AiFormAction
    {
        return AiFormAction::make('refineSummary')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarise the body.')
            ->conversational();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
