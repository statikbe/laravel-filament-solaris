<?php

namespace Statikbe\FilamentSolaris\Tests\Fixtures;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Files\Image;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Support\UserInput;

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
        return $form->schema([
            FileUpload::make('upload'),
        ])->statePath('data');
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
            ->handleUsing(fn ($data) => collect($data->records)->each(fn (array $row) => SeedCategory::create($row)));
    }

    public function importCategoriesAction(): AiGenerateAction
    {
        return AiGenerateAction::make('importCategories')
            ->prompt('Transform this row into a SeedCategory.')
            ->forModel(SeedCategory::class)
            ->sourceRecords([
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
            ->sourceRecords(fn () => SeedCategory::all())
            ->updateRecords();
    }

    public function updateWithoutRecordsAction(): AiGenerateAction
    {
        return AiGenerateAction::make('updateWithoutRecords')
            ->prompt('x')
            ->forModel(SeedCategory::class)
            ->updateRecords();   // missing ->sourceRecords() — invalid
    }

    public function bothTerminalsAction(): AiGenerateAction
    {
        return AiGenerateAction::make('bothTerminals')
            ->prompt('x')
            ->forModel(SeedCategory::class)
            ->sourceRecords([['x' => 1]])
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

    public function importWithRowPromptAction(): AiGenerateAction
    {
        return AiGenerateAction::make('importWithRowPrompt')
            ->prompt(fn (array $rows) => 'Process these rows: '.implode(', ', array_column($rows, 'tag')).'.')
            ->forModel(SeedCategory::class)
            ->sourceRecords([
                ['tag' => 'alpha'],
                ['tag' => 'beta'],
            ])
            ->createRecords();
    }

    public function userInputSingleCallAction(): AiGenerateAction
    {
        return AiGenerateAction::make('userInputSingleCall')
            ->userInput(UserInput::make()->fields([Textarea::make('focus')]))
            ->prompt(fn (array $userInput) => 'Generate for focus: '.($userInput['focus'] ?? 'none'))
            ->outputSchema(fn ($s) => ['x' => $s->string()])
            ->handleUsing(fn (array $data, array $userInput, $livewire) => $livewire->handledData = [
                'data' => $data,
                'userInput' => $userInput,
            ]);
    }

    public function userInputCreateRecordsLoopAction(): AiGenerateAction
    {
        return AiGenerateAction::make('userInputCreateRecordsLoop')
            ->userInput(UserInput::make()->fields([Textarea::make('focus')]))
            ->forModel(SeedCategory::class)
            ->prompt(fn (array $rows, array $userInput) => 'Process '.count($rows).' rows with focus: '.($userInput['focus'] ?? 'none'))
            ->sourceRecords([['name' => 'A', 'slug' => 'a'], ['name' => 'B', 'slug' => 'b']])
            ->createRecords();
    }

    public function userInputSourceRecordsClosureAction(): AiGenerateAction
    {
        return AiGenerateAction::make('userInputSourceRecordsClosure')
            ->userInput(UserInput::make()->fields([Textarea::make('count_hint')]))
            ->forModel(SeedCategory::class)
            ->prompt('Process.')
            ->sourceRecords(function (array $userInput) {
                $count = (int) ($userInput['count_hint'] ?? 1);

                return array_map(static fn (int $i): array => ['name' => "Row{$i}", 'slug' => "row-{$i}"], range(1, $count));
            })
            ->createRecords();
    }

    public function noUserInputAction(): AiGenerateAction
    {
        return AiGenerateAction::make('noUserInput')
            ->prompt('Static prompt.')
            ->outputSchema(fn ($s) => ['x' => $s->string()])
            ->handleUsing(fn (array $data, $livewire) => $livewire->handledData = $data);
    }

    public function staticAttachmentsAction(): AiGenerateAction
    {
        return AiGenerateAction::make('staticAttachments')
            ->attachments(fn () => Image::fromUrl('https://example.com/seed.png'))
            ->prompt('Describe the seed image.')
            ->outputSchema(fn ($s) => ['description' => $s->string()])
            ->handleUsing(fn (array $data, $livewire) => $livewire->handledData = $data);
    }

    public function userInputAttachmentAction(): AiGenerateAction
    {
        return AiGenerateAction::make('userInputAttachment')
            ->userInput(UserInput::make()->fields([Textarea::make('csv_path')]))
            ->attachmentFromUserInput('csv_path')
            ->prompt('Process the attached CSV.')
            ->outputSchema(fn ($s) => ['ok' => $s->string()])
            ->handleUsing(fn (array $data, $livewire) => $livewire->handledData = $data);
    }

    public function parentFormAttachmentAction(): AiGenerateAction
    {
        return AiGenerateAction::make('parentFormAttachment')
            ->attachmentField('upload')
            ->prompt('Process the form-supplied file.')
            ->outputSchema(fn ($s) => ['ok' => $s->string()])
            ->handleUsing(fn (array $data, $livewire) => $livewire->handledData = $data);
    }

    public function userInputAttachmentRecordsLoopAction(): AiGenerateAction
    {
        return AiGenerateAction::make('userInputAttachmentRecordsLoop')
            ->userInput(UserInput::make()->fields([Textarea::make('csv_path')]))
            ->attachmentFromUserInput('csv_path')
            ->forModel(SeedCategory::class)
            ->prompt('Process the records below using the attached CSV.')
            ->sourceRecords([['name' => 'A', 'slug' => 'a'], ['name' => 'B', 'slug' => 'b'], ['name' => 'C', 'slug' => 'c']])
            ->createRecords();
    }

    public function batchedUpdateAction(): AiGenerateAction
    {
        return AiGenerateAction::make('batchedUpdate')
            ->forModel(SeedCategory::class)
            ->prompt('Update the records below.')
            ->sourceRecords(fn () => SeedCategory::all())
            ->batchSize(10)
            ->updateRecords();
    }

    public function batchedCreateFromSourceAction(): AiGenerateAction
    {
        return AiGenerateAction::make('batchedCreateFromSource')
            ->forModel(SeedCategory::class)
            ->prompt('Create the records below.')
            ->sourceRecords([
                ['name' => 'A', 'slug' => 'a'],
                ['name' => 'B', 'slug' => 'b'],
                ['name' => 'C', 'slug' => 'c'],
            ])
            ->batchSize(10)
            ->createRecords();
    }

    public function batchedSmallBatchAction(): AiGenerateAction
    {
        // batchSize=1 — same batched code path with batches of one.
        return AiGenerateAction::make('batchedSmallBatch')
            ->forModel(SeedCategory::class)
            ->prompt(fn (array $rows) => 'Processing batch of '.count($rows).'.')
            ->sourceRecords([
                ['name' => 'X', 'slug' => 'x'],
                ['name' => 'Y', 'slug' => 'y'],
                ['name' => 'Z', 'slug' => 'z'],
            ])
            ->batchSize(1)
            ->createRecords();
    }

    public function batchedRowClosureAction(): AiGenerateAction
    {
        // Intentionally declares $row — should throw LogicException at execute.
        return AiGenerateAction::make('batchedRowClosure')
            ->forModel(SeedCategory::class)
            ->prompt(fn (array $row) => "Process {$row['name']}.")
            ->sourceRecords([['name' => 'A', 'slug' => 'a']])
            ->batchSize(10)
            ->createRecords();
    }

    public function seedCategoriesCreateAction(): AiGenerateAction
    {
        // Single-call createRecords (no sourceRecords) — exercises dispatchSingleResponse WRITE_CREATE.
        return AiGenerateAction::make('seedCategoriesCreate')
            ->prompt('Parse the input into categories.')
            ->forModel(SeedCategory::class)
            ->count(2)
            ->createRecords();
    }

    public function partialFailureCaptureAction(): AiGenerateAction
    {
        return AiGenerateAction::make('partialFailureCapture')
            ->forModel(SeedCategory::class)
            ->only(['name', 'slug'])
            ->sourceRecords([
                ['name' => 'A', 'slug' => 'a'],
                ['name' => 'B', 'slug' => 'b'],
                ['name' => 'C', 'slug' => 'c'],
            ])
            ->batchSize(10)
            ->prompt(fn (array $rows) => 'Process '.count($rows).' rows.')
            ->onPartialFailure(fn (array $failures, int $succeeded, int $failed, int $total, array $userInput, $livewire) => $livewire->handledData = [
                'succeeded' => $succeeded,
                'failed' => $failed,
                'total' => $total,
                'ids' => array_map(fn ($f) => $f->identifier, $failures),
                'reasons' => array_map(fn ($f) => $f->reason, $failures),
                'inputs' => array_map(fn ($f) => $f->input, $failures),
            ])
            ->createRecords();
    }

    public function partialFailureUpdateCaptureAction(): AiGenerateAction
    {
        return AiGenerateAction::make('partialFailureUpdateCapture')
            ->forModel(SeedCategory::class)
            ->only(['slug'])
            ->sourceRecords(fn () => SeedCategory::all())
            ->batchSize(10)
            ->prompt(fn (array $rows) => 'Process.')
            ->onPartialFailure(fn (array $failures, $livewire) => $livewire->handledData = [
                'ids' => array_map(fn ($f) => $f->identifier, $failures),
                'inputIsModel' => array_map(fn ($f) => $f->input instanceof Model, $failures),
            ])
            ->updateRecords();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
