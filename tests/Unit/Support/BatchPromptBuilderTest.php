<?php

use Statikbe\FilamentSolaris\Support\Batch\BatchPromptBuilder;

it('assembles base text + user context + records block + batch instructions', function () {
    $out = (new BatchPromptBuilder('_index'))->build(
        'Translate each row.',
        [['name' => 'Alice'], ['name' => 'Bob']],
        ['locale' => 'fr'],
    );

    expect($out)->toContain('Translate each row.')
        ->toContain('## User context')
        ->toContain('"locale": "fr"')
        ->toContain('## Records')
        ->toContain('"name": "Alice"')
        ->toContain('"_index": 0')
        ->toContain('"_index": 1')
        ->toContain('## Instructions')
        ->toContain('echoing the `_index`');
});

it('omits the user-context block when no input is filled', function () {
    $out = (new BatchPromptBuilder('_index'))->build('Do it.', [['x' => 1]], []);

    expect($out)->not->toContain('## User context')
        ->toContain('## Records');
});

it('invokes a closure instruction with the context rows and user input', function () {
    $seen = null;
    $out = (new BatchPromptBuilder('_index'))->build(
        function (array $rows, array $userInput) use (&$seen): string {
            $seen = [$rows, $userInput];

            return 'Process '.count($rows).' rows';
        },
        [['name' => 'A'], ['name' => 'B']],
        ['tone' => 'formal'],
    );

    expect($out)->toContain('Process 2 rows')
        ->and($seen)->toBe([[['name' => 'A'], ['name' => 'B']], ['tone' => 'formal']]);
});

it('restricts the ## Records block to promptContextColumns', function () {
    $out = (new BatchPromptBuilder('_index', promptContextColumns: ['name']))->build(
        'x',
        [['name' => 'Alice', 'secret' => 'hidden']],
        [],
    );

    expect($out)->toContain('"name": "Alice"')
        ->not->toContain('hidden');
});

it('enriches a batch with positional _index identifiers', function () {
    [$key, $rows] = (new BatchPromptBuilder('_index'))->enrich([['name' => 'A'], ['name' => 'B']]);

    expect($key)->toBe('_index')
        ->and($rows)->toBe([
            ['name' => 'A', '_index' => 0],
            ['name' => 'B', '_index' => 1],
        ]);
});
