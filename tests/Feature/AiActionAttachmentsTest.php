<?php

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\AttachmentFormComponent;

beforeEach(function () {
    AiActionFake::reset();
    Storage::persistentFake(FileUploadConfiguration::disk());
});

afterEach(function () {
    AiActionFake::reset();
});

it('records no attachments when none configured', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithClosure'); // closure-only action — easy baseline

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toBeArray()
            ->and(count($attachments))->toBe(1);
    });
});

it('attaches a fresh upload from a parent form FileUpload field', function () {
    AiAction::fake(['summary' => 'Test']);

    $tempFile = createTempUploadedFile('photo.png', 'image/png', 'fake-png-bytes');

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->set('data.reference_image', [uniqid() => $tempFile])
        ->callAction('summarize');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class);
    });
});

it('records an empty attachments array when the bound field is empty', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarize');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toBe([]);
    });
});

it('attaches files supplied from a closure', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithClosure');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(RemoteImage::class);
    });
});

it('attaches a path supplied via UserInput modal data', function () {
    AiAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeFromUserInput', data: [
            'extra_image' => 'modal/already-saved.png',
        ]);

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(StoredImage::class);
    });
});

it('merges form field + closure channels', function () {
    AiAction::fake(['summary' => 'Test']);

    $fieldFile = createTempUploadedFile('photo.png', 'image/png', 'a');

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->set('data.reference_image', [uniqid() => $fieldFile])
        ->callAction('summarizeAllChannels');

    AiAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        // 1 form field (Base64Image) + 1 closure (RemoteImage) = 2
        expect($attachments)->toHaveCount(2);
    });
});
