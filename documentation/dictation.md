# DictationFieldAction

[← Back to README](../README.md)

`DictationFieldAction` captures audio via the browser's MediaRecorder API, transcribes it using `laravel/ai`'s Transcription API, and optionally processes the transcript through the AI pipeline. It works in two modes: pure transcription and transcription with AI processing.

Attach it directly to any Filament `Field` via `->hintAction(...)` (works on `Textarea`, `RichEditor`, etc.) or via `->suffixAction(...)` on a `TextInput`. The transcript is written back into the **host field** — no `->targetField()` call needed.

## Pure Transcription

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

On a `RichEditor`, attach via `->hintAction(...)` (the `->suffixAction(...)` slot is `TextInput`-only). The transcript **replaces** the editor's whole value, or **appends** to it with `->append()` — it writes the field value, it does not insert at the cursor position.

> **Roadmap:** a `DictationToolbarAction` that lives *in the RichEditor's toolbar* and inserts the transcript at the cursor is planned but not yet shipped. The recording/transcription internals already live in a shared `HandlesDictation` trait to make that variant cheap to add later. For now, use the `hintAction` path above.

## Transcription + AI Processing

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

## Transcription Provider

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

## How It Works

When the user clicks the dictation button, a modal opens with a recording UI. The user clicks to start recording, speaks, then clicks to stop. The audio is uploaded and transcribed server-side. In AI processing mode, the transcript is then fed into the prompt pipeline.

The recording UI shows clear visual states: a pulsing red button with elapsed timer while recording, a green checkmark when the upload completes, and inline error messages for microphone permission issues. The modal's submit button is disabled while recording or uploading is in progress, so a transcription can't fire without audio.

## Browser Support

DictationFieldAction uses the MediaRecorder API and works in all modern browsers (Chrome 49+, Edge 79+, Firefox 25+, Safari 14.1+). The button is automatically hidden in unsupported browsers.

## Testing

See [Testing documentation](testing.md#testing-dictationfieldaction).
