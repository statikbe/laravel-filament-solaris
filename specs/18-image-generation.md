# 18 — Image Generation

## Summary

An action (and optionally a field component) that generates images via `laravel/ai`'s `ImageGeneration` API and stores the result in a Filament `FileUpload` or `SpatieMediaLibraryFileUpload` field. Supports prompt-from-fields (like AiAction), user input for prompt customization, and conversational refinement for iterating on results.

## Status: Design Phase

Open design questions are noted inline. This spec will be refined before implementation.

## laravel/ai Image Generation API

```php
use Laravel\Ai\ImageGeneration;

$response = ImageGeneration::prompt('A sunset over mountains')
    ->size('1024x1024')      // or '3:2', '2:3', '1:1'
    ->square()               // shorthand for '1:1'
    ->landscape()            // shorthand for '3:2'
    ->portrait()             // shorthand for '2:3'
    ->quality('hd')          // 'low', 'medium', 'high'
    ->timeout(60)
    ->generate('openai', 'dall-e-3');

// ImageResponse
$response->firstImage();         // GeneratedImage
$response->images();             // Collection<GeneratedImage>
$response->count();

// GeneratedImage
$image = $response->firstImage();
$image->content();               // raw bytes (base64_decode)
$image->store('images/ai');      // store to default disk, returns path
$image->storePublicly('images'); // store publicly
$image->toHtml('alt text');      // <img src="data:mime;base64,...">
```

### Supported Providers

| Provider | Models | Notes |
|----------|--------|-------|
| OpenAI | dall-e-3, gpt-image-1 | Most mature |
| Gemini | imagen-3.0-generate-002 | Google |
| X.ai | grok-2-image | |

## Proposed API

### Option A: ImageGenerationAction (Action-based)

```php
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;

// Generate from prompt string
ImageGenerationAction::make('generate-hero')
    ->targetField('hero_image')        // FileUpload field
    ->prompt('A professional hero image for a blog post about:')
    ->sourceFields(['title'])          // context for the prompt
    ->size('landscape')
    ->quality('hd')

// Generate with user input (prompt in modal)
ImageGenerationAction::make('generate-image')
    ->targetField('featured_image')
    ->userInput(
        UserInput::make()
            ->prompt('Describe the image you want')
            ->placeholder('A colorful illustration of...')
    )

// With preview (show image before saving)
ImageGenerationAction::make('generate-cover')
    ->targetField('cover_image')
    ->prompt('Book cover illustration for:')
    ->sourceFields(['title', 'description'])
    ->withPreview()

// With conversational refinement
ImageGenerationAction::make('generate-cover')
    ->targetField('cover_image')
    ->prompt('Book cover illustration for:')
    ->sourceFields(['title', 'description'])
    ->withPreview()
    ->conversational()
    // "Make the sky more blue", "Add a mountain in the background"
```

### Option B: AiImageField (Custom Field Component)

```php
// A custom Filament field with built-in generate button
AiImageField::make('hero_image')
    ->disk('public')
    ->directory('ai-images')
    ->prompt('Professional hero image for:')
    ->sourceFields(['title'])
    ->size('landscape')
```

### Recommendation

Start with **Option A** (action). It follows the established pattern (AiAction, DictationAction) and is more flexible. Option B can be added later as a convenience wrapper.

## Execution Flow

### Step 1: User Triggers Action

- User clicks the image generation button
- If `userInput()` is configured, a modal opens for prompt input
- If `sourceFields()` are configured, their values are read

### Step 2: Prompt Composition

Unlike AiAction which uses structured output, image generation uses a plain text prompt:

```php
$prompt = $this->composeImagePrompt($sourceData, $userInput);
// "A professional hero image for a blog post about: [title value]. [user instructions]"
```

No factories or JSON schema needed — the AI returns an image, not structured data.

### Step 3: Image Generation

```php
$pending = ImageGeneration::prompt($prompt);

if ($this->imageSize) $pending->size($this->imageSize);
if ($this->imageQuality) $pending->quality($this->imageQuality);
if ($timeout) $pending->timeout($timeout);

$response = $pending->generate($provider, $model);
$image = $response->firstImage();
```

### Step 4: Storage

Store the generated image to the configured disk/directory:

```php
$path = $image->store(
    $this->getStorageDirectory(),
    $this->getStorageDisk()
);
```

### Step 5: Apply to Field

Write the stored path to the target FileUpload field:

```php
$set($targetField, $path);
```

### Step 5 (with Preview)

Show the generated image in a preview modal before storing:

```
┌─────────────────────────────────────┐
│  Generated Image                    │
├─────────────────────────────────────┤
│                                     │
│  ┌───────────────────────────────┐  │
│  │                               │  │
│  │      [generated image]        │  │
│  │                               │  │
│  └───────────────────────────────┘  │
│                                     │
│  Prompt: "A professional hero..."   │
│                                     │
├─────────────────────────────────────┤
│  [Discard]   [Regenerate]  [Accept] │
└─────────────────────────────────────┘
```

## Configuration

### Action-Level

```php
ImageGenerationAction::make('generate')
    ->targetField('image')
    ->prompt('...')
    ->sourceFields(['title'])
    ->size('landscape')              // '1:1', '3:2', '2:3', or 'square', 'landscape', 'portrait'
    ->quality('hd')                  // 'low', 'medium', 'high'
    ->disk('public')                 // storage disk
    ->directory('ai-images')         // storage directory
    ->provider('openai', 'dall-e-3') // image generation provider
    ->timeout(120)
    ->withPreview()
    ->conversational()               // "make the sky more blue"
```

### Config-Level

```php
// config/filament-solaris.php
'image_generation' => [
    'default_provider' => null,      // falls back to laravel/ai default
    'default_model' => null,
    'default_size' => 'square',
    'default_quality' => null,
    'default_disk' => 'public',
    'default_directory' => 'ai-images',
    'default_timeout' => 120,
],
```

## Relationship to Other Specs

| Spec | Relationship |
|------|-------------|
| 16 (Preview Modal) | Reuses preview modal for image preview before accepting |
| 17 (Conversational) | "Make it more blue" — iterative image refinement via chat |
| 14 (HasPromptPipeline) | Does NOT reuse — image generation has a different pipeline (no factories, no structured output) |
| 15 (DictationAction) | Pattern reference — separate provider config like transcription |

## Open Design Questions

1. **Multiple images per generation?** Should the action generate 1 image or N options to pick from? (DALL-E supports `n` parameter)

2. **Spatie Media Library integration?** Should there be explicit support for `SpatieMediaLibraryFileUpload`? This would require handling collections/conversions.

3. **Image editing (inpainting)?** laravel/ai's `PendingImageGeneration` supports `attachments()` — could be used to send an existing image for editing/variation. Future scope?

4. **Prompt template?** Should image prompts use PromptBuilder/Blade templates like AiAction, or is a simple string + source data sufficient? Leaning toward simple string — image prompts are typically short.

5. **FileUpload field detection?** How to detect that the target field is a FileUpload and configure storage accordingly? Could read `getDisk()` and `getDirectory()` from the component.

## Testing

```php
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;

// Fake image generation
ImageGenerationAction::fake('path/to/fake-image.png');

$livewire->callAction('generate-hero');

ImageGenerationAction::assertCalled();
expect($livewire->data['hero_image'])->toBe('path/to/fake-image.png');
```

## Dependencies

- laravel/ai `ImageGeneration` API
- Filament `FileUpload` component (target field)
- Spec 16 (Preview Modal) — for `withPreview()` support
- Spec 17 (Conversational) — for iterative refinement
- No new npm/JS dependencies (image display is server-rendered HTML)
