# Usage Tracking

[← Back to README](../README.md)

Every Solaris AI call dispatches one of two events you can listen to for metering, budgeting, or audit purposes:

- **`Statikbe\FilamentSolaris\Events\SolarisResponseReceived`** — fired after each successful call (text, image, or transcription). Carries the laravel/ai `Usage` object (prompt/completion/cache/reasoning token counts) plus Solaris-specific context: action name, action class, resolved provider/model, call duration, the authenticated user (if any), and the Livewire component that owns the form.
- **`Statikbe\FilamentSolaris\Events\SolarisResponseFailed`** — fired when an `AiException` is caught (rate limit, provider outage, configuration error). Same shape, but carries the exception instead of usage.

Solaris does **not** persist anything — apps decide what to do with the data. A typical listener:

```php
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;

class TrackAiUsage
{
    public function handle(SolarisResponseReceived $event): void
    {
        AiCall::create([
            'action_name'             => $event->actionName,
            'action_class'            => $event->actionClass,
            'user_id'                 => $event->user?->getAuthIdentifier(),
            'provider'                => is_array($event->provider)
                ? ($event->provider[0] ?? null)
                : (is_object($event->provider) ? $event->provider->value : $event->provider),
            'model'                   => $event->model,
            'prompt_tokens'           => $event->usage->promptTokens,
            'completion_tokens'       => $event->usage->completionTokens,
            'cache_read_input_tokens' => $event->usage->cacheReadInputTokens,
            'cache_write_input_tokens'=> $event->usage->cacheWriteInputTokens,
            'reasoning_tokens'        => $event->usage->reasoningTokens,
            'duration_ms'             => $event->durationMs,
            'created_at'              => now(),
        ]);
    }
}
```

Register it in `EventServiceProvider` the usual Laravel way. The event also fires from the Solaris fakes (`AiAction::fake()`, `ImageGenerationAction::fake()`, `DictationFieldAction::fake()`) so you can test your listener without hitting a real provider — the fake dispatches with a zero-token `Usage`.

**What the event deliberately does NOT carry:** the prompt text, the source field values, or the AI response. These can be large, may contain PII, and would bloat any listener's storage. Use `SolarisPromptLogger` (gate it on `prompt_logging_enabled`) if you need that level of detail for development.

**Versioning note:** these events ship in `0.1.0`. Apps that adopt Solaris on `0.1.x` and don't register a listener can opt into tracking later by registering one — but only future calls will be captured. Plan accordingly.

## Option-match detection event

A third event, **`Statikbe\FilamentSolaris\Events\SolarisOptionMatched`**, fires when an AI value is resolved to a Select/CheckboxList option via an *inexact* strategy (substring or fuzzy). It's the signal for detecting option misclassification in production. See [Component Factories → Option Matching](factories.md#option-matching) for the payload and a sample listener.

## Rate Limiting & Retry

Solaris distinguishes three failure modes when an `AiException` is caught and renders dedicated user-facing notifications for each, per pipeline:

| Exception | Text (`AiAction`) | Image (`ImageGenerationAction`) | Transcription (`DictationFieldAction`) |
|---|---|---|---|
| `RateLimitedException` | `notifications.rate_limited` | `notifications.image_generation_rate_limited` | `notifications.transcription_rate_limited` |
| `ProviderOverloadedException` | `notifications.overloaded` | `notifications.overloaded` | `notifications.transcription_overloaded` |
| any other `AiException` | `notifications.error` | `notifications.image_generation_error` | `notifications.transcription_error` |

Translations ship for English, Dutch, and French; override any of these keys in your app's published `lang/vendor/filament-solaris/{locale}/filament-solaris.php` to customise the wording.

**Backoff / retry hook.** The `SolarisResponseFailed` event documented above carries the raw exception — listen for it and `instanceof RateLimitedException` for app-level backoff or queued retry:

```php
use Laravel\Ai\Exceptions\RateLimitedException;
use Statikbe\FilamentSolaris\Events\SolarisResponseFailed;

class HandleAiRateLimit
{
    public function handle(SolarisResponseFailed $event): void
    {
        if (! $event->exception instanceof RateLimitedException) {
            return;
        }

        // Examples: enqueue a delayed retry, increment a per-user Redis
        // counter, page ops, fall back to a cached previous result …
        RetrySolarisAction::dispatch(
            actionName: $event->actionName,
            user: $event->user,
        )->delay(now()->addSeconds(30));
    }
}
```

Solaris itself does not retry automatically — that decision (when to retry, with what backoff, after how many attempts to give up) is yours to make.
