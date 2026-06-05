# AiGenerateAction

[← Back to README](../README.md)

`AiGenerateAction` is a form-agnostic AI action that generates structured data and hands it straight to your own handler — no target fields, no form wiring required. Where `AiFormAction` reads source fields and writes AI output back into form fields, `AiGenerateAction` lets you define the output shape yourself and do whatever you like with the result: seed database records, build a taxonomy, gather structured info from a prompt, or run any batch operation.

## When to use which action

| | `AiFormAction` | `AiGenerateAction` |
|---|---|---|
| Reads source fields from a form | Yes | No |
| Writes back into form fields | Yes | No |
| Output schema | Auto-derived from target Filament components | You define it (`->outputSchema()`) or derive it from a model (`->forModel()`) |
| Handler | — (framework writes the fields) | Required (`->handleUsing()`) |
| Typical use | Summarize, classify, translate, fill fields | Seed records, build taxonomies, gather structured data |

## Custom Schema

Define the output shape with `->outputSchema()`. The closure receives a schema builder `$s` and must return an associative array of field names to `Type` definitions.

```php
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;

AiGenerateAction::make('build-taxonomy')
    ->prompt(fn ($record) => "Generate a category taxonomy for {$record->topic}.")
    ->outputSchema(fn ($s) => [
        'taxonomy' => $s->array()->items($s->object([
            'name' => $s->string(),
            'slug' => $s->string(),
        ])),
    ])
    ->handleUsing(fn (array $data) => /* build records from $data['taxonomy'] */);
```

`->outputSchema()` and `->forModel()` are mutually exclusive — providing both throws a validation error.

## Model-Derived Schema (seeding)

Use `->forModel()` to derive the output schema automatically from an Eloquent model's database columns. The action introspects the table, honours `$fillable`/`$guarded`, and maps column types to JSON schema types. The handler receives both `$data` (full decoded response) and the injected `$records` array (the `records` key from the response).

```php
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;

AiGenerateAction::make('seed-categories')
    ->prompt('Generate realistic blog categories.')
    ->forModel(Category::class)
    ->count(20)
    ->only(['name', 'slug', 'description'])
    ->handleUsing(fn (array $records) => Category::query()->insert($records));
```

### `forModel` options

| Method | Description |
|---|---|
| `->count(int $n)` | Ask the AI to generate `$n` records. |
| `->only(array $columns)` | Include only these columns in the schema. |
| `->except(array $columns)` | Exclude these columns from the schema. |

### What `forModel` introspects

- Column list from `Schema::getColumns()`.
- Fills only columns permitted by `$fillable` / `$guarded`.
- Auto-excludes the primary key, `created_at`, `updated_at`, `deleted_at`.
- Maps DB types to JSON schema types: strings → `string`, integers → `integer`, booleans → `boolean`, decimals/floats → `number`, everything else → `string`.
- Nullable columns become optional schema fields.
- Casts on the model further refine the type (e.g. a `boolean` cast on an integer column yields a `boolean` schema field).
- Backed-enum casts (e.g. `$casts['status'] = StatusEnum::class`) become typed `enum` constraints — string-backed enums produce a `string` field constrained to the case values; int-backed produce an `integer` field with the integer case values. Unit (non-backed) enums fall through to the plain mapped type (no `->value` to enumerate). MySQL `enum(…)` columns without a cast stay deferred (see below).

### Per-column overrides

Two setters refine the `forModel`-generated schema per column. Both no-op silently for columns not in the resolved schema (consistent with `targetHint()` on `AiFormAction`).

```php
AiGenerateAction::make('seed-articles')
    ->forModel(Article::class)
    ->count(10)
    ->columnEnum('status', ['draft', 'review', 'published'])    // constrains values
    ->columnHint('title', 'title-case, 50-60 characters, no clickbait')
    ->columnHint('summary', 'one sentence, max 160 chars')
    ->handleUsing(fn (array $records) => Article::query()->insert($records));
```

- `->columnEnum()` overrides cast-detected enums if both apply.
- `->columnHint()` sets the column's JSON-schema `description` — the model uses it as guidance.

### Deferred for future versions

The following will be added gradually:

- `json` / `jsonb` column handling.
- `maxLength` from string column definitions.
- Foreign key / relationship resolution.
- Casts-only (DB-less) inference.
- Validation-rule constraints (e.g. `min`/`max` from `HasValidation`).
- Dependency injection in the `->outputSchema()` closure.

## Record Write-back & Enrichment

Beyond the basic seed-from-scratch pattern, `AiGenerateAction` can iterate over a set of source rows, make one AI call per row, and write the result directly into the database — no `->handleUsing()` needed. Two terminal methods replace the handler: `->createRecords()` and `->updateRecords()`.

### Operations matrix

| | no `->sourceRecords()` | with `->sourceRecords()` |
|---|---|---|
| `->createRecords()` | **Seed** — generate N records from scratch | **Import** — transform rows into model records |
| `->updateRecords()` | invalid (deferred — see `specs/24-userinput-on-aigenerateaction.md`) | **Enrich** — update existing models in place |

**Mutual exclusivity:** exactly one of `->handleUsing()`, `->createRecords()`, or `->updateRecords()` is required. Providing more than one, or none, throws a `RuntimeException` at call time.

**`->forModel()` is required** for both terminal methods — the schema is always model-derived.

**`->updateRecords()` requires `->sourceRecords()`** — there is nothing to update without a source of existing models.

### Seed from scratch

The simplest variant: no source rows, just a count. The AI generates `$n` records from scratch and `Model::create()` is called for each one.

```php
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;

AiGenerateAction::make('seed-categories')
    ->forModel(Category::class)
    ->count(20)
    ->createRecords();
```

This is sugar for the longhand `->handleUsing(fn (array $records) => Category::query()->insert($records))` but with per-record create semantics (model events fire, casts are applied).

### Import: transform rows into model records

Pass a source of raw rows (from a spreadsheet, an external API, a CSV, …) and the AI normalises each one into the model's shape, then `Model::create()` is called per row.

```php
AiGenerateAction::make('import-prospects')
    ->prompt('Parse this contact into a sales prospect. Split full name; normalize email/phone; infer company from email domain.')
    ->forModel(Prospect::class)
    ->sourceRecords($rowsFromExcel)
    ->createRecords();
```

### Enrich: per-record AI update of existing models

Provide a source of existing Eloquent models. The AI is called once per model with the row's attributes in context; `$row->update($aiOutput)` is called with the response.

```php
AiGenerateAction::make('enrich-articles')
    ->prompt('Write a concise SEO meta description for this article: 150-160 chars, leads with the main topic.')
    ->forModel(Article::class)
    ->sourceRecords(fn ($livewire) => $livewire->getSelectedTableRecords())
    ->columnHint('meta_description', '150-160 chars, conversational, no clickbait')
    ->updateRecords();
```

### `->sourceRecords()` source types

`->sourceRecords()` accepts:

- **`Builder`** — executed lazily via `->get()` before iteration begins.
- **`Collection` / `EloquentCollection`** — iterated as-is.
- **`array<Model>` or `array<array>`** — raw attribute arrays are supported for `->createRecords()`; `->updateRecords()` requires Model instances (needs `getKey()` for write-back).
- **`Closure`** — resolved via Filament's `evaluate()` with DI: `$record` (the action's host), `$livewire`, `$get`, and any other standard Filament injected arguments. The Closure must return one of the types above.

### `->promptContextColumns()`

By default, every non-excluded column of each row is injected into the prompt as part of the `## Records` context block (all batch rows as a JSON array). Whitelist specific columns to limit what the AI sees — useful for privacy (omit PII not needed for the task) and token cost (drop large HTML body columns when only metadata matters):

```php
AiGenerateAction::make('enrich-articles')
    ->forModel(Article::class)
    ->sourceRecords(Article::all())
    ->promptContextColumns(['title', 'author', 'published_at'])  // exclude 'body', 'raw_html', etc.
    ->columnHint('meta_description', '150-160 chars')
    ->updateRecords();
```

When `->promptContextColumns()` is not called, the full row (minus the primary key and auto-timestamps) is included.

### Prompt closure `$rows` injection

When `->prompt()` receives a `Closure`, it is called **once per batch** with the batch's rows injected as `$rows` (an `array<int, array<string, mixed>>`). Filament's standard named arguments (`$record`, `$livewire`, `$get`, …) are unchanged — `$record` refers to the action's host, while `$rows` is the current batch of iteration items:

```php
AiGenerateAction::make('personalise-subject-lines')
    ->forModel(EmailCampaign::class)
    ->sourceRecords(EmailCampaign::where('subject', null)->get())
    ->prompt(fn (array $rows) => 'Write compelling email subject lines for ' . count($rows) . ' campaigns. Segments: ' . implode(', ', array_column($rows, 'segment')) . '.')
    ->updateRecords();
```

> **Note:** Declaring `$row` (singular) in a prompt closure throws a `LogicException` at execute time. Always use `$rows`.

### Partial-failure handling

Each batch is wrapped in its own `try/catch`. If the AI call or a write-back fails for a batch, the exception is passed to `report()` and a failure counter is incremented. The loop continues with the next batch.

In `forModel` mode the schema unconditionally includes a `failed: [{identifier, reason}]` array. The AI can report individual row failures within a batch (e.g. a record it could not process) by populating that array; those failures are also counted and reported. Failures from AI-reported errors, silent drops, hallucinated identifiers, and write errors are all aggregated into the batch summary notification.

At the end of the loop a single **summary notification** is shown:

- All succeeded → `"Processed N records."`
- Some failed → `"Processed N records, M failed — check logs."`

Per-batch AI error toasts are suppressed to avoid notification spam on large jobs.

### Large imports — queue the work

For more than ~50 rows, running AI calls synchronously in a web request will exceed timeouts and block the UI. A queued-execution mode (`->queued()`) is planned for a future version. Until then, dispatch a queued job from your `->handleUsing()` closure (single-call variant) or wrap the action invocation in a job yourself.

### Testing

Use `AiGenerateAction::fakeEach([...])` to queue a separate canned response per batch call. In `forModel` mode each entry must be a `BatchResponse`-shaped array with `records` and `failed` keys. The fake throws a `RuntimeException` if the action tries to make more calls than responses were provided.

```php
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;

// forModel mode: each entry is a BatchResponse-shaped array
AiGenerateAction::fakeEach([
    [
        'records' => [
            ['meta_description' => 'First article SEO description.'],
            ['meta_description' => 'Second article SEO description.'],
        ],
        'failed' => [],
    ],
]);

// … trigger the action (one batch of 2 rows) …

AiGenerateAction::assertCalledTimes(1);
```

Use `AiGenerateAction::assertCalledWithBatch(Closure)` to inspect the batch the action received:

```php
AiGenerateAction::assertCalledWithBatch(function (array $rows) {
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toHaveKey('id');
});
```

Use `AiGenerateAction::assertHandledWith()` to inspect the data the handler received on the most recent call:

```php
AiGenerateAction::assertHandledWith(function (array $data) {
    expect($data['meta_description'])->toStartWith('Second');
});
```

---

## Handler Contract

`->handleUsing()` is **required** when not using `->createRecords()` or `->updateRecords()`. The closure receives:

- `array $data` — the full decoded AI response, always available.
- `array $records` — shorthand injected only when `->forModel()` is used; equals `$data['records']`.
- Filament's standard dependency injection — `$record`, `$livewire`, `$get`, `$operation`, and the rest.

The handler is fully responsible for its own success feedback (notifications, redirects, etc.). If the handler throws, the action catches the exception and shows a generic error notification to the user.

```php
->handleUsing(function (array $data, array $records, $livewire) {
    Category::query()->insert($records);

    Notification::make()
        ->title('Categories seeded!')
        ->success()
        ->send();
})
```

## Prompt

`->prompt()` accepts a string, a Blade `View`, or a `Closure`. Closures receive Filament's dependency injection — `$record`, `$livewire`, `$get`, etc. — so you can build the instruction from the current record or page state:

```php
->prompt('Generate realistic blog categories with name, slug, and description.')

->prompt(fn ($record) => "Generate a taxonomy for the topic: {$record->topic}.")

->prompt(view('prompts.taxonomy-generation'))
```

## Validation

The action validates its configuration at call time and throws a `RuntimeException` if:

- Neither `->outputSchema()` nor `->forModel()` is provided.
- Both `->outputSchema()` and `->forModel()` are provided at once.
- None of `->handleUsing()`, `->createRecords()`, or `->updateRecords()` is set.
- More than one of `->handleUsing()`, `->createRecords()`, `->updateRecords()` is set.
- `->createRecords()` or `->updateRecords()` is used without `->forModel()`.
- `->updateRecords()` is used without `->sourceRecords()`.

## Provider, Model & Timeout

The same resolution chain that `AiFormAction` uses applies here: action → config `preset_providers` → config `default_provider` → `laravel/ai` default.

```php
AiGenerateAction::make('seed-categories')
    ->prompt('Generate realistic blog categories.')
    ->forModel(Category::class)
    ->count(20)
    ->provider('anthropic', 'claude-sonnet-4-5-20250514')
    ->timeout(120)
    ->handleUsing(fn (array $records) => Category::query()->insert($records));
```

See [Configuration](configuration.md) for package-wide defaults.

## Batching

The records loop processes source rows in batches of N per AI call. Configure via `->batchSize($n)` (default `10`).

```php
AiGenerateAction::make('enrich-articles')
    ->forModel(Article::class)
    ->prompt(fn (array $rows) => 'Enrich these articles with category labels.')
    ->sourceRecords(fn () => Article::needsEnrichment()->get())
    ->batchSize(20)
    ->updateRecords();
```

- Default `batchSize` is `10`. Reduce it if your row data is large (avoid context-window overflow); increase it for smaller rows where AI-call overhead dominates.
- Closures receive `$rows` (`array<int, array<string, mixed>>`) — even at `batchSize=1`, you get a one-element array. The legacy `$row` (singular) arg is no longer supported and throws at execute time if declared.
- `->handleUsing()` receives a `BatchResponse` DTO (`$data->records`, `$data->failed`) in `forModel` mode.

### Auto-prompt boilerplate

In `forModel` mode the action auto-appends to your prompt:

```
## Records
[ each batch's input rows as a JSON array, with identifier echoed ]

## Instructions
For each record above, return an entry in `records` echoing the `id` (or `_index`) field unchanged with the processed fields.
For any record you cannot process, add an entry to `failed` with the `identifier` and a short `reason`.
```

The schema unconditionally includes a `failed: [{identifier, reason}]` array. Failures are aggregated and reported via the batch summary notification.

### Identifier conventions

- `updateRecords`: the model's primary key column (echoed unchanged).
- `createRecords + sourceRecords`: an injected `_index` integer (0..N-1 within each batch).
- Single-call `createRecords` (no source — e.g., textarea/CSV parsing): the LLM populates `identifier` freely from input context (line number, CSV row excerpt, etc.).

### Note on timeouts

`->timeout($seconds)` and `->maxSteps($n)` are per-AI-call, not per-action. At `batchSize=10`, a 60s timeout covers a batch of 10 rows, not one. Tune accordingly.

## User Input

Open a Filament modal before the action runs to collect runtime values (steering text, file paths, structured selections). Modal data is:

1. Auto-injected into the prompt as a `## User context` JSON block (top-level and per-batch in the records loop, alongside the `## Records` and `## Instructions` blocks).
2. Available as a `$userInput` named-arg in `->prompt()`, `->handleUsing()`, and `->sourceRecords()` closures (Filament-style DI — declare the arg to receive it; omit it if you don't need it).

### Free-text steering for single-call generation

```php
AiGenerateAction::make('generate-meta')
    ->userInput(UserInput::make()->fields([Textarea::make('focus')]))
    ->prompt(fn (array $userInput) => "Generate SEO meta. Focus: {$userInput['focus']}")
    ->outputSchema(fn ($schema) => [
        'title' => $schema->string(),
        'description' => $schema->string(),
    ])
    ->handleUsing(fn (array $data, array $userInput) => Cache::put('meta', $data));
```

### Spreadsheet-driven enrichment

```php
AiGenerateAction::make('enrich-from-spreadsheet')
    ->userInput(UserInput::make()->fields([
        FileUpload::make('csv')->acceptedFileTypes(['text/csv'])->required(),
        Textarea::make('focus')->placeholder('Tone, style, audience…'),
    ]))
    ->forModel(Article::class)
    ->prompt('Enrich each article for the requested audience.')
    ->sourceRecords(fn (array $userInput) => Article::query()
        ->whereIn('id', collect(parseCsv($userInput['csv']))->pluck('id'))
        ->get())
    ->updateRecords();
```

### Notes

- For sending uploaded files to the AI as Image/Audio/Document attachments instead of just exposing their paths in `$userInput`, see [Attachments](#attachments) below.
- `->withDefaultUserInput()` (pulling a preset's default modal config) is not available on `AiGenerateAction` — presets aren't yet a concept on this action. Calling it throws `BadMethodCallException`.

## Attachments

`AiGenerateAction` sends user-uploaded files (or any `Files\File` instance) to the AI as native attachments — Image, Audio, or Document, auto-detected by MIME. Same three-channel API as `AiFormAction`:

```php
AiGenerateAction::make('enrich-from-spreadsheet')
    ->userInput(UserInput::make()->fields([
        FileUpload::make('csv')->acceptedFileTypes(['text/csv'])->required(),
    ]))
    ->attachmentFromUserInput('csv')   // ← send the file directly to the AI
    ->forModel(Article::class)
    ->prompt('Enrich each article using the rows in the attached spreadsheet.')
    ->sourceRecords(fn (array $userInput) => Article::all())
    ->updateRecords();
```

- `->attachmentField('upload')` — bind a parent-form `FileUpload` field.
- `->attachmentFromUserInput('csv')` — bind a key from the UserInput modal.
- `->attachments(fn () => Image::fromUrl(...))` — supply files programmatically.

Resolution is **job-level**: the same `Files\File[]` flows to every per-row AI call in the records loop. Disk resolution uses `config('filesystems.default')`.

## Generation Options

Tune the underlying text generation. All four options are optional — when not set, the resolver falls back to the config `default_*` keys, and ultimately to `laravel/ai`'s own attribute defaults.

```php
AiGenerateAction::make('seed-articles')
    ->forModel(Article::class)
    ->count(20)
    ->temperature(0.9)   // creativity for diverse seed data
    ->maxTokens(2000)
    ->maxSteps(5)
    ->topP(0.95)
    ->createRecords();
```

Setters accept `Closure` for runtime values:

```php
AiGenerateAction::make('seed-articles')
    ->temperature(fn () => auth()->user()->ai_creativity ?? 0.7)
```

Resolution chain per option (highest wins): action → config `default_*` → `laravel/ai` default. See [Configuration](configuration.md) for the package-wide `default_temperature` / `default_max_tokens` / `default_max_steps` / `default_top_p` keys.

> [!NOTE]
> **Preview and conversational refinement** are not supported on `AiGenerateAction` — the action executes, calls your handler, and returns. These may be added in a later version.

## Testing

Use `AiGenerateAction::fake()` to swap the real AI call with a controlled fake in tests. Pass the array the handler should receive as `$data`.

```php
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;

AiGenerateAction::fake([
    'records' => [
        ['name' => 'Technology', 'slug' => 'technology', 'description' => 'Tech news.'],
        ['name' => 'Science', 'slug' => 'science', 'description' => 'Science articles.'],
    ],
]);
```

### Assertions

```php
// Was the action called at all?
AiGenerateAction::assertCalled();

// Was it called exactly N times?
AiGenerateAction::assertCalledTimes(1);

// Was it never called?
AiGenerateAction::assertNotCalled();

// Inspect the data that reached the handler (assert with expect() inside the closure)
AiGenerateAction::assertHandledWith(function (array $data) {
    expect($data['records'])->toHaveCount(2)
        ->and($data['records'][0]['name'])->toBe('Technology');
});
```

Simulate a provider failure with `AiGenerateAction::fakeError('...')` — the handler does not run and an error notification is shown.

The fake dispatches the same `SolarisResponseReceived` event the real action does, so usage-tracking listeners can be exercised in tests without a live provider.

See [Testing](testing.md) for the full testing guide.
