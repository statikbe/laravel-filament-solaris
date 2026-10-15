# 44 — Per-field selection in the preview modal

> **Status:** Design — awaiting Sten's review before an implementation plan.
> **Date:** 2026-10-15.
> **Context:** Step 1 of [42](42-field-metadata-and-selection.md) §8 (Feature A, §3).
> Refines 42 §3 in two places — where the selection state lives and where filtering
> happens — see "Departures from spec 42".

---

## Goal

The preview modal is all-or-nothing: Accept writes every generated value, or Discard drops
the lot. Let the user tick which fields actually get written. Off by default, no AI
involvement, useful on its own — and the `solarisPreviewData` → checkbox plumbing that
spec 42 step 2 (metadata) seeds from.

## API

```php
AiFormAction::make('generate')
    ->withPreview()
    ->selectableFields();          // or ->selectableFields(false)
```

- Off by default.
- Needs a preview: `withPreview()`, or `conversational()`, which implies it. Configured
  without one → `RuntimeException` at the first invocation, from the same pre-AI validation
  point as `validatePreviewConfiguration()` (`SolarisAction.php:149`).
- `AiFormAction` only in this spec. The plumbing below is generic so other preview actions
  can opt in later.

## Design

### 1. Selection state: a key inside `solarisPreviewData`

When selection is on, the stored preview data carries two extra keys:

```php
'selectable' => true,
'selected'   => ['title', 'summary'],   // preview-item keys currently ticked
```

The checkboxes bind with `wire:model="solarisPreviewData.selected"` (array of values).

No new property on `InteractsWithSolarisPreview`: the data is stored, refined, discarded and
reset on unmount together with the values it describes, which `solarisPreviewData` already
does.

**Keys are preview-item keys, not form-field names.** Each entry in `displays` already has
a key; for `AiFormAction` it is the field name. Keying selection by item keeps the plumbing
usable for a future preview whose items are not fields (e.g. image variants — see "Out of
scope").

### 2. Reusable plumbing in `HasPreviewModal` + the Blade

Every preview action uses the action-side `HasPreviewModal` trait and renders through
`components/preview-fields.blade.php`, so the shared parts live there:

- **Blade:** `preview-fields` renders a checkbox per item when `selectable` is true, bound
  as above. Driven by data — no published view.
- **Helpers on `HasPreviewModal`:**
  - seed: given the item keys, return the initial `selected` list (all keys);
  - filter: given a values array and preview data, return only the selected entries.
  - re-seed on refinement: see §4.

Each action's `acceptPreview()` decides what "selected" means for it.

### 3. Filtering in `AiFormAction`'s `acceptPreview()`

`HasPromptPipeline::acceptPreview()` (`:627`) filters `values` by `selected` before
`writeResults()`, and **recomputes `filledLabels` from the surviving values**. `failedLabels`
is left as it is: a deselected field was not a failure and must not be reported as "the AI
could not fill this".

The host's `solarisAcceptPreview()` stays unchanged — plain plumbing shared with image
generation and dictation.

### 4. Conversational refinement keeps the user's choices

`updatePreviewData()` (`HasPromptPipeline.php:~790`) re-seeds the selection after each
refinement turn:

- a key the user unticked stays unticked;
- a key that is newly present (filled for the first time) starts ticked;
- a key that disappeared (the field is now a failure) drops out of `selected`.

Rule, precisely: `new selected = (old selected ∩ new keys) ∪ (new keys − old keys)`.

### 5. Nothing ticked

- **UI:** the Accept button is disabled while `selected` is empty — in both
  `preview-modal-footer.blade.php` and the conversational view's own Accept
  (`preview-conversational.blade.php:107`).
- **Server (safety net):** an empty selection writes nothing and sends no notification, then
  closes the modal as Accept normally does.

## Departures from spec 42 §3

- **State:** a `selected` key inside `solarisPreviewData`, not a new
  `public array $solarisPreviewSelection`. Same location (the Livewire host), less
  bookkeeping.
- **Filtering:** in `AiFormAction`'s `acceptPreview()`, not in the host's
  `solarisAcceptPreview()`. The action owns `values`, labels and notifications.

Spec 42 §3's other rules are unchanged (opt-in toggle, preview required, deselected ≠ failed,
data-driven rendering).

## Testing

Livewire fixture tests, following the existing preview-modal tests:

- selection off (default): no `selectable`/`selected` keys, no checkboxes, Accept writes
  everything — unchanged behaviour;
- selection on: all items start ticked; unticking one and accepting writes only the rest;
- notification after a partial accept lists only the written fields as filled and does not
  list the deselected ones as failed;
- empty selection + Accept: nothing written, no notification, modal closed;
- conversational: an unticked field stays unticked after a refinement; a field filled for
  the first time by the refinement starts ticked; a field that becomes a failure drops out;
- `->selectableFields()` without a preview throws `RuntimeException` before any AI call;
- the checkbox renders in `preview-fields` only when `selectable` is true;
- the Accept button carries the disabled binding when selection is on.

## Out of scope

- **Seeding from metadata** (ticks driven by evidence / confidence) — spec 42 step 2.
- **"Select all / none" toggle.**
- **Selection on other actions.** Image generation produces one image for one field, so
  there is nothing to choose. *Multiple-image variants* (pick one of N, or a subset for a
  multi-file upload) are a different choice — which candidates to keep, not which fields to
  write — but would reuse this plumbing by keying items `variant_0…n`. Recorded in
  `missing-features.md` (#17), not designed here.
