<?php

namespace Statikbe\FilamentSolaris\Prompts\Presets;

use Closure;
use Filament\Forms\Components\Select;
use RuntimeException;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Support\UserInput;

class TranslationPreset extends Preset
{
    protected string|Closure|null $language = null;

    protected bool $preserveFormatting = true;

    protected string|Closure|null $glossary = null;

    /**
     * Set the target language for translation.
     */
    public function language(string|Closure $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getLanguage(): string
    {
        $language = value($this->language);

        if ($language === null) {
            throw new RuntimeException(
                "TranslationPreset requires a target language. Call ->language('fr') before using."
            );
        }

        return $language;
    }

    /**
     * Set whether to preserve HTML/Markdown formatting.
     */
    public function preserveFormatting(bool $preserveFormatting = true): static
    {
        $this->preserveFormatting = $preserveFormatting;

        return $this;
    }

    /**
     * Set domain-specific glossary or terminology notes.
     */
    public function glossary(string|Closure $glossary): static
    {
        $this->glossary = $glossary;

        return $this;
    }

    public function getGlossary(): ?string
    {
        return value($this->glossary);
    }

    /**
     * {@inheritDoc}
     */
    public function defaultUserInput(): ?UserInput
    {
        return UserInput::make()
            ->fields([
                Select::make('language')
                    ->label(filament_solaris_trans('presets.translate.label'))
                    ->options($this->availableLanguages())
                    ->required(),
            ]);
    }

    /**
     * {@inheritDoc}
     */
    protected function promptView(): string
    {
        return 'filament-solaris::prompts.translate';
    }

    /**
     * {@inheritDoc}
     */
    protected function viewData(): array
    {
        return [
            'language' => $this->resolveLanguageName($this->getLanguage()),
            'preserveFormatting' => $this->preserveFormatting,
            'glossary' => $this->getGlossary(),
        ];
    }

    /**
     * Get available languages for the default user input.
     *
     * @return array<string, string>
     */
    protected function availableLanguages(): array
    {
        return collect(FilamentSolaris::config()->getSupportedLocaleOptions())
            ->sort()
            ->all();
    }
}
