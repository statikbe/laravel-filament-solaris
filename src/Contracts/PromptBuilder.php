<?php

namespace Statikbe\FilamentSolaris\Contracts;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentSolaris\Support\UserInput;

interface PromptBuilder
{
    /**
     * Compose the final prompt string for the AI agent.
     *
     * @param  string|View  $instruction  Developer's instruction
     * @param  array<string, mixed>  $sourceData  Source field values (after scopes)
     * @param  array<string, ComponentFactory>  $factories  Target field factories keyed by field name
     * @param  Model|null  $record  Current Eloquent model (null on create pages)
     * @param  string|null  $locale  Target locale (null = app locale)
     * @param  array<string, mixed>  $userInput  End-user's runtime input from modal
     * @return string The composed prompt
     */
    public function build(
        string|View $instruction,
        array $sourceData,
        array $factories,
        ?Model $record = null,
        ?string $locale = null,
        array $userInput = [],
    ): string;

    /**
     * Suggest default UserInput fields for this prompt builder.
     */
    public function defaultUserInput(): ?UserInput;
}
