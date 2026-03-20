# 15 — DictationAction

## Summary

`DictationAction` is a Filament action that opens a modal with a recording UI, captures audio via the browser's MediaRecorder API, transcribes it using `laravel/ai`'s Transcription API, and optionally processes the transcript through the same AI prompt pipeline used by `AiAction`. It supports two modes: pure transcription (audio to text) and transcription with AI processing (audio to structured output across multiple target fields).

## Class: DictationAction extends Filament\Actions\Action

### Traits Used
- `HasPromptPipeline` (from spec 14 — includes HasTargetFields, HasUserInput)

### Additional Properties

- `$append` (bool, default false): Whether to append the transcript to existing field content or replace it.
- `$transcriptionLang` (?string): BCP 47 language tag for the transcription (e.g., `'en-US'`, `'nl-BE'`). Falls back to the action's locale.
- `$transcriptionProvider` (Lab|array|string|Closure|null): Override the transcription API provider. Separate from the AI pipeline provider inherited from `HasPromptPipeline`.
- `$transcriptionModel` (string|Closure|null): Override the transcription model.
- `$transcriptionTimeout` (int|Closure|null): Override the transcription timeout in seconds.

## Configuration API

### Transcription Configuration

#### `lang(string $lang): static`
Sets the BCP 47 language tag for the transcription engine. Separate from the prompt locale.

#### `append(bool $append = true): static`
When in pure transcription mode, appends the transcript to existing field content instead of replacing it.

#### `transcriptionProvider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static`
Sets the provider (and optionally model) for the transcription API call. Separate from the `->provider()` inherited from `HasPromptPipeline` which controls the AI processing step. Falls back to config `default_transcription_provider` / `default_transcription_model`, then `null` (laravel/ai default).

#### `transcriptionTimeout(int|Closure $timeout): static`
Sets the timeout in seconds for the transcription API call. Falls back to config `default_transcription_timeout`.

### Supported Transcription Providers

Only providers that implement the `TranscriptionProvider` interface in `laravel/ai` can be used:

| Provider | Default Model | Notes |
|----------|--------------|-------|
| OpenAI | `gpt-4o-transcribe-diarize` | Default provider. Supports language, diarization |
| Mistral | `voxtral-mini-2602` | Supports language, prompt, temperature |
| ElevenLabs | `scribe_v2` | Supports language, speaker diarization |

Other providers (Anthropic, Groq, Gemini, etc.) do not support transcription.

### Full Configuration Examples

#### Pure Transcription

```php
DictationAction::make('dictate')
    ->targetField('notes')
    ->lang('en-US')
    ->append()

// With explicit transcription provider
DictationAction::make('dictate')
    ->targetField('notes')
    ->transcriptionProvider('openai', 'gpt-4o-transcribe-diarize')
    ->transcriptionTimeout(30)
```

Records audio, transcribes it, and puts the text into the `notes` field. No AI processing.

#### Transcription + AI Processing

```php
DictationAction::make('voice-summary')
    ->targetField('summary')
    ->targetField('category_id')
    ->preset(SummaryPreset::make()->maxWords(200))
    ->userInput(UserInput::make()->placeholder('Any extra instructions?'))
    ->locale('nl')
    ->lang('nl-BE')
```

Records audio, transcribes it, feeds the transcript into the prompt pipeline, and writes structured AI output to multiple target fields.

#### Transcription + AI with Separate Providers

```php
DictationAction::make('voice-summary')
    ->targetField('summary')
    ->preset(SummaryPreset::make())
    ->transcriptionProvider('mistral', 'voxtral-mini-2602')  // transcription step
    ->provider('anthropic', 'claude-sonnet-4-5-20250514')     // AI processing step
```

The `->transcriptionProvider()` controls the `Transcription::generate()` call. The `->provider()` (inherited from `HasPromptPipeline`) controls the AI processing pipeline.

#### Transcription + Inline Prompt

```php
DictationAction::make('voice-to-email')
    ->targetField('email_body')
    ->prompt('Rewrite this spoken text as a professional email.')
    ->locale('en')
```

#### As a Suffix Action on a Field

```php
TextInput::make('notes')
    ->suffixAction(
        DictationAction::make('dictate')
            ->targetField('notes')
    )
```

## Execution Flow

### Step 1: User Clicks the Dictation Button

- Filament opens a modal (standard `mountAction` flow — schema component context is preserved)
- The modal shows the recording UI (Alpine component) and optional user input fields
- The action icon is configurable via `FilamentSolarisConfig::getDictationIcon()` / config `dictation_icon`

### Step 2: User Clicks Record in the Modal

- The Alpine component requests microphone permission via `getUserMedia()`
- On success: MediaRecorder starts, the record button turns red with a pulse animation, a timer shows elapsed duration
- On permission denied: an inline error message is shown in the modal

### Step 3: User Clicks Stop

- MediaRecorder stops, produces an audio Blob from collected chunks
- The blob is uploaded to the server via Livewire's `$wire.upload()` to `componentFileAttachments.dictation_audio`
- The record button changes to a green checkmark, status shows "Recording complete — ready to submit"

### Step 4: User Clicks "Transcribe" (Submit)

- Filament's `callMountedAction()` fires (shows loading spinner on the submit button)
- Server receives the uploaded audio file from `$livewire->componentFileAttachments['dictation_audio']`
- Resolves transcription provider/model via `resolveTranscriptionProviderAndModel()` (action override → config → null)
- Calls `Transcription::fromUpload($file)->generate($provider, $model)`
- Cleans up the temporary audio file

#### Given transcription succeeds
- Proceed to Step 5

#### Given transcription fails (API error)
- Report the exception via `report()`
- Show error notification: "Could not transcribe the audio. Please try again."

#### Given transcription returns empty text
- Show warning notification: "No speech detected in the recording."

### Step 5: Mode Decision

#### Given no PromptBuilder is configured (pure transcription mode)
- Write the transcript directly to the target field
- If `$append` is true, prepend existing content with a newline separator
- Show success notification

#### Given a PromptBuilder is configured (AI processing mode)
- Call `runPipeline(['transcription' => $transcript], $userInput)`
- The transcript becomes the source data for the prompt
- User input from the modal form (if configured via `schema()`) is passed through
- Notifications follow the same pattern as AiAction (success, partial failure, error)

## Alpine.js Component: `dictationModal`

### Registration

The Alpine component is registered as a Filament asset in the service provider:

```php
// FilamentSolarisServiceProvider
FilamentAsset::register([
    AlpineComponent::make('dictation-modal', __DIR__ . '/../dist/components/dictation.js'),
], 'statikbe/filament-solaris');
```

The component is rendered inside the modal via `modalContent()` using the `dictation-modal.blade.php` view. It uses `x-load` / `x-load-src` for lazy loading.

### Build Configuration

The JS is built with esbuild. `keepNames: true` is required so the function name `dictationModal` is preserved after minification (Filament's `x-load` uses the function's `.name` property to register it as an Alpine component).

```js
// bin/build.js
esbuild.build({
    entryPoints: ['resources/js/components/dictation.js'],
    outdir: 'dist/components',
    bundle: true,
    platform: 'neutral',
    mainFields: ['module', 'main'],
    target: ['es2020'],
    minify: !isDev,
    keepNames: true,
    sourcemap: isDev ? 'inline' : false,
    format: 'esm',
})
```

### Component State

| Property | Type | Description |
|----------|------|-------------|
| `recording` | boolean | Whether audio is being captured |
| `uploading` | boolean | Whether the blob is being uploaded to Livewire |
| `uploaded` | boolean | Whether the upload completed successfully |
| `supported` | boolean | Whether the browser supports MediaRecorder |
| `microphoneDenied` | boolean | Whether microphone permission was denied |
| `uploadFailed` | boolean | Whether the Livewire upload failed |
| `duration` | number | Elapsed recording time in seconds |
| `durationInterval` | number\|null | The `setInterval` ID for the timer |

### Visual States

| State | Record Button | Status Text | Timer |
|-------|--------------|-------------|-------|
| Idle | Primary color, mic icon | "Click to start recording." | Hidden |
| Recording | Red, pulsing, scaled up | "Recording... Click to stop." | Visible (M:SS) |
| Uploading | Disabled, 50% opacity | "Uploading..." | Hidden |
| Uploaded | Green, check icon | "Recording complete — ready to submit." | Hidden |
| Upload Failed | Primary color, mic icon | "Upload failed. Click to try again." | Hidden |
| Mic Denied | Hidden | Error message shown instead | Hidden |
| Not Supported | Hidden | Error message shown instead | Hidden |

### Feature Detection

#### Given the browser does not support MediaRecorder or getUserMedia
- The recording controls are hidden
- An error message is shown: "Your browser does not support audio recording."

#### Given the user denies microphone permission
- An error message is shown inline in the modal: "Microphone access was denied. Please allow microphone access in your browser settings and try again."

### Audio Format

- MediaRecorder produces `audio/webm` (Chrome/Edge/Firefox) or `audio/mp4` (Safari)
- Chrome may report the mime type as `video/webm` — this is normal
- The file extension is set based on the mime type: `.webm` or `.mp4`

### Tailwind CSS Note

The modal Blade view uses Tailwind CSS classes. Projects using this package must add a `@source` directive in their Filament theme CSS to scan the package's views:

```css
@source "../../vendor/statikbe/laravel-filament-solaris/resources/views";
```

## Server-Side Processing

### Livewire Integration

Audio is uploaded via Livewire's `$wire.upload()` mechanism to `componentFileAttachments.dictation_audio`. The action retrieves it via `data_get($livewire, 'componentFileAttachments.dictation_audio')` and cleans it up after processing.

### Transcription Call

```php
use Laravel\Ai\Transcription;

['provider' => $provider, 'model' => $model] = $this->resolveTranscriptionProviderAndModel();
$timeout = $this->resolveTranscriptionTimeout();

$pending = Transcription::fromUpload($audioFile)
    ->language($this->getTranscriptionLang());

if ($timeout !== null) {
    $pending->timeout($timeout);
}

$response = $pending->generate($provider, $model);
$text = (string) $response;
```

## Error Handling

All errors are reported via `report()`.

### Transcription Errors

- API error: "Could not transcribe the audio. Please try again."
- Rate limit: "Too many transcription requests. Please wait a moment."
- Empty transcript: "No speech detected in the recording." (warning)

### Client-Side Errors

- Microphone denied: inline error in modal
- Upload failed: inline status in modal
- Browser not supported: inline message in modal

### Pipeline Errors (when PromptBuilder is configured)

Same as AiAction — handled by `HasPromptPipeline`:
- Timeout, rate limit, API error notifications
- Partial failure with field-level warnings

## Notifications

### Pure Transcription Mode

- Success: "Transcription added to :fields."
- Empty transcript: "No speech detected in the recording."
- Error: "Could not transcribe the audio. Please try again."

### AI Processing Mode

Same notifications as AiAction (via `HasPromptPipeline`):
- Success: "AI filled :fields."
- Partial failure: "Could not fill :fields."
- Error: "Something went wrong with the AI request. Please try again."

## Translation Keys

```php
'dictation' => [
    'modal_heading' => 'Record Audio',
    'submit_label' => 'Transcribe',
    'not_supported' => 'Your browser does not support audio recording.',
    'microphone_denied' => 'Microphone access was denied. Please allow microphone access in your browser settings and try again.',
],
'notifications' => [
    // ... existing keys ...
    'transcription_success' => 'Transcription added to :fields.',
    'transcription_empty' => 'No speech detected in the recording.',
    'transcription_error' => 'Could not transcribe the audio. Please try again.',
    'transcription_rate_limited' => 'Too many transcription requests. Please wait a moment.',
    'microphone_denied' => 'Microphone access is required for dictation.',
],
```

## Configuration

### Config Keys

```php
// config/filament-solaris.php

'dictation_icon' => Heroicon::OutlinedMicrophone,

'default_transcription_provider' => null,
'default_transcription_model' => null,
'default_transcription_timeout' => null,
```

### Icon

The dictation icon is configurable via `FilamentSolarisConfig::getDictationIcon()` and the `dictation_icon` config key. Defaults to `Heroicon::OutlinedMicrophone`.

## Validation Before Execution

#### Given no targetField configured
- Then it throws a `RuntimeException`: "DictationAction requires at least one target field."

#### Given multiple target fields but no PromptBuilder
- When in pure transcription mode
- Then only the first target field receives the transcript (transcription produces a single string)

## Filament Registration

```php
// As a header action on a page
public function getActions(): array
{
    return [
        DictationAction::make('voice-summary')
            ->targetFields(['description'])
            ->preset(SummaryPreset::make()->maxWords(200))
            ->lang('nl-BE'),
    ];
}

// As a suffix action on a field
TextInput::make('notes')
    ->suffixAction(
        DictationAction::make('dictate')
            ->targetField('notes')
            ->lang('en-US')
            ->append()
    )

// In a Forms\Components\Actions group
Forms\Components\Actions::make([
    DictationAction::make('dictate-notes')
        ->targetField('notes')
        ->lang('en-US'),
]),
```

## Browser Support

| Browser | MediaRecorder | getUserMedia | Status |
|---|---|---|---|
| Chrome 49+ | Yes | Yes | Full support |
| Edge 79+ | Yes | Yes | Full support |
| Firefox 25+ | Yes | Yes | Full support |
| Safari 14.1+ | Yes | Yes | Full support |

The button is automatically hidden in unsupported browsers via feature detection.

## Testing

### DictationAction::fake()

```php
use Statikbe\FilamentSolaris\Actions\DictationAction;

// Fake transcription with a predetermined transcript
DictationAction::fake('This is the transcribed text.');

// Fake transcription + AI processing
DictationAction::fake('Meeting notes about project timeline.', aiResponse: [
    'summary' => 'Discussion about project deadlines.',
    'category_id' => 'meetings',
]);
```

### Assertions

```php
DictationAction::assertCalled();
DictationAction::assertTranscribed();
DictationAction::assertTranscribedWith(function (string $transcript) {
    expect($transcript)->toContain('meeting');
});
```

### Behavior

#### Given DictationAction::fake() is called with a transcript string
- When the dictation action processes audio
- Then the Transcription API is skipped
- And the provided string is used as the transcript
- And if a PromptBuilder is configured, it passes through the pipeline with `AiActionFake`

### JavaScript Tests

The Alpine component has a full Vitest test suite (`resources/js/components/dictation.test.js`) covering:
- Feature detection (supported/unsupported browsers)
- Recording lifecycle (start, stop, state transitions)
- Duration timer
- Upload success/failure
- Microphone permission denial
- Status text for all states

JS tests run in CI via the `run-tests.yml` GitHub Action alongside PHP tests.

## Package Dependencies

### Build Tooling

```
resources/
├── js/
│   └── components/
│       └── dictation.js          # source (exports dictationModal function)
dist/
├── components/
│   └── dictation.js              # built (esbuild, keepNames: true)
```

Build via `npm run build` (uses `bin/build.js` with esbuild).

### Service Provider

```php
public function packageBooted(): void
{
    FilamentAsset::register([
        AlpineComponent::make('dictation-modal', __DIR__ . '/../dist/components/dictation.js'),
    ], 'statikbe/filament-solaris');
}
```

No new Composer dependencies — `laravel/ai` already provides the Transcription API.
