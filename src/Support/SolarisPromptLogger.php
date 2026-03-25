<?php

namespace Statikbe\FilamentSolaris\Support;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Psr\Log\LoggerInterface;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

class SolarisPromptLogger
{
    /**
     * Log the composed prompt and factory schema when prompt logging is enabled.
     *
     * @param  array<string, ComponentFactory>  $factories
     */
    public static function log(string $prompt, array $factories): void
    {
        if (! FilamentSolaris::config()->isPromptLoggingEnabled()) {
            return;
        }

        $schema = new JsonSchemaTypeFactory;
        $responseSchema = collect($factories)
            ->map(fn (ComponentFactory $factory): Type => $factory->responseSchema($schema))
            ->map(fn (Type $type): array => $type->toArray())
            ->all();

        static::logger()->debug('Filament Solaris — Prompt', [
            'prompt' => $prompt,
            'schema' => $responseSchema,
        ]);
    }

    /**
     * Log the actual agent schema sent to the LLM provider.
     */
    public static function logAgentSchema(Agent $agent): void
    {
        if (! FilamentSolaris::config()->isPromptLoggingEnabled()) {
            return;
        }

        if (! $agent instanceof HasStructuredOutput) {
            return;
        }

        $schema = new JsonSchemaTypeFactory;
        $agentSchema = collect($agent->schema($schema))
            ->map(fn (Type $type): array => $type->toArray())
            ->all();

        static::logger()->debug('Filament Solaris — Agent Schema', [
            'agent' => get_class($agent),
            'schema' => $agentSchema,
        ]);
    }

    /**
     * Log the AI response.
     *
     * @param  array<string, mixed>  $aiResponse
     */
    public static function logResponse(array $aiResponse, ?string $conversationId = null): void
    {
        if (! FilamentSolaris::config()->isPromptLoggingEnabled()) {
            return;
        }

        $context = ['response' => $aiResponse];

        if ($conversationId !== null) {
            $context['conversationId'] = $conversationId;
        }

        static::logger()->debug('Filament Solaris — Response', $context);
    }

    /**
     * Log an image generation prompt.
     */
    public static function logImagePrompt(string $prompt, ?string $size, ?string $quality, mixed $provider, ?string $model): void
    {
        if (! FilamentSolaris::config()->isPromptLoggingEnabled()) {
            return;
        }

        static::logger()->debug('Filament Solaris — Image Prompt', array_filter([
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'provider' => $provider instanceof \BackedEnum ? $provider->value : $provider,
            'model' => $model,
        ]));
    }

    /**
     * Log an image generation response.
     */
    public static function logImageResponse(int $imageCount, ?string $mime): void
    {
        if (! FilamentSolaris::config()->isPromptLoggingEnabled()) {
            return;
        }

        static::logger()->debug('Filament Solaris — Image Response', [
            'images' => $imageCount,
            'mime' => $mime,
        ]);
    }

    /**
     * Log the image target handler and result.
     */
    public static function logImageWrite(string $handler, string $targetField): void
    {
        if (! FilamentSolaris::config()->isPromptLoggingEnabled()) {
            return;
        }

        static::logger()->debug('Filament Solaris — Image Write', [
            'handler' => $handler,
            'targetField' => $targetField,
        ]);
    }

    /**
     * Resolve the logger instance.
     */
    protected static function logger(): LoggerInterface
    {
        return FilamentSolaris::getLogger()
            ?? Log::channel(FilamentSolaris::config()->getPromptLoggingChannel());
    }
}
