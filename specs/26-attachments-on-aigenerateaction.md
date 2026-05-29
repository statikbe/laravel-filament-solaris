# 26 — Attachments on `AiGenerateAction`

## Summary

Wire the existing `HasAttachments` trait (already inherited from `SolarisAction`) into `AiGenerateAction`'s execute paths. Users can already call `->attachmentField()`, `->attachmentFromUserInput()`, and `->attachments()` on `AiGenerateAction` — those setters land harmlessly because the resolver is never invoked. This spec calls `resolveAttachments()` once per action invocation and passes the resulting `Files\File[]` to every `$agent->prompt(...)` call (single-call and per-row).

Concrete unlock: the spreadsheet-driven enrichment flow from spec 24's "Notes" — `->attachmentFromUserInput('csv')` lets the AI read the uploaded file directly instead of just receiving the path string in `$userInput['csv']`.

## Motivation

Spec 24 (UserInput on AiGenerateAction) shipped with this caveat in the docs:

> File uploads in the modal surface as path strings in `$userInput['<field>']` — your `->sourceRecords()` or `->prompt()` closure parses from there. Sending uploaded files to the AI as Image/Audio/Document attachments is a planned follow-up (separate spec).

User flagged 2026-05-27 that attachments "make it much more usable" for the matrix-cell enrich/import case: a CSV passed as text in the prompt is awkward; passing it as an attachment lets the model read it directly. Same for reference-image-driven generation flows.

The `HasAttachments` trait already exists, already lives on `SolarisAction`, and already powers `AiFormAction` and `ImageGenerationAction`. `AiGenerateAction` inherits the setters by accident of the trait split — this spec finishes the integration.

## Scope

**In:**
- Wire `resolveAttachments($userInput)` into the single-call execute path → `$agent->prompt($instruction, $attachments, ...)`.
- Wire it into the records-loop path → resolve **once** at `executeRecordsLoop()` top, thread the same `File[]` into every `generateForRow()` call and every per-row `$agent->prompt(...)` call.
- Extend `AiGenerateActionFake::recordCall` to capture the resolved `$attachments` array.
- New `assertCalledWithAttachments(Closure)` helper on the fake + static facade on `AiGenerateAction`.
- ~5 Pest tests covering the three channels + the records-loop path.
- Short docs section + CHANGELOG entry. Delete the now-stale "planned follow-up" bullet from the spec 24 docs.

**Out:**
- `$attachments` closure named-arg DI on `->prompt()` / `->handleUsing()` / `->sourceRecords()` (see Closure-DI section).
- `->attachmentDisk()` setter (AiFormAction doesn't have one either — inherit the trait's null default).
- Per-row attachment resolution (see Job-level vs per-row).
- Refinement / preview attachment turn-handling (`AiGenerateAction` doesn't support either; both throw).

## Job-level vs per-row resolution

Resolution is **job-level**: `resolveAttachments($userInput)` runs once at the top of each execute path. The same `File[]` is passed to every AI call within the invocation (single call, or N per-row calls in the records loop).

Two arguments:

1. **Matches the v1 mental model.** `$userInput` is already job-level — a single modal payload covers the whole batch. Attachments resolved from that payload are necessarily job-level too. The motivating use case ("the same CSV applies to every row") is exactly this shape.

2. **Composes with future batching.** When `AiGenerateAction` eventually moves from "1 prompt per row" to "1 prompt for M rows + error handling", job-level attachments keep working: every batch call receives the same `File[]` regardless of batch size. Per-row attachments would block batching outright — you'd have to either send the union of all rows' attachments per batch (defeating the consolidation), split batches by attachment groups (defeating the optimization), or pick a winner row (silently dropping the rest).

Consequence: closures supplied to `->attachments(Closure)` are evaluated at job-time, so `$row` is **not** in scope. If a future use case needs row-varying attachments, it's a separate spec.

## Closure-DI subtleties

No new `$attachments` named arg on `->prompt()`, `->handleUsing()`, or `->sourceRecords()`.

The resolved value is `Files\File[]` — opaque laravel/ai SDK types. What closures actually want is the upload path or original filename, which already comes through `$userInput['<field>']`. Closures that need to parse CSV bytes read from `Storage::disk(...)->get($path)`. Exposing `Files\File[]` in closure DI would mostly invite confusion (`$userInput['csv']` is a path string, `$attachments` is a wrapped File referring to the same upload — easy to conflate).

`AiFormAction` doesn't expose it in any of its closure surfaces either. Symmetry preserved. Trivial to add later if a real use case shows up.

## Modified: `src/Actions/AiGenerateAction.php`

Six touch-points. All small. No new traits, no new public setters (all three live on the inherited `HasAttachments`).

### `execute()` — single-call path

```php
// Before:
$response = $this->executeAiCall(
    fn () => $agent->prompt($instruction, [], $provider, $model, $timeout),
    $provider,
    $model,
);

// After:
$attachments = $this->resolveAttachments($userInput);

$response = $this->executeAiCall(
    fn () => $agent->prompt($instruction, $attachments, $provider, $model, $timeout),
    $provider,
    $model,
);
```

### `executeFake()`

```php
protected function executeFake(array $userInput = []): void
{
    $fake = AiGenerateActionFake::getInstance();
    $data = $fake->getResponse();
    $attachments = $this->resolveAttachments($userInput);
    $fake->recordCall($this->getName(), $data, $userInput, $attachments);

    // ... rest unchanged
}
```

### `executeRecordsLoop()`

Resolve once at the top, thread into `generateForRow`:

```php
protected function executeRecordsLoop(array $userInput = []): void
{
    $rows = $this->resolveRecordsSource($userInput);
    ['provider' => $provider, 'model' => $model] = $this->resolveProviderAndModel();
    $timeout = $this->resolveTimeout();
    $resolver = $this->resolveSchemaResolver();
    $attachments = $this->resolveAttachments($userInput);  // NEW

    $succeeded = 0;
    $failed = 0;

    foreach ($rows as $row) {
        try {
            $attrs = $this->generateForRow($row, $resolver, $provider, $model, $timeout, $userInput, $attachments);
            // ... rest unchanged
```

### `generateForRow()`

New trailing parameter `array $attachments = []`. Pass to `recordCall` in the fake branch and to `$agent->prompt(...)` in the real branch (replacing today's `[]`).

```php
protected function generateForRow(
    array|Model $row,
    Closure $resolver,
    mixed $provider,
    ?string $model,
    ?int $timeout,
    array $userInput = [],
    array $attachments = [],
): ?array {
    if (AiGenerateActionFake::isActive()) {
        $this->resolveInstructionForRow($row, $userInput);

        $fake = AiGenerateActionFake::getInstance();
        $data = $fake->getResponse();
        $fake->recordCall($this->getName(), $data, $userInput, $attachments);

        // ...
    }

    $instruction = $this->resolveInstructionForRow($row, $userInput);
    $agent = (new SolarisAgent)->configure($instruction, [], $resolver);
    $this->applyGenerationOptions($agent);

    $response = $this->executeAiCall(
        fn () => $agent->prompt($instruction, $attachments, $provider, $model, $timeout),
        $provider,
        $model,
        static fn (): null => null,
    );

    return $response?->toArray();
}
```

### Static facade

Add next to the existing `assertCalledWithUserInput`:

```php
public static function assertCalledWithAttachments(Closure $callback): void
{
    AiGenerateActionFake::getInstance()->assertCalledWithAttachments($callback);
}
```

## Modified: `src/Testing/AiGenerateActionFake.php`

### `recordCall` signature

```php
// Before:
public function recordCall(string $actionName, array $data, array $userInput = []): void
{
    $this->calls[] = ['name' => $actionName, 'data' => $data, 'userInput' => $userInput];
}

// After:
public function recordCall(
    string $actionName,
    array $data,
    array $userInput = [],
    array $attachments = [],
): void {
    $this->calls[] = [
        'name' => $actionName,
        'data' => $data,
        'userInput' => $userInput,
        'attachments' => $attachments,
    ];
}
```

Update the `$calls` array shape annotation accordingly.

### New `assertCalledWithAttachments` helper

Mirror of `assertCalledWithUserInput`:

```php
/**
 * Assert that at least one recorded call's $attachments satisfies the callback.
 *
 * @param  Closure(array<int, File>): bool  $callback
 */
public function assertCalledWithAttachments(Closure $callback): void
{
    Assert::assertNotEmpty($this->calls, 'Expected an AiGenerateAction call with attachments, but none was recorded.');

    foreach ($this->calls as $call) {
        if ($callback($call['attachments']) === true) {
            return;
        }
    }

    Assert::fail('No AiGenerateAction call matched the attachments callback.');
}
```

## Public API surface (cheat sheet)

`AiGenerateAction` gains nothing new in public setters — they all exist via the inherited `HasAttachments`. Public test API gains one helper:

| Method | Source | Shape |
|---|---|---|
| `->attachmentField(string\|array\|Closure)` | inherited (`HasAttachments`) | parent-form FileUpload field(s) |
| `->attachmentFromUserInput(string\|array\|Closure)` | inherited (`HasAttachments`) | UserInput modal FileUpload key(s) |
| `->attachments(File\|UploadedFile\|array\|Closure)` | inherited (`HasAttachments`) | programmatic / hardcoded |
| `AiGenerateAction::assertCalledWithAttachments(Closure)` | new | test helper |

Disk resolution inherits `HasAttachments::resolveAttachmentDisk()` → returns `null` → uses `config('filesystems.default')`.

## Error handling

Nothing new. The existing `executeRecordsLoop` try/catch around `generateForRow` already wraps the AI call, so a malformed attachment (bad path, missing file) surfaces as a per-row failure counted in the batch summary notification — not a 500.

`HasAttachments::resolveAttachments()` already swallows null/empty channels gracefully (the existing AiFormAction tests cover this).

## Testing

New file: `tests/Feature/AiGenerateActionAttachmentsTest.php`. Pattern mirrors `tests/Feature/AiFormActionAttachmentsTest.php`. Five tests:

1. **`->attachments(File)` static channel** — pass `Image::fromUrl(...)`; assert one `File` landed in `recordCall`.
2. **`->attachmentFromUserInput('csv')`** — exercise the UserInput modal data shape (the motivating use case from spec 24's deferred note); assert the resolved `File` came through.
3. **`->attachmentField('upload')`** — parent-form `FileUpload` channel; uses the existing `createTempUploadedFile` helper from `tests/Pest.php`.
4. **Records-loop path** — `fakeEach()` with 3 rows + `->attachmentFromUserInput('csv')`; assert that **every** captured `recordCall` carried the same `$attachments` (concrete verification of the job-level decision).
5. **No attachments configured** — baseline. `recordCall` receives `[]`; `assertCalledWithAttachments(fn ($a) => $a === [])` passes.

Three new fixture actions on `GenerateFormComponent` — one per channel. The records-loop test reuses the existing `userInputCreateRecordsLoopAction` with `->attachmentFromUserInput('csv')` added (or, if reuse causes cross-test pollution, defines its own twin fixture).

### Testing gotchas

- Filament/Livewire's `setActionData(['file_field' => [uuid => $tempFile]])` triggers Livewire's `_startUpload` which expects a raw `Symfony\UploadedFile` — see the gotcha in `project_attachments_feature.md`. Workaround already in use by `AiFormActionAttachmentsTest`: use string paths for modal attachment values in tests, or test against a `TextInput`-typed modal field with the same key (records the path channel without needing the FileUpload state shape).

## Documentation

### `documentation/ai-generate-action.md`

Add an "Attachments" subsection under the existing "User Input" section. Example mirroring the spec 24 spreadsheet flow:

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

Also delete this bullet from the spec 24 "Notes" block:

> File uploads in the modal surface as path strings in `$userInput['<field>']` … Sending uploaded files to the AI as Image/Audio/Document attachments is a planned follow-up (separate spec).

Replace with a single sentence pointing at the new section.

### `CHANGELOG.md`

One entry at the top of `## [Unreleased] > ### Added`:

```markdown
- `AiGenerateAction` now sends user-uploaded files to the AI as attachments
  (Image/Audio/Document, auto-detected by MIME). Use `->attachmentField()`,
  `->attachmentFromUserInput()`, or `->attachments()` — same three-channel API
  as `AiFormAction`. Resolution is job-level: the same `Files\File[]` flows
  to every per-row AI call in the records loop. `AiGenerateActionFake::recordCall`
  captures the resolved attachments; assert via
  `AiGenerateAction::assertCalledWithAttachments(Closure)`.
```

## Deferred / next

- `$attachments` closure named-arg DI on `->prompt()` / `->handleUsing()` / `->sourceRecords()`. Skipped per the Closure-DI section; trivial to add later.
- Per-row attachment resolution (e.g., `->attachments(fn ($row) => $row->reference_image)`). Blocked by the job-level decision and the future row-batching refactor; would need a separate spec.
- `->attachmentDisk()` setter. AiFormAction doesn't have one either; add to both at the same time if a need surfaces.

## Related

- `specs/24-userinput-on-aigenerateaction.md` — predecessor; this spec finishes the "Notes" deferral.
- `project_attachments_feature.md` (memory) — the original three-channel attachments arc on AiFormAction + ImageGenerationAction.
- `src/Concerns/HasAttachments.php` — the trait this spec wires in (no changes to it).
