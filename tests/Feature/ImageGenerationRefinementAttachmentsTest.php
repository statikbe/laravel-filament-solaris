<?php

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Image;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;
use Statikbe\FilamentSolaris\Testing\ImageGenerationActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ConversationalImageGenerationFormComponent;

beforeEach(function () {
    ImageGenerationActionFake::reset();
    Storage::persistentFake(FileUploadConfiguration::disk());
});

afterEach(function () {
    ImageGenerationActionFake::reset();
});

it('forwards a turn attachment to the image refinement call', function () {
    ImageGenerationAction::fake();

    $tempFile = createTempUploadedFile('reference.png', 'image/png', 'fake-png-bytes');

    Livewire::test(ConversationalImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Movie X'])
        ->callAction('generatePoster')
        ->set('solarisRefinementAttachments', [uniqid() => $tempFile])
        ->call('solarisRefinePreview', 'Use this as reference');

    ImageGenerationActionFake::getInstance()->assertRefinedWith(function (string $message, array $attachments) {
        expect($message)->toBe('Use this as reference')
            ->and($attachments)->toHaveCount(1)
            ->and($attachments[0])->toBeInstanceOf(Base64Image::class);
    });
});

it('clears refinement attachments after sending in image refinement', function () {
    ImageGenerationAction::fake();

    $tempFile = createTempUploadedFile('a.png', 'image/png', 'a');

    $component = Livewire::test(ConversationalImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Movie X'])
        ->callAction('generatePoster')
        ->set('solarisRefinementAttachments', [uniqid() => $tempFile])
        ->call('solarisRefinePreview', 'Hi');

    expect($component->get('solarisRefinementAttachments'))->toBe([]);
});

it('appends attachment metadata to image-refinement user message bubble', function () {
    ImageGenerationAction::fake();

    $tempFile = createTempUploadedFile('hero.png', 'image/png', 'a');

    $component = Livewire::test(ConversationalImageGenerationFormComponent::class)
        ->fillForm(['title' => 'Movie X'])
        ->callAction('generatePoster')
        ->set('solarisRefinementAttachments', [uniqid() => $tempFile])
        ->call('solarisRefinePreview', 'Look at this');

    $messages = $component->get('solarisPreviewData.messages');
    $userMessage = collect($messages)->firstWhere('role', 'user');

    expect($userMessage)->not->toBeNull()
        ->and($userMessage['attachments'])->toHaveCount(1)
        ->and($userMessage['attachments'][0]['name'])->toBe('hero.png');
});
