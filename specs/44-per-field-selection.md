# 44 — Per-field selection in the preview modal

> **Status:** Implemented (2026-10-16) on branch `feature/per-field-selection`.
> **Date:** 2026-10-15.
> **Context:** Step 1 of [42](42-field-metadata-and-selection.md) §8 (Feature A, §3).
> Refines 42 §3 — the API name, a field subset, where the selection state lives and where
> filtering happens — see "Departures from spec 42".

---

## Goal

The preview modal is all-or-nothing: Accept writes every generated value, or Discard drops
the lot. Let the user tick which fields actually get written. Off by default, no AI
involvement, useful on its own — and the `solarisPreviewData` → checkbox plumbing that
spec 42 step 2 (metadata) seeds from.

## API

```php
AiFormAction::make('generate')
    ->withSelectablePreview();                      // every target field selectable

AiFormAction::make('generate')
    ->withSelectablePreview(['summary', 'tags']);   // only these get a checkbox

AiFormAction::make('generate')
    ->conversational()
    ->withSelectablePreview();                      // combines with conversational

->withSelectablePreview(false)                      // selection off; preview left as configured
```

Signature: `withSelectablePreview(bool|array $fields = true): static`.

- Off by default.
- **Implies the preview**, the way `conversational()` does: `true` or an array also calls
  `withPreview()`. There is no "selection without preview" state to misconfigure.
- `false` turns selection off and leaves the preview as it was configured.
- **A field subset:** fields not in the array are shown without a checkbox and are always
  written — for fields that must not be split (a title with its slug, lat/lng). `true`
  means every target field is selectable.
- **Unknown field names** (not among the action's target fields) throw `RuntimeException`
  at the first invocation, before any AI call — the same pre-AI validation point as
  `validatePreviewConfiguration()` (`SolarisAction.php:149`). A typo fails loudly instead of
  silently rendering no checkbox.
- No closure form for now; add on demand.
- `AiFormAction` only in this spec. The plumbing below is generic so other preview actions
  can opt in later.

## Design

### 1. Selection state: a key inside `solarisPreviewData`

When selection is on, the stored preview data carries two extra keys:

```php
'selectable' => ['title', 'summary', 'tags'],   // preview-item keys that get a checkbox
'selected'   => ['title', 'summary'],           // the subset currently ticked
```

`selectable` holds only the selectable items that are actually present in `displays` (a
configured field the AI failed to fill has no item). Items outside `selectable` are
*fixed*: rendered without a checkbox, always written.

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

- **Blade:** `preview-fields` renders a checkbox for each item whose key is in
  `selectable`, bound as above; other items render as today. Driven by data — no published view.
- **Helpers on `HasPreviewModal`:**
  - seed: given the item keys and the configured selectable fields, return `selectable`
    and the initial `selected` (all selectable keys);
  - filter: given a values array and preview data, return the fixed entries plus the
    selected ones.
  - re-seed on refinement: see §4.

Each action's `acceptPreview()` decides what "selected" means for it.

### 3. Filtering in `AiFormAction`'s `acceptPreview()`

`HasPromptPipeline::acceptPreview()` (`:627`) filters `values` — fixed items plus the
selected ones — before `writeResults()`, and **recomputes `filledLabels` from the surviving values**. `failedLabels`
is left as it is: a deselected field was not a failure and must not be reported as "the AI
could not fill this".

The host's `solarisAcceptPreview()` stays unchanged — plain plumbing shared with image
generation and dictation.

### 4. Conversational refinement keeps the user's choices

`updatePreviewData()` (`HasPromptPipeline.php:~790`) recomputes `selectable` from the new
items and re-seeds the selection after each refinement turn (over selectable keys only):

- a key the user unticked stays unticked;
- a key that is newly present (filled for the first time) starts ticked;
- a key that disappeared (the field is now a failure) drops out of `selected`.

Rule, precisely, with `S` = the new selectable keys and `S₀` the previous ones:
`new selected = (old selected ∩ S) ∪ (S − S₀)`.

### 5. Nothing ticked

- **UI:** the Accept button is disabled while `selected` is empty **and** there are no
  fixed items — in both `preview-modal-footer.blade.php` and the conversational view's own
  Accept (`preview-conversational.blade.php:107`). With fixed items, accepting zero ticks
  still writes something, so Accept stays enabled.
- **Server (safety net):** an empty selection writes the fixed items only; if nothing at all
  is written, no notification is sent. The modal closes as Accept normally does.

## Departures from spec 42 §3

- **API:** `->withSelectablePreview(bool|array $fields = true)` instead of
  `->selectableFields()` + a required `withPreview()`. It implies the preview, so it
  cannot be misconfigured, and it takes an optional field subset.
- **State:** a `selected` key inside `solarisPreviewData`, not a new
  `public array $solarisPreviewSelection`. Same location (the Livewire host), less
  bookkeeping.
- **Filtering:** in `AiFormAction`'s `acceptPreview()`, not in the host's
  `solarisAcceptPreview()`. The action owns `values`, labels and notifications.

Spec 42 §3's other rules are unchanged (opt-in, deselected ≠ failed, data-driven rendering).
Spec 42's provisional name `selectableFields()` (§9.1) is superseded.

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
- `->withSelectablePreview()` alone turns the preview on (no separate `withPreview()`);
- `->withSelectablePreview(false)` after `withPreview()` leaves the preview on, selection off;
- a field subset: only listed fields get a checkbox; unlisted fields are written even when
  every checkbox is unticked;
- an unknown field name in the subset throws `RuntimeException` before any AI call;
- a listed field the AI failed to fill is absent from `selectable`;
- the checkbox renders in `preview-fields` only for keys in `selectable`;
- the Accept button carries the disabled binding only when there are no fixed items.

## Out of scope

- **Seeding from metadata** (ticks driven by evidence / confidence) — spec 42 step 2.
- **"Select all / none" toggle.**
- **Selection on other actions.** Image generation produces one image for one field, so
  there is nothing to choose. *Multiple-image variants* (pick one of N, or a subset for a
  multi-file upload) are a different choice — which candidates to keep, not which fields to
  write — but would reuse this plumbing by keying items `variant_0…n`. Recorded in
  `missing-features.md` (#17), not designed here.
