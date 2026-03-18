# 04 — TextFactory

## Summary

The `TextFactory` handles text-based Filament components: `TextInput`, `Textarea`, `RichEditor`, and `MarkdownEditor`. It is the simplest factory — the AI returns a string and the form state receives a string. No transformation logic needed beyond type casting.

## Class: TextFactory extends ComponentFactory

## Response Schema

### Given any text-based component
- When `responseSchema()` is called
- Then it returns `['type' => 'string']`

### Given a TextInput with a maxLength constraint
- When `responseSchema()` is called
- Then it returns `['type' => 'string', 'maxLength' => $maxLength]`
- And the description mentions the character limit

## toFormValue (AI response → form state)

### Given an AI response that is a string
- When `toFormValue()` is called
- Then it returns the string as-is

### Given an AI response that is not a string (number, array, object)
- When `toFormValue()` is called
- Then it casts to string via `(string)` for scalars
- Or returns `json_encode($value)` for arrays/objects

### Given a RichEditor target field and the AI returns plain text
- When `toFormValue()` is called
- Then it wraps the text in `<p>` tags (basic paragraph wrapping)

### Given a RichEditor target field and the AI returns HTML
- When `toFormValue()` is called
- Then it returns the HTML as-is (RichEditor accepts HTML)

### Given a MarkdownEditor target field
- When `toFormValue()` is called
- Then it returns the text as-is (Markdown is already plain text)

## toPromptContext (form state → prompt)

### Given a form value that is a string
- When `toPromptContext()` is called
- Then it returns the string as-is

### Given a form value that is null
- When `toPromptContext()` is called
- Then it returns an empty string

### Given a RichEditor form value containing HTML
- When `toPromptContext()` is called
- Then it strips HTML tags via `strip_tags()` to send clean text to the AI

## Component Detection

The following Filament component classes all map to `TextFactory`:
- `Filament\Forms\Components\TextInput`
- `Filament\Forms\Components\Textarea`
- `Filament\Forms\Components\RichEditor`
- `Filament\Forms\Components\MarkdownEditor`

The factory can distinguish between these via `get_class($this->component)` when behavior differs (e.g., HTML handling for RichEditor).
