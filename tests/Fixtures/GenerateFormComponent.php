<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;

class GenerateFormComponent extends FormsComponent
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $handledData = [];

    public function mount(): void
    {
        $this->form->fill([]);
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([])->statePath('data');
    }

    public function buildTaxonomyAction(): AiGenerateAction
    {
        return AiGenerateAction::make('buildTaxonomy')
            ->prompt('Generate a category taxonomy.')
            ->outputSchema(fn ($s) => ['taxonomy' => $s->array()->items($s->object([
                'name' => $s->string(),
            ]))])
            ->handleUsing(fn (array $data, $livewire) => $livewire->handledData = $data);
    }

    public function missingHandlerAction(): AiGenerateAction
    {
        return AiGenerateAction::make('missingHandler')
            ->prompt('x')
            ->outputSchema(fn ($s) => ['a' => $s->string()]);
    }

    public function missingSchemaAction(): AiGenerateAction
    {
        return AiGenerateAction::make('missingSchema')
            ->prompt('x')
            ->handleUsing(fn (array $data) => null);
    }

    public function throwingHandlerAction(): AiGenerateAction
    {
        return AiGenerateAction::make('throwingHandler')
            ->prompt('x')
            ->outputSchema(fn ($s) => ['a' => $s->string()])
            ->handleUsing(fn (array $data) => throw new \RuntimeException('handler boom'));
    }

    public function seedCategoriesAction(): AiGenerateAction
    {
        return AiGenerateAction::make('seedCategories')
            ->prompt('Generate realistic blog categories.')
            ->forModel(SeedCategory::class)
            ->count(2)
            ->handleUsing(fn (array $records) => collect($records)->each(fn (array $row) => SeedCategory::create($row)));
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
