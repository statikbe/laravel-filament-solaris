<?php

use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;

it('is constructible from scalars and survives php serialize() round-trip', function () {
    $config = new BatchRunConfig(
        actionName: 'importCategories',
        modelClass: 'App\\Models\\Category',
        onlyColumns: ['name', 'slug'],
        exceptColumns: [],
        columnHints: ['slug' => 'kebab-case'],
        columnEnums: [],
        identifierKey: '_index',
        writeTerminal: 'create',
        provider: 'openai',
        model: 'gpt-4o',
        timeout: 120,
        runId: 'run-123',
        temperature: 0.4,
        maxTokens: null,
        maxSteps: null,
        topP: null,
    );

    $restored = unserialize(serialize($config));

    expect($restored)->toEqual($config)
        ->and($restored->identifierKey)->toBe('_index')
        ->and($restored->runId)->toBe('run-123');
});
