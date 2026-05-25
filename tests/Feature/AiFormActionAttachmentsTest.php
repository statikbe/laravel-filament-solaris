<?php

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\AttachmentFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
    Storage::persistentFake(FileUploadConfiguration::disk());
});

afterEach(function () {
    AiFormActionFake::reset();
});

it('records no attachments when none configured', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithClosure'); // closure-only action — easy baseline

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toBeArray()
            ->and(count($attachments))->toBe(1);
    });
});

it('attaches a fresh upload from a parent form FileUpload field', function () {
    AiFormAction::fake(['summary' => 'Test']);

    $tempFile = createTempUploadedFile('photo.png', 'image/png', 'fake-png-bytes');

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->set('data.reference_image', [uniqid() => $tempFile])
        ->callAction('summarize');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class);
    });
});

it('records an empty attachments array when the bound field is empty', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarize');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toBe([]);
    });
});

it('attaches files supplied from a closure', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithClosure');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(RemoteImage::class);
    });
});

it('attaches a path supplied via UserInput modal data', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeFromUserInput', data: [
            'extra_image' => 'modal/already-saved.png',
        ]);

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(StoredImage::class);
    });
});

it('attachmentField accepts a Closure returning a single field name', function () {
    AiFormAction::fake(['summary' => 'Test']);

    $tempFile = createTempUploadedFile('photo.png', 'image/png', 'fake-png-bytes');

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->set('data.reference_image', [uniqid() => $tempFile])
        ->callAction('summarizeWithClosureField');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class);
    });
});

it('attachmentField accepts a Closure returning an array of field names', function () {
    AiFormAction::fake(['summary' => 'Test']);

    $tempFile = createTempUploadedFile('photo.png', 'image/png', 'fake-png-bytes');

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->set('data.reference_image', [uniqid() => $tempFile])
        ->callAction('summarizeWithClosureFieldArray');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class);
    });
});

it('attachmentFromUserInput accepts a Closure returning the key', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithClosureUserInput', data: [
            'extra_image' => 'modal/already-saved.png',
        ]);

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(StoredImage::class);
    });
});

it('attachments() accepts a static array of Files\File instances', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithStaticAttachmentArray');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(RemoteImage::class);
    });
});

it('attachments() accepts a single Files\File instance', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithSingleFileInstance');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(RemoteImage::class);
    });
});

it('attachments() accepts a single Illuminate UploadedFile', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithSingleUploadedFile');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class);
    });
});

it('attachments() accepts a mixed array of File and UploadedFile', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithMixedArray');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(2)
            ->and($attachments[0])->toBeInstanceOf(RemoteImage::class)
            ->and($attachments[1])->toBeInstanceOf(Base64Audio::class);
    });
});

it('attachments() Closure may return a single Files\File', function () {
    AiFormAction::fake(['summary' => 'Test']);

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->callAction('summarizeWithClosureReturningSingleFile');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(RemoteImage::class);
    });
});

it('merges form field + closure channels', function () {
    AiFormAction::fake(['summary' => 'Test']);

    $fieldFile = createTempUploadedFile('photo.png', 'image/png', 'a');

    Livewire::test(AttachmentFormComponent::class)
        ->fillForm(['title' => 'Hello'])
        ->set('data.reference_image', [uniqid() => $fieldFile])
        ->callAction('summarizeAllChannels');

    AiFormAction::assertCalledWith(function ($sourceData, $prompt, $provider, $model, $timeout, $options, $attachments) {
        // 1 form field (Base64Image) + 1 closure (RemoteImage) = 2
        expect($attachments)->toHaveCount(2);
    });
});
