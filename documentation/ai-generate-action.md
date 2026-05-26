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

## Handler Contract

`->handleUsing()` is **required**. The closure receives:

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
- `->handleUsing()` is not set.

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

> [!NOTE]
> **Generation options** (`->temperature()`, `->maxTokens()`, `->maxSteps()`, `->topP()`) are not available on `AiGenerateAction` in v1. **Preview and conversational refinement** are also not supported — the action executes, calls your handler, and returns. These features may be added in a later version.

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
