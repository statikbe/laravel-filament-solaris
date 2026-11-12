<?php

namespace Statikbe\FilamentSolaris\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Statikbe\FilamentSolaris\Contracts\MetadataVocabulary;

enum Evidence: string implements HasColor, HasLabel, MetadataVocabulary
{
    case Verbatim = 'verbatim';
    case Derived = 'derived';

    public function promptDescription(): string
    {
        return match ($this) {
            self::Verbatim => 'the value is traceable to an explicit statement in the source',
            self::Derived => 'the value is inferred, summarised, reorganised or reformatted from the source',
        };
    }

    public function startsSelected(): bool
    {
        return $this === self::Verbatim;
    }

    public function getLabel(): string
    {
        return filament_solaris_trans('metadata.evidence.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Verbatim => 'success',
            self::Derived => 'warning',
        };
    }
}
