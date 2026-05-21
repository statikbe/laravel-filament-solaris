# ImageGenerationAction

[← Back to README](../README.md)

`ImageGenerationAction` generates images via `laravel/ai`'s Image API and writes them to `FileUpload` or `SpatieMediaLibraryFileUpload` fields. It composes a text prompt from an instruction, source field values, and optional user input.

## Basic Usage

```php
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;

Forms\Components\Actions::make([
    ImageGenerationAction::make('generate-poster')
        ->prompt('Generate a movie poster based on the story')
        ->sourceFields(['title', 'description'])
        ->targetField('poster'),
]),

SpatieMediaLibraryFileUpload::make('poster')
    ->collection('poster')
    ->disk('public')
    ->image(),
```

## Size & Quality

Control the image dimensions and quality using enums or strings:

```php
use Statikbe\FilamentSolaris\Enums\ImageSize;
use Statikbe\FilamentSolaris\Enums\ImageQuality;

ImageGenerationAction::make('generate')
    ->prompt('A hero banner image')
    ->targetField('hero_image')
    ->imageSize(ImageSize::Landscape)       // or 'landscape', '3:2'
    ->imageQuality(ImageQuality::High)      // or 'high'
```

Available sizes: `Square` (`1:1`), `Portrait` (`2:3`), `Landscape` (`3:2`) — or pass any ratio string directly.

Available qualities: `Low`, `Medium`, `High`.

## Provider & Model

Image generation has its own provider resolution chain, separate from the structured output pipeline:

```php
ImageGenerationAction::make('generate')
    ->prompt('A product photo')
    ->targetField('image')
    ->provider('openai', 'gpt-image-1.5')
    ->timeout(120)
```

**Resolution chain** (highest wins):
1. Action-level `->provider()`
2. Config `default_image_provider` / `default_image_model`
3. laravel/ai default (`config('ai.default_for_images')`)

Supported image providers in laravel/ai: **OpenAI**, **Gemini**, **xAI (Grok)**.

## Storage

For `FileUpload` and `SpatieMediaLibraryFileUpload` targets, the generated image is stored as a Livewire temporary upload. Filament's save pipeline handles the rest — including creating Spatie Media records. The image is stored to the disk/directory configured on the component itself.

For other component types (e.g. `TextInput`), the image is stored to the disk/directory from the package config and the path is set as the field value. Override the storage destination per-action with the fluent setters, or set package-wide defaults in config:

```php
ImageGenerationAction::make('generate')
    ->prompt('A product photo')
    ->targetField('image_path')
    ->disk('s3')                  // overrides default_image_disk
    ->directory('products/ai')    // overrides default_image_directory
    ->visibility('public');       // overrides default_image_visibility
```

```php
// In config/filament-solaris.php — used when the per-action setters aren't called
'default_image_disk' => null,           // null = default filesystem disk
'default_image_directory' => 'ai-images',
'default_image_visibility' => null,     // 'public' or null
```

`->disk()`, `->directory()`, and `->visibility()` all accept a `Closure` for runtime resolution. Per-panel defaults are available on the plugin (`->defaultImageDisk()`, `->defaultImageDirectory()`, `->defaultImageVisibility()`).

## User Input

Add a modal for the user to provide additional instructions:

```php
use Statikbe\FilamentSolaris\Support\UserInput;

ImageGenerationAction::make('generate')
    ->prompt('Generate an image based on the product')
    ->sourceFields(['name', 'description'])
    ->targetField('image')
    ->userInput(UserInput::make()
        ->prompt('Any specific instructions?')
        ->placeholder('e.g. bright colors, minimalist style...')
    )
```

## Reference Images

Pass an input image (or several) to the image-generation provider for image-to-image, edit, or reference-based generation. OpenAI switches to its `images/edits` endpoint; Gemini sends the input as native multi-modal parts. Same three-channel API as [`AiAction` Attachments](ai-action.md#attachments) — only `Files\Image` instances reach the gateway (other types are silently dropped):

```php
use Laravel\Ai\Files\Image;

ImageGenerationAction::make('reskin')
    ->prompt('Reskin in studio-photography style')
    ->targetField('featured_image')
    ->attachmentField('reference_image')   // FileUpload on the parent form
```

Closure form (e.g. for a brand logo from a record relationship):

```php
->attachments(fn ($livewire) => [
    Image::fromStorage($livewire->record->logo_path, 'public'),
])
```

Multiple references work too — bind a `->multiple()` FileUpload, or supply an array from a closure. Conversational refinement re-attaches the input image on every turn, so "now make it warmer" follow-ups still see the original reference.

## Testing

See [Testing documentation](testing.md#testing-imagegenerationaction).
