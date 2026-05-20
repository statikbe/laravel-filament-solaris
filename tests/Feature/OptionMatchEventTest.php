<?php

use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Event;
use Statikbe\FilamentSolaris\Events\SolarisOptionMatched;
use Statikbe\FilamentSolaris\Factories\SelectFactory;

it('dispatches SolarisOptionMatched for a fuzzy match', function () {
    Event::fake([SolarisOptionMatched::class]);

    $factory = SelectFactory::make(
        Select::make('category')->options(['opinion' => 'Opinions'])
    );

    $factory->toFormValue('Opinon'); // distance 2 → fuzzy

    Event::assertDispatched(SolarisOptionMatched::class, function (SolarisOptionMatched $e): bool {
        return $e->field === 'category'
            && $e->aiValue === 'Opinon'
            && $e->matchedKey === 'opinion'
            && $e->matchedLabel === 'Opinions'
            && $e->strategy === 'fuzzy'
            && $e->distance === 2;
    });
});

it('dispatches SolarisOptionMatched for a substring match', function () {
    Event::fake([SolarisOptionMatched::class]);

    $factory = SelectFactory::make(
        Select::make('category')->options(['breaking_news' => 'Breaking News'])
    );

    $factory->toFormValue('news'); // substring of "Breaking News"

    Event::assertDispatched(SolarisOptionMatched::class, function (SolarisOptionMatched $e): bool {
        return $e->strategy === 'substring'
            && $e->matchedKey === 'breaking_news'
            && $e->distance === null;
    });
});

it('does not dispatch for exact / case-insensitive matches', function () {
    Event::fake([SolarisOptionMatched::class]);

    $factory = SelectFactory::make(
        Select::make('category')->options(['news' => 'News'])
    );

    $factory->toFormValue('news');   // exact key
    $factory->toFormValue('News');   // exact label
    $factory->toFormValue('NEWS');   // case-insensitive label

    Event::assertNotDispatched(SolarisOptionMatched::class);
});

it('does not dispatch (and returns raw) when fuzzy is disabled and only fuzzy would match', function () {
    Event::fake([SolarisOptionMatched::class]);

    $factory = SelectFactory::make(
        Select::make('category')->options(['opinion' => 'Opinions'])
    )->fuzzyMatching(false);

    // "Opinon" only resolves via fuzzy; disabled → falls through to raw value
    expect($factory->toFormValue('Opinon'))->toBe('Opinon');

    Event::assertNotDispatched(SolarisOptionMatched::class);
});
