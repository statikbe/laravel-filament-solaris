# 13 — RichEditorFactory

## Summary

The `RichEditorFactory` is a dedicated factory for Filament's `RichEditor` component, replacing the generic `TextFactory` handling that currently wraps plain text in `<p>` tags. Filament v4's RichEditor uses TipTap internally and stores data as **TipTap JSON documents** in form state, regardless of whether the developer uses `->json()` mode or not. The state cast (`RichEditorStateCast`) handles conversion between the internal TipTap JSON and the developer-facing format (HTML or JSON).

This factory leverages Filament's `RichContentRenderer` and the `ueberdosis/tiptap-php` `Editor` class to properly convert between AI-generated HTML and the TipTap document format that the RichEditor component expects.

## Background: RichEditor Data Flow

```
AI returns HTML string
    ↓
RichEditorFactory::toFormValue()
    ↓
Editor::setContent(html)->getDocument()
    ↓
TipTap JSON array (stored in form state)
    ↓
RichEditorStateCast::get()
    ↓
Display in editor / Save to database
```

The form state for a RichEditor is **always a TipTap JSON document** (array). When the developer reads data back, the `StateCast::get()` method converts it to HTML or keeps it as JSON based on the `->json()` flag. Our factory writes directly to form state, so we must produce valid TipTap JSON.

## Why Not Ask the AI to Return TipTap JSON?

The TipTap JSON schema is deeply nested and verbose. A simple paragraph with bold text:

```json
{
    "type": "doc",
    "content": [{
        "type": "paragraph",
        "attrs": {"textAlign": "start"},
        "content": [
            {"type": "text", "text": "Hello "},
            {"type": "text", "marks": [{"type": "bold"}], "text": "world"}
        ]
    }]
}
```

vs. the equivalent HTML:

```html
<p>Hello <strong>world</strong></p>
```

**Decision: The AI returns HTML. We convert to TipTap JSON using Filament's helpers.**

Reasons:
- All LLMs generate HTML fluently — it's a standard format in training data
- TipTap JSON is 5-10x more verbose, consuming more output tokens
- The nested document structure is fragile — AI frequently makes structural errors
- The schema varies based on which TipTap extensions are enabled
- Filament's `Editor::setContent(html)->getDocument()` handles conversion reliably

## Class: RichEditorFactory extends ComponentFactory

### Dependencies

- `Filament\Forms\Components\RichEditor\RichContentRenderer` — for creating a properly-configured TipTap Editor instance
- `Tiptap\Editor` — for HTML ↔ TipTap JSON conversion

## Response Schema

### Given a RichEditor component
- When `responseSchema()` is called
- Then it returns `['type' => 'string', 'description' => 'HTML content. Use standard HTML tags: <p>, <h2>, <h3>, <strong>, <em>, <ul>, <ol>, <li>, <blockquote>, <code>, <a href="...">.']`

### Given a RichEditor with a maxLength constraint
- When `responseSchema()` is called
- Then the `description` additionally mentions the character limit

### Rationale

The schema explicitly lists allowed HTML tags so the AI stays within the TipTap-supported subset. Tags like `<div>`, `<span>`, `<br>` are omitted because TipTap maps them poorly. The factory handles any non-standard HTML gracefully via TipTap's parser, which ignores unknown tags.

## toFormValue (AI response → form state)

### Given an AI response that is an HTML string
- When `toFormValue()` is called
- Then it creates a `RichContentRenderer` with the component's plugins
- And uses its `getEditor()` to call `setContent($html)->getDocument()`
- And returns the TipTap JSON document (array)

### Given an AI response that is plain text (no HTML tags)
- When `toFormValue()` is called
- Then the Editor's `setContent()` wraps it in paragraph nodes automatically
- And returns a valid TipTap JSON document

### Given an AI response that is null
- When `toFormValue()` is called
- Then it returns the empty document: `['type' => 'doc', 'content' => []]`

### Given an AI response that is not a string (number, array, object)
- When `toFormValue()` is called
- Then it casts to string (json_encode for arrays/objects) before processing

### Implementation

```php
public function toFormValue(mixed $aiValue): mixed
{
    if ($aiValue === null) {
        return ['type' => 'doc', 'content' => []];
    }

    if (is_array($aiValue) || is_object($aiValue)) {
        $aiValue = json_encode($aiValue);
    } else {
        $aiValue = (string) $aiValue;
    }

    /** @var RichEditor $component */
    $component = $this->component;

    $editor = $component->getTipTapEditor()
        ->setContent($aiValue);

    return $editor->getDocument();
}
```

### Why Use `$component->getTipTapEditor()`?

The component's `getTipTapEditor()` returns an `Editor` pre-configured with the exact same TipTap extensions that the RichEditor uses (custom blocks, mentions, merge tags, etc.). This ensures the document structure matches what the editor expects. Using a raw `new Editor` would miss custom extensions and produce incompatible documents.

## toPromptContext (form state → prompt)

### Given a form value that is a TipTap JSON document (array)
- When `toPromptContext()` is called
- Then it creates a `RichContentRenderer` with the content
- And calls `toText()` to extract clean plain text
- And returns the plain text string

### Given a form value that is an HTML string (legacy/fallback)
- When `toPromptContext()` is called
- Then it strips HTML tags via `strip_tags()` and returns the result

### Given a form value that is null or empty
- When `toPromptContext()` is called
- Then it returns an empty string

### Implementation

```php
public function toPromptContext(mixed $formValue): mixed
{
    if (empty($formValue)) {
        return '';
    }

    // TipTap JSON document (array with 'type' => 'doc')
    if (is_array($formValue)) {
        return RichContentRenderer::make($formValue)->toText();
    }

    // HTML string fallback
    return strip_tags((string) $formValue);
}
```

### Why `toText()` Instead of `toHtml()` → `strip_tags()`?

`RichContentRenderer::toText()` uses TipTap's `getText()` method which understands the document structure. It properly handles:
- Block boundaries (paragraphs → newlines)
- List items (numbered/bulleted)
- Headings (preserved with newlines)
- Custom blocks (extracted text content)

`strip_tags()` on HTML loses all structural information, collapsing everything into a single line.

## Config Map Update

The default factory map in `config/filament-solaris.php` must be updated:

```php
'factories' => [
    // ...existing entries...
    RichEditor::class => RichEditorFactory::class,  // was: TextFactory::class
],
```

## Edge Cases

### Given a RichEditor with custom blocks enabled
- When the AI returns HTML containing unknown elements
- Then TipTap's parser ignores them gracefully
- And the document contains only valid nodes

### Given a RichEditor with mentions enabled
- When the AI returns text with `@username` patterns
- Then these are treated as plain text, not mention nodes
- This is expected: the AI cannot know mention IDs. Mentions are a user-interactive feature.

### Given a RichEditor in `->json()` mode
- When the developer stores TipTap JSON in the database
- Then `toFormValue()` works identically — it always produces TipTap JSON for form state
- And `toPromptContext()` handles the array document format

### Given a RichEditor source field and a RichEditor target field
- When the same field is both source and target
- Then `toPromptContext()` extracts text from the TipTap JSON
- And `toFormValue()` converts the AI's HTML response back to TipTap JSON
- The round-trip is clean: JSON → text → AI → HTML → JSON

## Tests

### Unit Tests: `tests/Unit/Factories/RichEditorFactoryTest.php`

```php
it('generates string schema with allowed HTML tags in description');
it('converts HTML to TipTap JSON document in toFormValue');
it('wraps plain text in paragraph nodes in toFormValue');
it('returns empty document for null in toFormValue');
it('handles non-string values in toFormValue');
it('extracts plain text from TipTap JSON in toPromptContext');
it('strips HTML from string values in toPromptContext');
it('returns empty string for null in toPromptContext');
it('returns empty string for empty array in toPromptContext');
it('preserves paragraph structure in text extraction');
```

## Migration from TextFactory

The current implementation in `TextFactory` has two RichEditor-specific behaviors:
1. `toFormValue`: wraps plain text in `<p>` tags — **incorrect**, RichEditor expects TipTap JSON
2. `toPromptContext`: calls `strip_tags()` — **works but loses structure**

After this change:
- `TextFactory` handles only `TextInput`, `Textarea`, `MarkdownEditor` — no RichEditor-specific code
- `RichEditorFactory` handles `RichEditor` with proper TipTap JSON conversion
- The `TextFactory` RichEditor checks (`instanceof RichEditor`) are removed

## File Summary

**Create:**
- `src/Factories/RichEditorFactory.php`
- `tests/Unit/Factories/RichEditorFactoryTest.php`

**Modify:**
- `config/filament-solaris.php` — update factory map
- `src/Factories/TextFactory.php` — remove RichEditor-specific logic
