<?php

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteImage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;
use Statikbe\FilamentSolaris\Testing\ImageGenerationActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ImageGenerationFormComponent;

beforeEach(function () {
    ImageGenerationActionFake::reset();
    Storage::persistentFake(FileUploadConfiguration::disk());
});

afterEach(function () {
    ImageGenerationActionFake::reset();
});

it('records no attachments when none configured on image action', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Hi'])
        ->callAction('generateImage');

    ImageGenerationAction::assertCalledWith(function ($prompt, $size, $quality, $provider, $model, $timeout, $disk, $directory, $attachments) {
        expect($attachments)->toBe([]);
    });
});

it('attaches a fresh upload from a parent form FileUpload field on image action', function () {
    ImageGenerationAction::fake();

    $tempFile = createTempUploadedFile('reference.png', 'image/png', 'fake-png');

    Livewire::test(ImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Hi'])
        ->set('data.reference_image', [uniqid() => $tempFile])
        ->callAction('generateWithReferenceField');

    ImageGenerationAction::assertCalledWith(function ($prompt, $size, $quality, $provider, $model, $timeout, $disk, $directory, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class);
    });
});

it('attaches files supplied from a closure on image action', function () {
    ImageGenerationAction::fake();

    Livewire::test(ImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Hi'])
        ->callAction('generateWithClosureRef');

    ImageGenerationAction::assertCalledWith(function ($prompt, $size, $quality, $provider, $model, $timeout, $disk, $directory, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(RemoteImage::class);
    });
});
