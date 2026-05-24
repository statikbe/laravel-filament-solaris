<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentSolaris\Actions\AiAction;

/**
 * Fixture exercising closure callbacks (with Filament dependency injection)
 * on AiAction's prompt / sourceFields / targetFields setters.
 *
 * A record is supplied via {@see getDefaultActionRecord()} so closures receive
 * a non-null `$record` (the same mechanism Filament uses for component-level
 * actions when no schema/record context is otherwise present).
 */
class ClosureCallbackFormComponent extends FormsComponent
{
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

    /**
     * Supplies the record Filament injects as `$record` into action closures.
     *
     * Built fresh per request rather than held as a public Livewire property —
     * Livewire dehydrates/rehydrates public model properties and an unsaved
     * model loses its attributes through that round-trip.
     */
    public function getDefaultActionRecord(Action $action): ?Model
    {
        return new ClosureTestRecord([
            'audience' => 'developers',
            'summary_fields' => ['title', 'body'],
            'multi_target' => true,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('title'),
                Textarea::make('body'),
                Textarea::make('summary'),
                Select::make('category')->options([
                    'tech' => 'Technology',
                    'science' => 'Science',
                ]),
            ])
            ->statePath('data');
    }

    /**
     * Prompt closure using both `$record` and the injected `$sourceData`.
     */
    public function promptClosureAction(): AiAction
    {
        return AiAction::make('promptClosure')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt(fn ($record, $sourceData) => "Summarise '{$sourceData['title']}' for a {$record->audience} audience.");
    }

    /**
     * Prompt closure returning a Blade View — selects ViewPromptBuilder.
     */
    public function promptViewClosureAction(): AiAction
    {
        return AiAction::make('promptViewClosure')
            ->sourceFields(['title', 'body'])
            ->targetField('summary')
            ->prompt(fn ($record) => view('filament-solaris::prompts.base-wrapper', [
                'instruction' => "View prompt for a {$record->audience} audience",
            ]));
    }

    /**
     * Source-fields closure using `$record`.
     */
    public function sourceFieldsClosureAction(): AiAction
    {
        return AiAction::make('sourceFieldsClosure')
            ->sourceFields(fn ($record) => $record->summary_fields)
            ->targetField('summary')
            ->prompt('Summarise.');
    }

    /**
     * Target-fields closure using `$record`.
     */
    public function targetFieldsClosureAction(): AiAction
    {
        return AiAction::make('targetFieldsClosure')
            ->sourceFields(['title', 'body'])
            ->targetFields(fn ($record) => $record->multi_target ? ['summary', 'category'] : ['summary'])
            ->prompt('Fill the fields.');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
