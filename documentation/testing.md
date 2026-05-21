# Testing

The package provides a fake system that intercepts AI calls and returns predetermined responses. No API calls are made during tests.

## Basic Usage

```php
use Statikbe\FilamentSolaris\Actions\AiAction;

it('fills the summary field', function () {
    AiAction::fake(['summary' => 'A concise summary of the article.']);

    // ... trigger the action via Livewire testing ...

    AiAction::assertCalled();
});
```

## Simulating Errors

```php
// API error
AiAction::fakeError('Service unavailable');

// Timeout
AiAction::fakeTimeout();

// Partial failure (null values are treated as failed fields)
AiAction::fakePartial([
    'summary' => 'This worked',
    'category_id' => null,  // this field will fail
]);
```

## Assertions

```php
// Assert the action was called
AiAction::assertCalled();

// Assert it was called N times
AiAction::assertCalledTimes(2);

// Assert it was never called
AiAction::assertNotCalled();

// Inspect source data and prompt
AiAction::assertCalledWith(function (array $sourceData, string $prompt) {
    expect($sourceData['title'])->toBe('My Article');
    expect($prompt)->toContain('summary');
});

// Inspect provider, model, and timeout
AiAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model, $timeout) {
    expect($provider)->toBe('openai');
    expect($model)->toBe('gpt-4o');
    expect($timeout)->toBe(120);
});
```

## Per-Action Fakes

```php
AiAction::fake(['summary' => 'Default summary'])
    ->forAction('classify')  // override for a specific action name
    ->forAction('translate');
```

## Testing DictationFieldAction

```php
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;

// Fake pure transcription
DictationFieldAction::fake('This is the transcribed text.');

// Fake transcription + AI processing
DictationFieldAction::fake('Meeting notes about deadlines.', aiResponse: [
    'summary' => 'Discussion about project deadlines.',
    'category_id' => 'meetings',
]);

// Assertions
DictationFieldAction::assertCalled();
DictationFieldAction::assertTranscribed();
DictationFieldAction::assertTranscribedWith(function (string $transcript) {
    expect($transcript)->toContain('meeting');
});
```

## Testing ImageGenerationAction

```php
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;

// Fake image generation with a predetermined stored path
ImageGenerationAction::fake('ai-images/fake-image.png');

// ... trigger the action via Livewire testing ...

// Assertions
ImageGenerationAction::assertCalled();
ImageGenerationAction::assertCalledTimes(1);
ImageGenerationAction::assertNotCalled();

// Inspect prompt, size, quality, provider, model
ImageGenerationAction::assertCalledWith(function (string $prompt, ?string $size, ?string $quality, $provider, ?string $model) {
    expect($prompt)->toContain('product photo');
    expect($size)->toBe('3:2');
    expect($quality)->toBe('high');
    expect($provider)->toBe('openai');
});
```

## Auto-Reset Trait

Use `WithAiActionFake` to automatically reset all fakes after each test:

```php
use Statikbe\FilamentSolaris\Testing\WithAiActionFake;

class MyTest extends TestCase
{
    use WithAiActionFake;

    // AiActionFake, DictationFieldActionFake, and ImageGenerationActionFake
    // are all reset automatically after each test
}
```
