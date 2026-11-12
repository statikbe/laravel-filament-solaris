<?php

namespace Statikbe\FilamentSolaris\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Statikbe\FilamentSolaris\Contracts\ConfidenceBand;

enum ConfidenceLevel: string implements ConfidenceBand, HasColor, HasLabel
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function threshold(): float
    {
        return match ($this) {
            self::High => 0.8,
            self::Medium => 0.5,
            self::Low => 0.0,
        };
    }

    public function promptDescription(): string
    {
        return match ($this) {
            self::High => 'the source states it explicitly',
            self::Medium => 'the source strongly implies it',
            self::Low => 'a guess with little support in the source',
        };
    }

    public function startsSelected(): bool
    {
        return $this === self::High;
    }

    public function getLabel(): string
    {
        return filament_solaris_trans('metadata.confidence.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::High => 'success',
            self::Medium => 'warning',
            self::Low => 'danger',
        };
    }
}
