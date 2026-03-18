# 12 — Testing

## Summary

The package provides an `AiAction::fake()` helper that lets developers test their AI-powered forms without making real API calls. Follows Laravel's convention of `Http::fake()`, `Queue::fake()`, etc. The fake intercepts the AI call and returns predetermined values.

## AiAction::fake()

### Basic Usage

```php
use Statikbe\FilamentSolaris\Actions\AiAction;

// Fake all AI actions with specific responses
AiAction::fake([
    'category_id' => 'news',
    'summary' => 'This is a test summary.',
]);

// Now trigger the action in a Livewire test — it returns the faked values
// without calling any AI API
```

### Behavior

#### Given AiAction::fake() is called with a response array
- When any AiAction executes
- Then the AI call is skipped entirely
- And the faked values are passed through each target field's factory `toFormValue()` method
- And the results are applied to the form as normal

#### Given AiAction::fake() is called with no arguments
- When any AiAction executes
- Then the AI call is skipped
- And empty values are returned for all target fields
- And the action completes without error (fields are not modified)

### Why Values Still Pass Through Factories

The fake doesn't bypass factories — it only bypasses the AI call. This means:
- Factory transformation logic is still tested
- Schema generation is still exercised
- Invalid fake values will be caught by the factory's validation

This is intentional: it lets developers verify that their factory configuration works correctly even in tests.

## Assertion Helpers

### `AiAction::assertCalled()`

```php
AiAction::fake([...]);

// ... trigger the action ...

AiAction::assertCalled();
```

#### Given an AiAction was executed during the test
- Then `assertCalled()` passes

#### Given no AiAction was executed
- Then `assertCalled()` fails with "Expected an AiAction to be called, but none was."

### `AiAction::assertCalledWith()`

```php
AiAction::fake([...]);

// ... trigger the action ...

AiAction::assertCalledWith(function (array $sourceData, string $prompt, $provider, $model) {
    expect($sourceData['title'])->toBe('Test Article');
    expect($prompt)->toContain('Classify');
    expect($provider)->toBe('openai');
    expect($model)->toBe('gpt-4o');
});
```

#### Given a callback
- When `assertCalledWith()` is called
- Then the callback receives the source data, the composed prompt string, the resolved provider, and the resolved model
- And the callback can assert on all four values

### `AiAction::assertNotCalled()`

```php
AiAction::fake([...]);

// ... don't trigger any action ...

AiAction::assertNotCalled();
```

### `AiAction::assertCalledTimes(int $count)`

```php
AiAction::assertCalledTimes(3);
```

## Faking Specific Actions

```php
// Fake only a specific action by name
AiAction::fake([
    'summary' => 'Test summary',
])->forAction('summarize');

// Different fakes for different actions
AiAction::fake([
    'category_id' => 'news',
])->forAction('classify');

AiAction::fake([
    'summary' => 'A brief summary.',
])->forAction('summarize');
```

### Behavior

#### Given fakes are registered for specific action names
- When an action matching that name executes
- Then the corresponding faked values are used

#### Given an action executes that has no specific fake registered
- When AiAction::fake() was called with a default (no forAction)
- Then the default fake is used

#### Given no matching fake exists at all
- Then the real AI call is made (or an exception if no API is configured)

## Faking Failures

```php
// Simulate an API error
AiAction::fakeError('The AI service is currently unavailable.');

// Simulate a timeout
AiAction::fakeTimeout();

// Simulate partial failure (some fields succeed, some fail)
AiAction::fakePartial([
    'category_id' => 'news',      // this succeeds
    'summary' => null,              // this fails (null = simulate failure)
]);
```

### Behavior

#### Given AiAction::fakeError() is called
- When any AiAction executes
- Then it behaves as if the API returned an error
- And the error notification is shown

#### Given AiAction::fakeTimeout() is called
- When any AiAction executes
- Then it behaves as if the API timed out
- And the timeout notification is shown

#### Given AiAction::fakePartial() with null values
- When the action executes
- Then fields with non-null values are filled normally
- And fields with null values trigger the "could not fill" warning

## Implementation Notes

### Fake Registry

Use a static property on AiAction (or a dedicated singleton) to store fake registrations:

```php
class AiActionFake
{
    protected static ?self $instance = null;
    protected array $responses = [];
    protected array $actionResponses = [];
    protected array $calls = [];
    protected ?string $errorMessage = null;
    protected bool $simulateTimeout = false;

    public static function activate(array $defaultResponse = []): static { ... }
    public static function reset(): void { ... }
    public static function isActive(): bool { ... }

    public function forAction(string $actionName): static { ... }
    public function resolve(string $actionName): ?array { ... }
    public function recordCall(string $actionName, array $sourceData, string $prompt, Lab|array|string|null $provider = null, ?string $model = null): void { ... }
    public function assertCalledWith(\Closure $callback): void { ... } // callback($sourceData, $prompt, $provider, $model)
}
```

### Cleanup

The fake should be automatically reset after each test. If using Pest, provide a trait or hook:

```php
// In a test file
uses(\Statikbe\FilamentSolaris\Testing\WithAiActionFake::class);

// The trait calls AiAction::fake()::reset() in tearDown
```

## Testing Factories in Isolation

Factories are pure logic and can be tested without Livewire:

```php
it('generates enum schema for static select options', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinion',
    ]);

    $factory = new SelectFactory($select);
    $schema = $factory->responseSchema();

    expect($schema['type'])->toBe('string');
    expect($schema['enum'])->toBe(['news', 'opinion']);
});
```

## Testing Presets in Isolation

Presets are PromptBuilders and can be tested by calling `build()` directly:

```php
it('includes max words in summarize prompt', function () {
    $preset = SummarizePreset::make()->maxWords(100)->tone('formal');
    $factory = new TextFactory(Textarea::make('summary'));

    $prompt = $preset->build(
        instruction: '',
        sourceData: ['body' => 'Article content...'],
        factories: ['summary' => $factory],
    );

    expect($prompt)
        ->toContain('100')
        ->toContain('formal');
});
```

## Testing UserInput

```php
it('includes user instructions in prompt', function () {
    $builder = new InlinePromptBuilder('Summarize this article.');

    $prompt = $builder->build(
        instruction: 'Summarize this article.',
        sourceData: ['body' => 'Content...'],
        factories: ['summary' => new TextFactory(Textarea::make('summary'))],
        userInput: ['user_instructions' => 'Focus on the financial aspects'],
    );

    expect($prompt)->toContain('Focus on the financial aspects');
});
```
