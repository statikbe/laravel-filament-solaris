<?php

namespace Statikbe\FilamentSolaris\Prompts;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

class InlinePromptBuilder extends AbstractPromptBuilder
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

        /** @var view-string $viewName */
        $viewName = 'filament-solaris::prompts.base-wrapper';

        return view($viewName, $data)->render();
    }
}
