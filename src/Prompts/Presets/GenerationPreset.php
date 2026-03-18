<?php

namespace Statikbe\FilamentSolaris\Prompts\Presets;

use Closure;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Support\UserInput;

class GenerationPreset extends Preset
{
    protected string|Closure|null $tone = null;

    protected int|Closure|null $maxLength = null;

    protected string|Closure|null $style = null;

    protected string|Closure|null $audience = null;

    /**
     * Set the desired tone.
     */
    public function tone(string|Closure $tone): static
    {
        $this->tone = $tone;

        return $this;
    }

    public function getTone(): string
    {
        return value($this->tone) ?? FilamentSolaris::config()->getDefaultTone();
    }

    /**
     * Set the maximum length in words.
     */
    public function maxLength(int|Closure $maxLength): static
    {
        $this->maxLength = $maxLength;

        return $this;
    }

    public function getMaxLength(): ?int
    {
        return value($this->maxLength);
    }

    /**
     * Set the writing style.
     */
    public function style(string|Closure $style): static
    {
        $this->style = $style;

        return $this;
    }

    public function getStyle(): ?string
    {
        return value($this->style);
    }

    /**
     * Set the target audience.
     */
    public function audience(string|Closure $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    public function getAudience(): ?string
    {
        return value($this->audience);
    }

    /**
     * {@inheritDoc}
     */
    public function defaultUserInput(): ?UserInput
    {
        return UserInput::make()
            ->prompt(filament_solaris_trans('presets.generate.prompt'))
            ->placeholder(filament_solaris_trans('presets.generate.placeholder'));
    }

    /**
     * {@inheritDoc}
     */
    protected function promptView(): string
    {
        return 'filament-solaris::prompts.generate';
    }

    /**
     * {@inheritDoc}
     */
    protected function viewData(): array
    {
        return [
            'tone' => $this->getTone(),
            'maxLength' => $this->getMaxLength(),
            'style' => $this->getStyle(),
            'audience' => $this->getAudience(),
        ];
    }
}
