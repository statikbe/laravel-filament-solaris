<?php

namespace Statikbe\FilamentSolaris\Prompts\Presets;

use Closure;
use Statikbe\FilamentSolaris\Enums\Tone;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

class SummaryPreset extends Preset
{
    protected int|Closure $maxWords = 200;

    protected Tone|string|Closure|null $tone = null;

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
     *
     * Accepts a Tone enum or any string for custom tones.
     */
    public function tone(Tone|string|Closure $tone): static
    {
        $this->tone = $tone;

        return $this;
    }

    public function getTone(): string
    {
        $tone = value($this->tone) ?? FilamentSolaris::config()->getDefaultTone();

        return $tone instanceof Tone ? $tone->value : $tone;
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
            'language' => $this->resolveLanguageName($this->getLanguage()),
        ];
    }
}
