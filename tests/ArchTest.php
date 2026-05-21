<?php

use Filament\Actions\Action;
use Statikbe\FilamentSolaris\Factories\ComponentFactory;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('actions extend the Filament Action base')
    ->expect('Statikbe\FilamentSolaris\Actions')
    ->toExtend(Action::class);

arch('factories extend the ComponentFactory base')
    ->expect('Statikbe\FilamentSolaris\Factories')
    ->toExtend(ComponentFactory::class)
    ->ignoring([
        ComponentFactory::class,
        'Statikbe\FilamentSolaris\Factories\Concerns',
    ]);

// Filament v4 moved the schema `Component` base to
// `Filament\Schemas\Components\Component`; the old `Filament\Forms\Components\Component`
// no longer exists. Importing it is the prior-breakage footgun this guards against.
arch('nothing references the pre-v4 Forms Component location')
    ->expect('Statikbe\FilamentSolaris')
    ->not->toUse('Filament\Forms\Components\Component');
