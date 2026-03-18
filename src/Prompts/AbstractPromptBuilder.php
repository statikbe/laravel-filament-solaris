<?php

namespace Statikbe\FilamentSolaris\Prompts;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory;
use Statikbe\FilamentSolaris\Contracts\PromptBuilder;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Support\UserInput;

abstract class AbstractPromptBuilder implements PromptBuilder
{
    /**
     * {@inheritDoc}
     */
    public function defaultUserInput(): ?UserInput
    {
        return null;
    }

    /**
     * Resolve locale code to a human-readable language name for AI prompts.
     *
     * Always resolves in English since this is used in prompt instructions.
     * Returns null for English or unresolvable locales.
     */
    protected function resolveLocaleName(?string $locale): ?string
    {
        if ($locale === null || $locale === 'en') {
            return null;
        }

        $name = FilamentSolaris::config()->resolveLocaleName($locale, 'en');

        return $name !== $locale ? $name : null;
    }

    /**
     * Build the standard view data array shared across all prompt builders.
     *
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, ComponentFactory>  $factories
     * @param  array<string, mixed>  $userInput
     * @return array<string, mixed>
     */
    protected function buildViewData(
        string|View $instruction,
        array $sourceData,
        array $factories,
        ?Model $record = null,
        ?string $locale = null,
        array $userInput = [],
    ): array {
        $schema = new JsonSchemaTypeFactory;

        return [
            'instruction' => $instruction,
            'sourceData' => $sourceData,
            'factories' => $factories,
            'responseSchema' => collect($factories)
                ->mapWithKeys(fn ($factory, $field) => [$field => $factory->responseSchema($schema)->toArray()])
                ->all(),
            'record' => $record,
            'locale' => $locale,
            'localeName' => $this->resolveLocaleName($locale),
            'userInput' => $userInput,
        ];
    }
}
