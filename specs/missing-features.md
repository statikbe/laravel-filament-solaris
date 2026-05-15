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

### 2. Preview modal (`withPreview()`)

The `withPreview()` setter exists in `HasTargetFields` and stores `$preview`, but nothing reads it. The spec (11-ai-action.md) describes a confirmation modal showing proposed AI values before applying them.

**TODO:**
- [ ] After AI response, if `$preview` is true, show a modal with the proposed values per field
- [ ] User can accept (apply) or cancel (discard)
- [ ] Requires a Blade view for the preview modal content
- [ ] `applyResults()` should be split: transform first, then conditionally apply
- [ ] Works for both `AiAction` and `DictationAction` (in AI processing mode)

**Target spec file:** `11-ai-action.md`

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

### 5. Retry / timeout configuration

`laravel/ai` supports timeout parameters and the `#[Timeout(n)]` attribute. The package has no way to configure per-action timeouts. A long summarization needs more time than a quick classification.

**API sketch:**
```php
AiAction::make('summarize')
    ->timeout(60); // seconds

DictationAction::make('voice')
    ->transcriptionTimeout(30)
    ->timeout(60); // AI pipeline timeout
```

**TODO:**
- [ ] Add `$timeout` (int|Closure|null) to `HasPromptPipeline`
- [ ] Add `$transcriptionTimeout` to `DictationAction`
- [ ] Pass to `SolarisAgent::prompt()` and `Transcription::generate()`
- [ ] Add config defaults

**Target spec file:** `11-ai-action.md`, `15-dictation-action.md`

---

## P2 — Nice to have, polish

### 6. Filament Plugin interface

The service provider doesn't implement `Filament\Contracts\Plugin`. No `->plugin(FilamentSolaris::make())` registration in panels. Works via autodiscovery but isn't idiomatic Filament v4/v5.

**TODO:**
- [ ] Create a `FilamentSolarisPlugin` class implementing `Plugin`
- [ ] Allow per-panel configuration (e.g. different defaults per panel)
- [ ] Keep service provider for non-panel features (config, translations, assets)

**Target spec file:** new spec

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
