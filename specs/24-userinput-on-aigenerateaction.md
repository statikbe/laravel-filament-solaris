# 24 — UserInput on `AiGenerateAction` (Deferred / Design Sketch)

> **Status: deferred** — design sketch for a future iteration. Captured here so the design intent isn't lost between specs 23 and the eventual implementation. Refine this file when picked up.

## Summary

Add `UserInput`-modal support to `AiGenerateAction`, mirroring the pattern already shipped on `AiFormAction` and `DictationFieldAction`. The most concrete unlock: runtime user-provided sources for `->sourceRecords()` (paste/upload a CSV or Excel) without each consumer rolling its own Livewire modal.

## Motivation

`AiGenerateAction` shipped (spec 21) intentionally without `UserInput` — `hasUserInput()` is hardcoded `false`, the action runs on click without a modal. That kept the v1 surface small.

The matrix from spec 23 left one cell empty by design:

| | no `->sourceRecords()` | with `->sourceRecords()` |
|---|---|---|
| `->updateRecords()` | **deferred → this spec** | enrich (shipped in 23) |

"Paste a spreadsheet of `(id, payload)` rows and enrich each by id" is a real workflow (legacy migrations, batch ops against external data). Today you'd build a custom Livewire form to collect the file/paste, parse it, and call the action programmatically. With `UserInput` on `AiGenerateAction`, the user's `->sourceRecords()` closure simply reads from the modal:

```php
AiGenerateAction::make('enrich-from-spreadsheet')
    ->userInput(UserInput::make()->fields([
        FileUpload::make('csv')->acceptedFileTypes(['text/csv']),
    ]))
    ->forModel(Article::class)
    ->sourceRecords(fn ($livewire) => Article::query()
        ->whereIn('id', collect(parseCsv($livewire->mountedActions[0]['data']['csv']))->pluck('id'))
        ->get())
    ->updateRecords();
```

No new dedicated API needed — `UserInput` + the closure-based `->sourceRecords()` (already polymorphic in spec 23) compose to fill the cell.

## Design sketch

- Make `AiGenerateAction` consume `UserInput` the same way `AiFormAction` does:
  - Reuse the existing `HasUserInput` trait (or its replacement) — keep one source of truth.
  - `hasUserInput()` returns true when `->userInput()` is set, false otherwise.
  - The action gains a modal-on-click flow: when `UserInput` is set, Filament opens the modal with the user-input fields; submit triggers `execute()`. When unset (default), behaviour is unchanged from today (click → run).
- Inject the user-input values into Filament `evaluate()` for `->prompt()`, `->sourceRecords()`, and `->handleUsing()` closures — same pattern as the closure DI we added in spec 20.
- The `$row` named injection from spec 23 stays — orthogonal.

## Anchor use cases

1. **Paste / upload → import**: `FileUpload` of CSV → `->sourceRecords()` closure parses → `->createRecords()`.
2. **Paste / upload → enrich**: same input + an `id` column → `->updateRecords()`. This fills the empty matrix cell.
3. **Free-text steering**: `Textarea` ("focus on SEO terms") → consumed by the prompt closure for interactive enrichment.

## Open questions to settle when picked up

- **`UserInput` reuse vs new shape**: the current `UserInput` was designed for free-text prompt-context fields. A FileUpload field is structurally different (binary upload, parsing). Does the existing class suffice (it's already a generic schema-builder), or does this push us to a more typed `UserInput::structured()` or similar?
- **Parsing responsibility**: CSV → keep in userland (closure body, fgetcsv); Excel → push to userland (Laravel-Excel) to avoid a heavy core dep. Confirm before implementing.
- **Pre-run validation UX**: parsed rows missing required columns / no `id` column under `updateRecords` → fail in the modal *before* executing, with a clear message; don't run, then show "0 of 0 updated".
- **Backward-compatibility**: adding `UserInput` support shouldn't surprise users who don't call `->userInput()` — verify the action still runs directly without a modal when not configured. Should be naturally true from the `hasUserInput()` switch but worth a test.
- **Interaction with `->prompt(Closure)`**: today the prompt closure gets `$record`/`$livewire`/`$get`. With `UserInput`, the closure should also receive named user-input values (same pattern as `AiFormAction`). Confirm.

## Out of scope (when picked up)

- Excel (`.xlsx`) parsing as a core capability — push to userland (Laravel-Excel, phpoffice).
- Resumable / multi-step modals.
- A built-in CSV parser with header-mapping UI (define which column is the key) — userland for v1.

## Related

- `specs/21-ai-generate-action.md` — the `AiGenerateAction` v1 (no UserInput support).
- `specs/23-record-writeback-enrichment.md` — the per-record loop primitive this composes with.
- `src/Concerns/HasUserInput.php` / `src/Support/UserInput.php` — the pattern to mirror.
