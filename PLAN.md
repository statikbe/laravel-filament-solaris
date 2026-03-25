# Plan: Fix Image Storage + Preview/Conversational for ImageGenerationAction

## Context

Preview and conversational refinement work for AiAction and DictationAction (via HasPromptPipeline). ImageGenerationAction has its own pipeline and can't reuse HasPromptPipeline. Adding these features to images exposes coupling issues in the accept flow that should be cleaned up first.

**Bug found:** Generated images don't appear in `SpatieMediaLibraryFileUpload` because `$set('poster', 'ai-images/file.png')` sets a file path, but the component expects Media model UUIDs. The component's state format is `['media-uuid' => 'media-uuid']`, not file paths.

---

## Phase 0 (REVISED): Unify FileUpload into the ComponentFactory pattern

### Problem with current approach

We introduced a separate `ImageTargetHandler` contract + `FileUploadHandler` / `DefaultHandler` to handle writing images to different component types. This creates a **second extension point** alongside the existing `ComponentFactory` — developers would need to learn two systems.

### Better approach: Extend ComponentFactory

The existing `ComponentFactory` already maps "Filament component → AI behavior." A `FileUploadFactory` naturally fits:

- `toPromptContext($formValue)` → extract file contents or URL for AI context (future: CSV summaries, image-to-image)
- `toFormValue($aiValue)` → transform JSON scalar to form state (for AiAction structured output)
- `toFormValueFromImage(ImageResponse $response)` → transform generated image to form state (for ImageGenerationAction)
- `toPreviewDisplay($formValue)` → render `<img>` tag for preview modal

### Design: Abstract base with opt-in methods

The abstract `ComponentFactory` gets a new method with a default `NotImplementedException`:

```php
// src/Factories/ComponentFactory.php (abstract base)
abstract class ComponentFactory implements ComponentFactoryContract
{
    // Existing methods — required for AiAction
    abstract public function responseSchema(JsonSchemaTypeFactory $schema): Type;
    abstract public function toFormValue(mixed $aiValue): mixed;
    abstract public function toPromptContext(mixed $formValue): mixed;

    // Existing with default implementation
    public function toPreviewDisplay(mixed $formValue): array { ... }

    // NEW — opt-in for ImageGenerationAction
    public function toFormValueFromImage(ImageResponse $response): mixed
    {
        throw new \BadMethodCallException(
            static::class . ' does not support image generation output. Implement toFormValueFromImage() to add support.'
        );
    }
}
```

Each factory implements only the methods its component supports:
- **TextFactory**: `responseSchema` + `toFormValue` + `toPromptContext` (existing) + `toFormValueFromImage` returns stored path
- **FileUploadFactory**: `toFormValueFromImage` returns `TemporaryUploadedFile` state, `toPromptContext` could extract file contents (future)
- **SelectFactory**: Only structured output methods (no image support needed)

### FileUploadFactory implementation

```php
class FileUploadFactory extends ComponentFactory
{
    public function responseSchema(JsonSchemaTypeFactory $schema): Type
    {
        return $schema->string()->description('File path');
    }

    public function toFormValue(mixed $aiValue): mixed
    {
        return $aiValue; // Path string — for AiAction fallback
    }

    public function toPromptContext(mixed $formValue): mixed
    {
        return $formValue; // Path string — future: could extract file contents
    }

    public function toFormValueFromImage(ImageResponse $response): mixed
    {
        // Create Livewire TemporaryUploadedFile (current FileUploadHandler logic)
        $image = $response->firstImage();
        $content = $image->content();
        // ... create temp file, return [$uuid => TemporaryUploadedFile]
    }
}
```

### What changes

1. **Delete** `ImageTargetHandler` contract, `FileUploadHandler`, `DefaultHandler`
2. **Delete** `image_target_handlers` config key
3. **Add** `toFormValueFromImage(ImageResponse)` to abstract `ComponentFactory` (throws by default)
4. **Add** `FileUploadFactory` in `src/Factories/`
5. **Add** `FileUpload::class => FileUploadFactory::class` + `SpatieMediaLibraryFileUpload::class => FileUploadFactory::class` to existing `factories` config map
6. **Update** `HasImageGenerationPipeline::writeImageToField()` to resolve factory via existing `ComponentFactoryResolver` and call `toFormValueFromImage()`
7. **Update** `TextFactory::toFormValueFromImage()` to store image and return path (fallback for TextInput targets)

### Benefits

- **One extension system** — developers register a single factory per component type
- **Forward-compatible** — `toPromptContext()` on FileUploadFactory enables future use cases (CSV/image as AI input)
- **Existing resolver works** — `ComponentFactoryResolver` already handles class hierarchy resolution + config map
- **DX consistency** — same pattern as all other component types

## Architecture Problems

### 0. SpatieMediaLibraryFileUpload incompatibility (BUG)

`writeImageToField()` calls `$set($targetField, $storedPath)` with a file path string. This works for:
- **TextInput** — stores strings (tests pass)
- **FileUpload** — `FileUploadStateCast` wraps to `['uuid' => 'path']`, component checks disk for file

But **fails** for **SpatieMediaLibraryFileUpload** because:
1. State format is `['media-uuid' => 'media-uuid']` (UUIDs from database Media records)
2. `getUploadedFileUsing` does `$record->media->firstWhere('uuid', $file)` — can't find our path
3. No Media record exists in the database for the generated image

**Fix:** Detect the target component type. For SpatieMediaLibraryFileUpload:
1. Store image to a temp file
2. Call `$record->addMedia($tempPath)->toMediaCollection($collection)` to create a Media record
3. Set the Media UUID as state (not the file path)
4. For create pages (no record yet), store as a Livewire temporary upload

For standard FileUpload: current `$set($field, $path)` approach works if the file exists on the component's configured disk.

### 1. Accept flow hardcoded in Livewire trait

`InteractsWithSolarisPreview::solarisAcceptPreview()` contains domain logic that belongs on the action:
- Resolves schema component from first field key
- Iterates `$data['values']` calling `$set()` per field
- Calls `SolarisNotification::sendResultNotifications()`

This won't work for images (single path, different notification, potentially deferred storage). The Livewire trait should be plumbing only — delegate to the action.

### 2. Display rendering assumes factories

`HasPromptPipeline::buildDisplays()` calls `factory->toPreviewDisplay()` per field. Images have no factory — they need an `'image'` display type showing the generated image as a base64 `<img>`. The preview Blade view (`preview-fields.blade.php`) only handles `'text'` and `'html'`.

### 3. Preview stores already-written values

In the current image pipeline, `runImagePipeline()` stores the image to disk immediately. With preview, the image should be held in memory as base64 until the user accepts — avoids orphan files on discard.

## Proposed Changes

### Phase 1: Abstract the accept flow

**Goal:** Make `solarisAcceptPreview()` action-agnostic by delegating to the mounted action.

#### 1a. Add `acceptPreview()` to `HasPromptPipeline`

Move the write logic from the Livewire trait into the action's pipeline trait:

```php
// src/Concerns/HasPromptPipeline.php
public function acceptPreview(array $data): void
{
    $this->writeResults($data['values'], $data['filledLabels'], $data['failedLabels']);
}
```

This is a pure move — same logic, new location.

#### 1b. Simplify `InteractsWithSolarisPreview::solarisAcceptPreview()`

Delegate to the action instead of doing field writes:

```php
public function solarisAcceptPreview(): void
{
    if ($this->solarisPreviewData === null) {
        return;
    }

    $data = $this->solarisPreviewData;
    $this->solarisPreviewData = null;

    $action = $this->getMountedAction();

    if ($action !== null && method_exists($action, 'acceptPreview')) {
        $action->acceptPreview($data);
    }

    $this->unmountAction();
}
```

#### 1c. Verify existing preview + conversational tests pass unchanged

**Files modified:**
- `src/Concerns/InteractsWithSolarisPreview.php`
- `src/Concerns/HasPromptPipeline.php`

---

### Phase 2: Add `'image'` display type to preview views

#### 2a. Update `preview-fields.blade.php`

Add image rendering alongside existing text/html:

```blade
@if ($field['type'] === 'image')
    <img src="{{ $field['display'] }}" alt="{{ $field['label'] }}" class="max-w-full rounded-lg" />
@elseif ($field['type'] === 'html')
    ...
```

**Files modified:**
- `resources/views/components/preview-fields.blade.php`

---

### Phase 3: Add preview to ImageGenerationAction

#### 3a. Add `withPreview()` / `shouldPreview()` to `HasImageGenerationPipeline`

```php
protected bool $preview = false;

public function withPreview(bool $preview = true): static
{
    $this->preview = $preview;
    if ($preview) {
        $this->modal(true);
    }
    return $this;
}

public function shouldPreview(): bool
{
    return $this->preview;
}
```

#### 3b. Update `runImagePipeline()` — preview path holds base64, not stored file

When preview is enabled, generate but don't store yet:

```php
protected function runImagePipeline(array $sourceData, array $userInput): void
{
    $prompt = $this->composePrompt($sourceData, $userInput);
    $response = $this->generateImage($prompt);

    if ($response === null) {
        return;
    }

    if ($this->shouldPreview()) {
        $this->storeImagePreviewData($response, $prompt, $sourceData, $userInput);
        $this->halt();
        return;
    }

    // Direct mode (no preview) — store and write immediately
    $storedPath = $this->storeImage($response);
    // ... existing logic
}
```

#### 3c. Add `storeImagePreviewData()` — holds image as base64

```php
protected function storeImagePreviewData(
    ImageResponse $response,
    string $prompt,
    array $sourceData,
    array $userInput,
): void {
    $image = $response->firstImage();
    $dataUri = 'data:' . ($image->mime ?? 'image/png') . ';base64,' . $image->image;

    $targetField = $this->getTargetField();
    $label = $this->resolveFieldLabel($targetField);

    $data = [
        'values' => [$targetField => null], // Placeholder, actual path set on accept
        'displays' => [
            $targetField => [
                'label' => $label,
                'display' => $dataUri,
                'type' => 'image',
            ],
        ],
        'filledLabels' => [$label],
        'failedLabels' => [],
        'actionName' => $this->getName(),
        // Image-specific data for accept/refine
        'imageBase64' => $image->image,
        'imageMime' => $image->mime,
        'originalPrompt' => $prompt,
        'sourceData' => $sourceData,
        'userInput' => $userInput,
    ];

    $this->getLivewire()->solarisPreviewData = $data;
}
```

#### 3d. Add `acceptPreview()` to `HasImageGenerationPipeline`

On accept: store base64 to disk, write path to field:

```php
public function acceptPreview(array $data): void
{
    $storedPath = $this->storeImageFromBase64($data['imageBase64'], $data['imageMime']);

    if ($storedPath === false) {
        Notification::make()
            ->title(filament_solaris_trans('notifications.image_generation_store_failed'))
            ->danger()
            ->send();
        return;
    }

    $this->writeImageToField($storedPath);
    $this->sendImageSuccessNotification();
}
```

#### 3e. Add `storeImageFromBase64()` helper

```php
protected function storeImageFromBase64(string $base64, ?string $mime): string|false
{
    $content = base64_decode($base64);
    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        default => 'png',
    };

    $filename = Str::random(40) . '.' . $extension;
    $directory = $this->resolveStorageDirectory();
    $disk = $this->resolveStorageDisk();
    $path = $directory . '/' . $filename;

    $stored = Storage::disk($disk)->put($path, $content,
        $this->resolveStorageVisibility() === 'public' ? 'public' : []
    );

    return $stored ? $path : false;
}
```

#### 3f. Add `HasPreviewModal` to `ImageGenerationAction`

Override `getModalContent()` for image-specific preview flow (loading spinner + preview display):

```php
use HasPreviewModal { getModalContent as baseGetModalContent; }

public function getModalContent(): View|Htmlable|null
{
    if ($this->hasPreviewData()) {
        $previewData = $this->getLivewire()->solarisPreviewData;

        if ($previewData['isConversational'] ?? false) {
            return view('filament-solaris::preview-conversational', [
                'displays' => $previewData['displays'],
                'messages' => $previewData['messages'] ?? [],
            ]);
        }

        return view('filament-solaris::preview-modal', [
            'displays' => $previewData['displays'],
        ]);
    }

    if ($this->shouldPreview()) {
        return view('filament-solaris::preview-loading');
    }

    return parent::getModalContent();
}
```

Note: This is the same pattern as AiAction. The shared preview views (`preview-modal`, `preview-loading`, `preview-conversational`) and the new `image` display type in `preview-fields` handle the rendering.

#### 3g. Update `runFakeImagePipeline()` for preview mode

When preview is enabled in fake mode, store fake preview data instead of writing directly:

```php
if ($this->shouldPreview()) {
    $this->storeFakeImagePreviewData($sourceData, $userInput);
    $this->halt();
    return;
}
```

**Files modified:**
- `src/Concerns/HasImageGenerationPipeline.php`
- `src/Actions/ImageGenerationAction.php`

---

### Phase 4: Add conversational refinement

#### 4a. Add `HasConversational` to `ImageGenerationAction`

Simple — just use the existing trait.

#### 4b. Add `refine()` to `HasImageGenerationPipeline`

Image APIs don't support multi-turn conversations. Refinement means: re-generate with feedback appended to the prompt.

```php
public function refine(string $message): void
{
    $livewire = $this->getLivewire();
    $previewData = $livewire->solarisPreviewData;

    if ($previewData === null || ! ($previewData['isConversational'] ?? false)) {
        return;
    }

    // Append user message to chat history
    $previewData['messages'][] = ['role' => 'user', 'content' => $message];
    $livewire->solarisPreviewData = $previewData;

    if (ImageGenerationActionFake::isActive()) {
        $this->runFakeImageRefinement($message, $previewData);
        return;
    }

    $this->runImageRefinement($message, $previewData);
}
```

#### 4c. `runImageRefinement()` — re-generate with feedback

```php
protected function runImageRefinement(string $message, array $previewData): void
{
    $refinedPrompt = $previewData['originalPrompt'] . "\n\nFeedback: " . $message;

    $response = $this->generateImage($refinedPrompt);

    if ($response === null) {
        return;
    }

    $this->updateImagePreviewData($response, $refinedPrompt, $previewData);
}
```

#### 4d. Add refinement support to `ImageGenerationActionFake`

```php
protected array $refinementPaths = [];
protected array $refinementCalls = [];

public function fakeRefinement(string $storedPath): static { ... }
public function resolveRefinement(): string { ... }
public function recordRefinementCall(string $actionName, string $message): void { ... }
public function assertRefined(): void { ... }
public function assertRefinedWith(Closure $callback): void { ... }
public function assertRefinedTimes(int $count): void { ... }
```

**Files modified:**
- `src/Actions/ImageGenerationAction.php`
- `src/Concerns/HasImageGenerationPipeline.php`
- `src/Testing/ImageGenerationActionFake.php`

---

### Phase 5: Tests

- Verify existing `PreviewModalTest` and `ConversationalTest` pass after Phase 1 refactor
- `ImageGenerationPreviewTest` — preview shows image, accept stores and writes, discard cleans up
- `ImageGenerationConversationalTest` — refine re-generates, chat history updates, accept after refine
- `ImageGenerationActionFakeTest` — add refinement assertion tests

---

## Implementation Order

1. **Phase 0** — Fix `writeImageToField()` for SpatieMediaLibraryFileUpload + FileUpload (bug fix)
2. **Phase 1** — Abstract accept flow (refactor, no new features)
3. **Phase 2** — Add `'image'` display type (small Blade change)
4. **Phase 3** — Preview for ImageGenerationAction
5. **Phase 4** — Conversational refinement for ImageGenerationAction
6. **Phase 5** — Tests throughout
7. Run simplifier

---

## Phase 0: Fix writeImageToField() — Component-Aware Storage

### Problem

`writeImageToField()` blindly calls `$set($targetField, $storedPath)`. This doesn't work for SpatieMediaLibraryFileUpload (needs Media UUID) and may not work reliably for FileUpload either (needs file on the component's disk, not our configured disk).

### Solution: Detect target component type and handle accordingly

Add `resolveTargetComponent()` to find the actual Filament component for the target field. Then branch:

#### For SpatieMediaLibraryFileUpload (edit page — record exists):
1. Get the record from the Livewire component
2. Get the collection name from the component (`$component->getCollection()`)
3. Store image to a temp file
4. Call `$record->addMedia($tempPath)->toMediaCollection($collection)`
5. Reload the component state from the relationship

#### For SpatieMediaLibraryFileUpload (create page — no record yet):
1. Store image as a Livewire temporary upload file
2. Set the temp file identifier as state (same as a user upload)
3. On form save, Filament's `saveRelationshipsUsing` handles the rest

#### For standard FileUpload:
1. Read disk/directory from the component (`$component->getDiskName()`, `$component->getDirectory()`)
2. Store image to that disk/directory (not our configured one — use component's config)
3. Set the path as state via `$set()`

#### For TextInput / other (fallback):
1. Store to our configured disk/directory
2. Set path as state via `$set()` (current behavior)

### Key method: `writeImageToField()`

```php
protected function writeImageToField(ImageResponse|string $imageData): void
{
    $component = $this->resolveTargetComponent();

    if ($component instanceof SpatieMediaLibraryFileUpload) {
        $this->writeImageToMediaLibrary($imageData, $component);
    } elseif ($component instanceof FileUpload) {
        $this->writeImageToFileUpload($imageData, $component);
    } else {
        // Fallback: store and $set path string
        $path = is_string($imageData) ? $imageData : $this->storeImage($imageData);
        $this->setFieldValue($path);
    }
}
```

### Files modified:
- `src/Concerns/HasImageGenerationPipeline.php` — rewrite `writeImageToField()`, add component detection + per-type handlers

### Dependencies:
- `filament/spatie-laravel-media-library-plugin` is an optional dependency (suggest in composer.json)
- Use class_exists() check before instanceof to avoid hard dependency

---

## Summary of abstractions

| Abstraction | What changes | Why |
|-------------|-------------|-----|
| Component-aware image storage | `writeImageToField()` detects component type | FileUpload vs SpatieMediaLibrary have different state formats |
| `acceptPreview()` on action | Livewire trait delegates to action | Each action owns its accept logic |
| `'image'` display type | Blade view handles `<img>` rendering | Preview fields become format-agnostic |
| Base64 in preview, store on accept | Image pipeline defers storage | No orphan files on discard |
| `refine()` via prompt concatenation | No conversation agent for images | Image APIs are stateless |
