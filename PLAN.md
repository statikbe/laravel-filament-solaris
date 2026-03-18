# Plan: Preview & Chat via Custom Livewire Modal

## Context

We need two features that share the same infrastructure:
1. **Preview** — show AI results before applying them to the form
2. **Chat** (future) — let the user refine results via conversation with the AI

Both require a stateful, multi-phase modal. Rather than hacking Filament's action lifecycle (`halt()`, `mergeMountedActionArguments`), we use a **custom Livewire component** rendered inside the action modal via `modalContent()`.

## Why Not `halt()` / Wizard?

| Approach | Issue |
|---|---|
| `halt()` + dynamic schema | Relies on internal APIs (`mergeMountedActionArguments`). Fragile across Filament upgrades. Hard to extend to chat. |
| Wizard | Steps are form-driven (advance via validation), not action-driven. No built-in hook for "run AI between steps". Awkward fit for chat. |
| **Custom Livewire component** | Full control over state, rendering, and communication. Uses only stable Filament APIs (`modalContent()`). Naturally extends to chat. |

## Architecture

```
┌──────────────────────────────────────────────────────┐
│  AiAction (Filament Action)                          │
│  - Configures modal with modalContent()              │
│  - Passes config props to Livewire component         │
│  - Listens for 'solaris:apply-results' event         │
│  - Calls $set() to write results to form             │
│  - Closes modal on apply/discard                     │
│                                                      │
│  ┌──────────────────────────────────────────────┐    │
│  │  SolarisModal (Livewire Component)           │    │
│  │                                              │    │
│  │  States:                                     │    │
│  │  ┌─────────┐   ┌─────────┐   ┌─────────┐   │    │
│  │  │  Input   │──▶│ Loading │──▶│ Preview │   │    │
│  │  │  (form)  │   │         │   │         │   │    │
│  │  └─────────┘   └─────────┘   └────┬────┘   │    │
│  │                                    │        │    │
│  │                               ┌────▼────┐   │    │
│  │                               │  Chat   │   │    │
│  │                               │(future) │   │    │
│  │                               └─────────┘   │    │
│  │                                              │    │
│  │  Dispatches:                                 │    │
│  │  - 'solaris:apply-results' {results}         │    │
│  │  - 'solaris:discard'                         │    │
│  └──────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────┘
```

## Component Design

### `SolarisModal` Livewire Component

**Location:** `src/Livewire/SolarisModal.php`

**Props received from AiAction:**
- `sourceData` — collected source field values (already resolved)
- `targetFieldMeta` — field names + labels + component types (for preview rendering)
- `promptConfig` — serializable prompt configuration (builder class, instruction, locale, etc.)
- `userInputSchema` — whether to show user input form and its config
- `actionName` — for fake/testing support

**Internal state:**
- `$phase` — `'input'` | `'loading'` | `'preview'` | `'chat'`
- `$aiResults` — the AI response array
- `$userInput` — form data from the input phase
- `$chatMessages` — array of messages (future)
- `$error` — error message if AI call fails

**Methods:**
- `submit()` — called from input phase, triggers AI call
- `executeAiCall()` — runs the prompt pipeline, transitions to preview
- `accept()` — dispatches results to parent
- `discard()` — dispatches close to parent
- `retry()` — re-runs AI call from preview phase
- `refine(string $message)` — sends chat message (future)

### Phase Rendering

**Input phase** (`$phase === 'input'`):
- Renders user input form using Filament form components (if configured)
- Submit button triggers `submit()`
- If no user input is configured, this phase is skipped — goes straight to loading

**Loading phase** (`$phase === 'loading'`):
- Shows a loading spinner / skeleton
- `executeAiCall()` runs (could be wire:init or triggered from submit)
- On success → transition to preview
- On error → show error with retry button

**Preview phase** (`$phase === 'preview'`):
- Shows each target field's proposed value with its label
- Uses appropriate rendering per component type:
  - Text fields → plain text display
  - Select/Radio → shows the matched option label
  - Boolean → Yes/No badge
  - RichEditor → rendered HTML preview
  - CheckboxList → comma-separated labels
- Footer: "Accept" (primary) + "Discard" (gray) + "Retry" (secondary)

**Chat phase** (future, `$phase === 'chat'`):
- Message list with user/AI bubbles
- Text input for refinements
- Updated preview panel showing latest results
- Accept/Discard buttons persist

### View

**Location:** `resources/views/livewire/solaris-modal.blade.php`

Uses Filament's Blade components for consistent styling:
- `<x-filament::section>` for content areas
- `<x-filament::button>` for actions
- `<x-filament::loading-indicator>` for loading state
- Standard Filament color tokens and spacing

## Communication: Livewire Component ↔ AiAction

### Passing config INTO the component

`AiAction::setUp()` uses `modalContent()` with a Blade view:

```php
$this->modalContent(function (AiAction $action): ?View {
    if (! $this->preview) {
        return null; // No custom modal, use default behavior
    }

    return view('filament-solaris::livewire.solaris-modal-wrapper', [
        'sourceData'      => $action->getSourceFieldValues(),
        'targetFieldMeta' => $action->buildTargetFieldMeta(),
        'promptConfig'    => $action->serializePromptConfig(),
        'userInputConfig' => $action->hasUserInput() ? ... : null,
        'actionName'      => $action->getName(),
    ]);
});
```

The wrapper view renders the Livewire component:
```blade
{{-- resources/views/livewire/solaris-modal-wrapper.blade.php --}}
@livewire('solaris-modal', [
    'sourceData' => $sourceData,
    'targetFieldMeta' => $targetFieldMeta,
    'promptConfig' => $promptConfig,
    'userInputConfig' => $userInputConfig,
    'actionName' => $actionName,
], key('solaris-modal-' . $actionName))
```

### Getting results OUT of the component

The Livewire component dispatches browser events:

```php
// In SolarisModal
public function accept(): void
{
    $this->dispatch('solaris:apply-results', results: $this->aiResults);
}

public function discard(): void
{
    $this->dispatch('solaris:discard');
}
```

The AiAction listens via Alpine on the modal wrapper or the parent Livewire component picks up the event.

**Option A — Alpine bridge (simpler, no parent Livewire coupling):**

```blade
{{-- In the wrapper view --}}
<div
    x-on:solaris:apply-results.window="
        $wire.call('applyAiResults', $event.detail.results);
        $dispatch('close-modal', { id: '...' });
    "
    x-on:solaris:discard.window="
        $dispatch('close-modal', { id: '...' });
    "
>
    @livewire(...)
</div>
```

Where `applyAiResults` is a method on the parent Livewire component (the Filament page/resource). But this requires adding a method to the user's Livewire component — not ideal for a package.

**Option B — Livewire event on parent (cleaner for package):**

Add a trait `InteractsWithSolarisModal` that the AiAction auto-registers on the Livewire component:

```php
// In the parent Livewire component (auto-registered via AiAction)
#[On('solaris:apply-results')]
public function applySolarisResults(array $results, string $actionName): void
{
    // AiAction handles the apply logic
}
```

**Option C — Use `$this->dispatch()` to parent with `dispatchTo`:**

The SolarisModal can use `$this->dispatch('solaris:apply-results', ...)` and the parent Livewire component listens. Since we don't know the parent's component name at build time, we use a global event with an action name identifier.

### Recommended: Option B with auto-registration

The `AiAction` registers a dynamic listener on the parent Livewire component during `setUp()`. This is clean and doesn't require the user to add any traits manually.

## Backward Compatibility

- `withPreview(false)` (default) → current behavior, no Livewire component involved
- `withPreview(true)` → uses `modalContent()` with the SolarisModal component
- The action's `schema()` for user input is skipped in preview mode (the Livewire component handles it internally)
- `AiActionFake` still works — the Livewire component checks `AiActionFake::isActive()` before making real AI calls

## Open Questions

1. **User input in preview mode**: Should the Livewire component render the user input form itself (duplicating Filament form rendering), or should we keep the action's `schema()` for input and only switch to the Livewire component after submit? The latter avoids re-implementing form rendering but requires the `halt()` pattern for the transition.

2. **Modal sizing**: The preview/chat phases need more space than the input form. Should we dynamically resize, or use a slide-over from the start when preview is enabled?

3. **Serializing prompt config**: PromptBuilder instances need to be serializable to pass to the Livewire component. Presets are simple value objects (easy). Custom PromptBuilder implementations might not be serializable. We could pass a closure reference or build the prompt in AiAction before passing to the component.

4. **Form state access**: The Livewire component runs in its own component context. It can't call `$this->getLivewire()` to access the parent form. Source data must be collected before mounting the component and passed as props.

5. **Testing**: `AiActionFake` intercepts at the AiAction level. With the Livewire component making the AI call directly, we need to ensure faking still works — either by checking the fake inside the component, or by having the component dispatch an event that triggers the AI call in the parent.

## Implementation Order

1. **Phase 1: Preview** — SolarisModal with input → loading → preview states
2. **Phase 2: Chat** — Add chat state with message history and iterative refinement
3. **Phase 3: Polish** — Rich preview rendering per component type, slide-over option, animations

## Files to Create/Modify

### New files
- `src/Livewire/SolarisModal.php` — the Livewire component
- `resources/views/livewire/solaris-modal.blade.php` — component view
- `resources/views/livewire/solaris-modal-wrapper.blade.php` — wrapper for modalContent()

### Modified files
- `src/Actions/AiAction.php` — add `modalContent()` setup for preview mode, add `buildTargetFieldMeta()` and `serializePromptConfig()` helpers
- `src/FilamentSolarisServiceProvider.php` — register the Livewire component
- `resources/lang/en/solaris.php` — add preview/chat translation keys
- `resources/lang/nl/solaris.php` — Dutch translations
- `resources/lang/fr/solaris.php` — French translations
