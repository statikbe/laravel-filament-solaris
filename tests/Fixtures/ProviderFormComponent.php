<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\AiAction;
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

    public function generateSummaryAction(): AiAction
    {
        return AiAction::make('generateSummary')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.');
    }

    public function generateWithProviderAction(): AiAction
    {
        return AiAction::make('generateWithProvider')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->provider('anthropic', 'claude-sonnet-4-5-20250514');
    }

    public function generateWithPresetProviderAction(): AiAction
    {
        return AiAction::make('generateWithPresetProvider')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->preset(SummaryPreset::make()->provider('openai', 'gpt-4o'));
    }

    public function generateWithBothProvidersAction(): AiAction
    {
        return AiAction::make('generateWithBothProviders')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->preset(SummaryPreset::make()->provider('openai', 'gpt-4o'))
            ->provider('anthropic', 'claude-sonnet-4-5-20250514');
    }

    public function generateWithPresetAction(): AiAction
    {
        return AiAction::make('generateWithPreset')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->preset(SummaryPreset::make());
    }

    public function generateWithFailoverAction(): AiAction
    {
        return AiAction::make('generateWithFailover')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->provider(['openai' => 'gpt-4o', 'anthropic']);
    }

    public function generateWithTimeoutAction(): AiAction
    {
        return AiAction::make('generateWithTimeout')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt('Summarize.')
            ->timeout(120);
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
