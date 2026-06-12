<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Statikbe\FilamentSolaris\Support\Batch\BatchRunConfig;
use Statikbe\FilamentSolaris\Tests\Fixtures\SeedCategory;
use Statikbe\FilamentSolaris\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Build a serializable BatchRunConfig for the queued-runner tests. Shared so
 * both QueuedRunnerTest and QueuedSingleCallTest can use it in isolation.
 */
function makeRunConfig(string $runId, string $writeTerminal = 'create', string $identifierKey = '_index'): BatchRunConfig
{
    return new BatchRunConfig(
        actionName: 'importCategories',
        modelClass: SeedCategory::class,
        onlyColumns: [], exceptColumns: [], columnHints: [], columnEnums: [],
        identifierKey: $identifierKey,
        writeTerminal: $writeTerminal,
        provider: null, model: null, timeout: null,
        runId: $runId,
        temperature: null, maxTokens: null, maxSteps: null, topP: null,
    );
}

function createTempUploadedFile(string $originalName, string $mimeType, string $contents): TemporaryUploadedFile
{
    Storage::persistentFake(FileUploadConfiguration::disk());

    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $filename = Str::random(20).'-meta'.str_replace('/', '_', base64_encode($originalName)).'-.'.$extension;

    Storage::disk(FileUploadConfiguration::disk())->put(
        FileUploadConfiguration::directory().'/'.$filename,
        $contents,
    );

    return TemporaryUploadedFile::createFromLivewire($filename);
}

/**
 * Create the Spatie media-library tables used by SpatieTestModel-backed tests.
 *
 * Idempotent — safe to call from multiple beforeEach hooks. Mirrors the schema
 * Spatie's own migration would create, plus a tiny `spatie_test_models` table
 * for the fixture model.
 */
function createSpatieMediaLibraryTables(): void
{
    if (! Schema::hasTable('media')) {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
    }

    if (! Schema::hasTable('spatie_test_models')) {
        Schema::create('spatie_test_models', function (Blueprint $table) {
            $table->id();
        });
    }
}
