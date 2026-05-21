# Security Considerations

[← Back to README](../README.md)

Solaris glues two untrusted boundaries together: **user-typed values flow into the prompt**, and **AI-generated output flows back into form fields.** Both deserve explicit handling — particularly when the form is public-facing, or when AI-populated values are later displayed to *other* users.

## Treat AI output as untrusted user input

Anything the AI writes back is, for security purposes, user-generated content. The user typing source values can steer what comes out — current LLMs are not reliably resistant to prompt-injection, and no input-side mitigation Solaris ships will change that. The real defense lives at the **render layer**, in the templates that display AI-populated values.

**Always-safe rendering** (Blade auto-escapes):

```blade
{{ $post->summary }}  {{-- safe, escaped --}}
```

**Unsafe unless you sanitize first**:

```blade
{!! $post->summary !!}  {{-- raw — only OK if you trust the source 100% --}}
```

The same rule applies to **Mail templates, PDF templates, exported JSON, webhook payloads, and any other place that's not Blade auto-escaping**. If your app routes an AI-populated value through `{!! !!}`, an HTML email body, a PDF library, an SMS, or a CSV export, that path must be sanitized at render time.

## Field-type guidance

| Filament component | AI-injection risk on form display | Notes |
|---|---|---|
| `TextInput` / `Textarea` / `MarkdownEditor` / `CodeEditor` | Low **on the form itself** (Blade escapes), high if value is rendered as raw HTML elsewhere | The form is safe; downstream rendering is your responsibility. |
| `RichEditor` | Low — Filament sanitizes RichEditor output via TipTap on render | Safer field type when AI output may contain markup. |
| `Select` / `Radio` / `CheckboxList` / `Toggle` | None | The factory constrains the AI to a fixed enum of allowed values — the output is structurally bounded. |
| `FileUpload` (image generation) | None — binary content with a verified MIME | The image is stored as a Livewire temporary upload; no text-injection vector. |

If you have a free-text field whose value will be rendered as HTML elsewhere, prefer `RichEditor` for that field, or use the sanitization hook below.

## Sanitization hook

Each `AiAction` accepts an optional sanitizer that runs on every AI value before it's written to form state:

```php
use Mews\Purifier\Facades\Purifier;  // any HTML purifier of your choice

AiAction::make('summarize')
    ->targetFields(['summary', 'body_html'])
    ->prompt('…')
    ->sanitize(fn (mixed $value) => is_string($value) ? Purifier::clean($value) : $value);
```

For different sanitization per field, use `sanitizeField()` to override per-action:

```php
AiAction::make('fill')
    ->targetFields(['plain_summary', 'rich_body'])
    ->prompt('…')
    ->sanitizeField('plain_summary', fn (mixed $value) => is_string($value) ? strip_tags($value) : $value)
    ->sanitizeField('rich_body', fn (mixed $value) => is_string($value) ? Purifier::clean($value) : $value);
```

Sanitizers run **after** the factory's `toFormValue()` but **before** the value is written. If a sanitizer throws, the value routes to "failed fields" (same as a `toFormValue()` failure) and the exception is `report()`'d so your exception tracker picks it up.

Solaris does not ship a default sanitizer because the right policy is application-specific. The hook is here so you can plug in HTML Purifier, DOMPurify-server, `strip_tags`, your CSP rules, or whatever your security review demands.

## Source-field steering

If a `sourceFields()` entry is user-controlled (TextInput, Textarea, etc.), that user can influence what the AI outputs. This is by design — letting users steer AI is often the whole point — but if your form has multiple actors (e.g. a commenter triggers an action that affects a moderator's view), think about whether the actor of the source field is the same person reading the target.

## Scoping which users can trigger actions

Use Filament's standard `Action::visible()` / `Action::authorize()` and your policy classes to gate AI actions per-user:

```php
AiAction::make('rewrite-customer-message')
    ->visible(fn () => auth()->user()->can('moderate', $record))
    ->sourceFields(['body'])
    ->targetField('body_clean');
```

Multi-panel apps can gate AI globally per panel via the [plugin's visibility predicate](configuration.md#per-panel-configuration-plugin) (`FilamentSolarisPlugin::make()->visible(...)` or `->disabled()`).

## Auditing AI activity

The [Usage Tracking](usage-tracking.md) events (`SolarisResponseReceived` / `SolarisResponseFailed`) carry the action name, action class, authenticated user, and Livewire component. Listen for those if you need an audit trail of who triggered which AI call when. (Deliberately doesn't carry prompts/responses — see that section for why and how to log those separately.)
