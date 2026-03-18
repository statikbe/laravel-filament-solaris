# 15 — DictationAction

## Summary

`DictationAction` is a Filament action that captures audio via the browser's MediaRecorder API, transcribes it using `laravel/ai`'s Transcription API, and optionally processes the transcript through the same AI prompt pipeline used by `AiAction`. It supports two modes: pure transcription (audio to text) and transcription with AI processing (audio to structured output across multiple target fields).

## Class: DictationAction extends Filament\Actions\Action

### Traits Used
- `HasPromptPipeline` (from spec 14 — includes HasTargetFields, HasUserInput)

### Additional Properties

- `$append` (bool, default false): Whether to append the transcript to existing field content or replace it.
- `$transcriptionLang` (?string): BCP 47 language tag for the transcription (e.g., `'en-US'`, `'nl-BE'`). Falls back to the action's locale.
- `$transcriptionProvider` (Lab|array|string|Closure|null): Override the transcription API provider. Separate from the AI pipeline provider inherited from `HasPromptPipeline`.
- `$transcriptionModel` (string|Closure|null): Override the transcription model.

## Configuration API

### Transcription Configuration

#### `lang(string $lang): static`
Sets the BCP 47 language tag for the transcription engine. Separate from the prompt locale.

#### `append(bool $append = true): static`
When in pure transcription mode, appends the transcript to existing field content instead of replacing it.

#### `transcriptionProvider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static`
Sets the provider (and optionally model) for the transcription API call. Separate from the `->provider()` inherited from `HasPromptPipeline` which controls the AI processing step. Falls back to config `default_transcription_provider` / `default_transcription_model`, then `null` (laravel/ai default).

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
    ->transcriptionProvider('openai', 'whisper-1')
```

Records audio, transcribes it, and puts the text into the `notes` field. No AI processing.

#### Transcription + AI Processing

```php
DictationAction::make('voice-summary')
    ->targetField('summary')
    ->targetField('category_id')
    ->preset(SummaryPreset::make()->maxWords(200))
    ->userInput(UserInput::make()->placeholder('Any extra instructions?'))
    ->withPreview()
    ->locale('nl')
    ->lang('nl-BE')
```

Records audio, transcribes it, feeds the transcript into the prompt pipeline, and writes structured AI output to multiple target fields. Optionally shows preview before applying.

#### Transcription + AI with Separate Providers

```php
DictationAction::make('voice-summary')
    ->targetField('summary')
    ->preset(SummaryPreset::make())
    ->transcriptionProvider('openai', 'whisper-1')  // transcription step
    ->provider('anthropic', 'claude-sonnet-4-5-20250514')  // AI processing step
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

### Step 1: User Clicks the Microphone Button

- The action button shows a microphone icon
- On click, the Alpine.js component starts the MediaRecorder
- The button changes to a "recording" state (pulsing indicator)
- Browser requests microphone permission if not already granted

### Step 2: User Clicks Again to Stop Recording

- MediaRecorder stops, produces an audio Blob
- The audio blob is uploaded to the server via Livewire's file upload mechanism (`$wire.upload()`)
- The button shows a loading state

### Step 3: Transcription

- Server receives the uploaded audio file
- Resolves transcription provider/model via `resolveTranscriptionProviderAndModel()` (action override → config `default_transcription_provider` → null)
- Calls `Transcription::fromUpload($file)->generate($provider, $model)`
- Returns the transcript text

#### Given transcription succeeds
- When the transcript text is available
- Then proceed to Step 4

#### Given transcription fails (API error, unsupported format)
- When the error is caught
- Then show an error notification: "Could not transcribe the audio. Please try again."
- And the uploaded audio file is cleaned up

### Step 4: Mode Decision

#### Given no PromptBuilder is configured (pure transcription mode)
- Then write the transcript directly to the target field
- If `$append` is true, prepend existing content with a newline separator
- Show a success notification

#### Given a PromptBuilder is configured (AI processing mode)
- Then call `runPipeline(['transcription' => $transcript], $userInput)`
- The transcript becomes the source data for the prompt
- The pipeline handles AI call, result transformation, and application
- Notifications follow the same pattern as AiAction (success, partial failure, error)

### Step 5: User Input (conditional)

#### Given hasUserInput() is true and a PromptBuilder is configured
- Then open a Filament modal with the UserInput form schema BEFORE recording starts
- User fills in instructions, clicks "Record"
- Recording begins after modal submission
- User input is passed to the pipeline as `$userInput`

#### Given hasUserInput() is true and no PromptBuilder (pure transcription)
- Then skip user input — it has no effect without AI processing

#### Given hasUserInput() is false
- Then recording starts immediately on button click

## Alpine.js Component

### Registration

The Alpine component is registered as a Filament asset in the service provider:

```php
// FilamentSolarisServiceProvider
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;

public function packageBooted(): void
{
    FilamentAsset::register([
        AlpineComponent::make('dictation', __DIR__ . '/../dist/components/dictation.js'),
    ], 'statikbe/filament-solaris');
}
```

### Component Behavior

```javascript
Alpine.data('dictation', ({ statePath, wire }) => ({
    recording: false,
    processing: false,
    mediaRecorder: null,
    chunks: [],
    supported: false,

    init() {
        this.supported = !!navigator.mediaDevices?.getUserMedia;
    },

    async toggle() {
        if (this.recording) {
            this.stop();
        } else {
            await this.start();
        }
    },

    async start() {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        this.mediaRecorder = new MediaRecorder(stream);
        this.chunks = [];

        this.mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) this.chunks.push(e.data);
        };

        this.mediaRecorder.onstop = () => {
            const blob = new Blob(this.chunks, { type: 'audio/webm' });
            stream.getTracks().forEach(t => t.stop());
            this.upload(blob);
        };

        this.mediaRecorder.start();
        this.recording = true;
    },

    stop() {
        this.mediaRecorder?.stop();
        this.recording = false;
        this.processing = true;
    },

    upload(blob) {
        const file = new File([blob], 'recording.webm', { type: 'audio/webm' });
        wire.upload('__dictationAudio', file, () => {
            wire.call('processDictation', statePath);
            this.processing = false;
        });
    },
}));
```

### Feature Detection

#### Given the browser does not support MediaRecorder or getUserMedia
- Then the dictation button is hidden (`x-show="supported"`)
- No error is shown — the feature silently degrades

#### Given the user denies microphone permission
- Then an error notification is shown: "Microphone access is required for dictation."
- And the button returns to its default state

### Audio Format

- MediaRecorder produces `audio/webm` (Chrome/Edge/Firefox) or `audio/mp4` (Safari)
- Both formats are supported by OpenAI and ElevenLabs transcription APIs
- The `mimeType` is detected automatically by MediaRecorder

## Server-Side Processing

### Livewire Integration

The DictationAction needs a server-side handler to receive the uploaded audio and call the Transcription API. This is handled via a temporary Livewire property and method injected into the parent component.

```php
// Approach: DictationAction registers a listener on the Livewire component
// The Alpine component calls $wire.upload() then $wire.call('processDictation')
```

### Transcription Call

```php
use Laravel\Ai\Transcription;

['provider' => $provider, 'model' => $model] = $this->resolveTranscriptionProviderAndModel();
$transcript = Transcription::fromUpload($audioFile)->generate($provider, $model);
$text = (string) $transcript;
```

#### Given the audio is short (< 30 seconds)
- Then process synchronously
- The user sees a loading indicator while transcription runs

#### Given the transcription returns empty text
- When the result is an empty string
- Then show a warning notification: "No speech detected in the recording."
- And do not modify any form fields

## Error Handling

Report all errors.

### Transcription Errors

- API error: "Could not transcribe the audio. Please try again."
- Rate limit: "Too many transcription requests. Please wait a moment."
- Unsupported audio format: "Audio format not supported. Please try again."

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
// Added to filament-solaris lang files
'notifications' => [
    // ... existing keys ...
    'transcription_success' => 'Transcription added to :fields.',
    'transcription_empty' => 'No speech detected in the recording.',
    'transcription_error' => 'Could not transcribe the audio. Please try again.',
    'transcription_rate_limited' => 'Too many transcription requests. Please wait a moment.',
    'microphone_denied' => 'Microphone access is required for dictation.',
],
```

## Validation Before Execution

#### Given no targetField configured
- When the action is initialized
- Then it throws a `RuntimeException`: "DictationAction requires at least one target field."

#### Given a PromptBuilder is configured but no targetField
- Then the same validation applies — target fields are always required

#### Given multiple target fields but no PromptBuilder
- When in pure transcription mode
- Then only the first target field receives the transcript (transcription produces a single string)

## Loading State

- While recording: microphone icon pulses / animates, button color changes
- While uploading: loading spinner on button
- While transcribing: loading spinner on button with "Transcribing..." text
- While AI processes (if PromptBuilder configured): loading spinner continues

## Filament Registration

```php
// As a header action
public static function form(Form $form): Form
{
    return $form
        ->schema([
            Textarea::make('notes'),
            Textarea::make('summary'),
            Select::make('category_id')->options([...]),
        ])
        ->headerActions([
            DictationAction::make('voice-to-notes')
                ->targetField('notes'),

            DictationAction::make('voice-to-structured')
                ->targetField('summary')
                ->targetField('category_id')
                ->preset(SummaryPreset::make()),
        ]);
}

// As a suffix action on a field
Textarea::make('notes')
    ->suffixAction(
        DictationAction::make('dictate')
            ->targetField('notes')
            ->lang('en-US')
            ->append()
    )
```

## Browser Support

| Browser | MediaRecorder | getUserMedia | Status |
|---|---|---|---|
| Chrome 49+ | Yes | Yes | Full support |
| Edge 79+ | Yes | Yes | Full support |
| Firefox 25+ | Yes | Yes | Full support |
| Safari 14.1+ | Yes | Yes | Full support |

MediaRecorder has significantly broader browser support than the Web Speech API. By using server-side transcription via `laravel/ai`, dictation works in all modern browsers including Firefox.

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

## Package Dependencies

### Build Tooling

The Alpine.js component requires a JavaScript build step:

```
resources/
├── js/
│   └── components/
│       └── dictation.js
dist/
├── components/
│   └── dictation.js
```

Build via Vite with the Filament plugin or a standalone build script.

### Service Provider Changes

```php
public function packageBooted(): void
{
    FilamentAsset::register([
        AlpineComponent::make('dictation', __DIR__ . '/../dist/components/dictation.js'),
    ], 'statikbe/filament-solaris');
}
```

No new Composer dependencies — `laravel/ai` already provides the Transcription API.
