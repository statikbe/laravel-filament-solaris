<?php

namespace Statikbe\FilamentSolaris\Testing;

use PHPUnit\Framework\Assert;

class DictationFieldActionFake
{
    protected static ?self $instance = null;

    protected string $transcript = '';

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $aiResponse = null;

    protected bool $activatedAiFormActionFake = false;

    /**
     * @var array<int, array{name: string, transcript: string}>
     */
    protected array $calls = [];

    /**
     * Activate the fake with a predetermined transcript and optional AI response.
     *
     * @param  array<string, mixed>|null  $aiResponse
     */
    public static function activate(string $transcript = '', ?array $aiResponse = null): static
    {
        static::$instance = new static;
        static::$instance->transcript = $transcript;
        static::$instance->aiResponse = $aiResponse;

        // If AI processing is expected, also activate AiFormActionFake
        if ($aiResponse !== null) {
            AiFormActionFake::activate($aiResponse);
            static::$instance->activatedAiFormActionFake = true;
        }

        return static::$instance;
    }

    /**
     * Reset the fake state, including AiFormActionFake if it was activated by this fake.
     */
    public static function reset(): void
    {
        if (static::$instance?->activatedAiFormActionFake) {
            AiFormActionFake::reset();
        }

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
     * Get the predetermined transcript.
     */
    public function getTranscript(): string
    {
        return $this->transcript;
    }

    /**
     * Record a call to the dictation action.
     */
    public function recordCall(string $actionName, string $transcript): void
    {
        $this->calls[] = [
            'name' => $actionName,
            'transcript' => $transcript,
        ];
    }

    /**
     * Assert that at least one dictation action was called.
     */
    public function assertCalled(): void
    {
        Assert::assertNotEmpty(
            $this->calls,
            'Expected a dictation action to be called, but none was.'
        );
    }

    /**
     * Assert that no dictation action was called.
     */
    public function assertNotCalled(): void
    {
        Assert::assertEmpty(
            $this->calls,
            'Expected no dictation action to be called, but '.count($this->calls).' were.'
        );
    }

    /**
     * Assert that a transcription was processed.
     */
    public function assertTranscribed(): void
    {
        $this->assertCalled();

        $lastCall = end($this->calls);
        Assert::assertNotEmpty(
            $lastCall['transcript'],
            'Expected a transcription to be processed, but the transcript was empty.'
        );
    }

    /**
     * Assert the transcript with a callback.
     */
    public function assertTranscribedWith(\Closure $callback): void
    {
        $this->assertCalled();

        $lastCall = end($this->calls);
        $callback($lastCall['transcript']);
    }

    /**
     * Assert the number of calls.
     */
    public function assertCalledTimes(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->calls,
            "Expected dictation action to be called {$count} times, but was called ".count($this->calls).' times.'
        );
    }
}
