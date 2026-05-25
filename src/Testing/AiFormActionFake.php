<?php

namespace Statikbe\FilamentSolaris\Testing;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\File;
use PHPUnit\Framework\Assert;

class AiFormActionFake
{
    protected static ?self $instance = null;

    /**
     * @var array<string, mixed>
     */
    protected array $defaultResponse = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $actionResponses = [];

    /**
     * @var array<int, array{name: string, sourceData: array, prompt: string, provider: Lab|array|string|null, model: ?string, timeout: ?int, options: array{temperature: ?float, max_tokens: ?int, max_steps: ?int, top_p: ?float}, attachments: array<File>}>
     */
    protected array $calls = [];

    protected ?string $errorMessage = null;

    protected bool $simulateTimeout = false;

    protected bool $partialMode = false;

    protected ?string $currentActionName = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $refinementResponses = [];

    /**
     * @var array<int, array{name: string, message: string}>
     */
    protected array $refinementCalls = [];

    /**
     * Activate the fake with a default response.
     *
     * @param  array<string, mixed>  $defaultResponse
     */
    public static function activate(array $defaultResponse = []): static
    {
        static::$instance = new static;
        static::$instance->defaultResponse = $defaultResponse;

        return static::$instance;
    }

    /**
     * Activate with an error simulation.
     */
    public static function activateError(string $message = 'AI service error'): static
    {
        static::$instance = new static;
        static::$instance->errorMessage = $message;

        return static::$instance;
    }

    /**
     * Activate with a timeout simulation.
     */
    public static function activateTimeout(): static
    {
        static::$instance = new static;
        static::$instance->simulateTimeout = true;

        return static::$instance;
    }

    /**
     * Activate with a partial failure simulation.
     *
     * @param  array<string, mixed|null>  $response
     */
    public static function activatePartial(array $response): static
    {
        static::$instance = new static;
        static::$instance->defaultResponse = $response;
        static::$instance->partialMode = true;

        return static::$instance;
    }

    /**
     * Reset the fake state.
     */
    public static function reset(): void
    {
        static::$instance = null;
    }

    /**
     * Check if the fake is active.
     */
    public static function isActive(): bool
    {
        return static::$instance !== null;
    }

    /**
     * Get the current instance.
     */
    public static function getInstance(): self
    {
        if (static::$instance === null) {
            static::$instance = new self;
        }

        return static::$instance;
    }

    /**
     * Register a fake for a specific action name.
     */
    public function forAction(string $actionName): static
    {
        if (! empty($this->defaultResponse)) {
            $this->actionResponses[$actionName] = $this->defaultResponse;
        }

        $this->currentActionName = $actionName;

        return $this;
    }

    /**
     * Resolve the response for a given action name.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $actionName): ?array
    {
        return $this->actionResponses[$actionName] ?? $this->defaultResponse;
    }

    /**
     * Record a call to an AI action.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     * @param  array{temperature?: ?float, max_tokens?: ?int, max_steps?: ?int, top_p?: ?float}  $options
     * @param  array<File>  $attachments
     */
    public function recordCall(string $actionName, array $sourceData, string $prompt, Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null, array $options = [], array $attachments = []): void
    {
        $this->calls[] = [
            'name' => $actionName,
            'sourceData' => $sourceData,
            'prompt' => $prompt,
            'provider' => $provider,
            'model' => $model,
            'timeout' => $timeout,
            'options' => [
                'temperature' => $options['temperature'] ?? null,
                'max_tokens' => $options['max_tokens'] ?? null,
                'max_steps' => $options['max_steps'] ?? null,
                'top_p' => $options['top_p'] ?? null,
            ],
            'attachments' => $attachments,
        ];
    }

    /**
     * Check if the fake should simulate an error.
     */
    public function shouldSimulateError(): bool
    {
        return $this->errorMessage !== null;
    }

    /**
     * Get the error message for simulation.
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage ?? 'AI service error';
    }

    /**
     * Check if the fake should simulate a timeout.
     */
    public function shouldSimulateTimeout(): bool
    {
        return $this->simulateTimeout;
    }

    /**
     * Queue a response for a refinement turn.
     *
     * @param  array<string, mixed>  $response
     */
    public function fakeRefinement(array $response): static
    {
        $this->refinementResponses[] = $response;

        return $this;
    }

    /**
     * Resolve the response for a refinement call.
     *
     * @return array<string, mixed>|null
     */
    public function resolveRefinement(string $actionName): ?array
    {
        if (! empty($this->refinementResponses)) {
            return array_shift($this->refinementResponses);
        }

        return $this->resolve($actionName);
    }

    /**
     * Record a refinement call.
     *
     * @param  array<int, mixed>  $attachments
     */
    public function recordRefinementCall(string $actionName, string $message, array $attachments = []): void
    {
        $this->refinementCalls[] = [
            'name' => $actionName,
            'message' => $message,
            'attachments' => $attachments,
        ];
    }

    /**
     * Assert that at least one AI action was called.
     */
    public function assertCalled(): void
    {
        Assert::assertNotEmpty(
            $this->calls,
            'Expected an AiFormAction to be called, but none was.'
        );
    }

    /**
     * Assert with a callback on call data.
     */
    public function assertCalledWith(\Closure $callback): void
    {
        $this->assertCalled();

        $lastCall = end($this->calls);
        $callback(
            $lastCall['sourceData'],
            $lastCall['prompt'],
            $lastCall['provider'] ?? null,
            $lastCall['model'] ?? null,
            $lastCall['timeout'] ?? null,
            $lastCall['options'] ?? [
                'temperature' => null,
                'max_tokens' => null,
                'max_steps' => null,
                'top_p' => null,
            ],
            $lastCall['attachments'] ?? [],
        );
    }

    /**
     * Assert that no AI action was called.
     */
    public function assertNotCalled(): void
    {
        Assert::assertEmpty(
            $this->calls,
            'Expected no AiFormAction to be called, but '.count($this->calls).' were.'
        );
    }

    /**
     * Assert the number of calls.
     */
    public function assertCalledTimes(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->calls,
            "Expected AiFormAction to be called {$count} times, but was called ".count($this->calls).' times.'
        );
    }

    /**
     * Assert that at least one refinement was made.
     */
    public function assertRefined(): void
    {
        Assert::assertNotEmpty(
            $this->refinementCalls,
            'Expected a refinement call, but none was made.'
        );
    }

    /**
     * Assert with a callback on the last refinement call.
     */
    public function assertRefinedWith(\Closure $callback): void
    {
        $this->assertRefined();

        $lastCall = end($this->refinementCalls);
        $callback($lastCall['message'], $lastCall['attachments'] ?? []);
    }

    /**
     * Assert the number of refinement calls.
     */
    public function assertRefinedTimes(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->refinementCalls,
            "Expected {$count} refinement calls, but got ".count($this->refinementCalls).'.'
        );
    }
}
