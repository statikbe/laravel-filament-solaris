# 40 — Sanitizers (parity port + close the record-write XSS gap)

> **Status:** Design — awaiting Sten's review before an implementation plan.
> **Date:** 2026-07-02.
> **Context:** Step 1 of the post-arc parity ports (see `39` sequencing). `preset`
> is postponed; `tools()` already exists on the service. Backward compat is not a
> constraint (≈1 known consumer) — we change freely.

---

## Goal

Give the service + `AiGenerateAction` a way to sanitize AI-generated **string**
values before they are written to records — closing a **stored-XSS gap** on
`createRecords`/`updateRecords` write-back that `AiFormAction` already guards for
form fields. Unify both under one `Sanitizer` abstraction, and refactor
`AiFormAction`'s existing closure-only sanitize onto it.

knxcou (headless, inline single call → `updateOrCreate`) is the driver; the inline
path fully covers it.

---

## The `Sanitizer` abstraction (shared)

```php
interface Sanitizer
{
    public function sanitize(string $value): string;   // pure string → string
}
```

- **Pure and context-free.** No `$field`, no column metadata. Field routing and the
  "only strings" rule live at the *call site*, not in the sanitizer (see below). We
  can add optional args later if a real need appears; not now.
- **`CallableSanitizer`** (internal) wraps a `Closure(string): string` so a closure
  and a `Sanitizer` are interchangeable. Public setters accept
  `Closure|Sanitizer|array` — a **closure** auto-wraps in `CallableSanitizer`, an
  **array** auto-wraps in `CompositeSanitizer` (a pipeline applied in order).

### Shipped implementations
- **`StripTagsSanitizer`** — `strip_tags()`. Plain-text columns; no dependency. The
  workhorse; fully closes XSS for plain-text.
- **`TrimSanitizer`** — `trim()`. Whitespace hygiene on AI output.
- **`CompositeSanitizer`** — the per-value **pipeline**: runs an ordered list of
  sanitizers (e.g. trim → strip_tags) against one value. Itself a `Sanitizer`; an
  array passed to a setter becomes one.
- **`HtmlPurifierSanitizer`** — safe-HTML for rich columns. **Optional dependency:**
  a thin wrapper over a purifier lib (`mews/purifier` / `ezyang/htmlpurifier`); do
  **not** add a hard dependency — throw a clear "install X" error if the lib is
  absent.

### Two distinct concepts (don't conflate)
- **`CompositeSanitizer`** = *per-value pipeline* — a sequence of sanitizers applied
  in order to a single string.
- **`SanitizerExecutor`** = *per-field router + executor* — holds a default + a
  per-field map, and runs the right one over a whole attribute map. **Not** a
  pipeline; it routes. A field's sanitizer may itself be a `CompositeSanitizer`.

### `SanitizerExecutor` (the routing + execution unit)
Holds the resolved config and runs it in one place:
```php
final class SanitizerExecutor
{
    // ?Sanitizer $default (from ->sanitize()), array<string,Sanitizer> $fields (from ->sanitizeField())
    public function execute(array $attrs): array;   // guard non-strings + route + run
    public function isSerializable(): bool;          // false if any member is a CallableSanitizer (closure)
}
```
`execute()` — for each `$field => $value`: **skip if `! is_string($value)`** (the
single call-site guard — every sanitizer therefore only ever sees a string), else
pick `$fields[$field] ?? $default` and run it. Routing lives here, so sanitizers
stay field-agnostic. (Active name chosen over "SanitizerSet" precisely because it
*executes*, not just holds.)

---

## Where it applies

Write-back is per-action (Layer 3), so there are two application sites — but both
use the same `SanitizerExecutor`:

- **Records** (`AiGenerator` / `AiGenerateAction`): `RecordWriter` gains an optional
  `SanitizerExecutor` and runs it inside `write()` (before `create`/`update`).
  Because the inline path *and* the queued worker both go through `RecordWriter`
  (piece 3), this is a single point — inline, worker, and future staging all inherit
  it.
- **Form** (`AiFormAction` / `HasPromptPipeline`): its existing `applySanitizer()` in
  `transformResults()` is refactored to build + use a `SanitizerExecutor` instead of
  the ad-hoc closure lookup.

---

## Public API (three surfaces, same union)

```php
->sanitize(Closure|Sanitizer|array $sanitizer)                 // default for every string value
->sanitizeField(string $field, Closure|Sanitizer|array $s)     // per-field override
```
An **array** is sugar for a `CompositeSanitizer` (pipeline in order); a **closure**
wraps in `CallableSanitizer`.
- **`AiGenerator`** (headless) — new setters; builds a `SanitizerExecutor`, hands it
  to `RecordWriter` (inline) / `BatchRunConfig` (queued).
- **`AiGenerateAction`** — new setters (this is the gap); stashes and passes them to
  the `AiGenerator` it builds in `makeBatchGenerator` / `makeFromScratchGenerator`.
  (These are plain value-transform closures, not Filament-DI — passed as-is.)
- **`AiFormAction`** — widen existing `sanitize()`/`sanitizeField()` from `Closure`
  to `Closure|Sanitizer|array`; behaviour otherwise unchanged (now via
  `SanitizerExecutor`).

---

## Inline vs queued

- **Inline** (headless `AiGenerator`, inline action, form): closures **and** class
  sanitizers both work — nothing serializes.
- **Queued**: the `SanitizerExecutor` rides in `BatchRunConfig` to the worker. Class
  sanitizers serialize fine; a **closure (`CallableSanitizer`) cannot**. So at
  dispatch, `runQueued()` checks `SanitizerExecutor::isSerializable()` — if false (a
  closure sanitizer + `->queued()`), throw a clear guard error, exactly like the
  existing queued guards (`queued` requires forModel + create/update).

---

## Out of scope (separate future feature)

**Column-length handling.** A `varchar(n)` overrun is a *data-integrity* concern, not
sanitization, and it needs DB-column metadata the form case lacks. Better handled
record-side where the schema already is: (1) `ModelSchemaResolver` emits `maxLength`
into the AI's JSON schema (first-line defense), (2) an opt-in truncate-on-write
safety net. Not part of this spec; keeps `Sanitizer` pure and shared.

---

## Decomposition (TDD, green at each step)

1. **Shared types + impls** — `Sanitizer`, `CallableSanitizer`, `SanitizerExecutor`,
   `StripTagsSanitizer`, `TrimSanitizer`, `CompositeSanitizer`, `HtmlPurifierSanitizer`
   (missing-lib error). Pure units; the bulk of the tests live here.
2. **Record write-back (inline)** — `RecordWriter` runs a `SanitizerExecutor`;
   `AiGenerator` + `AiGenerateAction` `sanitize()`/`sanitizeField()` (incl. array →
   Composite); covers the headless + inline-action XSS gap (knxcou).
3. **Queued** — `BatchRunConfig` carries the `SanitizerExecutor`; worker runs it via
   `RecordWriter`; closure-sanitizer + `->queued()` guard.
4. **Form refactor** — `AiFormAction` widen to `Closure|Sanitizer|array`;
   `HasPromptPipeline` `applySanitizer()` → `SanitizerExecutor`.

---

## Decisions locked
- `Sanitizer::sanitize(string $value): string` — pure, no `$field` / column context.
- `Closure|Sanitizer|array` union everywhere; closures → `CallableSanitizer`, arrays
  → `CompositeSanitizer` (per-value pipeline).
- Two distinct concepts: `CompositeSanitizer` (per-value pipeline) vs
  `SanitizerExecutor` (per-field router + executor; `execute($attrs)`).
- Ship: StripTags, Trim, Composite, HtmlPurifier (optional-dep).
- Opt-in; no default sanitizer applied.
- Non-string guard is a single call-site check in `SanitizerExecutor::execute()`.
- Queued: class sanitizers serialize; closure + queued → guard error.
- Column-length is a separate future feature.

## Open / to confirm at spec-review
- `SanitizerExecutor` inside `RecordWriter` vs. composed in the persist callback —
  leaning inside `RecordWriter` (single point for inline + worker).
- Exact `HtmlPurifierSanitizer` dependency target (`mews/purifier` vs raw
  `ezyang/htmlpurifier`).
