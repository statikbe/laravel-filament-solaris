# 19 — DictationToolbarAction (RichEditor toolbar button)

## Summary

A dictation button that lives **inside a `RichEditor`'s toolbar** (alongside bold,
link, attach-files, etc.). Clicking it opens the same recording modal Solaris
already uses, transcribes the audio via `laravel/ai`, and **inserts the
transcript at the editor's current cursor position** — rather than replacing or
appending the whole field value the way `DictationFieldAction` does.

Scope for this version is **pure transcription → cursor insertion**. The AI
preset/prompt pipeline (`->preset()` / `->prompt()`) supported by
`DictationFieldAction` is intentionally **out of scope** here; the recording +
transcription plumbing lives in the shared `HandlesDictation` trait, so chaining
can be layered on later without reworking the cursor-insert path.

This is the realisation of the "(Planned) `DictationToolbarAction`" note in the
`HandlesDictation` trait docblock.

## Why a plugin (not just an action)

Filament v4 exposes exactly one supported mechanism for adding a *new* RichEditor
toolbar button: a `RichContentPlugin` attached via `RichEditor::make()->plugins([...])`.
Every built-in button (bold, link, and notably **attach-files**, which opens a
modal exactly like dictation) is registered internally through this same
contract. So the public surface of this feature is a **plugin**, and the
`Action` is an internal collaborator the plugin registers — mirroring how
Filament keeps `AttachFilesAction` internal to the editor.

## Components

| Class | Location | Visibility | Role |
|-------|----------|-----------|------|
| `DictationRichEditorPlugin` | `src/RichEditor/` | **public** | Fluent config object; implements `RichContentPlugin` + `HasToolbarButtons`. The thing consumers pass to `->plugins([...])` (and to `configureUsing()` for the global case). |
| `DictationToolbarAction` | `src/Actions/` | internal | `extends SolarisAction`, `use HandlesDictation`. Owns the recording modal + the cursor-insert write-back. Built and registered by the plugin. |
| `DictationToolbarActionFake` / `WithDictationToolbarActionFake` | `src/Testing/` | public (tests) | Transcript-only fake mirroring `DictationFieldActionFake`. |

---

## Class: `DictationToolbarAction extends SolarisAction`

### Traits
- `HandlesDictation` — recording modal schema/content/heading, audio state-path
  resolution (modal-stacking aware), `extractAudioFile()`, `transcribe()`, and
  the `lang() / transcriptionProvider() / transcriptionModel() / transcriptionTimeout()`
  setters + resolution chains.

**Not** used: `HasPromptPipeline`, `HasFormPipeline`, `HasTargetFields`. There is
no AI pipeline and no form-field write-back — the target is the editor cursor.

### `make()`

```php
public static function make(?string $name = 'solarisDictation'): static
{
    return parent::make($name);
}
```

The name must match the tool name the plugin registers (`solarisDictation`), so
the tool's generated `$wire.mountAction('solarisDictation', …)` resolves to this
action.

### `setUp()`

```php
protected function setUp(): void
{
    parent::setUp(); // SolarisAction: registers the panel visibility ->hidden() gate

    $this->setUpDictationModal(fn (): array => []); // no extra schema (no UserInput / pipeline)

    $this->action(function (DictationToolbarAction $action, array $data = [], array $arguments = []): void {
        $action->processDictation($data, $arguments);
    });
}
```

`$data` carries the modal form state (the hidden `FileUpload` the recorder
uploads to). `$arguments` carries the mounted-action arguments — crucially
`editorSelection`, which the tool's JS click handler captured from the editor at
click time.

### `isPreviewLoading(): bool`

Returns `false` — same rationale as `DictationFieldAction`: the recorder modal
must never be replaced by the preview loading spinner on mount.

### `processDictation(array $data, array $arguments): void`

```
if DictationToolbarActionFake::isActive():
    processFakeDictation($data, $arguments); return

audio = extractAudioFile($data[self::AUDIO_FIELD] ?? null)
if audio is not UploadedFile:
    notify(notifications.transcription_error, danger); return

transcript = transcribe(audio)   // trait; null on error/empty (already notified)
if transcript === null: return

insertTranscript(transcript, $arguments['editorSelection'] ?? null)
```

### `insertTranscript(string $transcript, ?array $editorSelection): void`

```php
$editor = $this->getSchemaComponent();

if (! $editor instanceof RichEditor) {
    throw new RuntimeException(
        'DictationToolbarAction could not resolve the host RichEditor. '
        .'It must be registered via DictationRichEditorPlugin.'
    );
}

$editor->runCommands(
    [EditorCommand::make('insertContent', [$transcript])],
    editorSelection: $editorSelection,
);

Notification::make()
    ->title(filament_solaris_trans('notifications.transcription_inserted'))
    ->success()
    ->send();
```

- The transcript is inserted as **plain text at the cursor** (raw string passed to
  TipTap's `insertContent`). TipTap places it as text within the current node, so
  it behaves predictably mid-sentence. Multi-paragraph dictation lands as one
  block (acceptable for v1; paragraph-splitting can be revisited if requested).
- `getSchemaComponent()` resolves to the `RichEditor` because the tool mounts the
  action with the editor as the schema-component context (`schemaComponent: <editorKey>`)
  — the same resolution `AttachFilesAction` relies on for its `RichEditor $component`
  injection.
- `editorSelection` is forwarded to `runCommands()` so the insertion targets the
  user's cursor, not the document start.

### Abstract-method guards

`SolarisAction` declares `acceptPreview(array $data): void` and
`refine(string $message, array $turnAttachments = []): void` (preview /
conversational features). This action supports neither, so both throw:

```php
public function acceptPreview(array $data): void
{
    throw new LogicException('DictationToolbarAction does not support the preview modal.');
}

public function refine(string $message, array $turnAttachments = []): void
{
    throw new LogicException('DictationToolbarAction does not support conversational refinement.');
}
```

These are unreachable in normal use (`shouldPreview()` is `false`, no
`conversational()`), but the base contract requires them and a loud throw beats a
silent no-op if a future change wires them up by accident.

### Fake path

```php
protected function processFakeDictation(array $data, array $arguments): void
{
    $fake = DictationToolbarActionFake::getInstance();
    $transcript = $fake->getTranscript();
    $fake->recordCall($this->getName(), $transcript);

    ['provider' => $provider, 'model' => $model] = $this->resolveTranscriptionProviderAndModel();
    $this->dispatchFakeResponseReceived($provider, $model); // usage-tracking parity

    $this->insertTranscript($transcript, $arguments['editorSelection'] ?? null);
}
```

---

## Class: `DictationRichEditorPlugin implements RichContentPlugin, HasToolbarButtons`

The public, fluent configuration object. One instance == one configured button.
The same object is passed both per-instance (`->plugins([...])`) and globally
(inside `RichEditor::configureUsing(...)`).

### Constant

```php
public const TOOL_NAME = 'solarisDictation';
```

Shared by the tool name, the action name, and the enabled/disabled toolbar-button
lists so they can never drift.

### Fluent config (proxied onto the built action)

| Method | Effect |
|--------|--------|
| `make(): static` | factory |
| `lang(string\|Closure)` | transcription language hint |
| `transcriptionProvider(Lab\|array\|string\|Closure, ?string)` | provider (+ model) override |
| `transcriptionModel(string\|Closure)` | model override |
| `transcriptionTimeout(int\|Closure)` | timeout override |
| `icon(string\|BackedEnum\|Closure)` | toolbar button icon (defaults to `icons.dictation` config) |
| `label(string\|Closure)` | button tooltip / aria-label (defaults to `dictation.toolbar_button_label`) |
| `visible(bool\|Closure)` / `hidden(...)` | per-instance enable/opt-out (see below) |

### `buildAction(): DictationToolbarAction`

Constructs `DictationToolbarAction::make(self::TOOL_NAME)` and applies the stored
config (lang/provider/model/timeout/icon). Memoised so the tool and the action
share one instance.

### Contract methods

```php
public function getTipTapPhpExtensions(): array { return []; }   // no custom node
public function getTipTapJsExtensions(): array  { return []; }

public function getEditorTools(): array
{
    if (! $this->shouldRender()) {
        return [];
    }

    return [
        RichEditorTool::make(self::TOOL_NAME)
            ->icon($this->resolveIcon())
            ->label($this->resolveLabel())
            ->action(self::TOOL_NAME)   // → $wire.mountAction('solarisDictation', { editorSelection, … })
            ->activeStyling(false),     // dictation has no "active" toggle state
    ];
}

public function getEditorActions(): array
{
    return $this->shouldRender() ? [$this->buildAction()] : [];
}

public function getEnabledToolbarButtons(): array
{
    return $this->shouldRender() ? [self::TOOL_NAME] : [];
}

public function getDisabledToolbarButtons(): array
{
    // When NOT rendering, actively disable the button so a per-instance
    // opt-out removes a globally-enabled button (see "Global + per-instance").
    return $this->shouldRender() ? [] : [self::TOOL_NAME];
}
```

### `shouldRender(): bool`

```php
return $this->isVisible()
    && DictationToolbarAction::isAllowedInCurrentPanel();
```

- `isVisible()` evaluates the `visible()`/`hidden()` config (default visible).
- `isAllowedInCurrentPanel()` reuses `SolarisAction`'s panel gate
  (`FilamentSolarisPlugin::current()?->isVisible() ?? true`). This gates the
  **button itself** — the action's own `->hidden()` gate only governs whether the
  action can be *mounted*, not whether the toolbar renders the button.

---

## Global + per-instance enablement

`RichEditor` inherits Filament's `Configurable` trait
(`RichEditor → Field → Schemas\Component → Support\ViewComponent → Support\Component`),
so `RichEditor::configureUsing(Closure)` applies a default to **every** instance.

### Global (config flag)

New config key, in the existing `transcription` group:

```php
// config/filament-solaris.php
'transcription' => [
    'default_provider' => null,
    'default_model' => null,
    'default_timeout' => null,
    'enable_rich_editor_toolbar_btn' => false,
],
```

In `FilamentSolarisServiceProvider::packageBooted()`:

```php
if (FilamentSolaris::config()->shouldAddDictationToRichEditorToolbar()) {
    RichEditor::configureUsing(static fn (RichEditor $editor): RichEditor =>
        $editor->plugins([DictationRichEditorPlugin::make()]));
}
```

`FilamentSolarisConfig::shouldAddDictationToRichEditorToolbar()` reads
`transcription.enable_rich_editor_toolbar_btn` (default `false`).

No new JS or asset registration — the existing `dictation-modal` Alpine component
and `dictation-modal.blade.php` are reused as-is.

### Per-instance

```php
RichEditor::make('body')->plugins([
    DictationRichEditorPlugin::make()->lang('nl-BE'),
]);
```

### Override / opt-out semantics

`configureUsing` runs at `make()` time; a per-instance `->plugins([...])` is
**appended after**. Two consequences, both desirable:

1. **Override** — `getTools()` keys tools by name (last wins), so a per-instance
   plugin's config (e.g. a different `lang`) supersedes the global one on that
   editor.
2. **Opt-out** — a per-instance `->visible(false)` plugin emits
   `getDisabledToolbarButtons() === ['solarisDictation']`. Processed after the
   global plugin's `enable`, it removes the button on that single editor:

   ```php
   RichEditor::make('slug')->plugins([
       DictationRichEditorPlugin::make()->visible(false),
   ]);
   ```

---

## Execution flow

1. **Click** the toolbar button. The `RichEditorTool`'s JS handler runs
   `$wire.mountAction('solarisDictation', { editorSelection, …{} }, { schemaComponent: <editorKey> })`,
   capturing the current cursor/selection.
2. **Modal opens** (standard `mountAction`). Recorder UI renders via
   `modalContent()` → `dictation-modal.blade.php`; a hidden `FileUpload` in the
   schema receives the recording.
3. **Record / stop / upload** — unchanged Alpine recorder; the busy flag blocks
   submit while recording/uploading.
4. **Submit** → action callback → `processDictation($data, $arguments)`.
5. **Transcribe** via `HandlesDictation::transcribe()` (routes through
   `executeAiCall`, so `SolarisResponseReceived/Failed` fire and the 3-way error
   notification logic applies).
6. **Insert** the transcript at `editorSelection` via
   `runCommands([insertContent], editorSelection)`; success notification.

### Error / edge cases (all reuse `HandlesDictation`)
- No audio file in `$data` → `notifications.transcription_error` (danger).
- Transcription API error / rate-limited / overloaded → matched notification.
- Empty transcript → `notifications.transcription_empty` (warning), no insertion.
- Host component is not a `RichEditor` → `RuntimeException` (misconfiguration —
  should only happen if the action is registered outside the plugin).

---

## Translations

Reuse existing keys: `dictation.modal_heading`, `dictation.submit_label`,
`dictation.not_supported`, `dictation.microphone_denied`, and all
`notifications.transcription_*`. Add:

```php
'dictation' => [
    // … existing …
    'toolbar_button_label' => 'Dictate',
],
'notifications' => [
    // … existing …
    'transcription_inserted' => 'Transcription inserted.',
],
```

(`transcription_inserted` is distinct from `transcription_success`, which names
the target field — there's no field name in the cursor-insert case.)

## Config

```php
// config/filament-solaris.php → 'transcription' group
'enable_rich_editor_toolbar_btn' => false,
```

```php
// FilamentSolarisConfig
public function shouldAddDictationToRichEditorToolbar(): bool
{
    return (bool) ($this->get('transcription.enable_rich_editor_toolbar_btn') ?? false);
}
```

Icon reuses `icons.dictation` (`FilamentSolaris::config()->getDictationIcon()`).

---

## Testing

### `DictationToolbarActionFake` (transcript-only)

Mirrors `DictationFieldActionFake` but **without** `aiResponse` (no pipeline):

```php
DictationToolbarAction::fake('Spoken text here.');

DictationToolbarAction::assertCalled();
DictationToolbarAction::assertTranscribed();
DictationToolbarAction::assertTranscribedWith(fn (string $t) => expect($t)->toContain('Spoken'));
DictationToolbarAction::assertNotCalled();
DictationToolbarAction::assertCalledTimes(1);
```

`WithDictationToolbarActionFake` trait for setup/teardown, matching the existing
`WithDictationFieldActionFake`.

### PHP tests

- **Feature:** with the fake active, mounting + submitting the action dispatches a
  `run-rich-editor-commands` Livewire event whose payload contains
  `insertContent` with the faked transcript and the forwarded `editorSelection`.
  Assert the success notification + `SolarisResponseReceived` event.
- **Plugin unit:**
  - `getEditorTools()` / `getEditorActions()` / `getEnabledToolbarButtons()` return
    the configured tool/action when visible; `[]` when `visible(false)`.
  - `getDisabledToolbarButtons()` returns `['solarisDictation']` when not visible
    (opt-out path).
  - panel gate: when a `FilamentSolarisPlugin` is registered with `->disabled()`,
    the button is gated out.
  - config proxying: `lang()` / `transcriptionProvider()` set on the plugin reach
    the built action.
- **Service provider:** with `transcription.enable_rich_editor_toolbar_btn => true`, a freshly
  `make()`d `RichEditor` has the plugin registered (button present); with the flag
  off it does not.

### JS

No new JS tests — the recorder Alpine component and its Vitest suite are unchanged.

---

## Documentation

- This spec: `specs/19-dictation-toolbar-action.md`.
- `specs/missing-features.md`: add an entry (P1/P2) marked shipped, pointing here.
- `documentation/dictation.md`: new "Toolbar button (RichEditor)" section covering
  the global flag, per-instance plugin, override/opt-out, and the testing fake.
- `CHANGELOG.md`: `Added` entry.

## Out of scope (deferred)

- **AI preset/prompt chaining** for the toolbar variant (transcript → pipeline →
  cursor). The `HandlesDictation`/`SolarisAction` split keeps this addable later.
- **Paragraph-splitting** of multi-paragraph transcripts (v1 inserts plain text).
- **Custom TipTap node** for dictated content (not needed — plain text insert).