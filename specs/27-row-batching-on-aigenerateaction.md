# 27 — Row batching on `AiGenerateAction`

## Summary

Refactor `AiGenerateAction`'s records-loop into a batched code path with a single unified response shape across all `forModel`-mode flows. `->batchSize($n)` (default 10) controls chunk size. A standardized `BatchResponse` DTO (`{records, failed}`) is the universal handler payload — there is no separate "per-row" shape, no `## Current record` legacy block, no `$row` closure arg. A `batchSize` of 1 is just a batch of 1.

Along the way: standardized failure reporting (`failed: [{identifier, reason}]`) covers both records-loop batching and the single-call `forModel + createRecords` path (textarea/CSV parsing); PK echo for `updateRecords`; injected `_index` for `createRecords + sourceRecords`; a latent bug in today's schema/data mismatch in the records-loop real path gets fixed for free by routing all responses through `BatchResponse::fromArray()`.

Closure API is a clean break (`$row` → `$rows`, `array $data` → `BatchResponse $data` in `handleUsing`) — the action isn't in production use yet.

## Motivation

The records loop today (spec 23 + 24) calls `$agent->prompt(...)` once per row. For a 50-row enrichment job that's 50 AI calls — 50× the system-prompt overhead, 50× the latency, 50× the per-call billing baseline. The per-row pattern was the simplest v1; row batching is the obvious follow-up.

The user flagged 2026-05-29 (during the spec 26 brainstorm): "1 prompt per row, to 1 prompt for multiple rows + error handling". This spec is that.

Secondary motivations:

- **Failed-row visibility.** Today, when 3 out of 50 rows fail (DB constraint, AI parse error, validation), the batch summary notification reports "47 succeeded, 3 failed" with no way to tell *which* 3 or *why*. A structured `failed: [{identifier, reason}]` schema convention gives the AI a way to surface problems, and the same convention applies to the single-call create path (textarea/CSV parsing) where today's failures are silent drops.
- **Unified response shape.** Today's `handleUsing` receives different `$data` shapes depending on which execute path fires — raw assoc for single-call, raw assoc per row for records loop. With BatchResponse universal, devs write one handler shape and reason about it once.
- **Bug fix.** Today's records-loop real path (non-fake) calls `$agent->prompt(...)` with a schema that asks for `{records: [...]}` but returns the whole envelope to `writeRow`, which calls `create($attrs)` on `{records: [{...}]}` — writing the envelope as model attributes. Tests don't catch it because `fakeEach` provides raw per-row dicts skipping the schema/envelope step. Routing everything through `BatchResponse::fromArray()` extracts `records` properly and `writeRow` only sees the inner record.

## Scope

**In:**

- `->batchSize(int|Closure $size)` fluent setter on `AiGenerateAction`. Default `10`. Resolves at execute time via `evaluate()`.
- Unified records-loop code path: `executeRecordsLoop()` chunks the source iterable, dispatches one AI call per batch via `processBatch()`. `generateForRow()` is **deleted**.
- Auto-appended `## Records` JSON block + `## Instructions` boilerplate in `forModel` mode, **always** (no batchSize fork). The `## Current record` legacy block is deleted.
- `forModel` schema unconditionally gains a `failed: array<{identifier, reason}>` field. Works in records-loop batches AND single-call create paths.
- Identifier conventions: PK column name + value for `updateRecords`; injected `_index: 0..N-1` for `createRecords + sourceRecords`. For single-call `createRecords` (no source), the LLM populates `identifier` freely from input context (line number, CSV row excerpt, etc.).
- Three new value-object DTOs in `src/Support/`: `BatchResponse`, `FailedRecord`, `BatchOutcome`.
- Reconciliation logic: identifier lookup, silent-drop detection, hallucinated-identifier handling, per-batch summary aggregation.
- Closure DI: `$rows` (`array<int, array<string, mixed>>`) replaces `$row`. `handleUsing` receives `BatchResponse $data` in `forModel` mode. `$row` declaration throws at execute time with a clear migration message — not a batch-mode-only guard, just "wrong arg name in the new shape".
- `assertHandledWith` callback receives `BatchResponse` instead of raw assoc — existing tests that use `$data['records'][0]` migrate to `$data->records[0]`.
- Fake: `fakeEach()` expects BatchResponse-shaped entries in `forModel` mode (`{records, failed}`), one per AI call. Raw per-row shape is no longer auto-detected — `forModel` records-loop tests always provide batched responses.
- Latent bug fix: today's records-loop real-AI path returns the schema envelope to `writeRow`; the unified `BatchResponse::fromArray()` strip fixes it.
- Tests: new `tests/Feature/AiGenerateActionBatchingTest.php` (~10 tests) + migration of every existing `forModel` records-loop test (~10-12 tests across `AiGenerateActionTest.php`, `AiGenerateActionAttachmentsTest.php`, `AiGenerateActionUserInputTest.php`).
- Documentation: rewrite the "Record Write-back & Enrichment" section in `documentation/ai-generate-action.md` to reflect the batched shape; new "Batching" subsection; CHANGELOG entry.

**Out:**

- `->withRetries($n)` for batch-level retry on transient failures. Deferred (see "Deferred / next").
- `->withTransaction()` for all-or-nothing semantics across batches. Deferred.
- Auto-token-budget batch sizing. Deferred — dev tunes manually for v1.
- Per-batch progress reporting during a multi-batch run. Out of scope — batch summary at the end is enough for v1.
- Streaming responses. Orthogonal (spec exists in `specs/missing-features.md` #3).
- Custom `outputSchema()` mode unification. Custom-schema users are in full control of prompt and schema; we don't auto-inject the `failed` array, don't auto-append `## Records`, and `handleUsing` still receives the raw assoc array. The DTO unification is a `forModel`-mode thing.

## Unified design

The single most important architectural decision in this spec: **in `forModel` mode, every path produces a `BatchResponse`**. There is no per-row data shape, no per-row prompt block, no per-row closure arg.

| Path | Today | After |
|---|---|---|
| Records loop, batched (`batchSize > 1`) | doesn't exist | `BatchResponse` per AI call |
| Records loop, single-row (`batchSize = 1`) | raw per-row dict | `BatchResponse` with `records` of length 1 |
| Single-call `createRecords` (no source) | raw `{records: [...]}` | `BatchResponse` (with `failed` for parse errors) |
| Single-call `handleUsing` (`forModel`) | raw assoc + derived `$records` | `BatchResponse $data` |
| Custom `outputSchema()` (any sub-path) | raw assoc | unchanged — raw assoc (DTO unification is `forModel`-only) |

Devs in `forModel` mode write one handler shape, regardless of batchSize, regardless of whether the records loop fires. `$data->records` is the array; `$data->failed` is the array of `FailedRecord` instances. Done.

## Public API

### `->batchSize($n)`

```php
->batchSize(int|Closure $size): static
```

- Default: `10`.
- `1` is a valid value — runs the same batched code path with batches of one. No legacy per-row shape.
- Closure form receives Filament DI plus `$userInput`: `->batchSize(fn (array $userInput) => (int) ($userInput['batch'] ?? 10))`.
- Validation: throws `RuntimeException` at execute time if resolved value is `<= 0`.

### Closure DI in `forModel` mode

```php
->prompt(fn (array $rows, array $userInput) => "Categorize the records below.")
->handleUsing(fn (BatchResponse $data, array $userInput) => ...)
->sourceRecords(fn (array $userInput) => Article::all())          // unchanged — returns full set, we batch it
```

`$rows` is `array<int, array<string, mixed>>` — N rows of attributes (always N ≥ 1; the records loop only fires when there's a source). For `updateRecords`, each entry is `$model->getAttributes()` enriched with the PK column. For `createRecords + sourceRecords`, each entry is the input array enriched with an `_index` integer key.

**Migration guard**: if any closure declares `$row` (singular), `executeRecordsLoop` throws at execute time:

```
LogicException: AiGenerateAction closures must declare `$rows` (plural), not `$row`.
The single-row code path was removed in spec 27; even at batchSize=1, closures
receive a batch (array of rows). See documentation/ai-generate-action.md#batching.
```

Detected via `ReflectionFunction` inspection of the closure's parameter names. The action isn't in production use yet, so this is internal-only migration.

### Custom `outputSchema()` mode

Unchanged. `->prompt(fn ($rows, $userInput) => ...)` still receives `$rows` in the records loop (the loop chunking applies regardless of schema source), but `handleUsing` receives the raw assoc as today, the schema is dev-controlled, no `## Records` auto-boilerplate is appended, no `failed` array is auto-injected. Devs who want batching with failure reporting in custom mode define the matching schema shape themselves.

### DTOs (new files in `src/Support/`)

```php
final readonly class BatchResponse
{
    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, FailedRecord>  $failed
     */
    public function __construct(
        public array $records,
        public array $failed,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            records: $payload['records'] ?? [],
            failed: array_map(
                fn (array $row): FailedRecord => new FailedRecord(
                    identifier: $row['identifier'] ?? null,
                    reason: (string) ($row['reason'] ?? ''),
                ),
                $payload['failed'] ?? [],
            ),
        );
    }
}

final readonly class FailedRecord
{
    public function __construct(
        public mixed $identifier,   // PK value, _index int, or freeform string (textarea/CSV mode)
        public string $reason,
    ) {}
}

final readonly class BatchOutcome
{
    /**
     * @param  array<int, FailedRecord>  $failures
     */
    public function __construct(
        public int $succeeded,
        public array $failures,                  // includes AI-reported, silent drops, hallucinated
    ) {}
}
```

## Modified: `src/Actions/AiGenerateAction.php`

### `executeRecordsLoop` — chunk and dispatch per batch

```php
protected function executeRecordsLoop(array $userInput = []): void
{
    $this->guardClosureArgs();   // throws if any closure declares $row

    $rows = $this->resolveRecordsSource($userInput);
    ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
    $timeout = $this->resolveTimeout();
    $resolver = $this->resolveSchemaResolver();
    $attachments = $this->resolveAttachments($userInput);
    $batchSize = $this->resolveBatchSize($userInput);

    $succeeded = 0;
    $failures = [];

    foreach ($this->chunkRows($rows, $batchSize) as $batch) {
        try {
            $outcome = $this->processBatch($batch, $resolver, $provider, $model, $timeout, $userInput, $attachments);

            $succeeded += $outcome->succeeded;
            $failures = array_merge($failures, $outcome->failures);
        } catch (AiGenerateActionFakeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $failures = array_merge($failures, $this->markBatchFailed($batch, 'AI call error'));
        }
    }

    $this->sendBatchSummary($succeeded, count($failures));
}
```

### `processBatch` — new (replaces `generateForRow`)

```php
protected function processBatch(
    array $batch,
    Closure $resolver,
    mixed $provider,
    ?string $model,
    ?int $timeout,
    array $userInput,
    array $attachments,
): BatchOutcome {
    if (AiGenerateActionFake::isActive()) {
        return $this->processFakeBatch($batch, $userInput, $attachments, $provider, $model);
    }

    $instruction = $this->buildBatchInstruction($batch, $userInput);
    $agent = (new SolarisAgent)->configure($instruction, [], $resolver);
    $this->applyGenerationOptions($agent);

    $response = $this->executeAiCall(
        fn () => $agent->prompt($instruction, $attachments, $provider, $model, $timeout),
        $provider,
        $model,
        static fn (): null => null,
    );

    if ($response === null) {
        return new BatchOutcome(0, $this->markBatchFailed($batch, 'AI call error'));
    }

    $batchResponse = BatchResponse::fromArray($response->toArray());

    return $this->reconcileBatch($batch, $batchResponse);
}
```

### `buildBatchInstruction` — new (replaces `resolveInstructionForRow`)

Auto-appends `## User context`, `## Records`, and `## Instructions`:

```php
protected function buildBatchInstruction(array $batch, array $userInput): string
{
    $instruction = $this->instruction;

    if ($instruction instanceof Closure) {
        $rows = array_map(
            fn ($row): array => $row instanceof Model ? $row->getAttributes() : $row,
            $batch,
        );
        $instruction = $this->evaluate($instruction, [
            'rows' => $rows,
            'userInput' => $userInput,
        ]);
    }

    if ($instruction instanceof View) {
        $instruction = $instruction->render();
    }

    $instruction = (string) $instruction;
    $instruction = $this->appendUserContext($instruction, $userInput);
    $instruction = $this->appendRecordsBlock($instruction, $batch);
    $instruction = $this->appendBatchInstructions($instruction, $batch);

    return $instruction;
}
```

### `appendRecordsBlock` — new

Serializes the batch as a JSON array with identifier enrichment:

```php
protected function appendRecordsBlock(string $instruction, array $batch): string
{
    [, $rows] = $this->enrichBatchWithIdentifier($batch);

    $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return trim($instruction)."\n\n## Records\n```json\n{$json}\n```";
}

/**
 * @return array{0: string, 1: array<int, array<string, mixed>>}
 */
protected function enrichBatchWithIdentifier(array $batch): array
{
    if ($this->writeTerminal === self::WRITE_UPDATE) {
        // updateRecords: PK echo. Source rows are always Eloquent models (validated upstream).
        $first = $batch[0];
        assert($first instanceof Model);
        $identifierKey = $first->getKeyName();

        $rows = array_map(function (Model $row) use ($identifierKey): array {
            $attrs = $this->buildContextForRow($row);
            // PK is always included regardless of promptContextColumns whitelist.
            $attrs[$identifierKey] = $row->getKey();

            return $attrs;
        }, $batch);

        return [$identifierKey, $rows];
    }

    // createRecords + sourceRecords: inject _index.
    $identifierKey = '_index';
    $rows = [];
    foreach ($batch as $index => $row) {
        $attrs = $this->buildContextForRow($row);
        $attrs[$identifierKey] = $index;
        $rows[] = $attrs;
    }

    return [$identifierKey, $rows];
}
```

### `appendBatchInstructions` — new

```php
protected function appendBatchInstructions(string $instruction, array $batch): string
{
    [$identifierKey] = $this->enrichBatchWithIdentifier($batch);

    $boilerplate = <<<TXT
## Instructions
For each record above, return an entry in `records` echoing the `{$identifierKey}` field unchanged with the processed fields.
For any record you cannot process, add an entry to `failed` with the `identifier` set to the `{$identifierKey}` value and a short `reason` (max 200 chars).
Preserve input order in the `records` array.
TXT;

    return trim($instruction)."\n\n".$boilerplate;
}
```

### `reconcileBatch` — new

```php
protected function reconcileBatch(array $batch, BatchResponse $response): BatchOutcome
{
    [$identifierKey] = $this->enrichBatchWithIdentifier($batch);

    // Build identifier -> input row lookup.
    $lookup = [];
    foreach ($batch as $index => $row) {
        $id = $identifierKey === '_index' ? $index : $row->getKey();
        $lookup[(string) $id] = $row;
    }

    $succeeded = 0;
    $failures = [];

    foreach ($response->records as $outputRecord) {
        $id = $outputRecord[$identifierKey] ?? null;

        if ($id === null || ! isset($lookup[(string) $id])) {
            report(new RuntimeException("AiGenerateAction: hallucinated or missing identifier in records output: ".json_encode($outputRecord)));
            continue;
        }

        $row = $lookup[(string) $id];
        unset($lookup[(string) $id]);

        // Strip identifier from attrs before write — it's bookkeeping, not data.
        $attrs = $outputRecord;
        unset($attrs[$identifierKey]);

        try {
            $this->writeRow($row, $attrs);
            $succeeded++;
        } catch (\Throwable $e) {
            report($e);
            $failures[] = new FailedRecord(identifier: $id, reason: 'write error: '.$e->getMessage());
        }
    }

    foreach ($response->failed as $failure) {
        $id = $failure->identifier;

        if ($id !== null && isset($lookup[(string) $id])) {
            unset($lookup[(string) $id]);
        }

        $failures[] = $failure;
    }

    // Whatever's left in $lookup is a silent drop.
    foreach ($lookup as $id => $row) {
        $failures[] = new FailedRecord(identifier: $id, reason: 'no response from AI');
    }

    return new BatchOutcome($succeeded, $failures);
}
```

### `chunkRows` — new

Wraps the source iterable into batches. Handles `Collection`, `EloquentCollection`, and `array` sources via `array_chunk` or `Collection::chunk` as appropriate. Returns an iterable of arrays (each a batch).

### `markBatchFailed` — new

Produces N `FailedRecord` instances when a whole batch is marked failed:

```php
protected function markBatchFailed(array $batch, string $reason): array
{
    [$identifierKey] = $this->enrichBatchWithIdentifier($batch);

    return array_map(function ($row) use ($identifierKey, $reason): FailedRecord {
        $id = $identifierKey === '_index'
            ? null  // can't recover index outside the foreach
            : $row->getKey();

        return new FailedRecord(identifier: $id, reason: $reason);
    }, $batch);
}
```

(For `_index` identifiers the per-row index is lost without the foreach key — acceptable, since the dev gets the aggregate batch count anyway.)

### `guardClosureArgs` — new

Inspects every dev-supplied closure (`->prompt()`, `->handleUsing()`, `->sourceRecords()`, `->attachments()`, `->batchSize()`) via `ReflectionFunction::getParameters()`. Throws `LogicException` if any declares `$row` (singular). One place, called at the top of both `execute()` and `executeRecordsLoop()`.

### `resolveInstruction` (single-call path) — updated

For the single-call `forModel + createRecords` path (no source), append the same `## Instructions` boilerplate (sans `## Records` since there's no input batch). The LLM is told to populate `failed` if any input it parses (textarea, CSV, attachment) doesn't yield a valid record.

```php
protected function resolveInstruction(array $userInput = []): string
{
    // ... existing logic (closure evaluation, View render, count append) ...

    $instruction = $this->appendUserContext($instruction, $userInput);

    if ($this->modelClass !== null) {
        $instruction = $this->appendSingleCallInstructions($instruction);
    }

    return $instruction;
}

protected function appendSingleCallInstructions(string $instruction): string
{
    $boilerplate = <<<'TXT'
## Instructions
Return generated records in the `records` array.
For any input you cannot process (e.g., malformed line, ambiguous source data), add an entry to `failed` with an `identifier` describing the failed input (line number, source excerpt) and a short `reason`.
TXT;

    return trim($instruction)."\n\n".$boilerplate;
}
```

### `dispatchSingleResponse` — updated

Replace the raw assoc handling with `BatchResponse` parse:

```php
protected function dispatchSingleResponse(array $data, array $userInput = []): void
{
    try {
        if ($this->modelClass !== null) {
            // forModel mode: parse as BatchResponse for unified handling.
            $batchResponse = BatchResponse::fromArray($data);

            if ($this->writeTerminal === self::WRITE_CREATE) {
                $succeeded = 0;
                foreach ($batchResponse->records as $row) {
                    try {
                        $this->modelClass::create($row);
                        $succeeded++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                if ($batchResponse->failed !== []) {
                    $this->sendBatchSummary($succeeded, count($batchResponse->failed));
                }

                return;
            }

            // handler mode in forModel: pass BatchResponse.
            $this->evaluate($this->handler, [
                'data' => $batchResponse,
                'userInput' => $userInput,
            ]);

            return;
        }

        // custom outputSchema mode: raw assoc, unchanged.
        $this->evaluate($this->handler, [
            'data' => $data,
            'userInput' => $userInput,
        ]);
    } catch (\Throwable $e) {
        report($e);
        Notification::make()
            ->title(filament_solaris_trans('notifications.handler_error'))
            ->danger()
            ->send();
    }
}
```

(The `'records' => ... ` named arg on handleUsing closures is removed — devs read `$data->records` instead. This is part of the breaking change.)

## Modified: schema resolver (`forModel` mode)

```php
return function (JsonSchemaTypeFactory $schema) use ($identifierKey): array {
    $properties = (new ModelSchemaResolver)->resolve(...);
    $properties[$identifierKey] = $identifierKey === '_index'
        ? $schema->integer()->description('The _index field from the input record. Echo unchanged.')
        : $schema->integer()->description('The primary key. Echo unchanged.');

    return [
        self::RECORDS_KEY => $schema->array()->items($schema->object($properties)),
        'failed' => $schema->array()->items($schema->object([
            'identifier' => $schema->string()->description('Identifier of the failed input row.'),
            'reason' => $schema->string()->description('Short reason for the failure (max 200 chars).'),
        ])),
    ];
};
```

`$identifierKey` is `_index` in the single-call `createRecords` path (the LLM populates it freely from input context) and `_index` or PK column name in the records-loop path.

## Modified: `src/Testing/AiGenerateActionFake.php`

### `fakeEach` (no API change, just semantic)

Each entry is now expected to be a BatchResponse-shape dict in `forModel` mode:

```php
AiGenerateAction::fakeEach([
    [   // response to batch 1
        'records' => [
            ['id' => 1, 'name' => 'A', 'slug' => 'a'],
            ['id' => 2, 'name' => 'B', 'slug' => 'b'],
        ],
        'failed' => [],
    ],
    [   // response to batch 2
        'records' => [['id' => 3, 'name' => 'C', 'slug' => 'c']],
        'failed' => [['identifier' => 4, 'reason' => 'malformed']],
    ],
]);
```

### `fake` (no API change, just semantic)

The single canned response is also expected in BatchResponse shape in `forModel` mode.

### `assertHandledWith` (no signature change, payload change)

The callback now receives `BatchResponse` instead of raw assoc. Tests using `$data['records'][0]` migrate to `$data->records[0]`.

### `assertCalledWithBatch` — new

```php
/**
 * Assert that at least one recorded call's input batch satisfies the callback.
 *
 * @param  Closure(array<int, array<string, mixed>>): bool  $callback
 */
public function assertCalledWithBatch(Closure $callback): void
```

The fake's `recordCall` gains a 5th positional arg: `array $batch = []` — the input batch (array of row attribute arrays) that the AI call was made with. Mirrors `assertCalledWithUserInput` / `assertCalledWithAttachments`.

## Public API surface (cheat sheet)

| Method | Added/Changed | Shape |
|---|---|---|
| `->batchSize(int\|Closure)` | new | sets batch size; default 10; `1` is valid |
| `->prompt()` closure DI | changed | `$rows` (always plural); `$row` declaration throws |
| `->handleUsing()` closure DI | changed | `$data` is `BatchResponse` in `forModel` mode; raw assoc in custom-schema mode |
| `->sourceRecords()` closure DI | unchanged | returns the full source; we batch it |
| `AiGenerateAction::assertCalledWithBatch(Closure)` | new | fake helper |
| `BatchResponse` (DTO) | new | `src/Support/BatchResponse.php` |
| `FailedRecord` (DTO) | new | `src/Support/FailedRecord.php` |
| `BatchOutcome` (DTO) | new | `src/Support/BatchOutcome.php` (internal-leaning) |

## Removed

- `generateForRow()` method — collapsed into `processBatch()`.
- `resolveInstructionForRow()` method — collapsed into `buildBatchInstruction()`.
- `## Current record` prompt block — replaced everywhere by `## Records`.
- `'row' => $row` closure named arg in `evaluate()` calls — replaced by `'rows' => $rows`.
- `'records' => ...` closure named arg on `handleUsing` — read `$data->records` instead.
- The legacy "raw per-row dict" `fakeEach` shape in `forModel` records-loop tests — provide BatchResponse-shaped dicts.

## Error handling

- **Batch-level AI failure** (5xx, timeout, parse error on structured output): `executeAiCall` returns null → entire batch counted as failed via `markBatchFailed` → reconciliation skipped → next batch.
- **Per-row write failure** (DB constraint, validation): caught inside `reconcileBatch`'s per-record loop, increments `failures` with the AI's `identifier` + the exception message as `reason`.
- **Schema validation failure** (laravel/ai's structured-output parse fails): same as batch-level AI failure → null → all batch rows marked failed.
- **Hallucinated identifiers** (LLM returns `records[i].identifier = 9999` not in input): `report()` for visibility, skip the entry, don't double-count.
- **`_index` collisions across batches**: not possible — `_index` is 0..N-1 within a batch, and reconciliation completes per-batch before moving to the next. Different batches have independent identifier spaces.
- **PK collisions across batches**: also not possible — every Eloquent row has a unique PK.
- **Empty source**: `executeRecordsLoop` runs zero iterations, sends `sendBatchSummary(0, 0)`. Unchanged from today.
- **`AiGenerateActionFakeException`**: re-thrown, never silently counted.

## Edge cases worth calling out

- `batchSize > total rows`: single batch contains all rows. Works.
- `batchSize = 1`: still uses `processBatch` with `array_chunk(..., 1)`. Prompt shape, schema, closure args all identical to larger batchSize values. No special code path.
- `->count(N)` with `->sourceRecords()`: existing validation throws ("count incompatible with sourceRecords"). Unchanged.
- `updateRecords` source rows that aren't Eloquent models: existing validation throws upstream. `enrichBatchWithIdentifier` asserts via `assert($first instanceof Model)`.

## Testing

New test file: `tests/Feature/AiGenerateActionBatchingTest.php`. ~10 tests:

1. **Happy path, single batch**: 5 rows, batchSize=10 → 1 AI call, 5 records out, all written.
2. **Happy path, multiple batches**: 25 rows, batchSize=10 → 3 batches (10/10/5), all succeed.
3. **batchSize=1 still uses the batched code path**: 3 rows → 3 AI calls, each with a single-record BatchResponse, all succeed.
4. **Batch-level total failure**: 2 batches, 1 fails (AI returns null) → first batch's 10 rows counted succeeded, second batch's 10 rows counted failed with reason "AI call error".
5. **LLM-reported `failed` array**: response carries 8 records + 2 failed → 8 succeeded, 2 failed with the AI's reasons logged.
6. **Silent drops**: input batch of 10, response has 7 records + 0 failed → 7 succeeded, 3 silent drops counted with reason "no response from AI".
7. **Hallucinated identifier**: response has `records[i].identifier = 9999` not in input → logged via `report()`, skipped, doesn't poison counts.
8. **`$row` closure throws** at execute time with the migration message.
9. **`$rows` closure receives the batch correctly** — array of N attribute arrays.
10. **Single-call `createRecords` with `failed` array** — textarea-paste-like scenario; AI reports 2 failures with freeform identifiers; notification surfaces them.

Existing tests needing migration to the BatchResponse fake shape:
- `tests/Feature/AiGenerateActionTest.php` — `importCategories`, `enrichCategories`, any records-loop test using `fakeEach`. Also any test asserting `$data['records']` in `handleUsing` callbacks.
- `tests/Feature/AiGenerateActionAttachmentsTest.php` — the records-loop test ("threads the same attachments to every per-row call").
- `tests/Feature/AiGenerateActionUserInputTest.php` — `userInputCreateRecordsLoop`, `userInputSourceRecordsClosure`.

Estimated: ~10-12 tests across the three files need their `fakeEach` payloads reshaped and any per-row assertions updated.

### Testing gotchas

- The `report()` calls for hallucinated identifiers and write-row failures need silencing in tests (use `Log::spy()` or the existing project pattern — check `AiGenerateActionTest` for prior art).
- The `ReflectionFunction` parameter-name check for `$row` detection must handle both fn-shorthand and regular closures. Use `getParameters()->getName()`.

## Documentation

### `documentation/ai-generate-action.md`

Substantial rewrite of the "Record Write-back & Enrichment" section to reflect the batched shape. New "Batching" subsection covers:

- The `->batchSize($n)` setter, default, validation.
- Tuning guidance: "start at 10; reduce if row data is large; increase up to the context-window limit if AI cost dominates".
- The auto-prompt shape (sketched), so devs know what's being injected.
- The `failed` array convention, with one example notification render.
- The closure DI surface (`$rows`, `BatchResponse $data`).
- Note about timeout/maxSteps now being per-batch, not per-row.

The existing "User Input" and "Attachments" sections need small touch-ups to reference `$rows` instead of `$row` in their code examples.

### `CHANGELOG.md`

Two entries:
- `### Added`: `->batchSize($n)`, `BatchResponse`/`FailedRecord`/`BatchOutcome` DTOs, `failed` array convention, `assertCalledWithBatch` fake helper.
- `### Changed` (breaking): `$row` → `$rows` closure DI, `handleUsing` receives `BatchResponse $data` in `forModel` mode, `## Current record` block replaced by `## Records`, `fakeEach` expects BatchResponse-shaped entries in `forModel` mode.

## Deferred / next

- `->withRetries($n)` — automatic retry on batch-level failures (transient API errors). User-flagged 2026-06-01.
- `->withTransaction()` — wrap all batches in a DB transaction; if any batch fails, rollback everything. User-flagged 2026-06-01.
- Per-batch progress notifications during a multi-batch run (live "5/10 batches complete" updates via Livewire events).
- Auto-token-budget batching (dev sets a token budget instead of a row count; we estimate row token cost and pack).
- Custom `outputSchema()` mode opt-in failure-reporting helper (e.g., `->withFailureReporting()` that auto-injects `failed` into the dev's schema).

## Related

- `specs/26-attachments-on-aigenerateaction.md` — predecessor; the user surfaced this batching spec during that brainstorming.
- `specs/24-userinput-on-aigenerateaction.md` — the records-loop path being refactored.
- `specs/23-record-writeback-enrichment.md` — original records-loop spec.
- `src/Actions/AiGenerateAction.php` — most of the code lives here.
