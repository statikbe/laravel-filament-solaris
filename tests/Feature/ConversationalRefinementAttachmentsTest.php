<?php

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Image;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ConversationalFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
    Storage::persistentFake(FileUploadConfiguration::disk());
});

afterEach(function () {
    AiFormActionFake::reset();
});

it('forwards a turn attachment to the refinement call', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    $tempFile = createTempUploadedFile('reference.png', 'image/png', 'fake-png-bytes');

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary');

    $component->set('solarisRefinementAttachments', [uniqid() => $tempFile])
        ->call('solarisRefinePreview', 'Use this image as reference');

    AiFormActionFake::getInstance()->assertRefinedWith(function (string $message, array $attachments) {
        expect($message)->toBe('Use this image as reference')
            ->and($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class);
    });
});

it('forwards multiple turn attachments to the refinement call', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    $first = createTempUploadedFile('a.png', 'image/png', 'a');
    $second = createTempUploadedFile('b.png', 'image/png', 'b');

    Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary')
        ->set('solarisRefinementAttachments', [
            uniqid('a') => $first,
            uniqid('b') => $second,
        ])
        ->call('solarisRefinePreview', 'See these two images');

    AiFormActionFake::getInstance()->assertRefinedWith(function (string $message, array $attachments) {
        expect($attachments)->toHaveCount(2)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class)
            ->and($attachments[1])->toBeInstanceOf(Base64Image::class);
    });
});

it('clears refinement attachments after a successful send', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    $tempFile = createTempUploadedFile('a.png', 'image/png', 'a');

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary')
        ->set('solarisRefinementAttachments', [uniqid() => $tempFile])
        ->call('solarisRefinePreview', 'Hi');

    expect($component->get('solarisRefinementAttachments'))->toBe([]);
});

it('appends attachment metadata to the user message bubble', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    $tempFile = createTempUploadedFile('hero.png', 'image/png', 'a');

    $component = Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary')
        ->set('solarisRefinementAttachments', [uniqid() => $tempFile])
        ->call('solarisRefinePreview', 'Look at this');

    $messages = $component->get('solarisPreviewData.messages');
    $userMessage = collect($messages)->firstWhere('role', 'user');

    expect($userMessage)->not->toBeNull()
        ->and($userMessage['attachments'])->toHaveCount(1)
        ->and($userMessage['attachments'][0]['name'])->toBe('hero.png');
});

it('records empty attachments when no turn files are attached', function () {
    AiFormAction::fake(['summary' => 'Initial.'])
        ->fakeRefinement(['summary' => 'Refined.']);

    Livewire::test(ConversationalFormComponent::class)
        ->fillForm(['title' => 'Test', 'body' => 'Content'])
        ->callAction('generateSummary')
        ->call('solarisRefinePreview', 'No files this turn');

    AiFormActionFake::getInstance()->assertRefinedWith(function (string $message, array $attachments) {
        expect($attachments)->toBe([]);
    });
});
