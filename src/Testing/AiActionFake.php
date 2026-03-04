<?php

namespace Statikbe\FilamentSolaris\Testing;

use PHPUnit\Framework\Assert;

class AiActionFake
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
     * @var array<int, array{name: string, sourceData: array, prompt: string}>
     */
    protected array $calls = [];

    protected ?string $errorMessage = null;

    protected bool $simulateTimeout = false;

    protected bool $partialMode = false;

    protected ?string $currentActionName = null;

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
     */
    public function recordCall(string $actionName, array $sourceData, string $prompt): void
    {
        $this->calls[] = [
            'name' => $actionName,
            'sourceData' => $sourceData,
            'prompt' => $prompt,
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
     * Assert that at least one AI action was called.
     */
    public function assertCalled(): void
    {
        Assert::assertNotEmpty(
            $this->calls,
            'Expected an AiAction to be called, but none was.'
        );
    }

    /**
     * Assert with a callback on call data.
     */
    public function assertCalledWith(\Closure $callback): void
    {
        $this->assertCalled();

        $lastCall = end($this->calls);
        $callback($lastCall['sourceData'], $lastCall['prompt']);
    }

    /**
     * Assert that no AI action was called.
     */
    public function assertNotCalled(): void
    {
        Assert::assertEmpty(
            $this->calls,
            'Expected no AiAction to be called, but '.count($this->calls).' were.'
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
            "Expected AiAction to be called {$count} times, but was called ".count($this->calls).' times.'
        );
    }
}
