# Missing Features — Working Document

Tracked gaps and planned features. When implementing, move the details into the appropriate numbered spec file.

---

## P0 — High impact, needed for production use

### ~~1. Provider & model selection per action~~ ✅ IMPLEMENTED

Implemented in full. See specs 09, 11, 14, 15 for details.

- [x] Add `$pipelineProvider` / `$pipelineModel` properties to `HasPromptPipeline` (with `Closure` support + `$this->evaluate()`)
- [x] Add `$transcriptionProvider` / `$transcriptionModel` to `DictationAction`
- [x] Pass through to `SolarisAgent::prompt($prompt, [], $provider, $model)`
- [x] Pass through to `Transcription::fromUpload()->generate($provider, $model)`
- [x] Add config keys `default_provider`, `default_model`, `default_transcription_provider`, `default_transcription_model`, `preset_providers` to `FilamentSolarisConfig`
- [x] Allow presets to define a default provider/model (overridable by action)
- [x] Resolution chain: action → preset → config preset_providers → config default → null (laravel/ai default)
- [x] `AiActionFake::recordCall()` and `assertCalledWith()` include provider/model
- [x] Tests (unit + feature)

---

### 2. Preview modal (`withPreview()`) ✅ Shipped

A confirmation modal showing proposed AI values before applying them, via
`->withPreview()` + the `InteractsWithSolarisPreview` trait on the host
Livewire component. Works for `AiFormAction` and `ImageGenerationAction`
(image refinement); `DictationFieldAction` writes its transcript directly.

**Shipped:** `HasPreviewModal` trait + `InteractsWithSolarisPreview` —
see `specs/16-preview-modal.md`.

---

## P1 — Important for developer experience

### 3. Streaming support

`laravel/ai` has `$agent->stream()` and `$agent->broadcast()` for real-time SSE responses. For long AI tasks (generation, translation of large text), a loading spinner is a poor UX.

**TODO:**
- [ ] Research Filament's approach to streaming / SSE in actions
- [ ] Determine if streaming structured output is feasible (JSON schema constraint)
- [ ] Consider `$agent->broadcast()` with Livewire event listeners
- [ ] Design the API: `->stream()` or `->broadcast(Channel $channel)`

**Target spec file:** new spec

---

### 4. DictationAction: pure transcription multi-target validation

Spec says: "Given multiple target fields but no PromptBuilder → only the first target field receives the transcript." The code does this correctly, but silently ignores extra targets. Should warn the developer.

**TODO:**
- [ ] In `validateDictationConfiguration()`, if `!hasPromptBuilder()` and `count(getTargetFields()) > 1`, log a warning or throw
- [ ] Document this constraint clearly

**Target spec file:** `15-dictation-action.md`

---

### 5. Retry / timeout configuration ✅ Shipped (timeout)

Per-action `->timeout(seconds)` (on `SolarisAction`, inherited by every
action) and `->transcriptionTimeout()` (on `HandlesDictation`). Resolution
chain: action-level → preset (where applicable) → config defaults
(`ai.default_timeout`, `transcription.default_timeout`,
`image_generation.default_timeout`).

**Shipped:**
- [x] `$timeout` (int|Closure|null) on `SolarisAction` — applies to text
      generation, image generation, and dictation AI processing
- [x] `$transcriptionTimeout` on `HandlesDictation`
- [x] Config defaults across `ai.*`, `transcription.*`, `image_generation.*`

**Still deferred:** automatic retry policy (the original "retry" half of
this section). `laravel/ai`'s `#[Retries(n)]` attribute is available but
not yet exposed via Solaris setters — open if you want a retry knob.

---

## P2 — Nice to have, polish

### 6. Filament Plugin interface ✅ Shipped

`FilamentSolarisPlugin implements Plugin` enables per-panel configuration
and an auth gate. Every Tier-1/2 config key has a fluent setter; the
visibility gate registers `->hidden(...)` on every action so user
`->visible()` calls can't bypass it.

**Shipped:** see `src/FilamentSolarisPlugin.php` and the README "Panel
Setup" section. Service provider stays for non-panel features (config,
translations, assets, autodiscovery).

---

### 7. Queued / async execution

`laravel/ai` supports `$agent->queue()` for long-running jobs. For expensive operations, queueing with a notification on completion would be valuable.

**TODO:**
- [ ] Design the API: `->queued()` or `->async()`
- [ ] Determine how results are applied after queue completes (Livewire event? polling?)
- [ ] Consider `$agent->broadcast()` as the mechanism

**Target spec file:** new spec

---

### 8. Action button loading states (DictationAction)

The spec describes specific loading states: "Transcribing..." text, different spinner states for recording vs uploading vs transcribing vs AI processing. Currently only `animate-pulse` is bound during recording.

**TODO:**
- [ ] Add `x-text` binding for dynamic button label based on state
- [ ] Integrate with Filament's loading indicator system
- [ ] Consider `wire:loading` for the server-side processing phase

**Target spec file:** `15-dictation-action.md`

---

### 9. Cost / usage tracking ✅ Shipped in 0.1.0

`laravel/ai` responses include `Usage` objects with token counts. Could be exposed for logging, budgeting, or UI display.

**TODO:**
- [x] Capture `$response->usage` after AI calls
- [x] Expose via event (e.g. `SolarisResponseReceived`) for app-level tracking
- [x] Optional: log usage alongside prompt logging (`SolarisPromptLogger`)

**Shipped:** `SolarisResponseReceived` + `SolarisResponseFailed` events dispatched from `SolarisAction::executeAiCall()` and from all three fakes. `SolarisPromptLogger::logUsage()` added. See README "Usage Tracking" section and CHANGELOG `[0.1.0]`. Built-in persistence model + migration deferred — likely a companion package or 0.3.

---

### 10. Split `ComponentFactory` file/attachment methods into opt-in interfaces

The `ComponentFactory` contract currently exposes 7 methods. Three are core (every factory implements them); four have safe defaults on the abstract base. Two of the four are file-shaped — `toFormValueFromFile()` and static `toAttachments()` — only meaningful for `FileUpload` / `SpatieMediaLibraryFileUpload` / `Text` (file output to a path).

**Proposal:** split the file-shaped methods out into opt-in interfaces:

- `SupportsFileOutput` — owns `toFormValueFromFile(string $content, string $mimeType): mixed`.
- `SupportsAttachments` — owns the attachment-resolution method (whether kept static or refactored to instance-level is part of the design).

**Why defer to post-1.0:** the change is **strictly additive** — adding the interfaces later doesn't break existing custom factories because they're not required to declare them. Existing factories continue to work through the abstract base's defaults. The actual user-facing pain ("forced to implement all 7") is already solved by those defaults. The interface split mostly helps type-safe dispatch in `HasImageGenerationPipeline::applyImageToTarget()` and `HasAttachments::resolveAttachments()` — currently those rely on the base throwing `UnsupportedFactoryOperationException` / returning `[]`.

**When to revisit:**
- A third file-shaped capability appears (e.g. streaming output, drag-drop targets, audio preview). At that point the pattern's payoff scales.
- A user-reported issue identifies friction authoring a custom factory.

**TODO (when picked up):**
- [ ] Introduce `Contracts/SupportsFileOutput` and `Contracts/SupportsAttachments`.
- [ ] Decide whether `toAttachments()` stays static (awkward on an interface but lets `HasAttachments` dispatch by class without instantiating) or becomes instance-level (cleaner interface, more invasive at call sites).
- [ ] Add `instanceof` checks in `HasImageGenerationPipeline::applyImageToTarget()` and `HasAttachments::resolveAttachments()`.
- [ ] Keep the abstract base's safe defaults so existing factories continue to work without declaring the interfaces.
- [ ] Update README "Custom Factories" section.

**Light touch in 0.1.0:** added a docblock at the top of `Contracts/ComponentFactory` clarifying which 3 methods are core (must implement) vs the 4 optional ones with safe defaults — so anyone authoring a custom factory sees the small set they actually need without reading the abstract base.

---

### 11. Cross-session conversation persistence

In `0.1.0`, conversational refinement persists messages within a single open modal session only. When the user closes the modal and re-opens the action, a fresh conversation starts; the previous one stays in `agent_conversation_messages` (laravel/ai's table) but Solaris never queries it again. From the user's perspective the chat is ephemeral.

**Why deferred:** the design isn't trivial — needs a Solaris-owned morph table to map `(morphable_type, morphable_id, action_name, user_id) → conversation_id`, plus decisions about lifetime (auto-resume? "Resume previous?" prompt? stale-data invalidation?) that benefit from real-use feedback. There's also an upstream gap: `laravel/ai`'s `Message::attachments` field doesn't rehydrate into `File[]` on read, so attachment-heavy conversations can't fully round-trip without a Solaris-side attachment index.

**Schema sketch** (when picked up):

```php
Schema::create('solaris_conversations', function (Blueprint $table) {
    $table->id();
    $table->morphs('morphable');           // (Resource model record, Page, etc.) — nullable for global actions
    $table->string('action_name');         // e.g. 'summarize', 'classify'
    $table->foreignId('user_id')->nullable();
    $table->string('conversation_id', 36); // FK-shape to agent_conversations.id
    $table->timestamps();

    $table->unique(['morphable_type', 'morphable_id', 'action_name', 'user_id']);
});
```

**TODO (when picked up):**
- [ ] Migration + Eloquent model (`SolarisConversation`).
- [ ] Config flag (`conversational.persistence_enabled`, default `true`) for opt-out.
- [ ] Hook in `HasPromptPipeline::runPipeline()`: look up existing conversation; call `$agent->continue($conversationId, $user)` if found, `$agent->forUser($user)` otherwise; upsert the row after the call returns `$response->conversationId`.
- [ ] Hydrate prior messages into `$livewire->solarisPreviewData['messages']` so the modal opens with the chat history visible.
- [ ] Decide on "stale conversation" handling: if source data has changed dramatically since the last turn, invalidate the conversation (vs. let the AI handle context drift).
- [ ] Image-pipeline equivalent (image refinement is stateless re-generation, but the prompt + per-turn images could still be persisted for UX restore).
- [ ] Sync with upstream `laravel/ai` on `Message::attachments` rehydration, or build a Solaris-side attachment index as a workaround.
- [ ] README "Conversational Refinement" section documenting the resume flow when shipped.

Existing roadmap note: [memory/project_continue_conversation_todo.md] (private).

---

### 13. AiGenerateAction ✅ Shipped

A form-agnostic AI action that generates structured data against a schema you
define — either a custom `->outputSchema()` or derived automatically from an
Eloquent model via `->forModel()` — and passes the parsed result to a
`->handleUsing()` closure. Enables AI-driven seeding, taxonomy building, and
info-gathering without any form wiring. Tested via `AiGenerateAction::fake()`.

See `specs/21-ai-generate-action.md` for the full design spec.

- Follow-up: enum auto-detection from backed-enum casts + `->columnEnum()` / `->columnHint()` per-column overrides shipped per `specs/22-formodel-enums-hints.md`.
- Follow-up: per-record write-back & enrichment — `->createRecords()` / `->updateRecords()` terminals, polymorphic `->records()` source, `->promptContextColumns()`, per-row prompt `$row` injection, partial-failure handling, and `AiGenerateAction::fakeEach([...])` test helper shipped per `specs/23-record-writeback-enrichment.md`. Deferred follow-up: UserInput on AiGenerateAction (paste/upload variant) tracked in `specs/24-userinput-on-aigenerateaction.md`.

---

### 12. RichEditor toolbar dictation button ✅ Shipped

A dictation button in the RichEditor toolbar (pure transcription → cursor
insert), via `DictationRichEditorPlugin` + internal `DictationToolbarAction`,
reusing the `HandlesDictation` trait. Global enablement through the
`transcription.enable_rich_editor_toolbar_btn` config flag or
`FilamentSolarisPlugin::make()->enableRichEditorToolbarButton()`; per-instance
override / opt-out via `->plugins([DictationRichEditorPlugin::make()->visible(false)])`.

**Shipped:** see `specs/19-dictation-toolbar-action.md`. AI-chaining for the
toolbar variant remains deferred.

---

### 14. Shared AI-call plumbing ✅ Shipped

`HasGenerationOptions` trait + `SolarisAction`-level provider/timeout
resolution share the text-generation plumbing between `HasPromptPipeline`
(preset-aware via override) and `AiGenerateAction`. Side effect:
`AiGenerateAction` gained `->temperature()` / `->maxTokens()` /
`->maxSteps()` / `->topP()`. Resolved options travel as a
`GenerationOptions` value object (final readonly) with `applyTo($agent)`
— so the `$options` arg on `AiFormAction::assertCalledWith()` closures
is now a DTO, not an array (`$options->temperature`, not `$options['temperature']`).

**Shipped:** see `specs/25-shared-ai-call-plumbing.md`.
