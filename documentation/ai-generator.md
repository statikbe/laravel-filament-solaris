# AiGenerator

[← Back to README](../README.md)

`AiGenerator` is the headless engine behind `AiGenerateAction`. Use it wherever there is no
Filament action — a job, a listener, a command, a controller.

```php
use Statikbe\FilamentSolaris\Generation\AiGenerator;

$result = AiGenerator::make()
    ->prompt('Suggest a title for this article: '.$article->body)
    ->schema(fn ($s) => ['title' => $s->string()])
    ->runInline();

$result->data['title'];
```

A failed call throws `Laravel\Ai\Exceptions\AiException`.

> Full reference for the batch and queued surface (`forModel()`, `sourceRecords()`,
> `createRecords()`, `runQueued()`, …) is still to come. Until then, the fluent methods
> mirror [AiGenerateAction](ai-generate-action.md).

## Reusable generators

When the same generator runs from several places — an upload action, an intake listener,
a re-run button — define it once as a subclass and configure it in `setUp()`:

```php
use Statikbe\FilamentSolaris\Generation\AiGenerator;
use Statikbe\FilamentSolaris\Sanitizers\StripTagsSanitizer;

class InvoiceExtractor extends AiGenerator
{
    protected function setUp(): void
    {
        $this->prompt(view('prompts.invoice'))
            ->forModel(Invoice::class)
            ->sanitize(new StripTagsSanitizer);
    }
}
```

Each call site then takes a fresh instance and adds what is specific to that run:

```php
InvoiceExtractor::make()->attachments([$file])->runInline();

// A call site can still override a default:
InvoiceExtractor::make()->provider('anthropic')->attachments([$file])->runInline();
```

`setUp()` runs however the instance is built — `new`, `make()`, or the container (so
type-hinting `InvoiceExtractor` in a job's `handle()` works too). Configure in `setUp()`, not
in the constructor; if you do override `__construct()`, call `parent::__construct()`.

### One instance per run

A generator is a mutable builder: every setter changes the instance. That is harmless as
long as each run gets its own instance.

- **Do:** `InvoiceExtractor::make()` per run, or `app()->bind(InvoiceExtractor::class, …)`.
- **Don't:** `app()->singleton(InvoiceExtractor::class, …)`, or a configured instance on a
  static or shared property.

Why: under `queue:work` or Octane the PHP process lives across many jobs or requests, and
so does a singleton. Per-run state — `attachments()`, `sourceRecords()`, `userInput()` —
set by one run stays on the instance. If the next run takes a path that does not set it,
the previous run's data (say, another customer's invoice) goes with the call.

Reusing an instance you do not change between runs is fine.
