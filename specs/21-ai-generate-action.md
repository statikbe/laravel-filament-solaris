# 21 — AiGenerateAction (structured output → custom handler)

## Summary

A form-agnostic AI action that generates **structured data** against a schema you control and hands the parsed result to **your closure** — instead of writing into form fields like `AiFormAction`. It's the "non-form action" the `SolarisAction` / `HasFormPipeline` split was built for: table actions, seeders, and information-gathering tasks.

```php
// Custom schema → your handler
AiGenerateAction::make('build-taxonomy')
    ->prompt(fn ($record) => "Generate a category taxonomy for {$record->topic}.")
    ->outputSchema(fn ($s) => ['taxonomy' => $s->array()->items($s->object([
        'name' => $s->string(),
        'slug' => $s->string(),
    ]))])
    ->handleUsing(fn (array $data) => /* build records from $data['taxonomy'] */);

// Model-derived schema → seed records
AiGenerateAction::make('seed-categories')
    ->prompt('Generate realistic blog categories.')
    ->forModel(Category::class)
    ->count(20)
    ->handleUsing(fn (array $records) => Category::query()->insert($records));
```

Pairs with `AiFormAction` as the two core AI primitives: **`AiFormAction`** (AI → form fields) and **`AiGenerateAction`** (AI → your handler).

## Motivation

Today the text pipeline is wired to form fields at both ends: the output schema is derived from target-field factories, and `writeResults()` (form `Set`) is the only sink. There's no way to (a) request an arbitrary JSON shape or (b) do something other than fill fields with the result. This action supplies both, enabling: seeding records from AI, generating taxonomies/option sets, extracting structured info for display, etc. The just-shipped `->prompt()` closure (with `$record`/Filament DI) means a standalone action needs **no source fields** — the prompt pulls whatever input it needs.

## Public API

`AiGenerateAction extends SolarisAction`.

| Method | Notes |
|--------|-------|
| `make(string $name)` | factory |
| `prompt(string\|View\|Closure)` | the instruction; reuses the existing prompt builders + closure DI (`$record`, `$livewire`, …). No `$sourceData` (no source fields). |
| `outputSchema(Closure)` | custom output schema: `fn (JsonSchema $s): array<string, Type>`. Mutually exclusive with `forModel()`. (Named `outputSchema`, not `schema`, because `Filament\Actions\Action` already defines `schema()` for the modal form.) |
| `forModel(class-string<Model>)` | derive the schema from an Eloquent model (see introspection rules). Mutually exclusive with `outputSchema()`. |
| `count(int)` | (forModel only) how many records to request; default `1`. Conveyed to the model and shapes the schema as an array. |
| `only(array<string>)` / `except(array<string>)` | (forModel only) refine the included columns. |
| `handleUsing(Closure)` | required. Receives the parsed response + Filament DI (see handler contract). Replaces the form-write sink. |
| inherited from `SolarisAction` | `provider()/model()/timeout()`, generation options, icon, the panel visibility gate, usage events. |

### Handler contract

`handleUsing()` runs **after** a successful AI call, resolved via `$this->evaluate()` so it gets full Filament DI. Injected, in addition to Filament's defaults (`$record`, `$livewire`, `$component`, `$get`, `$operation`, …):

- **`$data`** — always — the full decoded response array (e.g. `['taxonomy' => [...]]` or `['records' => [...]]`).
- **`$records`** — when `forModel()` is used — convenience alias for `$data['records']` (the list of attribute arrays).

```php
->handleUsing(fn (array $data) => Term::insertTaxonomy($data['taxonomy']))     // custom schema
->handleUsing(fn (array $records) => Category::query()->insert($records))      // forModel
```

The handler owns its own success feedback (it knows the domain outcome — "Created 20 categories"); the action sends **no automatic success notification**. If the handler throws, it's caught and a generic error notification is shown (so a bad run doesn't 500).

## `forModel()` — simplified blend (v1)

Resolved by a dedicated `ModelSchemaResolver` (the model-side analogue of `ComponentFactory`):

1. Instantiate the model; read its table via `Schema::getColumns($table)`.
2. **Writable columns:** `$fillable` if non-empty, else all columns minus `$guarded`.
3. **Auto-exclude:** primary key (`getKeyName()`), `created_at`/`updated_at` (when `$timestamps`), `deleted_at` (when `SoftDeletes`).
4. **`->only()` / `->except()`** refine the set.
5. **Type map** (column type → `JsonSchema` Type):
   - string / char / varchar / text → `string`
   - integer / bigint / smallint / tinyint → `integer`
   - boolean → `boolean`
   - decimal / float / double / numeric → `number`
   - date / datetime / timestamp → `string`
   - anything else (incl. `json`, `enum`) → `string`
6. **Nullability:** non-nullable column → required property; nullable → optional.
7. The resolver returns the object's `array<string, Type>` properties; `AiGenerateAction` wraps them as `['records' => $s->array()->items($s->object($properties))]`, and appends a concise "Generate {count} records." line to the instruction.

**Deliberately deferred ("gradually add"):** enum value lists, `json`/`jsonb` columns as objects/arrays, string `maxLength` from column length, per-field hints, FK/relationship handling, casts-only (DB-less) inference, validation-rule-derived constraints, DI in the schema closure.

## Internals

- **`SolarisAgent`** gains a schema-resolver path: `configure()` accepts an optional `Closure $schemaResolver`; `schema(JsonSchema $s)` returns `($schemaResolver)($s)` when set, else maps factories (unchanged for `AiFormAction`).
- **`AiGenerateAction`** orchestrates: build instruction (prompt builder; closure resolved via `evaluate()`), resolve the schema resolver (`outputSchema()` closure, or one produced by `ModelSchemaResolver`), `createAgent()` → `configure(instruction, [], $schemaResolver)` → `executeAiCall(agent->prompt(...))` (so `SolarisResponseReceived/Failed` + provider notifications fire), then dispatch the decoded array to `handleUsing()`.
- **Shared core / light factoring:** the prompt-building (`prompt()` + builder selection + instruction render) and the generation-options setters are currently inside `HasPromptPipeline` (form-coupled). The implementation plan will extract the form-agnostic parts (a `HasPrompt` concern, and the generation-options setters) so `AiGenerateAction` reuses them **without** inheriting `sourceFields()/targetFields()`. The 460-test suite guards `AiFormAction` against regressions during that factoring. (If factoring proves invasive, the fallback is localized reuse of the prompt builders + a small options duplication — decided in the plan, not here.)
- **No preset support** for v1 (presets are prompt-shapers tied to the form/target-field flow); `AiGenerateAction` uses a plain instruction.

## Validation (before execution)

- Exactly one schema source: `outputSchema()` **or** `forModel()` (neither → error; both → error).
- A `handleUsing()` handler is required (none → error).
- `count()` / `only()` / `except()` are only meaningful with `forModel()` (ignored otherwise, or a dev-facing warning).

## Errors & notifications

- AI/provider errors flow through the existing `executeAiCall` (rate-limit / overloaded / generic notifications + `SolarisResponseFailed`).
- Handler exceptions are caught → generic error notification (`report()`ed for the tracker).
- No automatic success notification — the handler owns success feedback.

## Testing

- **`AiGenerateActionFake`**: `AiGenerateAction::fake(['records' => [...]])` provides a canned decoded response, skips the real AI call, dispatches the fake usage event, and **still runs the handler** with the faked data (so tests assert real effects — e.g. records created). Assertions: `assertCalled()`, `assertCalledTimes()`, `assertHandledWith(fn (array $data) => …)`, `assertNotCalled()`.
- **Feature tests**: custom-schema path (handler receives `$data`, runs); `forModel()` path (handler receives `$records`, creates rows — use an in-memory/SQLite test model + migration); validation errors (missing handler, both/neither schema source); handler-throw → error notification; `$record` DI into prompt + handler.
- **Unit**: `ModelSchemaResolver` type mapping, exclusions, only/except, nullability.
- Gates: PHPStan level 5, Pint, full Pest suite.

## Documentation

- `documentation/ai-generate-action.md` — full reference (custom schema, forModel + simplified rules + deferred list, handler contract, testing).
- `README.md` — recipes: "Seed records from AI" and "Generate a taxonomy"; mention the `AiFormAction` vs `AiGenerateAction` pairing in "What you get".
- `CHANGELOG.md` — `Added` entry under `[Unreleased]`.

## Out of scope (v1)

Preview / conversational refinement for `AiGenerateAction`; a `->createRecords()` convenience handler (handler is explicit for now); plain-text (non-structured) output handlers; preset support; and the deferred `forModel` introspection refinements listed above.
