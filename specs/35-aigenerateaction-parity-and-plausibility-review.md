# 35 — AiGenerateAction ↔ AiFormAction parity & the knxcou plausibility use case (review)

> **Status:** Review / discussion artifact, not a finalized implementation spec.
> It captures (A) an API/DX parity review between the two actions and (B) a
> design exploration of a real external use case (knxcou plausibility checker)
> used to pressure-test the package. It is expected to spawn one or more numbered
> implementation specs (the "AiGenerateAction maturity arc").
>
> **Date:** 2026-06-23. **Author context:** Sten + Claude.
> **Open questions are at the bottom — unanswered as of writing.**

---

## Part A — AiFormAction vs AiGenerateAction: parity & feel

### They are different shapes on purpose

| | **AiFormAction** | **AiGenerateAction** |
|---|---|---|
| Mental model | "Fill *these form fields* from *those form fields*" | "Generate structured data and *do something with it*" |
| Output sink | Live Filament form state (factory write-back) | DB records / a `handleUsing` closure |
| Cardinality | One call, one form | 1…N rows, batched, optionally queued |
| Human-in-loop | Preview + conversational refinement | None (fire-and-persist) |
| Trigger | Button on a form | Button on a form/table/page |

Full feature parity would be wrong (YAGNI). The questions are (1) where the
*shared* surface should feel identical and (2) which genuinely-useful ideas
should leak across.

### Naming / feel: mostly consistent, three rough edges

**Already aligned (good):** `provider()/timeout()`,
`temperature()/maxTokens()/maxSteps()/topP()` (shared `HasGenerationOptions`),
`attachmentField()/attachmentFromUserInput()/attachments()` (shared
`HasAttachments`), `userInput()`, and the `SolarisResponseReceived/Failed`
events. Both use `$userInput` as a closure arg. Solid consistent core.

**Rough edges:**
1. **Prompt closure args diverge** — form gets `$sourceData/$userInput/$locale`;
   generate gets `$userInput/$rows`. Justified (form fields vs DB rows), but
   document it as a deliberate parallel (`$sourceData` ≈ `$rows`).
2. **Presets + `tools()` are form-only.** `AiGenerateAction` cannot use
   `SummaryPreset`/`ClassificationPreset` or agent tools. No principled reason —
   classification/extraction presets arguably fit structured output *better*
   than free-form generation.
3. **No `sanitize()` on the generate side.** `AiFormAction` sanitizes every AI
   value before it touches form state; `AiGenerateAction` writes AI values
   straight into `Model::create()/update()` with no hook. Backwards from a
   security view — stored values deserve sanitization *more* than transient
   form state (stored XSS).

### What to port — recommendation

**AiGenerateAction → AiFormAction:** essentially nothing. The completion-handler
/ event / queue machinery is bulk-run infrastructure; a synchronous single-form
write is already covered by `SolarisResponseReceived/Failed` + Filament's native
`->after()/->before()`. A `BatchCompletionHandler` pipeline there would be
ceremony. **Do not port.**

**AiFormAction → AiGenerateAction (the valuable direction):**
- **`sanitize()/sanitizeField()`** — *port.* Real gap (stored-XSS on `createRecords`).
- **Presets + `tools()`** — *port if cheap*, presets especially. Lets a
  "classify these rows" action reuse `ClassificationPreset`.
- **Inexact option matching + `SolarisOptionMatched`** — *maybe.* With `forModel`
  enum columns the structured-output schema already constrains values, so a
  near-miss is rarer. Lower priority; revisit if enum echoes fail in practice.

---

## Part B — knxcou plausibility checker

### The use case (as described by the project owner)

- knxcou collects **marketing data of countries**; data is noisy.
- Use AI to check whether an input value is **plausible**.
- Reference signals: **prior-year data** + **other countries' this-year data**.
- Output: a **plausibility score** + a **description** (when the value looks off).
- Store results in a model FK'd to the **data submission** + the **field name**,
  alongside whatever the LLM returns.
- Display: in the form as **helper text with an icon** (explicitly *separate*
  from Solaris — just a `->helperText()` read on their side).
- Possibly pass all historic + current data as a **CSV attachment** or inline in
  the prompt.

### knxcou facts (verified by code exploration, cite-backed)

- **Stack:** Laravel 12, PHP 8.4, Filament v5, PostgreSQL/TimescaleDB.
- **Solaris already required at `^0.3`** (`composer.json`) — predates all the
  batch/queue/structured-output work. Real implementation needs a package
  release bump on their side.
- **Data shape:** `national_group_data_submissions` is **one wide row per
  (Year × NationalGroup)** with ~34 typed field columns. `FieldCatalog` (code)
  is the single source of truth for field metadata (type, min/max, owner,
  scoring strategy, i18n keys).
- **Reference data is queryable:** prior-year submission via
  `NationalGroup::submissionFor(Year)`; peer cohort via
  `$year->dataSubmissions()` pluck of the same column.
- **Form hook exists:** `NationalGroupDataSubmissionForm` already calls
  `->helperText(new HtmlString(__($f->helpKey())))` — the exact attach point.
- An existing `SubmissionSubmitted` event is dispatched on submit — a natural
  auto-trigger seam.

### Is it a fit? Yes, with honest caveats — and a great stress test

It is **not** `AiFormAction`: that overwrites the user's input fields, but here
we need a *separate, non-destructive assessment* stored in a side model. Wrong
sink.

It maps onto `AiGenerateAction`'s **structured-output → persist** core
(`forModel(PlausibilityCheck)` with score/description/is_flagged columns). Four
things strain the current API:

1. **Unit of work is a *field*, not a *row*.** A submission is one wide row
   (~34 cols); you want ~34 assessments out of it. The batch loop is
   row-oriented (`sourceRecords` → chunk → reconcile by identifier). You model
   fields as *synthetic array rows* — workable, but the mental model bends.
2. **Write-back is create *or* update — no upsert.** Re-checking should replace
   prior results keyed by `(submission_id, field_key)`. `createRecords` always
   inserts (duplicates on re-run); `updateRecords` updates the *source* models
   by PK. Neither does `updateOrCreate` into a *different* model on a composite
   business key. **Real gap.**
3. **No way to inject constant/derived attributes into written records.** Each
   `PlausibilityCheck` needs `submission_id` (a constant FK) and `field_key`
   from context, not from the AI. No `->recordAttributes(fn ($row) => …)` merged
   into each create/update. **Real gap.**
4. **Trigger is event/auto, not a button.** This should run on submit (and
   probably also as an admin "re-check" button). Solaris actions are *mounted
   Filament buttons*; "run automatically after save, on the queue, headless"
   isn't their native mode. **Biggest strain** — connects to Part A: even though
   `AiGenerateAction` is form-agnostic, it is still an `Action` that needs
   mounting. A **headless entry point** (invoke the pipeline from a
   job/listener/command) would unlock event-driven use generally.

**Not a gap:** reference data gathering. Precompute per-field stats and either
bake them into the synthetic rows (`promptContextColumns`) or pass a CSV via the
first-class `attachments()`.

**Advice on reference delivery:** precomputed per-field stats inline **beat** a
full CSV dump — far fewer tokens, more reliable than asking the LLM to aggregate
a spreadsheet itself. Use a CSV attachment only if you want the model to do its
own slicing; a hybrid is possible but likely overkill for v1.

### How I'd build it **today** (zero package changes)

One AI call assessing all fields at once (cross-field plausibility — shares
summing to 100, per-capita sanity — *needs* the whole picture), custom schema +
`handleUsing` doing `updateOrCreate` manually:

```php
// Illustrative — JsonSchema builder method names to be confirmed at impl time.
AiGenerateAction::make('checkPlausibility')
    ->label('Check plausibility')
    ->outputSchema(fn (JsonSchemaTypeFactory $s) => [
        'assessments' => $s->array()->items($s->object([
            'field_key'          => $s->string(),
            'plausibility_score' => $s->integer()->description('0–100'),
            'description'        => $s->string()->description('Only when implausible; else empty'),
        ])),
    ])
    ->prompt(fn () => view('prompts.plausibility', [
        'fields' => $this->fieldDescriptorsWithReferenceStats($submission), // prior-year + peer min/median/max per field
    ]))
    ->handleUsing(function (array $data) use ($submission) {
        foreach ($data['assessments'] as $a) {
            $submission->plausibilityChecks()->updateOrCreate(
                ['field_key' => $a['field_key']],
                [
                    'plausibility_score' => $a['plausibility_score'],
                    'description'        => $a['description'] ?: null,
                    'is_flagged'         => $a['plausibility_score'] < 50,
                ],
            );
        }
    });
```

Works now, full control. Cost: you hand-roll the loop and lose
tracking/queue/failure-report/live-updates. The helper-text display stays a
plain `->helperText()` read on knxcou's side.

### The "north star" the use case argues for

Adding the gaps makes the declarative path first-class and yields
tracking/queue/failure-report for free:

```php
AiGenerateAction::make('checkPlausibility')
    ->forModel(SubmissionFieldPlausibilityCheck::class)
    ->only(['plausibility_score', 'description', 'is_flagged'])
    ->sourceRecords(fn () => $this->fieldDescriptors($submission))   // synthetic per-field rows + reference stats
    ->promptContextColumns(['field_key','unit','submitted_value','prior_year_value','peer_min','peer_median','peer_max'])
    ->identifyBy('field_key')                                        // NEW: business identifier vs PK/_index
    ->recordAttributes(fn (array $row) => [                          // NEW: constant/derived attrs per write
        'submission_id'   => $submission->id,
        'field_key'       => $row['field_key'],
        'submitted_value' => $row['submitted_value'],
    ])
    ->upsertRecords(uniqueBy: ['submission_id', 'field_key'])        // NEW terminal: updateOrCreate
    ->sanitize(fn ($v) => is_string($v) ? strip_tags($v) : $v)      // PORT from AiFormAction
    ->queued()
    ->trackBatchRuns();
```

### Candidate "AiGenerateAction maturity arc" (would become numbered specs)

| Idea | Origin | Type |
|---|---|---|
| `upsertRecords(uniqueBy: […])` terminal (`updateOrCreate`) | knxcou friction #2 | NEW |
| `recordAttributes(array\|Closure)` — constant/derived attrs merged per write | knxcou friction #3 | NEW |
| `identifyBy(string)` — business identifier instead of PK/`_index` | knxcou friction #1 | NEW |
| Headless runner — invoke the generation pipeline from job/listener/command | knxcou friction #4 | NEW |
| `sanitize()/sanitizeField()` on the generate side | Part A | PORT from AiFormAction |
| Preset + `tools()` support on AiGenerateAction | Part A | PORT from AiFormAction |
| Inexact option matching for enum columns | Part A | MAYBE |

---

## Recommendation

1. Treat Part A's ports (`sanitize`, presets) and Part B's new methods
   (`upsertRecords` / `recordAttributes` / `identifyBy` / headless runner) as
   **one coherent "AiGenerateAction maturity" arc**, specced piece-by-piece like
   the batch work.
2. **Validate by prototyping the knxcou action in their repo first** (the
   `handleUsing` version above) *before* building the declarative API — let real
   friction confirm which additions earn their place, exactly as
   N6 → BatchProcessor played out.

---

## Open questions (decision-critical, unanswered as of writing)

- **Trigger model:** admin-initiated button, auto-on-submit (event → queued,
  needs the headless runner), or both? *Single biggest fork.*
- **Granularity:** one call over all ~34 fields (lean: cross-field reasoning,
  cheaper) vs per-field/batched (isolation, retry granularity)?
- **Reference delivery:** precomputed inline stats (lean) vs CSV attachment vs
  hybrid?
- **Scope of the next chunk:** (a) write up the Part A parity recommendations as
  specs, (b) prototype the knxcou action in `../knxcou` to pressure-test, or
  (c) design the new API additions first?
