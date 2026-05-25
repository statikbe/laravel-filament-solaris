<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Filament\Actions\Action;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Statikbe\FilamentSolaris\Concerns\HasAttachments;
use Statikbe\FilamentSolaris\Concerns\HasConversational;
use Statikbe\FilamentSolaris\Concerns\HasPreviewModal;
use Statikbe\FilamentSolaris\Concerns\InteractsWithSolarisPreview;
use Statikbe\FilamentSolaris\Events\SolarisResponseFailed;
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;
use Statikbe\FilamentSolaris\FilamentSolarisPlugin;
use Statikbe\FilamentSolaris\Support\SolarisNotification;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;

/**
 * Abstract base for every Solaris action.
 *
 * Owns the form-agnostic AI plumbing: provider/model/timeout state and
 * their fluent setters, the AiException-aware call wrapper, the preview-modal
 * toggle, and the shared attachments / conversational / modal traits.
 *
 * Concrete actions (AiFormAction, ImageGenerationAction, DictationFieldAction)
 * extend this and mix in pipeline-specific concerns. Future non-form actions
 * (data importers, report generators) can also extend it without inheriting
 * any form-field assumptions — those live in {@see HasFormPipeline}.
 */
abstract class SolarisAction extends Action
{
    use HasAttachments;
    use HasConversational;
    use HasPreviewModal;

    /**
     * @var Lab|array<string, string>|array<int, string>|string|Closure|null
     */
    protected Lab|array|string|Closure|null $provider = null;

    protected string|Closure|null $model = null;

    protected int|Closure|null $timeout = null;

    /**
     * Whether the preview modal is enabled for this action.
     */
    protected bool $preview = false;

    /**
     * Register the panel-level visibility gate.
     *
     * Filament evaluates `hidden` and `visible` independently — an action
     * is shown only when neither says hide — so using `->hidden(...)` here
     * makes the panel gate a hard AND with whatever the consumer sets via
     * `->visible(...)` on their action. Consumers can't accidentally
     * bypass {@see FilamentSolarisPlugin::disabled()}.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->hidden(static fn (): bool => ! static::isAllowedInCurrentPanel());
    }

    /**
     * Whether Solaris actions are allowed on the current Filament panel.
     *
     * Returns true (allow) when no panel context, no plugin registered, or
     * the plugin's visibility predicate evaluates true. Returns false only
     * when a plugin is registered AND its `->visible(...)` predicate
     * resolves false (e.g. via `->disabled()`).
     */
    public static function isAllowedInCurrentPanel(): bool
    {
        return FilamentSolarisPlugin::current()?->isVisible() ?? true;
    }

    /**
     * Set the AI provider (and optionally model) for this action.
     *
     * @param  Lab|array<string, string>|array<int, string>|string|Closure  $provider
     */
    public function provider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static
    {
        $this->provider = $provider;

        if ($model !== null) {
            $this->model = $model;
        }

        return $this;
    }

    /**
     * Set the timeout in seconds for the AI call.
     */
    public function timeout(int|Closure $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Enable or disable the preview modal for this action.
     */
    public function withPreview(bool $preview = true): static
    {
        $this->preview = $preview;

        if ($preview) {
            $this->modal(true);
        }

        return $this;
    }

    /**
     * Whether the preview modal is enabled.
     */
    public function shouldPreview(): bool
    {
        return $this->preview;
    }

    /**
     * Verify the host Livewire component is wired for preview mode.
     *
     * `->withPreview()` (and `->conversational()`, which implies it) needs
     * the {@see InteractsWithSolarisPreview}
     * trait on the owning Livewire component — the trait provides the
     * `solarisPreviewData` property + the `acceptSolarisPreview()` /
     * `refineSolaris()` Livewire methods that the preview modal and
     * conversational chat UI dispatch to.
     *
     * Called by each concrete action's execute path **before** any AI side
     * effect, so misconfiguration fails loud at the first action invocation
     * rather than silently no-op'ing.
     *
     * @throws \RuntimeException when the trait is missing.
     */
    protected function validatePreviewConfiguration(): void
    {
        if (! $this->shouldPreview()) {
            return;
        }

        $livewire = $this->getLivewire();

        if (in_array(InteractsWithSolarisPreview::class, class_uses_recursive($livewire), true)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Solaris action "%s" has withPreview() (or conversational()) enabled, but its Livewire component (%s) is missing the InteractsWithSolarisPreview trait. Add `use \\Statikbe\\FilamentSolaris\\Concerns\\InteractsWithSolarisPreview;` to the component class — the trait provides the `solarisPreviewData` property and the `acceptSolarisPreview()` / `refineSolaris()` methods the preview modal dispatches to.',
            $this->getName(),
            $livewire::class,
        ));
    }

    /**
     * Resolve the action-level provider override, if any.
     *
     * Returns the resolved provider+model when the action's own `provider()`
     * was set; null otherwise so pipeline-specific resolution can fall
     * through to its preset / config layers.
     *
     * @return array{provider: Lab|array|string|null, model: ?string}|null
     */
    protected function resolveActionProvider(): ?array
    {
        $provider = $this->evaluate($this->provider);

        if ($provider === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'model' => $this->evaluate($this->model),
        ];
    }

    /**
     * Resolve the action-level timeout override, if any.
     */
    protected function resolveActionTimeout(): ?int
    {
        return $this->evaluate($this->timeout);
    }

    /**
     * Execute an AI call with standardized AiException handling and
     * usage-tracking event dispatch.
     *
     * On success, dispatches {@see SolarisResponseReceived} when the
     * response carries a Usage object and routes the same data through
     * {@see SolarisPromptLogger::logUsage()} for development visibility.
     *
     * On AiException, dispatches {@see SolarisResponseFailed} before
     * sending the user-facing error notification. Returns null so the
     * caller can short-circuit.
     *
     * Returns `mixed` because laravel/ai response types don't share a
     * common ancestor — text returns AgentResponse, image returns
     * ImageResponse, transcription returns TranscriptionResponse.
     * Callers narrow the type at the call site.
     *
     * @template TResponse
     *
     * @param  Closure(): TResponse  $callback
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider  resolved provider for context (event + log)
     * @param  ?Closure(AiException): void  $onError  custom error-notification handler; falls back to the generic Solaris error notification
     * @return TResponse|null
     */
    protected function executeAiCall(
        Closure $callback,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?Closure $onError = null,
    ): mixed {
        $startedAt = microtime(true);

        try {
            $response = $callback();
        } catch (\Throwable $original) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            // Catch every Throwable, not just AiException — providers can leak
            // raw `Illuminate\Http\Client\RequestException` (e.g. Mistral's
            // 422 on an unsupported language code) past laravel/ai's failover
            // wrapper. Without this, those bubble up as a generic 500 and the
            // user-facing notification never fires.
            //
            // Non-AiException is wrapped so the dispatched event + $onError
            // contract stay typed; the original is preserved as previous() so
            // the exception tracker keeps the real stack trace.
            $e = $original instanceof AiException
                ? $original
                : new AiException($original->getMessage(), $original->getCode(), $original);

            $this->dispatchResponseFailed($e, $provider, $model, $durationMs);

            if ($onError !== null) {
                $onError($e);
            } else {
                SolarisNotification::sendAiErrorNotification($e);
            }

            return null;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $usage = $this->extractUsage($response);

        if ($usage !== null) {
            $this->dispatchResponseReceived($usage, $provider, $model, $durationMs);
        }

        return $response;
    }

    /**
     * Dispatch the success event + route the same data to the prompt logger.
     *
     * Used by {@see executeAiCall()} on the real path and called directly
     * by the fake pipelines so consumer-side listeners fire under
     * `AiFormAction::fake()` / `ImageGenerationAction::fake()` /
     * `DictationFieldAction::fake()` too. Fakes pass a zero-token Usage by
     * default since they bypass the real model.
     *
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     */
    protected function dispatchResponseReceived(
        Usage $usage,
        Lab|array|string|null $provider,
        ?string $model,
        int $durationMs,
    ): void {
        SolarisResponseReceived::dispatch(
            $this->getName(),
            static::class,
            $usage,
            $provider,
            $model,
            $durationMs,
            auth()->user(),
            $this->getLivewire(),
        );

        SolarisPromptLogger::logUsage($this->getName(), $usage, $provider, $model, $durationMs);
    }

    /**
     * Dispatch the failure event.
     *
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     */
    protected function dispatchResponseFailed(
        AiException $exception,
        Lab|array|string|null $provider,
        ?string $model,
        int $durationMs,
    ): void {
        SolarisResponseFailed::dispatch(
            $this->getName(),
            static::class,
            $exception,
            $provider,
            $model,
            $durationMs,
            auth()->user(),
            $this->getLivewire(),
        );
    }

    /**
     * Dispatch the success event for a fake pipeline call.
     *
     * Fakes bypass the real provider, so there is no Usage to extract and no
     * meaningful duration to measure. The synthetic zero-token Usage exists
     * solely so consumer listeners can be exercised under `::fake()` without
     * a real provider call.
     *
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     */
    protected function dispatchFakeResponseReceived(Lab|array|string|null $provider, ?string $model): void
    {
        $this->dispatchResponseReceived(new Usage, $provider, $model, 0);
    }

    /**
     * Dispatch the failure event for a fake pipeline error/timeout.
     *
     * Mirrors {@see dispatchFakeResponseReceived()} for the failure path:
     * synthesizes an AiException so listeners can assert against a real
     * exception type even when the fake bypassed the provider entirely.
     *
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     */
    protected function dispatchFakeResponseFailed(
        string $message,
        Lab|array|string|null $provider,
        ?string $model,
    ): void {
        $this->dispatchResponseFailed(new AiException($message), $provider, $model, 0);
    }

    /**
     * Extract the laravel/ai Usage object from a response, if it carries one.
     *
     * AgentResponse, ImageResponse, and TranscriptionResponse all expose
     * `->usage`. Anything else returns null and skips dispatch silently
     * so future response types can flow through without surprises.
     */
    private function extractUsage(mixed $response): ?Usage
    {
        return match (true) {
            $response instanceof AgentResponse => $response->usage,
            $response instanceof ImageResponse => $response->usage,
            $response instanceof TranscriptionResponse => $response->usage,
            default => null,
        };
    }

    /**
     * Accept the preview and apply the result.
     *
     * Concrete pipeline traits implement this — text writes form values,
     * image stores the generated image, future actions may run an import.
     *
     * @param  array<string, mixed>  $data  The preview data stored on the Livewire component
     */
    abstract public function acceptPreview(array $data): void;

    /**
     * Refine the preview with a conversational follow-up message.
     *
     * Concrete pipeline traits implement this — text uses the conversation
     * agent's continueLastConversation flow; image re-generates with feedback
     * appended to the prompt.
     *
     * @param  array<string, mixed>  $turnAttachments  Files attached to this turn only
     */
    abstract public function refine(string $message, array $turnAttachments = []): void;
}
