<?php

namespace Statikbe\FilamentSolaris\Generation;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Statikbe\FilamentSolaris\Agents\SolarisAgent;
use Statikbe\FilamentSolaris\Events\SolarisResponseFailed;
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;
use Statikbe\FilamentSolaris\Support\GenerationOptions;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;

/**
 * Headless, Filament-free service that performs a single structured AI call.
 *
 * Build it fluently and call {@see runInline()} to get a {@see GenerationResult}.
 * Usable from jobs, listeners, commands, and chainable (feed one result's
 * ->data into the next builder). Filament actions configure it from their own
 * resolved values; headless callers configure it directly.
 *
 * Unlike the action wrapper it throws {@see AiException} on failure instead of
 * sending a UI notification — error presentation is the caller's concern.
 */
class AiGenerator
{
    protected string $prompt = '';

    /** @var ?Closure(JsonSchemaTypeFactory): array<string, Type> */
    protected ?Closure $schemaResolver = null;

    /** @var Lab|array<int|string, string>|string|null */
    protected Lab|array|string|null $provider = null;

    protected ?string $model = null;

    protected ?int $timeout = null;

    protected GenerationOptions $options;

    /** @var array<int, File> */
    protected array $attachments = [];

    /** @var ?iterable<mixed> */
    protected ?iterable $tools = null;

    protected string $sourceName = 'ai-generator';

    /** @var class-string */
    protected string $sourceClass = self::class;

    protected ?object $livewire = null;

    protected bool $hasUser = false;

    protected ?Authenticatable $user = null;

    public function __construct()
    {
        $this->options = new GenerationOptions;
    }

    public static function make(): static
    {
        return new static;
    }

    public function prompt(string $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    /**
     * @param  Closure(JsonSchemaTypeFactory): array<string, Type>  $resolver
     */
    public function schema(Closure $resolver): static
    {
        $this->schemaResolver = $resolver;

        return $this;
    }

    /**
     * @param  Lab|array<int|string, string>|string|null  $provider
     */
    public function provider(Lab|array|string|null $provider, ?string $model = null): static
    {
        $this->provider = $provider;
        $this->model = $model;

        return $this;
    }

    public function timeout(?int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function options(GenerationOptions $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @param  array<int, File>  $attachments
     */
    public function attachments(array $attachments): static
    {
        $this->attachments = $attachments;

        return $this;
    }

    /**
     * @param  ?iterable<mixed>  $tools
     */
    public function tools(?iterable $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * @param  class-string|null  $sourceClass
     */
    public function source(string $name, ?string $sourceClass = null): static
    {
        $this->sourceName = $name;
        $this->sourceClass = $sourceClass ?? $this->sourceClass;

        return $this;
    }

    public function forLivewire(?object $livewire): static
    {
        $this->livewire = $livewire;

        return $this;
    }

    public function forUser(?Authenticatable $user): static
    {
        $this->hasUser = true;
        $this->user = $user;

        return $this;
    }

    public function runInline(): GenerationResult
    {
        $agent = (new SolarisAgent)->configure($this->prompt, [], $this->schemaResolver);

        if ($this->tools !== null) {
            $agent->withTools($this->tools);
        }

        $this->options->applyTo($agent);

        $user = $this->hasUser ? $this->user : auth()->user();
        $startedAt = microtime(true);

        // NOTE: the timing + event-dispatch shape below intentionally mirrors
        // SolarisAction::executeAiCall(). They differ in error presentation
        // (this throws; the action notifies), so they stay separate until the
        // action AI calls migrate onto this service and a shared executor pays off.
        try {
            /** @var StructuredAgentResponse $response */
            $response = $agent->prompt($this->prompt, $this->attachments, $this->provider, $this->model, $this->timeout);
        } catch (\Throwable $original) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $e = $original instanceof AiException
                ? $original
                : new AiException($original->getMessage(), (int) $original->getCode(), $original);

            SolarisResponseFailed::dispatch(
                $this->sourceName,
                $this->sourceClass,
                $e,
                $this->provider,
                $this->model,
                $durationMs,
                $user,
                $this->livewire,
            );

            throw $e;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $usage = $response->usage;

        SolarisResponseReceived::dispatch(
            $this->sourceName,
            $this->sourceClass,
            $usage,
            $this->provider,
            $this->model,
            $durationMs,
            $user,
            $this->livewire,
        );

        SolarisPromptLogger::logUsage($this->sourceName, $usage, $this->provider, $this->model, $durationMs);

        return new GenerationResult(
            data: $response->toArray(),
            usage: $usage,
            conversationId: $response->conversationId,
            response: $response,
        );
    }
}
