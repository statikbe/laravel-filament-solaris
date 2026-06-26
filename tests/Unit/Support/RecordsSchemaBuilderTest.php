<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Support\Batch\RecordsSchemaBuilder;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->text('description')->nullable();
        $table->integer('weight');
        $table->boolean('is_active');
        $table->string('status');
        $table->integer('priority');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('seed_categories');
});

it('wraps the model properties in a records[]/failed[] envelope', function () {
    $map = (new RecordsSchemaBuilder)->build(new JsonSchemaTypeFactory, SeedCategory::class, '_index');

    expect(array_keys($map))->toEqualCanonicalizing(['records', 'failed']);

    $recordItem = $map['records']->toArray()['items'];
    expect(array_keys($recordItem['properties']))
        ->toContain('name', 'weight', 'is_active', '_index');

    $failedItem = $map['failed']->toArray()['items'];
    expect(array_keys($failedItem['properties']))->toEqualCanonicalizing(['identifier', 'reason']);
});

it('echoes the _index identifier as an integer with an echo-unchanged hint', function () {
    $map = (new RecordsSchemaBuilder)->build(new JsonSchemaTypeFactory, SeedCategory::class, '_index');

    $idProp = $map['records']->toArray()['items']['properties']['_index'];
    expect($idProp['type'])->toBe('integer')
        ->and($idProp['description'])->toContain('_index')
        ->and($idProp['description'])->toContain('Echo');
});

it('describes a primary-key identifier distinctly from _index', function () {
    $map = (new RecordsSchemaBuilder)->build(new JsonSchemaTypeFactory, SeedCategory::class, 'id');

    $idProp = $map['records']->toArray()['items']['properties']['id'];
    expect($idProp['type'])->toBe('integer')
        ->and($idProp['description'])->toContain('primary key');
});

it('passes only/except/hints/enums through to the model schema resolver', function () {
    $map = (new RecordsSchemaBuilder)->build(
        new JsonSchemaTypeFactory,
        SeedCategory::class,
        '_index',
        only: ['name', 'weight'],
        hints: ['name' => 'Keep it punchy'],
        enums: ['weight' => [1, 2, 3]],
    );

    $props = $map['records']->toArray()['items']['properties'];

    expect(array_keys($props))->toEqualCanonicalizing(['name', 'weight', '_index'])
        ->and($props['name']['description'])->toBe('Keep it punchy')
        ->and($props['weight']['enum'])->toEqualCanonicalizing([1, 2, 3]);
});
