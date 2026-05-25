# Component Factories

[← Back to README](../README.md)

Factories are the bridge between Filament components and AI. Each factory implements three methods:

| Method | Purpose |
|---|---|
| `responseSchema(JsonSchema $schema): Type` | Returns the JSON schema fragment for this field, constraining the AI's output format |
| `toFormValue(mixed $aiValue): mixed` | Transforms the AI's raw JSON response into valid Filament form state |
| `toFormValueFromFile(string $content, string $mimeType): mixed` | Transforms generated file content (e.g. from `ImageGenerationAction`) into valid form state |
| `toPromptContext(mixed $formValue): mixed` | Transforms the current form state into a human-readable string for the prompt |

## Supported Components

| Filament Component | Factory | AI Schema | Notes |
|---|---|---|---|
| `Select` | `SelectFactory` | `string` enum or free-text | Enum mode for ≤100 options, free-text with fuzzy matching for >100 |
| `Radio` | `RadioFactory` | `string` enum or free-text | Extends `SelectFactory` |
| `ToggleButtons` | `SelectFactory` | `string` enum or free-text | Same as Select, supports `multiple()` |
| `CheckboxList` | `CheckboxListFactory` | `array` of `string` | Multi-select, same matching as Select |
| `TextInput` | `TextFactory` | `string` | Respects `maxLength` if set on the component |
| `Textarea` | `TextFactory` | `string` | Same as TextInput |
| `MarkdownEditor` | `MarkdownFactory` | `string` (Markdown) | AI prompted to output Markdown syntax |
| `RichEditor` | `RichEditorFactory` | `string` (HTML) | AI returns HTML, factory converts to TipTap JSON for form state |
| `CodeEditor` | `TextFactory` | `string` | Plain text |
| `Toggle` | `BooleanFactory` | `boolean` | Handles string/int coercion ("true", "yes", 1 → true) |
| `Checkbox` | `BooleanFactory` | `boolean` | Same as Toggle |
| `TagsInput` | `TagsFactory` | `array` of `string` | Includes suggestions in schema, handles comma-separated strings |
| `FileUpload` | `FileUploadFactory` | — | Supports `toFormValueFromFile()` for image generation output |
| `SpatieMediaLibraryFileUpload` | `FileUploadFactory` | — | Same as FileUpload — creates Livewire temp upload, Spatie handles Media record on save |

## Custom Factories

Register a factory for a custom or unsupported component:

```php
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

// In a service provider
FilamentSolaris::registerFactory(MyComponent::class, MyComponentFactory::class);
```

Or add it to the `factories` array in `config/filament-solaris.php`:

```php
'factories' => [
    // ...defaults...
    \App\Filament\Components\ColorPicker::class => \App\Factories\ColorFactory::class,
],
```

Implement the factory by extending the abstract base class:

```php
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;

class ColorFactory extends ComponentFactory
{
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        return $schema->string()
            ->description('A hex color code, e.g. #ff5733')
            ->required();
    }

    public function toFormValue(mixed $aiValue): mixed
    {
        // Ensure the value starts with #
        return str_starts_with($aiValue, '#') ? $aiValue : "#{$aiValue}";
    }

    public function toPromptContext(mixed $formValue): mixed
    {
        return $formValue ?? 'No color selected';
    }
}
```

The factory map also supports class inheritance — if a factory is registered for a parent class, subclasses will match it automatically.

## Option Matching

`SelectFactory` and `CheckboxListFactory` use a 6-step matching chain to resolve AI responses to valid option keys. This tolerates common AI "near-misses":

1. **Exact key match** — the AI returned the option key directly
2. **Exact label match** — the AI returned the option label
3. **Case-insensitive label** — "Technology" matches "technology"
4. **Substring** — "tech" matches "Technology & Science"
5. **Length-relative Levenshtein** — "technolgy" matches "technology"
6. **Fallback** — return the raw value

When a Select/CheckboxList has more than `max_options` (default: 100) options, the schema switches from a strict enum to free-text with a sample of 10 options and relies on this chain to resolve the response.

### Fuzzy matching is tunable

The fuzzy step (5) can produce a wrong-but-plausible match — the AI says "Reno", the only close option is "Renault". Three things make that controllable:

**Length-relative threshold.** The allowed edit distance scales with the longer string (`fuzzy_threshold`, default `0.25` — "up to a quarter of the characters differ"). Short labels need near-exact matches; long labels tolerate proportionally more typos. Values/labels shorter than `fuzzy_min_length` (default `4`) skip fuzzy entirely, since a one-character edit on a short word usually flips meaning ("cat" → "car").

**Detection event.** Whenever an *inexact* match resolves (substring or fuzzy — the exact steps are safe), Solaris dispatches `Statikbe\FilamentSolaris\Events\SolarisOptionMatched` carrying the field, the AI value, the matched key/label, the strategy, and the Levenshtein distance. Listen for it to detect misclassification in production without enabling verbose prompt logging:

```php
use Statikbe\FilamentSolaris\Events\SolarisOptionMatched;

class FlagFuzzyOptionMatches
{
    public function handle(SolarisOptionMatched $event): void
    {
        if ($event->strategy === 'fuzzy' && ($event->distance ?? 0) >= 2) {
            Log::warning('Solaris fuzzy option match', [
                'field' => $event->field,
                'ai_value' => $event->aiValue,
                'matched' => $event->matchedLabel,
                'distance' => $event->distance,
            ]);
        }
    }
}
```

**Safety knob.** Disable fuzzy entirely for high-stakes fields (billing codes, medical categories) where a wrong match is worse than no match — the value then falls through unmatched (step 6) instead of being silently mis-assigned. Tune per field or globally:

```php
// Per field, on the action
AiFormAction::make('classify')
    ->targetFuzzyMatching('billing_code', false)   // off for this field
    ->targetFuzzyThreshold('city', 0.15);          // stricter for this field

// Globally, in config/filament-solaris.php
'option_matching' => [
    'fuzzy' => true,
    'fuzzy_threshold' => 0.25,
    'fuzzy_min_length' => 4,
],
```

Per-panel overrides are available on the plugin: `->optionFuzzyMatching(false)`, `->optionFuzzyThreshold(0.15)`, `->optionFuzzyMinLength(5)`.
