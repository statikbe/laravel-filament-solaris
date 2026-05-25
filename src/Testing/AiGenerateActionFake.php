<?php

namespace Statikbe\FilamentSolaris\Testing;

use Closure;
use PHPUnit\Framework\Assert;

class AiGenerateActionFake
{
    protected static ?self $instance = null;

    /** @var array<string, mixed> */
    protected array $response = [];

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
        return $this->response;
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
