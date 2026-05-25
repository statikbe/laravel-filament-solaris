<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\BooleanType;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Support\ModelSchemaResolver;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->text('description')->nullable();
        $table->integer('weight');
        $table->boolean('is_active');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('seed_categories');
});

it('builds a property map from fillable columns, excluding pk + timestamps', function () {
    $props = (new ModelSchemaResolver)->resolve(new JsonSchemaTypeFactory, SeedCategory::class);

    expect(array_keys($props))->toEqualCanonicalizing(['name', 'slug', 'description', 'weight', 'is_active'])
        ->and($props)->not->toHaveKey('id')
        ->and($props)->not->toHaveKey('created_at')
        ->and($props)->not->toHaveKey('updated_at');
});

it('maps column/cast types to JSON schema types', function () {
    $props = (new ModelSchemaResolver)->resolve(new JsonSchemaTypeFactory, SeedCategory::class);

    expect($props['name'])->toBeInstanceOf(StringType::class)
        ->and($props['weight'])->toBeInstanceOf(IntegerType::class)
        ->and($props['is_active'])->toBeInstanceOf(BooleanType::class);
});

it('honours only() and except()', function () {
    $resolver = new ModelSchemaResolver;

    expect(array_keys($resolver->resolve(new JsonSchemaTypeFactory, SeedCategory::class, only: ['name', 'slug'])))
        ->toEqualCanonicalizing(['name', 'slug']);

    expect(array_keys($resolver->resolve(new JsonSchemaTypeFactory, SeedCategory::class, except: ['description'])))
        ->not->toContain('description');
});
