# 17 — Conversational Refinement

## Summary

`conversational()` enables multi-turn AI refinement. After the initial AI response, the user can provide feedback in a chat interface to iteratively improve results before accepting. Conversations are persisted via a package-owned table with a morph relationship to the consuming app's model, linked to laravel/ai's conversation storage.

**Depends on:** Spec 16 (Preview Modal) — the preview modal is extended with a "Refine" chat interface.

## API

```php
// Basic — ephemeral conversation (no persistence across page loads)
AiAction::make('summarize')
    ->sourceFields(['title', 'body'])
    ->targetField('summary')
    ->preset(SummaryPreset::make())
    ->withPreview()
    ->conversational()

// With persistence — requires migration, resumes previous conversation
AiAction::make('summarize')
    ->sourceFields(['title', 'body'])
    ->targetField('summary')
    ->preset(SummaryPreset::make())
    ->withPreview()
    ->conversational(persist: true)

// DictationAction
DictationAction::make('voice-summary')
    ->targetFields(['summary'])
    ->preset(SummaryPreset::make())
    ->withPreview()
    ->conversational()
```

`conversational()` implies `withPreview()` — the preview modal is always shown when conversational mode is enabled.

## Database Schema

### Package Migration: `solaris_conversations`

```php
Schema::create('solaris_conversations', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('conversation_id');           // FK → agent_conversations.id
    $table->morphs('conversable');               // conversable_type + conversable_id
    $table->string('action_name');               // e.g. 'summarize', 'voice-summary'
    $table->timestamps();

    $table->foreign('conversation_id')
        ->references('id')
        ->on('agent_conversations')
        ->cascadeOnDelete();

    $table->index(['conversable_type', 'conversable_id', 'action_name']);
});
```

### Opt-in

- Migration ships with the package but only needed when `persist: true` is used
- Published via `php artisan vendor:publish --tag="filament-solaris-migrations"`
- Without the migration, `conversational()` (no persist) works with ephemeral in-memory history

## User Flow

### First Use (no prior conversation)

```
1. User clicks AI action button
2. AI generates initial response
3. Preview modal opens with proposed values + chat input

┌───────────────────────────────────────────┐
│  Review AI Results                        │
├───────────────────────────────────────────┤
│                                           │
│  Summary                                  │
│  ┌───────────────────────────────────┐    │
│  │ The article discusses the impact   │    │
│  │ of renewable energy on...          │    │
│  └───────────────────────────────────┘    │
│                                           │
│  ── Conversation ─────────────────────    │
│                                           │
│  🤖 Here's my summary based on the       │
│     article title and body.               │
│                                           │
│  ┌───────────────────────────────────┐    │
│  │ Make it shorter and more formal   │    │
│  └───────────────────────────────────┘    │
│                           [Send ↵]        │
│                                           │
├───────────────────────────────────────────┤
│           [Discard]            [Accept]    │
└───────────────────────────────────────────┘

4. User types "Make it shorter and more formal" → Send
5. AI refines with conversation context → preview updates
6. User types "Change category to Science" → Send
7. AI refines again → preview updates
8. User clicks Accept → values written to form
```

### Resume (with persist: true, existing conversation)

```
1. User clicks AI action button on a record that has a previous conversation
2. Package looks up solaris_conversations for this model + action
3. Loads prior conversation via $agent->continue($conversationId, $user)
4. AI generates response with full history context
5. Preview modal shows result + previous conversation messages
6. User can continue refining or accept
```

## Architecture

### SolarisAgent Changes

Create `ConversationalSolarisAgent` extending `SolarisAgent`:

```php
class ConversationalSolarisAgent extends SolarisAgent implements Conversational
{
    use RemembersConversations;

    // Override maxConversationMessages if needed
    protected function maxConversationMessages(): int
    {
        return 50;
    }
}
```

### Conversation Context in Prompts

The refinement prompt includes:
1. Original system instructions (from the preset/prompt)
2. Original source data
3. Previous conversation messages (auto-loaded by laravel/ai)
4. The user's refinement request

The AI sees the full context and returns an updated structured response.

### HasConversational Trait

New trait on actions:

```php
trait HasConversational
{
    protected bool|Closure $conversational = false;
    protected bool|Closure $persistConversation = false;

    public function conversational(bool|Closure $conversational = true, bool|Closure $persist = false): static
    {
        $this->conversational = $conversational;
        $this->persistConversation = $persist;

        // Conversational implies preview
        if ($this->evaluate($conversational)) {
            $this->withPreview();
        }

        return $this;
    }

    public function isConversational(): bool
    {
        return (bool) $this->evaluate($this->conversational);
    }

    public function shouldPersistConversation(): bool
    {
        return (bool) $this->evaluate($this->persistConversation);
    }
}
```

### Refinement Flow (Server-Side)

```php
// User sends refinement message
public function refineResult(string $message): void
{
    $agent = $this->getConversationalAgent();

    // Continue existing conversation
    if ($this->conversationId) {
        $agent->continue($this->conversationId, auth()->user());
    } else {
        $agent->forUser(auth()->user());
    }

    // Prompt with the user's refinement request
    $response = $agent->prompt($message);

    // Store conversation link if persisting
    if ($this->shouldPersistConversation() && !$this->solarisConversationExists) {
        SolarisConversation::create([
            'conversation_id' => $agent->currentConversation(),
            'conversable_type' => get_class($record),
            'conversable_id' => $record->getKey(),
            'action_name' => $this->getName(),
        ]);
    }

    // Update preview with refined values
    $this->updatePreview($response);
}
```

### Livewire Integration

The chat messages and refinement state live on the Livewire component:

```php
// On the Livewire page component
public ?array $solarisConversation = null;
// Structure:
// [
//     'conversationId' => 'uuid',
//     'messages' => [
//         ['role' => 'assistant', 'content' => 'Here is my summary...'],
//         ['role' => 'user', 'content' => 'Make it shorter'],
//         ['role' => 'assistant', 'content' => 'Here is a shorter version...'],
//     ],
//     'previewValues' => ['summary' => '...', 'category_id' => '...'],
// ]
```

## Modal UI Component

The conversational preview modal could be implemented as:

### Option A: Custom Blade View via `modalContent()`

A single Blade view with Alpine.js handling the chat interaction. Similar to how DictationAction's recording modal works.

### Option B: Filament Wizard Steps

```
Step 1: "Review" — shows preview values + chat input
Step 2: (no step 2 — wizard used for its UI, not for progression)
```

### Option C: SlideOver Panel

Use `->slideOver()` for a side panel with more room for chat + preview.

**Recommendation:** Option A (custom Blade) is most flexible. Option C (slideOver) is best for UX when there are many fields. Decide during implementation.

## Edge Cases

### Structured Output Across Turns

laravel/ai's `HasStructuredOutput` works with conversations. Each refinement turn returns a full structured response matching the schema. The preview updates entirely — not a partial diff.

### Temperature for Refinement

Refinement messages may benefit from lower temperature (more deterministic). This depends on laravel/ai#197 for per-call temperature setters. Until then, use class-level `#[Temperature]` attribute on `ConversationalSolarisAgent`.

### Record Not Yet Saved

For create forms (`CreateRecord`), there's no model ID yet. Persistence (`persist: true`) should only work on edit pages. On create pages, fall back to ephemeral mode.

### Conversation Cleanup

When a record is deleted, orphaned `solaris_conversations` rows should be cleaned up. Options:
- Cascade delete via morph (consumer registers observer)
- Package provides `SolarisConversation::prunable()` for scheduled cleanup
- Document that consumers should handle cleanup

## Configuration

```php
// config/filament-solaris.php
'conversation' => [
    'max_messages' => 50,        // Max messages loaded from history
    'max_refinements' => 10,     // Max refinement turns per session
],
```

## Testing

```php
// Test conversational refinement
AiAction::fake(['summary' => 'Initial summary']);

$livewire->callAction('summarize');

// Preview shown, not applied
expect($livewire->data['summary'])->toBeNull();

// Simulate refinement
AiAction::fake(['summary' => 'Refined summary']);
$livewire->call('refineResult', 'Make it shorter');

// Preview updated
expect($livewire->solarisPreviewData['values']['summary'])->toBe('Refined summary');

// Accept
$livewire->call('acceptPreview');
expect($livewire->data['summary'])->toBe('Refined summary');
```

## Dependencies

- Spec 16 (Preview Modal) — required, must be implemented first
- Spec 14 (HasPromptPipeline) — extended with conversation support
- laravel/ai `RemembersConversations` trait
- laravel/ai `agent_conversations` migration (required for `persist: true`)
- New package migration: `solaris_conversations` (optional, only for `persist: true`)
- Alpine.js for chat UI in modal (similar pattern to dictation modal)
