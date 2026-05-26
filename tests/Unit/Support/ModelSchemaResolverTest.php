<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\BooleanType;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Support\ModelSchemaResolver;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategoryPriority;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategoryStatus;

beforeEach(function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->text('description')->nullable();
        $table->integer('weight');
        $table->boolean('is_active');
        $table->string('status');     // backed by SeedCategoryStatus
        $table->integer('priority');  // backed by SeedCategoryPriority
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('seed_categories');
});

it('builds a property map from fillable columns, excluding pk + timestamps', function () {
    $props = (new ModelSchemaResolver)->resolve(new JsonSchemaTypeFactory, SeedCategory::class);

    expect(array_keys($props))->toEqualCanonicalizing(['name', 'slug', 'description', 'weight', 'is_active', 'status', 'priority'])
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

it('uses a typed enum for a string-backed enum cast', function () {
    $props = (new ModelSchemaResolver)->resolve(new JsonSchemaTypeFactory, SeedCategory::class);

    $arr = $props['status']->toArray();
    expect($arr['type'])->toBe('string')
        ->and($arr['enum'])->toEqualCanonicalizing(['draft', 'published']);
});

it('uses a typed enum for an int-backed enum cast', function () {
    $arr = (new ModelSchemaResolver)
        ->resolve(new JsonSchemaTypeFactory, SeedCategory::class)['priority']->toArray();

    expect($arr['type'])->toBe('integer')
        ->and($arr['enum'])->toEqualCanonicalizing([1, 5]);
});

it('applies a manual columnEnum override on a plain column', function () {
    $props = (new ModelSchemaResolver)
        ->resolve(new JsonSchemaTypeFactory, SeedCategory::class, enums: ['name' => ['Tech', 'Science']]);

    expect($props['name']->toArray()['enum'])->toBe(['Tech', 'Science']);
});

it('manual columnEnum overrides a cast-detected enum', function () {
    $props = (new ModelSchemaResolver)
        ->resolve(new JsonSchemaTypeFactory, SeedCategory::class, enums: ['status' => ['x', 'y']]);

    expect($props['status']->toArray()['enum'])->toBe(['x', 'y']);
});

it('applies a columnHint as the schema description', function () {
    $props = (new ModelSchemaResolver)
        ->resolve(new JsonSchemaTypeFactory, SeedCategory::class, hints: ['slug' => 'kebab-case of the name']);

    expect($props['slug']->toArray()['description'])->toBe('kebab-case of the name');
});

it('combines columnHint + columnEnum on the same column', function () {
    $props = (new ModelSchemaResolver)->resolve(
        new JsonSchemaTypeFactory,
        SeedCategory::class,
        hints: ['status' => 'lifecycle stage'],
        enums: ['status' => ['x', 'y']],
    );

    $arr = $props['status']->toArray();
    expect($arr['enum'])->toBe(['x', 'y'])
        ->and($arr['description'])->toBe('lifecycle stage');
});

it('silently ignores columnHint/columnEnum for unknown columns', function () {
    $props = (new ModelSchemaResolver)->resolve(
        new JsonSchemaTypeFactory,
        SeedCategory::class,
        hints: ['nope' => 'x'],
        enums: ['nope' => ['a']],
    );

    expect($props)->not->toHaveKey('nope');
});
