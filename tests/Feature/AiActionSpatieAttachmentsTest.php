<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredImage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\SpatieAttachmentFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\SpatieTestModel;

beforeEach(function () {
    AiActionFake::reset();

    if (! app()->getProvider(MediaLibraryServiceProvider::class)) {
        app()->register(MediaLibraryServiceProvider::class);
    }

    Storage::fake('public');

    createSpatieMediaLibraryTables();
});

afterEach(function () {
    AiActionFake::reset();
});

it('resolves Spatie media UUIDs into StoredImage attachments via AiAction', function () {
    AiAction::fake(['summary' => 'Test']);

    $model = SpatieTestModel::create();
    $media = $model->addMedia(UploadedFile::fake()->image('reference.png'))
        ->toMediaCollection('default', 'public');

    Livewire::test(SpatieAttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->set('data.reference_image', [$media->uuid => $media->uuid])
        ->callAction('summarizeWithSpatie');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(StoredImage::class);
    });
});
