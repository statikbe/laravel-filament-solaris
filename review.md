# Senior Developer Code Review — filament-solaris

## Overall Assessment

The package is well-structured with clean separation of concerns. 126 tests passing, PHPStan level 5 clean, Pint clean. The architecture (Factories, PromptBuilders, Presets, AiAction) is sound and extensible. Below are findings ordered by priority.

---

## 1–2. DRY: Unify Prompt Builders + Move Presets to `Prompts/Presets`

**Problem:** Locale resolution is duplicated in `Preset::build()` (less robust, doesn't check translation files) and view data building (`responseSchema` mapping) is identical in 3 places.

**Fix:** Make `Preset` extend `AbstractPromptBuilder`. Move presets to `Prompts/Presets` namespace. Extract shared `buildViewData()` to the abstract class.

### Hierarchy after change:
```
Prompts/
├── AbstractPromptBuilder.php    (resolveLocaleName, buildViewData, defaultUserInput)
├── InlinePromptBuilder.php      (uses buildViewData)
├── ViewPromptBuilder.php        (uses buildViewData)
└── Presets/
    ├── Preset.php               (extends AbstractPromptBuilder, adds make/promptView/viewData)
    ├── SummaryPreset.php
    ├── ClassificationPreset.php
    ├── GenerationPreset.php
    └── TranslationPreset.php
```

---

## 3. Refactor `HasOptions::fuzzyMatch()` to Own the Levenshtein Logic

**File:** `src/Factories/Concerns/HasOptions.php`

Currently `fuzzyMatch()` is just an alias for `resolveOptionKey()`. Refactor so `fuzzyMatch()` contains the isolated Levenshtein matching logic (step 5), and `resolveOptionKey()` calls it. This makes `fuzzyMatch()` independently reusable by custom factories.

---

## 4. Overly Broad Error Catching

**Files:**
- `src/Factories/RichEditorFactory.php:73` — catches `\Error`
- `src/Support/ComponentFactoryResolver.php:74` — catches `\Error`

Document why `\Error` is correct (Filament throws `Error`, not `Exception`, when `getStatePath()` is called without a container).

---

## 5. Use Laravel's `JsonSchema` Builder in Factories (replaces SchemaAdapter)

**Problem:** Factories return plain `array` for response schemas — error-prone, no IDE support, requires a conversion layer (SchemaAdapter). The `required()` issue (all fields forced required) is a symptom of this indirection.

**Fix:** Change `ComponentFactory::responseSchema()` to accept `JsonSchema` and return `Type` directly. Each factory controls its own `required()` / `nullable()`. Remove SchemaAdapter.

```php
// Contract: responseSchema(JsonSchema $schema): Type
// SelectFactory:  $schema->string()->enum($keys)->description("...")->required()
// BooleanFactory: $schema->boolean()->description("true or false")->required()
```

---

## 6. RichEditorFactory HTML Detection is Fragile

**File:** `src/Factories/RichEditorFactory.php:46`

`$aiValue !== strip_tags($aiValue)` fails for content like `5 < 10`. Use regex instead:

```php
$isHtml = (bool) preg_match('/<[a-z][\s\S]*>/i', $aiValue);
```

---

## 7. Facade PHPDoc is Incomplete

**File:** `src/Facades/FilamentSolaris.php`

Missing `@method` annotations for `setLocales()`, `getLocales()`, `clearLocales()`, `getRuntimeFactories()`, `clearRuntimeFactories()`.

---

## 8. Levenshtein Performance on Large Option Sets

**File:** `src/Factories/Concerns/HasOptions.php`

With `fuzzyMatch()` now isolated (#3), add an early-exit on exact distance 0. Keep spec behavior, just optimize.

---

## 9. Test Coverage Gaps

Key features with no integration tests:
- **User input flow** — `HasUserInput`, `withDefaultUserInput()`, modal form data
- **Source/target scopes** — `sourceScope()` / `targetScope()` closures
- **Preview modal** — `withPreview()`
- **Locale override** — `AiAction::locale()` method
- **ViewPromptBuilder in integration** — only tested in isolation with temp files

---

## 10. Minor Improvements

| Item | File | Suggestion |
|------|------|------------|
| Redundant `use Locale;` import | `Presets/Preset.php:7` | Removed when Preset extends AbstractPromptBuilder |
| No validation on `maxWords`/`maxLength` | `SummaryPreset`, `GenerationPreset` | Guard against ≤ 0 values |
| `toPromptContext` raw fallback | `SelectFactory.php:74` | Consider logging a warning |
| View error handling | All prompt builders | Wrap `->render()` with try/catch |

---

## Recommended Action Order

1. **JsonSchema refactor** (#5): Change factories to use `Type` builder, remove SchemaAdapter
2. **DRY refactor** (#1–2): Move Presets to `Prompts/Presets`, make Preset extend AbstractPromptBuilder, extract `buildViewData()`
3. **Quick wins** (#3, #7): Refactor `fuzzyMatch()` to own Levenshtein logic, fix Facade PHPDoc
4. **Robustness** (#6, #4): Fix HTML detection, document broad error catches
5. **Test gaps** (#9): Add integration tests for user input, scopes, locale, preview