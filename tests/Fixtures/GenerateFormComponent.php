<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Supplies the record Filament injects as `$record` into action closures.
     * Built fresh per request (Livewire would strip an unsaved model held as a
     * public property).
     */
    public function getDefaultActionRecord(Action $action): ?Model
    {
        return new SeedCategory(['name' => 'Ctx']);
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

    public function bothSourcesAction(): AiGenerateAction
    {
        return AiGenerateAction::make('bothSources')
            ->prompt('x')
            ->outputSchema(fn ($s) => ['a' => $s->string()])
            ->forModel(SeedCategory::class)
            ->handleUsing(fn (array $data) => null);
    }

    public function recordAwareAction(): AiGenerateAction
    {
        return AiGenerateAction::make('recordAware')
            ->prompt('x')
            ->outputSchema(fn ($s) => ['a' => $s->string()])
            ->handleUsing(fn (array $data, $record, $livewire) => $livewire->handledData = [
                'name' => $record?->name,
                'data' => $data,
            ]);
    }

    public function seedCategoriesAction(): AiGenerateAction
    {
        return AiGenerateAction::make('seedCategories')
            ->prompt('Generate realistic blog categories.')
            ->forModel(SeedCategory::class)
            ->count(2)
            ->handleUsing(fn (array $records) => collect($records)->each(fn (array $row) => SeedCategory::create($row)));
    }

    public function importCategoriesAction(): AiGenerateAction
    {
        return AiGenerateAction::make('importCategories')
            ->prompt('Transform this row into a SeedCategory.')
            ->forModel(SeedCategory::class)
            ->records([
                ['raw_name' => 'tech'],
                ['raw_name' => 'science'],
                ['raw_name' => 'art'],
            ])
            ->createRecords();
    }

    public function enrichCategoriesAction(): AiGenerateAction
    {
        return AiGenerateAction::make('enrichCategories')
            ->prompt('Improve the slug field of this category.')
            ->forModel(SeedCategory::class)
            ->records(fn () => SeedCategory::all())
            ->updateRecords();
    }

    public function updateWithoutRecordsAction(): AiGenerateAction
    {
        return AiGenerateAction::make('updateWithoutRecords')
            ->prompt('x')
            ->forModel(SeedCategory::class)
            ->updateRecords();   // missing ->records() — invalid
    }

    public function bothTerminalsAction(): AiGenerateAction
    {
        return AiGenerateAction::make('bothTerminals')
            ->prompt('x')
            ->forModel(SeedCategory::class)
            ->records([['x' => 1]])
            ->createRecords()
            ->updateRecords();   // mutually exclusive
    }

    public function createWithoutModelAction(): AiGenerateAction
    {
        return AiGenerateAction::make('createWithoutModel')
            ->prompt('x')
            ->outputSchema(fn ($s) => ['records' => $s->array()->items($s->object(['name' => $s->string()]))])
            ->createRecords();   // requires forModel
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
