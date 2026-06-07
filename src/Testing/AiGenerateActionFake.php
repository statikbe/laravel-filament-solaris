<?php

namespace Statikbe\FilamentSolaris\Testing;

use Closure;
use Laravel\Ai\Files\File;
use PHPUnit\Framework\Assert;

class AiGenerateActionFake
{
    protected static ?self $instance = null;

    /** @var array<string, mixed> */
    protected array $response = [];

    /** @var array<int, array<string, mixed>>|null */
    protected ?array $responseQueue = null;

    protected ?string $errorMessage = null;

    /** @var array<int, array{name: string, data: array<string, mixed>, userInput: array<string, mixed>, attachments: array<int, File>, batch: array<int, array<string, mixed>>}> */
    protected array $calls = [];

    protected bool $handlerCalled = false;

    protected mixed $handlerPayload = null;

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
            throw new AiGenerateActionFakeException('AiGenerateAction fakeEach queue exhausted — provide one canned response per AI call (one per batch in the records loop).');
        }

        return array_shift($this->responseQueue);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $userInput
     * @param  array<int, File>  $attachments
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function recordCall(string $actionName, array $data, array $userInput = [], array $attachments = [], array $batch = []): void
    {
        $this->calls[] = [
            'name' => $actionName,
            'data' => $data,
            'userInput' => $userInput,
            'attachments' => $attachments,
            'batch' => $batch,
        ];
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

    /**
     * Assert that at least one recorded call's $userInput satisfies the callback.
     *
     * @param  Closure(array<string, mixed>): bool  $callback
     */
    public function assertCalledWithUserInput(Closure $callback): void
    {
        Assert::assertNotEmpty($this->calls, 'Expected an AiGenerateAction call with userInput, but none was recorded.');

        foreach ($this->calls as $call) {
            if ($callback($call['userInput']) === true) {
                return;
            }
        }

        Assert::fail('No AiGenerateAction call matched the userInput callback.');
    }

    /**
     * Assert that at least one recorded call's $attachments satisfies the callback.
     *
     * @param  Closure(array<int, File>): bool  $callback
     */
    public function assertCalledWithAttachments(Closure $callback): void
    {
        Assert::assertNotEmpty($this->calls, 'Expected an AiGenerateAction to be called, but none was.');

        foreach ($this->calls as $call) {
            if ($callback($call['attachments']) === true) {
                return;
            }
        }

        Assert::fail('No AiGenerateAction call matched the attachments callback.');
    }

    /**
     * Assert that at least one recorded call's $batch satisfies the callback.
     *
     * @param  Closure(array<int, array<string, mixed>>): bool  $callback
     */
    public function assertCalledWithBatch(Closure $callback): void
    {
        Assert::assertNotEmpty($this->calls, 'Expected an AiGenerateAction to be called, but none was.');

        foreach ($this->calls as $call) {
            if ($callback($call['batch']) === true) {
                return;
            }
        }

        Assert::fail('No AiGenerateAction call matched the batch callback.');
    }

    /**
     * Record the exact value handed to the ->handleUsing() closure — a raw array
     * in custom-schema mode, or a BatchResponse in forModel mode — so
     * assertHandledWith reflects what the handler actually received.
     */
    public function recordHandlerCall(mixed $payload): void
    {
        $this->handlerCalled = true;
        $this->handlerPayload = $payload;
    }

    /**
     * Assert the ->handleUsing() handler ran, and inspect the exact value it
     * received (raw array in custom-schema mode, BatchResponse in forModel mode).
     * Fails if no handler ran — e.g. on a ->createRecords()/->updateRecords() run.
     */
    public function assertHandledWith(Closure $callback): void
    {
        Assert::assertTrue(
            $this->handlerCalled,
            'Expected the ->handleUsing() handler to run, but it did not (->createRecords()/->updateRecords() do not invoke a handler).',
        );

        $callback($this->handlerPayload);
    }
}
