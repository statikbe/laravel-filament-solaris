<?php

namespace Statikbe\FilamentSolaris\Prompts\Presets;

use Closure;
use Statikbe\FilamentSolaris\FilamentSolarisConfig;

class SummaryPreset extends Preset
{
    protected int|Closure $maxWords = 200;

    protected string|Closure|null $tone = null;

    protected string|Closure|null $language = null;

    /**
     * Set the maximum word count for the summary.
     */
    public function maxWords(int|Closure $maxWords): static
    {
        $this->maxWords = $maxWords;

        return $this;
    }

    public function getMaxWords(): int
    {
        return value($this->maxWords);
    }

    /**
     * Set the tone of the summary.
     */
    public function tone(string|Closure $tone): static
    {
        $this->tone = $tone;

        return $this;
    }

    public function getTone(): string
    {
        return value($this->tone) ?? app(FilamentSolarisConfig::class)->getDefaultTone();
    }

    /**
     * Set the output language (overrides locale).
     */
    public function language(string|Closure $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return value($this->language);
    }

    /**
     * {@inheritDoc}
     */
    protected function promptView(): string
    {
        return 'filament-solaris::prompts.summarize';
    }

    /**
     * {@inheritDoc}
     */
    protected function viewData(): array
    {
        return [
            'maxWords' => $this->getMaxWords(),
            'tone' => $this->getTone(),
            'language' => $this->getLanguage(),
        ];
    }
}
