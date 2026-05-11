<?php

namespace Statikbe\FilamentSolaris\Testing;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\File;
use PHPUnit\Framework\Assert;

class ImageGenerationActionFake
{
    protected static ?self $instance = null;

    protected string $storedPath = 'ai-images/fake-image.png';

    /**
     * @var array<int, array{name: string, prompt: string, size: ?string, quality: ?string, provider: Lab|array|string|null, model: ?string, timeout: ?int, disk: ?string, directory: string, attachments: array<File>}>
     */
    protected array $calls = [];

    /**
     * Activate the fake with an optional stored path.
     */
    public static function activate(string $storedPath = 'ai-images/fake-image.png'): static
    {
        static::$instance = new static;
        static::$instance->storedPath = $storedPath;

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
     * Get the fake stored path.
     */
    public function getStoredPath(): string
    {
        return $this->storedPath;
    }

    /**
     * Record a call to the image generation action.
     *
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     * @param  array<File>  $attachments
     */
    public function recordCall(
        string $actionName,
        string $prompt,
        ?string $size = null,
        ?string $quality = null,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
        ?string $disk = null,
        string $directory = 'ai-images',
        array $attachments = [],
    ): void {
        $this->calls[] = [
            'name' => $actionName,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'provider' => $provider,
            'model' => $model,
            'timeout' => $timeout,
            'disk' => $disk,
            'directory' => $directory,
            'attachments' => $attachments,
        ];
    }

    /**
     * Assert that at least one image generation action was called.
     */
    public function assertCalled(): void
    {
        Assert::assertNotEmpty(
            $this->calls,
            'Expected an ImageGenerationAction to be called, but none was.'
        );
    }

    /**
     * Assert with a callback on call data.
     *
     * Callback signature: (prompt, size, quality, provider, model, timeout, disk, directory, attachments).
     * Existing tests using fewer parameters continue to work since PHP closures
     * silently accept extra positional arguments.
     */
    public function assertCalledWith(\Closure $callback): void
    {
        $this->assertCalled();

        $lastCall = end($this->calls);
        $callback(
            $lastCall['prompt'],
            $lastCall['size'] ?? null,
            $lastCall['quality'] ?? null,
            $lastCall['provider'] ?? null,
            $lastCall['model'] ?? null,
            $lastCall['timeout'] ?? null,
            $lastCall['disk'] ?? null,
            $lastCall['directory'] ?? 'ai-images',
            $lastCall['attachments'] ?? [],
        );
    }

    /**
     * Assert that no image generation action was called.
     */
    public function assertNotCalled(): void
    {
        Assert::assertEmpty(
            $this->calls,
            'Expected no ImageGenerationAction to be called, but '.count($this->calls).' were.'
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
            "Expected ImageGenerationAction to be called {$count} times, but was called ".count($this->calls).' times.'
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Refinement
    // ──────────────────────────────────────────────────────────────

    /**
     * @var array<int, array{name: string, message: string, attachments: array<int, mixed>}>
     */
    protected array $refinementCalls = [];

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
