<?php

namespace Statikbe\FilamentSolaris\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use Statikbe\FilamentSolaris\Actions\DictationToolbarAction;

/**
 * Transcript-only fake for {@see DictationToolbarAction}.
 *
 * The toolbar action has no AI pipeline (pure transcription → cursor insert),
 * so unlike {@see DictationFieldActionFake} there is no `aiResponse` / coupled
 * `AiActionFake` activation — only a canned transcript and call recording.
 */
class DictationToolbarActionFake
{
    protected static ?self $instance = null;

    protected string $transcript = '';

    /**
     * @var array<int, array{name: string, transcript: string}>
     */
    protected array $calls = [];

    public static function activate(string $transcript = ''): static
    {
        static::$instance = new static;
        static::$instance->transcript = $transcript;

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

    public function getTranscript(): string
    {
        return $this->transcript;
    }

    public function recordCall(string $actionName, string $transcript): void
    {
        $this->calls[] = [
            'name' => $actionName,
            'transcript' => $transcript,
        ];
    }

    public function assertCalled(): void
    {
        Assert::assertNotEmpty(
            $this->calls,
            'Expected the dictation toolbar action to be called, but it was not.'
        );
    }

    public function assertNotCalled(): void
    {
        Assert::assertEmpty(
            $this->calls,
            'Expected no dictation toolbar action call, but '.count($this->calls).' were recorded.'
        );
    }

    public function assertTranscribed(): void
    {
        $this->assertCalled();

        $last = end($this->calls);
        Assert::assertNotEmpty(
            $last['transcript'],
            'Expected a transcription to be processed, but the transcript was empty.'
        );
    }

    public function assertTranscribedWith(Closure $callback): void
    {
        $this->assertCalled();

        $last = end($this->calls);
        $callback($last['transcript']);
    }

    public function assertCalledTimes(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->calls,
            "Expected the dictation toolbar action to be called {$count} times, but it was called ".count($this->calls).' times.'
        );
    }
}
