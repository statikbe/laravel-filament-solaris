# 16 — Preview Modal

## Summary

`withPreview()` adds a confirmation step between AI response and field application. Instead of writing values directly, the action opens a modal with a loading spinner, runs the AI, then shows the proposed values per field, letting the user accept or discard. This is the foundation for conversational refinement (spec 17).

## Status

**Implemented** (2026-03-21)

## API

```php
// AiAction
AiAction::make('summarize')
    ->sourceFields(['title', 'body'])
    ->targetField('summary')
    ->preset(SummaryPreset::make())
    ->withPreview()

// DictationAction
DictationAction::make('voice-summary')
    ->targetFields(['summary', 'category_id'])
    ->preset(SummaryPreset::make())
    ->withPreview()
```

Consumer adds trait to their Livewire page:

```php
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;

class EditPost extends EditRecord
{
    use InteractsWithSolarisPreview;
}
```

## Execution Flow

### Without Preview (unchanged)

```
Click → AI runs → applyResults() → $set() per field → success notification → done
```

### With Preview

```
Click → modal opens with loading spinner → auto-submit triggers action
  → AI runs → storePreviewData() → halt() → modal re-renders with preview
  → Accept: apply values → success notification → close modal
  → Discard: clear data → close modal
```

## Technical Approach

### Key Decisions

1. **`modal(true)` + auto-submit**: `withPreview()` forces a modal. The modal opens with a loading spinner that auto-submits via Alpine `x-init="$nextTick(() => $el.closest('form').requestSubmit())"`. This gives instant user feedback without requiring an extra click.

2. **Getter overrides, not setter closures**: Conditional modal content is implemented by overriding `getModalContent()`, `getModalContentFooter()`, `getModalSubmitAction()`, `getModalCancelAction()` — NOT by using setter closures like `modalContent(Closure)`. Setting a closure makes Filament think the action always has a modal (`hasModalContent()` checks `$this->modalContent !== null`), causing empty modals for actions without preview.

3. **Split content + footer**: Preview values render via `getModalContent()`, Accept/Discard buttons via `getModalContentFooter()`. This uses Filament's native modal spacing between sections.

4. **`toPreviewDisplay()` on factories**: New method on `ComponentFactory` interface for preview-specific formatting. RichEditorFactory renders HTML, MarkdownFactory renders markdown to HTML, others delegate to `toPromptContext()`.

### Architecture

```
InteractsWithSolarisPreview (trait on Livewire component)
├── $solarisPreviewData        — public property
├── solarisAcceptPreview()     — writes values, sends notification, unmounts action
├── solarisDiscardPreview()    — clears data, unmounts action
└── unmountAction()            — override: clears preview data on any unmount

HasPromptPipeline (trait on actions)
├── applyResults()             — orchestrates: transform → preview or write
├── transformResults()         — loops factories, calls toFormValue()
├── writeResults()             — resolves Set utility, writes values, sends notifications
├── sendResultNotifications()  — success/partial/error notifications
└── storePreviewData()         — builds display data, stores on Livewire component

AiAction / DictationAction (getter overrides)
├── getModalContent()          — loading spinner → preview view → default
├── getModalContentFooter()    — Accept/Discard buttons when previewing
├── getModalHeading()          — "Review AI Results" when previewing
├── getModalSubmitAction()     — null when shouldPreview()
├── getModalCancelAction()     — null when shouldPreview()
└── schema()                   — [] when previewing (hides user input fields)

Blade views:
├── preview-loading.blade.php  — spinner + auto-submit
├── preview-modal.blade.php    — field labels + values
└── preview-modal-footer.blade.php — Accept/Discard buttons
```

### Modal States

```
AiAction:        Loading spinner → [AI runs] → Preview
DictationAction: Recording UI → [Transcribe+AI] → Preview
```

## Preview Modal UI

### Layout

```
┌─────────────────────────────────────┐
│  Review AI Results              [X] │
├─────────────────────────────────────┤
│                                     │
│  Summary                            │
│  ┌─────────────────────────────────┐│
│  │ The article discusses...        ││
│  └─────────────────────────────────┘│
│                                     │
│  Category                           │
│  ┌─────────────────────────────────┐│
│  │ Technology                      ││
│  └─────────────────────────────────┘│
│                                     │
│              [Discard]    [Accept]   │
└─────────────────────────────────────┘
```

### Value Display

| Factory | Display Format | Type |
|---------|---------------|------|
| TextFactory | Plain text | text |
| RichEditorFactory | Rendered HTML (via RichContentRenderer) | html |
| MarkdownFactory | Rendered Markdown (via Str::markdown) | html |
| SelectFactory | Option label (not key) | text |
| CheckboxListFactory | Comma-separated labels | text |
| BooleanFactory | "Yes" / "No" | text |
| TagsFactory | Comma-separated tags | text |

## Considerations for Spec 17 (Conversational)

This architecture extends naturally:

- `InteractsWithSolarisPreview` grows a `solarisRefine(string $message)` method
- `preview-modal.blade.php` grows a chat section below the preview values
- `$solarisPreviewData` gains a `messages` array for conversation history
- No new traits needed — same trait, same views

```
┌─────────────────────────────────────┐
│  Review AI Results                  │
├─────────────────────────────────────┤
│  [proposed values displayed here]   │
│                                     │
│  ── Conversation ─────────────────  │
│  🤖 Here's my summary...           │
│  👤 Make it shorter                 │
│  🤖 Here's a shorter version...    │
│                                     │
│  ┌───────────────────────────────┐  │
│  │ Type refinement...            │  │
│  └───────────────────────────────┘  │
│                          [Send ↵]   │
│                                     │
│              [Discard]    [Accept]   │
└─────────────────────────────────────┘
```

## Files

### Modified
- `src/Contracts/ComponentFactory.php` — added `toPreviewDisplay()`
- `src/Factories/ComponentFactory.php` — default `toPreviewDisplay()`
- `src/Factories/RichEditorFactory.php` — HTML preview display
- `src/Factories/MarkdownFactory.php` — Markdown preview display
- `src/Concerns/HasTargetFields.php` — `shouldPreview()`, `modal(true)` in `withPreview()`
- `src/Concerns/HasPromptPipeline.php` — split `applyResults()`, `storePreviewData()`
- `src/Actions/AiAction.php` — getter overrides, schema guard, loading spinner
- `src/Actions/DictationAction.php` — getter overrides
- `resources/lang/en/filament-solaris.php` — preview translation keys

### Created
- `src/Concerns/InteractsWithSolarisPreview.php` — consumer trait
- `resources/views/preview-modal.blade.php` — preview content
- `resources/views/preview-modal-footer.blade.php` — Accept/Discard buttons
- `resources/views/preview-loading.blade.php` — auto-submit loading spinner
- `tests/Fixtures/PreviewFormComponent.php` — test fixture
- `tests/Feature/PreviewModalTest.php` — 10 feature tests

## Dependencies

- Works with both AiAction and DictationAction
- No new database tables
- No new npm dependencies
- Consumer must add `InteractsWithSolarisPreview` trait to their Livewire page
