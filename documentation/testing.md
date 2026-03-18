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

## Auto-Reset Trait

Use `WithAiActionFake` to automatically reset the fake after each test:

```php
use Statikbe\FilamentSolaris\Testing\WithAiActionFake;

class MyTest extends TestCase
{
    use WithAiActionFake;

    // AiActionFake::reset() is called automatically after each test
}
```
