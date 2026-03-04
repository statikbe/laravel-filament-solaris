<?php

use Filament\Forms\Components\Select;
use Statikbe\FilamentSolaris\Factories\SelectFactory;

it('fuzzyMatch returns key for exact Levenshtein match', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
        'opinion' => 'Opinions',
    ]);

    $factory = SelectFactory::make($select);

    // Use reflection to test the protected fuzzyMatch directly
    $method = new ReflectionMethod($factory, 'fuzzyMatch');

    // "Opinon" → "Opinions" has Levenshtein distance 2
    expect($method->invoke($factory, 'Opinon', ['news' => 'News', 'opinion' => 'Opinions']))
        ->toBe('opinion');
});

it('fuzzyMatch returns null when distance exceeds threshold', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
    ]);

    $factory = SelectFactory::make($select);
    $method = new ReflectionMethod($factory, 'fuzzyMatch');

    // "completely_different" is way beyond distance 3 from "News"
    expect($method->invoke($factory, 'completely_different', ['news' => 'News']))
        ->toBeNull();
});

it('fuzzyMatch returns key for exact case-insensitive match (distance 0)', function () {
    $select = Select::make('category')->options([
        'news' => 'News',
    ]);

    $factory = SelectFactory::make($select);
    $method = new ReflectionMethod($factory, 'fuzzyMatch');

    expect($method->invoke($factory, 'news', ['news' => 'News']))
        ->toBe('news');
});

it('fuzzyMatch picks closest match when multiple options within threshold', function () {
    $select = Select::make('category')->options([
        'cat' => 'Cat',
        'car' => 'Car',
        'cap' => 'Cap',
    ]);

    $factory = SelectFactory::make($select);
    $method = new ReflectionMethod($factory, 'fuzzyMatch');

    // "Cas" is distance 1 from "Cat", "Car", "Cap"
    // Should return the first one found with distance 1
    $result = $method->invoke($factory, 'Cas', ['cat' => 'Cat', 'car' => 'Car', 'cap' => 'Cap']);
    expect($result)->toBeIn(['cat', 'car', 'cap']);
});
