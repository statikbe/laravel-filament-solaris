# Dictation

[← Back to README](../README.md)

Solaris offers two ways to dictate into a form, both capturing audio via the browser's MediaRecorder API and transcribing it with `laravel/ai`'s Transcription API. They share the same recording modal and language/provider settings — they differ in *where* the transcript lands.

| | Field action | Toolbar button |
|---|---|---|
| Class | `DictationFieldAction` | `DictationRichEditorPlugin` |
| Attaches to | any `Field` — `->hintAction()` / `->suffixAction()` | a `RichEditor` toolbar — `->plugins()` |
| Writes | the field value (replace / append) | inserts at the cursor |
| AI pipeline | yes (`->preset()` / `->prompt()`) | no (pure transcription) |

## Field action (`DictationFieldAction`)

`DictationFieldAction` works in two modes: pure transcription and transcription with AI processing. Attach it directly to any Filament `Field` via `->hintAction(...)` (works on `Textarea`, `RichEditor`, etc.) or via `->suffixAction(...)` on a `TextInput`. The transcript is written back into the **host field** — no `->targetField()` call needed.

### Pure transcription

```php
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;

// On a TextInput (suffix or hint both work)
TextInput::make('title')
    ->suffixAction(
        DictationFieldAction::make()->lang('en')
    );

// On a Textarea / RichEditor via hintAction
Textarea::make('notes')
    ->hintAction(
        DictationFieldAction::make()
            ->lang('en')
            ->append()     // append to existing content instead of replacing
    );
```

> **Language tag format is provider-specific.** Most transcription APIs (OpenAI Whisper, Mistral, etc.) want ISO 639-1 alpha-2 (`'en'`, `'nl'`, `'fr'`). Google Speech and Azure Speech accept BCP 47 with a region tag (`'nl-BE'`). Passing `'nl-BE'` to Whisper or Mistral returns a 422 "Invalid language alpha2 code" — strip the region for those.

### RichEditor support

On a `RichEditor`, attach via `->hintAction(...)` (the `->suffixAction(...)` slot is `TextInput`-only). The transcript **replaces** the editor's whole value, or **appends** to it with `->append()` — it writes the field value, it does not insert at the cursor position. For cursor insertion, use the [toolbar button](#toolbar-button-dictationricheditorplugin) instead.

### Transcription + AI processing

Add a `->prompt()` or `->preset()` and the transcript flows through the AI pipeline before being written:

```php
Textarea::make('summary')
    ->hintAction(
        DictationFieldAction::make('voice-summary')
            ->preset(SummaryPreset::make()->maxWords(200))
            ->locale('nl')
            ->lang('nl')
    );
```

To write into multiple fields (or a different field than the host), set `->targetFields()` explicitly:

```php
Textarea::make('summary')
    ->hintAction(
        DictationFieldAction::make('voice-classify')
            ->targetFields(['summary', 'category_id'])
            ->prompt('Summarize the transcription and classify it.')
    );
```

The transcript becomes the source data (`['transcription' => $transcript]`) for any prompt or preset.

### Transcription provider

The transcription step and AI processing step can use different providers:

```php
DictationFieldAction::make('voice')
    ->preset(SummaryPreset::make())
    ->transcriptionProvider('openai', 'whisper-1')         // transcription
    ->transcriptionTimeout(30)                              // transcription timeout
    ->provider('anthropic', 'claude-sonnet-4-5-20250514')  // AI processing
    ->timeout(120)                                          // AI processing timeout
```

Package-wide defaults can be set in the config (`default_transcription_provider`, `default_transcription_model`, `default_transcription_timeout`). See [Configuration](configuration.md).

### Testing

See [Testing documentation](testing.md#testing-dictationfieldaction).

## Toolbar button (`DictationRichEditorPlugin`)

Add a dictation button to a `RichEditor`'s toolbar. Clicking it opens the recording modal and inserts the transcript **at the cursor** — rather than replacing or appending to the whole field value like the field action's `hintAction` path.

> **Scope:** pure transcription → cursor insert. The AI preset/prompt pipeline available on `DictationFieldAction` (via `->preset()` / `->prompt()`) is **not** supported for the toolbar button.

### Per editor

Attach a `DictationRichEditorPlugin` to any `RichEditor` instance:

```php
use Filament\Forms\Components\RichEditor;
use Statikbe\FilamentSolaris\RichEditor\DictationRichEditorPlugin;

RichEditor::make('body')
    ->plugins([
        DictationRichEditorPlugin::make()
            ->lang('nl-BE'),
    ]);
```

### Globally (every RichEditor)

Enable the toolbar button for every `RichEditor` in the application via the config flag:

```php
// config/filament-solaris.php
'transcription' => [
    'enable_rich_editor_toolbar_btn' => true,
],
```

Or, if you prefer to scope enablement to a specific panel, use the Solaris panel plugin:

```php
use Statikbe\FilamentSolaris\FilamentSolarisPlugin;

$panel->plugin(
    FilamentSolarisPlugin::make()
        ->enableRichEditorToolbarButton(),
);
```

A per-instance `DictationRichEditorPlugin` always overrides the global setting — useful for per-editor language or to opt a single editor out:

```php
// Different language on this editor
RichEditor::make('body')
    ->plugins([
        DictationRichEditorPlugin::make()->lang('nl-BE'),
    ]);

// Opt this editor out even when the global flag is on
RichEditor::make('slug')
    ->plugins([
        DictationRichEditorPlugin::make()->visible(false),
    ]);
```

### Configuration

`DictationRichEditorPlugin` exposes the same transcription settings as `DictationFieldAction`:

| Method | Description |
|---|---|
| `->lang(string)` | BCP 47 / ISO 639-1 language tag passed to the transcription API |
| `->transcriptionProvider(string $provider, string $model)` | Override the transcription provider and model |
| `->transcriptionTimeout(int $seconds)` | Override the transcription request timeout |
| `->icon(string)` | Button icon (defaults to `icons.dictation` config value) |
| `->label(string)` | Button tooltip / aria-label |
| `->visible(bool\|Closure)` | Show or hide the button |
| `->hidden(bool\|Closure)` | Inverse of `->visible()` |

### Testing

Use `DictationToolbarAction::fake()` to stub the toolbar action in tests:

```php
use Statikbe\FilamentSolaris\Actions\DictationToolbarAction;

DictationToolbarAction::fake('Dictated text.');

// … trigger the Livewire action …

DictationToolbarAction::assertTranscribed();
DictationToolbarAction::assertTranscribedWith(fn (string $t) => expect($t)->toContain('Dictated'));
DictationToolbarAction::assertCalled();
DictationToolbarAction::assertCalledTimes(1);
DictationToolbarAction::assertNotCalled(); // use this when verifying it was NOT triggered
```

## How it works

When the user clicks a dictation button, a modal opens with a recording UI. The user clicks to start recording, speaks, then clicks to stop. The audio is uploaded and transcribed server-side. For the field action's AI processing mode, the transcript is then fed into the prompt pipeline.

The recording UI shows clear visual states: a pulsing red button with elapsed timer while recording, a green checkmark when the upload completes, and inline error messages for microphone permission issues. The modal's submit button is disabled while recording or uploading is in progress, so a transcription can't fire without audio.

## Browser support

Dictation uses the MediaRecorder API and works in all modern browsers (Chrome 49+, Edge 79+, Firefox 25+, Safari 14.1+). The button is automatically hidden in unsupported browsers.
