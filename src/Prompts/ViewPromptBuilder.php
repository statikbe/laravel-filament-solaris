<?php

namespace Statikbe\FilamentSolaris\Prompts;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

class ViewPromptBuilder extends AbstractPromptBuilder
{
    /**
     * {@inheritDoc}
     */
    public function build(
        string|View $instruction,
        array $sourceData,
        array $factories,
        ?Model $record = null,
        ?string $locale = null,
        array $userInput = [],
    ): string {
        $data = $this->buildViewData($instruction, $sourceData, $factories, $record, $locale, $userInput);

        // Remove instruction from view data — the instruction IS the view
        unset($data['instruction']);

        if ($instruction instanceof View) {
            return $instruction->with($data)->render();
        }

        // If a string is passed, treat it as a view name
        return view($instruction, $data)->render();
    }
}
