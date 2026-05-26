<?php

namespace Statikbe\FilamentSolaris\Testing;

use Closure;
use PHPUnit\Framework\Assert;

class AiGenerateActionFake
{
    protected static ?self $instance = null;

    /** @var array<string, mixed> */
    protected array $response = [];

    /** @var array<int, array<string, mixed>>|null */
    protected ?array $responseQueue = null;

    protected ?string $errorMessage = null;

    /** @var array<int, array{name: string, data: array<string, mixed>}> */
    protected array $calls = [];

    /**
     * @param  array<string, mixed>  $response
     */
    public static function activate(array $response = []): static
    {
        static::$instance = new static;
        static::$instance->response = $response;

        return static::$instance;
    }

    /**
     * Activate the fake with a queue of responses, one consumed per call.
     * Throws when exhausted (catches a test bug — too few canned responses).
     *
     * @param  array<int, array<string, mixed>>  $responses
     */
    public static function fakeEach(array $responses): static
    {
        static::$instance = new static;
        static::$instance->responseQueue = $responses;

        return static::$instance;
    }

    public static function activateError(string $message = 'AI service error'): static
    {
        static::$instance = new static;
        static::$instance->errorMessage = $message;

        return static::$instance;
    }

    public static function reset(): void
    {
        static::$instance = null;
    }

    public static function isActive(): bool
    {
        return static::$instance !== null;
    }

    public static function getInstance(): self
    {
        if (static::$instance === null) {
            static::$instance = new self;
        }

        return static::$instance;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponse(): array
    {
        if ($this->responseQueue === null) {
            return $this->response;
        }

        if ($this->responseQueue === []) {
            throw new AiGenerateActionFakeException('AiGenerateAction fakeEach queue exhausted — provide more responses for the per-row loop.');
        }

        return array_shift($this->responseQueue);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordCall(string $actionName, array $data): void
    {
        $this->calls[] = ['name' => $actionName, 'data' => $data];
    }

    public function shouldSimulateError(): bool
    {
        return $this->errorMessage !== null;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage ?? 'AI service error';
    }

    public function assertCalled(): void
    {
        Assert::assertNotEmpty($this->calls, 'Expected an AiGenerateAction to be called, but none was.');
    }

    public function assertNotCalled(): void
    {
        Assert::assertEmpty($this->calls, 'Expected no AiGenerateAction call, but '.count($this->calls).' were recorded.');
    }

    public function assertCalledTimes(int $count): void
    {
        Assert::assertCount($count, $this->calls, "Expected {$count} AiGenerateAction calls, got ".count($this->calls).'.');
    }

    public function assertHandledWith(Closure $callback): void
    {
        $this->assertCalled();

        $last = end($this->calls);
        $callback($last['data']);
    }
}
