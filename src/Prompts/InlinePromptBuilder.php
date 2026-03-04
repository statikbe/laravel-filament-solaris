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

        /** @var \Illuminate\Contracts\View\View $view */
        $view = view('filament-solaris::prompts.base-wrapper', $data);

        return $view->render();
    }
}
