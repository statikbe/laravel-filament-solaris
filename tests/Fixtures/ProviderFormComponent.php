<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;

class ProviderFormComponent extends FormsComponent
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
                TextInput::make('title')->required(),
                Textarea::make('body')->required(),
                Textarea::make('summary'),
            ])
            ->statePath('data');
    }

    public function generateSummaryAction(): AiFormAction
    {
        return AiFormAction::make('generateSummary')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.');
    }

    public function generateWithProviderAction(): AiFormAction
    {
        return AiFormAction::make('generateWithProvider')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->provider('anthropic', 'claude-sonnet-4-5-20250514');
    }

    public function generateWithPresetProviderAction(): AiFormAction
    {
        return AiFormAction::make('generateWithPresetProvider')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->preset(SummaryPreset::make()->provider('openai', 'gpt-4o'));
    }

    public function generateWithBothProvidersAction(): AiFormAction
    {
        return AiFormAction::make('generateWithBothProviders')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->preset(SummaryPreset::make()->provider('openai', 'gpt-4o'))
            ->provider('anthropic', 'claude-sonnet-4-5-20250514');
    }

    public function generateWithPresetAction(): AiFormAction
    {
        return AiFormAction::make('generateWithPreset')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->preset(SummaryPreset::make());
    }

    public function generateWithFailoverAction(): AiFormAction
    {
        return AiFormAction::make('generateWithFailover')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->provider(['openai' => 'gpt-4o', 'anthropic']);
    }

    public function generateWithTimeoutAction(): AiFormAction
    {
        return AiFormAction::make('generateWithTimeout')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->timeout(120);
    }

    public function generateWithOptionsAction(): AiFormAction
    {
        return AiFormAction::make('generateWithOptions')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->temperature(0.7)
            ->maxTokens(2048)
            ->maxSteps(5)
            ->topP(0.95);
    }

    public function generateWithPresetOptionsAction(): AiFormAction
    {
        return AiFormAction::make('generateWithPresetOptions')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->preset(SummaryPreset::make()->temperature(0.3)->maxTokens(512));
    }

    public function generateWithBothOptionsAction(): AiFormAction
    {
        return AiFormAction::make('generateWithBothOptions')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->preset(SummaryPreset::make()->temperature(0.3)->maxTokens(512))
            ->temperature(0.9);
    }

    public function generateWithClosureTemperatureAction(): AiFormAction
    {
        return AiFormAction::make('generateWithClosureTemperature')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->temperature(fn () => 0.42);
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
