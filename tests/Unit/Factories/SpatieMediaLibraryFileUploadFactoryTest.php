<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Statikbe\FilamentSolaris\Factories\SpatieMediaLibraryFileUploadFactory;
use Statikbe\FilamentSolaris\Tests\Fixtures\SpatieTestModel;

uses()->beforeEach(function () {
    // Spatie's service provider isn't auto-loaded by our TestCase (which only
    // boots Filament + Solaris providers); register manually for these tests.
    if (! app()->getProvider(MediaLibraryServiceProvider::class)) {
        app()->register(MediaLibraryServiceProvider::class);
    }

    Storage::fake('public');

    createSpatieMediaLibraryTables();
})->in(__DIR__);

it('returns empty array when value is blank', function () {
    expect(SpatieMediaLibraryFileUploadFactory::toAttachments(null, null))->toBe([])
        ->and(SpatieMediaLibraryFileUploadFactory::toAttachments([], null))->toBe([]);
});

it('falls through to plain FileUploadFactory for fresh uploads', function () {
    $tempFile = createTempUploadedFile('photo.png', 'image/png', 'fake-png');

    $result = SpatieMediaLibraryFileUploadFactory::toAttachments([uniqid() => $tempFile], null);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(Base64Image::class);
});

it('falls through to plain FileUploadFactory for indexed path arrays', function () {
    $result = SpatieMediaLibraryFileUploadFactory::toAttachments(['uploads/a.png', 'uploads/b.pdf'], 'public');

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBeInstanceOf(StoredImage::class)
        ->and($result[1])->toBeInstanceOf(StoredDocument::class);
});

it('resolves Spatie [uuid => uuid] state into StoredImage attachments', function () {
    $model = SpatieTestModel::create();
    $media = $model->addMedia(UploadedFile::fake()->image('product.png'))
        ->toMediaCollection('default', 'public');

    $state = [$media->uuid => $media->uuid];

    $result = SpatieMediaLibraryFileUploadFactory::toAttachments($state, null);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBeInstanceOf(StoredImage::class);
});

it('resolves multiple Spatie media UUIDs into typed attachments', function () {
    $model = SpatieTestModel::create();
    $imageMedia = $model->addMedia(UploadedFile::fake()->image('photo.png'))
        ->toMediaCollection('default', 'public');
    $audioMedia = $model->addMedia(UploadedFile::fake()->create('clip.mp3', 100, 'audio/mpeg'))
        ->toMediaCollection('default', 'public');
    $docMedia = $model->addMedia(UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'))
        ->toMediaCollection('default', 'public');

    $state = [
        $imageMedia->uuid => $imageMedia->uuid,
        $audioMedia->uuid => $audioMedia->uuid,
        $docMedia->uuid => $docMedia->uuid,
    ];

    $result = SpatieMediaLibraryFileUploadFactory::toAttachments($state, null);

    $classes = array_map(fn ($attachment) => get_class($attachment), $result);

    expect($result)->toHaveCount(3)
        ->and($classes)->toContain(StoredImage::class)
        ->and($classes)->toContain(StoredAudio::class)
        ->and($classes)->toContain(StoredDocument::class);
});

it('returns empty array when none of the supplied UUIDs exist', function () {
    $state = ['nonexistent-uuid-1' => 'nonexistent-uuid-1'];

    expect(SpatieMediaLibraryFileUploadFactory::toAttachments($state, null))->toBe([]);
});
