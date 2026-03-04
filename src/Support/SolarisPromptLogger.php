<?php

namespace Statikbe\FilamentSolaris\Support;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\FilamentSolarisConfig;

class SolarisPromptLogger
{
    /**
     * Log the composed prompt and response schema when prompt logging is enabled.
     *
     * @param  array<string, ComponentFactory>  $factories
     */
    public static function log(string $prompt, array $factories): void
    {
        if (! app(FilamentSolarisConfig::class)->isPromptLoggingEnabled()) {
            return;
        }

        $schema = new JsonSchemaTypeFactory;
        $responseSchema = collect($factories)
            ->map(fn (ComponentFactory $factory): Type => $factory->responseSchema($schema))
            ->map(fn (Type $type): array => $type->toArray())
            ->all();

        $logger = FilamentSolaris::getLogger()
            ?? Log::channel(app(FilamentSolarisConfig::class)->getPromptLoggingChannel());

        $logger->debug('Filament Solaris — Prompt', [
            'prompt' => $prompt,
            'schema' => $responseSchema,
        ]);
    }
}
