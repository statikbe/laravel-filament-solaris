<?php

use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentSolaris\Support\Batch\RecordWriter;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;

beforeEach(function () {
    Schema::create('seed_categories', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->text('description')->nullable();
        $table->integer('weight')->default(0);
        $table->boolean('is_active')->default(true);
        $table->string('status')->default('draft');
        $table->integer('priority')->default(1);
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('seed_categories');
});

it('creates a new record on the create terminal', function () {
    (new RecordWriter(SeedCategory::class, RecordWriter::CREATE))->write(['_index' => 0], ['name' => 'Fresh']);

    expect(SeedCategory::query()->where('name', 'Fresh')->exists())->toBeTrue();
});

it('updates the given model on the update terminal', function () {
    $model = SeedCategory::create(['name' => 'Old']);

    (new RecordWriter(SeedCategory::class, RecordWriter::UPDATE))->write($model, ['name' => 'New']);

    expect($model->fresh()->name)->toBe('New');
});

it('re-fetches and updates by pk when the row is a plain descriptor (worker path)', function () {
    $model = SeedCategory::create(['name' => 'Old']);

    (new RecordWriter(SeedCategory::class, RecordWriter::UPDATE))->write(['id' => $model->id], ['name' => 'Patched']);

    expect($model->fresh()->name)->toBe('Patched');
});

it('throws when an update target no longer exists', function () {
    (new RecordWriter(SeedCategory::class, RecordWriter::UPDATE))->write(['id' => 999999], ['name' => 'X']);
})->throws(RuntimeException::class, 'no longer exists');
