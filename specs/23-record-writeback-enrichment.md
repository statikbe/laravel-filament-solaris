# 23 — Record write-back & enrichment (createRecords / updateRecords)

## Summary

Add per-row write-back to `AiGenerateAction` with two terminal operations and a polymorphic record source, turning today's `handleUsing(fn ($records) => …)` boilerplate into first-class ergonomics — and adding a genuinely new capability (per-record AI enrichment of existing rows).

```php
// Seed from scratch (existing behaviour, now sugar)
AiGenerateAction::make('seed-categories')
    ->forModel(Category::class)
    ->count(20)
    ->createRecords();

// Import: transform raw rows into model records
AiGenerateAction::make('import-prospects')
    ->prompt('Parse this contact into a sales prospect. Split full name; normalize email/phone; infer company from email domain.')
    ->forModel(Prospect::class)
    ->records($rowsFromExcel)                  // array<array> of raw input rows
    ->createRecords();

// Enrich: per-record AI update of existing rows
AiGenerateAction::make('enrich-articles')
    ->prompt('Write a concise SEO meta description for this article: 150-160 chars, leads with the main topic.')
    ->forModel(Article::class)
    ->records(fn ($livewire) => $livewire->getSelectedTableRecords())
    ->columnHint('meta_description', '150-160 chars, conversational, no clickbait')
    ->updateRecords();
```

## Motivation

`AiGenerateAction` shipped with one terminal (`->handleUsing()`) — fully general but boilerplate-heavy for the common write-back cases (seeding, importing, enriching). Today these all funnel into "loop the records and call `Model::create()` / `$record->update()`" in user code; the per-record AI orchestration (loop, context-injection, partial-failure handling, summary notification) belongs in the framework. Enrichment specifically is a new capability — feeding an existing record's data into the prompt per iteration — that's awkward to express with a single AI call + handler.

This builds directly on the primitives from spec 21 (`AiGenerateAction`, `ModelSchemaResolver`, `outputSchema`, `forModel`, `count`) and spec 22 (`columnHint`/`columnEnum`). All form/preset machinery stays untouched.

## Public API

| Method | Effect |
|---|---|
| `->records(Builder\|Collection\|array\|Closure $source)` | Per-row iteration source. Closure resolved via Filament `evaluate()` (gets `$record`/`$livewire`/`$get` DI). |
| `->createRecords()` | Terminal: per row, call `Model::create($aiOutput)`. |
| `->updateRecords()` | Terminal: per source record, call `$record->update($aiOutput)` (keyed by `getKey()`). |
| `->promptContextColumns(array<string> $columns)` | Whitelist of column names that get serialised into the prompt as the row's context. Default = the whole row's attributes (auto-exclusions aside). |

Existing `->handleUsing()` is unchanged — the three terminals are **mutually exclusive** (validation error if more than one is set).

## Operations matrix

| | no `->records()` | with `->records()` |
|---|---|---|
| `->createRecords()` | seed N from scratch (one AI call → schema is `records: array.items(...)`) — current behaviour, just sugar over `handleUsing` | **import / transform**: per-row AI call → `Model::create()` |
| `->updateRecords()` | **invalid** — runtime error: "updateRecords requires a `->records()` source" | **enrich**: per-record AI call → `$record->update()` |

(The "no records + updateRecords" cell — paste-a-spreadsheet workflow — is intentionally left for a follow-up; see `specs/24-userinput-on-aigenerateaction.md`.)

## Source contracts per terminal

The polymorphic `->records()` accepts:
- `Illuminate\Database\Eloquent\Builder` — executed lazily (`->get()`) at action time.
- `Illuminate\Support\Collection` / `Illuminate\Database\Eloquent\Collection`.
- `array<int, Model>` or `array<int, array<string, mixed>>` (raw attribute rows).
- `Closure` — resolved via `$this->evaluate()`; must return one of the above.

Per terminal, runtime validation:

- **`updateRecords`** requires each item to be an Eloquent `Model` (needs `getKey()` for write-back). A non-Model item → `RuntimeException("updateRecords source items must be Eloquent models, got {type}")`. The model class is also expected to match `->forModel()`'s class (warn / soft-check; same-class enforced — mixed-class collections are out of scope).
- **`createRecords`** accepts both `Model` items (each treated as its attribute array) and raw arrays. The most common shape is raw arrays from a parsed file.

## Per-record mechanics (v1: synchronous loop)

For every source row (each iteration):

1. Extract the row's attributes for context: `Model::getAttributes()` for Models (filtered to `promptContextColumns` if set; PK/timestamps/soft-delete column always stripped), or the array as-is.
2. **Append the row to the instruction** as `## Current record\n```json\n{json}\n```` (so the AI sees what it's transforming/enriching). The instruction itself (string/View/Closure prompt) is rendered once per iteration in case the prompt closure depends on per-iteration state — see "Prompt closure semantics" below.
3. Resolve the schema once (the model's writable columns via `ModelSchemaResolver` — same code path as `forModel` today, with enums/hints applied). Schema is per-iteration identical.
4. `executeAiCall(fn () => $agent->prompt($instruction, [], $provider, $model, $timeout))` → decoded `array<string, mixed>` of new attribute values.
5. Apply the terminal: `Model::create($newAttrs)` or `$existingRecord->update($newAttrs)`.
6. Catch any `Throwable` from steps 2–5 per row: `report($e)`, accumulate into a failures counter, continue.

After the loop: send one summary notification (`"Processed N, failed M."` — translated keys). **No new batch event** — `executeAiCall` already fires `SolarisResponseReceived`/`SolarisResponseFailed` once per iteration, so existing usage-tracking listeners pick up every call naturally; an aggregate event would be redundant.

### Prompt closure semantics

The `->prompt()` closure currently receives the action's host record via Filament DI (`$record`). For per-iteration loops, we explicitly pass the iteration row as a **second** named injection so the user can opt in:

```php
->prompt(fn ($row) => "Rewrite the meta_description for the article titled '{$row['title']}' …")
```

`$row` is the array form of the iteration item (a Model's attributes, or the raw array). Filament's default `$record` (the action's host) is unchanged — it remains whatever Filament resolves. Naming `$row` avoids overloading `$record` for the iteration item vs the host.

The default prompt path (no closure, just a string/View) is unchanged — the row's data is appended automatically (step 2 above).

## Schema & context

- `ModelSchemaResolver` is the existing one (spec 21 + 22). PK / timestamps / soft-delete columns are auto-excluded — so the AI never tries to set `id` / `created_at`, and `updateRecords` lifts `getKey()` from the source row, not from the AI output.
- `->promptContextColumns([...])` filters the row's attributes that get serialised into the `## Current record` block. Default = all attributes minus the auto-exclusions. Useful for privacy (don't send `password_hash` to the AI) and token-cost.
- `->columnHint()` and `->columnEnum()` from spec 22 apply unchanged.

## Validation (before execution)

- Exactly one terminal: `createRecords` ^ `updateRecords` ^ `handleUsing`. None set → "AiGenerateAction requires …".
- `createRecords` / `updateRecords` require `forModel()` (no custom `outputSchema` — the write-back needs a model).
- `updateRecords` requires `->records()` (the "no source + update" cell is deferred).
- `count()` is incompatible with `->records()` (the count comes from the source). Both set → runtime error.

## Errors & notifications

- **AI errors per row** flow through `executeAiCall` as today (per-row `SolarisResponseReceived` / `SolarisResponseFailed` + the matched user-facing error notification per row would be noisy — suppress the per-row error notifications during a loop and roll them into the summary instead).
- **Handler/write errors per row** → `report($e)`, increment failure counter, continue.
- **Summary notification** at end: success when M = 0 (`"Processed N records."`); warning when M > 0 (`"Processed N records, {M} failed — check logs."`). Translation keys: `notifications.batch_completed`, `notifications.batch_partial_failure`.

## Testing

**Fake extension.** The existing `AiGenerateAction::fake(array $response)` returns the single response for every call — fine for the seed/single-call cases. For the N-call loop we add:

```php
AiGenerateAction::fakeEach([
    ['name' => 'Alice', 'company' => 'Acme'],
    ['name' => 'Bob', 'company' => 'Initech'],
    ['name' => 'Carol', 'company' => 'Hooli'],
]);
```

The fake holds a queue of responses, consuming one per `executeFake()` invocation. If exhausted (more iterations than canned responses), the fake **throws** — catches a test bug where too few responses were provided. Both `fake()` (single-response shared across all calls) and `fakeEach()` (queued per call) are valid; use one per test.

**Feature tests** (extend `tests/Feature/AiGenerateActionTest.php`):

- **createRecords + records (import)**: source = `array<array>` of 3 rows; fakeEach 3 responses; assert 3 new `SeedCategory` rows created with the faked attributes.
- **updateRecords + records (enrich)**: pre-seed 2 `SeedCategory` rows; source = `SeedCategory::all()`; fakeEach 2 responses; assert both rows updated by id with the faked attributes; assert original `id`/`created_at` preserved.
- **Per-row partial failure**: 3 rows, middle row's AI call throws (use a fake that throws on the 2nd call); assert the other 2 succeed, failure is reported, summary notification has count "2 succeeded, 1 failed".
- **Validation**: `updateRecords` without `->records()` throws. `createRecords` + `updateRecords` together throws. `createRecords` + `outputSchema` (no forModel) throws.
- **`promptContextColumns`**: assert that with `->promptContextColumns(['name'])`, only the `name` attribute appears in the `## Current record` block of the prompt (read via the fake's recorded `$instruction` argument — add capture if needed).
- **`$row` prompt closure injection**: prompt closure using `$row['name']` produces an instruction containing the right value per iteration.
- **Source types**: a `Closure` returning a `Collection`; a `Builder` directly (executed lazily); an `array<array>`.

**Unit tests**: source-resolution helper (Builder → Collection; closure → call; type validation per terminal).

## Documentation

- `documentation/ai-generate-action.md`: a new top-level section "Record write-back & enrichment" covering the matrix, examples for the three working cells, `->records()` source types, `promptContextColumns`, the prompt-closure `$row` injection, and partial-failure handling. Trim "createRecords sugar" and "updateRecords / enrichment" from the deferred list.
- `README.md`: a new recipe "Enrich existing records" (sits naturally next to "Seed records from AI").
- `CHANGELOG.md` → `## [Unreleased]` → `### Added`.
- `specs/missing-features.md`: mark createRecords/updateRecords shipped under the existing AiGenerateAction entry.

## Out of scope (v1)

Listed verbatim so the deferred list stays explicit:

- **UserInput on `AiGenerateAction`** — paste/upload CSV → `->records()` source. Sketched in `specs/24-userinput-on-aigenerateaction.md`.
- **Batched mode** — one AI call returning N rows. Possible but harder (context window, key correlation). Tier-2.
- **Queued/async execution** — big imports (>50 rows) should queue with progress + completion notification. Compositional with this feature once the queued-execution feature lands.
- **`->quietly()` flag** — skip events/observers on create/update.
- **Auto-detect Filament BulkAction `$records`** — pull selected records from the table-action context without explicit `->records()`. Compositional, small follow-up.
- **Cross-model collections** for `updateRecords` (each item could be a different model class) — out of scope; require homogeneous Models matching `forModel()`'s class.
- **Concurrency** within v1's synchronous loop — sequential only; concurrency comes with the queued feature.
