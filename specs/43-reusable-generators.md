# 43 — Reusable generators (`setUp()` hook + reuse docs)

> **Status:** Implemented (2026-10-14) on branch `feature/reusable-generators`.
> **Date:** 2026-10-14.
> **Context:** Step 1 of [41](41-handler-terminal-and-agent-reuse.md) §7. **Replaces** the
> `AgentDefinition` / run split that 41 §3.2 decided on 2026-09-24 — that decision is
> reversed (see 41 §6.3 and "Why not the split" below).

---

## Goal

One configured generator should serve several call sites. Pacioli's case: a single-upload
action, a dropzone, an intake listener and a re-propose action all run the same invoice
extractor. Today the only way to share one is to share an *instance*, which is unsafe in a
long-lived process.

Make "define once, use anywhere" a first-class pattern **without** a breaking change.

## Why not the split

Spec 41 chose an immutable `AgentDefinition` plus a transient run, because `AiGenerator` is a
mutable fluent builder. Re-examined on 2026-10-14: mutability itself is harmless. The
hazard is **sharing one instance**:

- **The real case:** `app()->singleton(InvoiceExtractor::class, …)` under `queue:work` or
  Octane. The singleton lives across jobs; job 1 sets `->attachments([$invoiceA])`, job 2
  takes a branch that skips that setter, and invoice A is sent with job 2's call.
- **Not a case:** a fresh instance per call site — in a job, an action, a listener —
  whatever page or worker it runs in.
- **Not a case:** reusing an instance *without changing it*. Running the same configuration
  twice is safe.
- **Not a case:** setters accumulating across a loop. Every setter replaces its value
  (`sanitizeField()` is keyed by field, `onCompletion()` replaces the list).

A factory — a fresh instance per run — solves the real case. A subclass with a setup hook
is the most Laravel-like factory, and it also keeps the other benefits 41 attributed to
the split: a class name can later be rebuilt in a worker or for chat continuation, and is
the natural anchor for a future `InvoiceExtractor::fake()`. The split would have cost a
0.5 breaking change and a rewrite of ~30 setters, the `AiGenerateAction` translator and
four test files.

**Rejected: a single-use guard** (throwing on a second terminal call). It cannot tell safe
unchanged reuse from the dangerous kind without knowing which properties are per-run —
which is the split again — so it would punish the safe pattern to catch the unsafe one.
Docs carry the rule instead.

## Design

### 1. `setUp()` hook

`AiGenerator::__construct()` calls a protected, empty-by-default `setUp()`:

```php
public function __construct()
{
    $this->options = new GenerationOptions;
    $this->setUp();
}

/**
 * Configure a reusable generator. Override in a subclass; runs for `new`, `make()`
 * and container resolution alike.
 */
protected function setUp(): void {}
```

Called from the constructor, not `make()`, so `new InvoiceExtractor`,
`InvoiceExtractor::make()` and `app(InvoiceExtractor::class)` (including DI into a listener
or job `handle()`) configure identically. `make()` already returns `new static`.

Usage:

```php
class InvoiceExtractor extends AiGenerator
{
    protected function setUp(): void
    {
        $this->prompt(view('prompts.invoice'))
            ->forModel(Invoice::class)
            ->createRecords()
            ->sanitize(new StripTagsSanitizer);
    }
}

// Any call site — a fresh instance each time:
InvoiceExtractor::make()->attachments([$file])->runInline();

// A call site can still override a default:
InvoiceExtractor::make()->provider(Lab::Anthropic)->attachments([$file])->runInline();
```

### 2. Docs — `documentation/ai-generator.md`

A new page with a short introduction to the headless generator and a **Reusable
generators** section:

- subclass `AiGenerator` and configure it in `setUp()`;
- get a fresh instance per run: `make()`, or `bind()` in the container — **not**
  `singleton()`;
- why: under `queue:work` / Octane a singleton survives across jobs, so per-run state
  (`attachments`, `sourceRecords`, `userInput`, …) set by one run can leak into the next;
- reusing an instance you do not change is fine.

The full headless docs (batch + queued surface) stay deferred, as in `status.md`. The
README's documentation list links the new page.

### 3. CHANGELOG

*Unreleased → Added:* the `setUp()` hook for reusable `AiGenerator` subclasses. No
breaking change, no upgrade note.

## Testing

In `tests/Feature/Generation/AiGeneratorTest.php` (or a sibling file), with a test-local
subclass:

- `setUp()` runs via `make()`, via `new`, and via `app()` resolution;
- the subclass's configuration (prompt, schema) reaches the AI call;
- a call-site setter overrides a `setUp()` default;
- a plain `AiGenerator::make()` behaves exactly as before (empty hook).

## Out of scope

- A subclass-aware `InvoiceExtractor::fake()` — still the deferred service-native fake
  (spec 37, option 2).
- A single-use guard or run-state reset — rejected above.
- Rebuilding a generator in the worker from its class name (would let closure prompts and
  closure sanitizers be queued). A possible later use of this pattern; today's
  `BatchRunConfig` snapshot already serves the queue.

## Effect on sequencing

Spec 41 §7 step 1 shrinks to this spec. Next: spec 42 §8 steps 1–4 and/or the
`AiFormAction` → service routing (its known gaps in `status.md` still apply — the form fake
bypass first). Routing no longer waits for a new shape.
