<?php

use Illuminate\Support\Str;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;
use Statikbe\FilamentSolaris\Tests\Fixtures\DictationFormComponent;

/**
 * Verifies the post-refactor data flow: the audio file lands in the action
 * modal's `$data[DictationFieldAction::AUDIO_FIELD]` slot (via Filament's
 * standard FileUpload + modal-data binding), instead of the previous
 * `$livewire->componentFileAttachments['dictation_audio']` mechanism.
 *
 * Doesn't exercise the real `Transcription::generate()` call (that needs a
 * mocked provider or HTTP fake); covers the upload destination + read path.
 * The transcribe step is tested via DictationFieldActionFake elsewhere.
 */
it('exposes AUDIO_FIELD as a class constant on the action', function () {
    expect(DictationFieldAction::AUDIO_FIELD)
        ->toBeString()
        ->not->toBeEmpty();
});

it('mounts the suffix action and accepts a file uploaded to the schema FileUpload state', function () {
    $audio = createTempUploadedFile('recording.webm', 'audio/webm', 'fake-audio-bytes');

    $component = Livewire::test(DictationFormComponent::class)
        ->mountFormComponentAction('body', 'dictateBody');

    // Simulate the Alpine recorder's $wire.upload() landing in Filament's
    // FileUpload state shape: [uuid => TemporaryUploadedFile].
    $statePath = sprintf('mountedActions.0.data.%s', DictationFieldAction::AUDIO_FIELD);
    $component->set($statePath, [Str::uuid()->toString() => $audio]);

    $stored = $component->get($statePath);
    expect($stored)->toBeArray()
        ->and(reset($stored))->toBe($audio);
});

it('does not leak Filament FileUpload UI into the rendered dictation modal', function () {
    $html = Livewire::test(DictationFormComponent::class)
        ->mountFormComponentAction('body', 'dictateBody')
        ->html();

    // Filament FileUpload's FilePond dropzone classes / wrappers are NOT
    // rendered — `columnSpan('hidden')` prevents the field from being shown.
    expect($html)
        ->not->toContain('filepond--root')
        ->not->toContain('fi-fo-file-upload');
});

it('keeps submit + cancel actions when withPreview is enabled on initial mount', function () {
    // Regression: HasPreviewModal::isPreviewLoading() previously evaluated to
    // true on initial mount of a withPreview() dictation because
    // `shouldPreview() && !hasUserInput() && !hasPreviewData()` matched — that
    // swapped the recorder for the preview-loading spinner AND nulled out
    // submit/cancel actions, so Filament auto-fired processDictation() with
    // empty $data and surfaced "Could not transcribe the audio. Please try
    // again." instead of letting the user record.
    //
    // DictationFieldAction now overrides isPreviewLoading() to always return
    // false because the dictation lifecycle has no "loading spinner"
    // intermediate state — modal goes recorder → (server work) → close
    // (pure transcription) or preview-modal (AI-chained via hasPreviewData).
    $component = Livewire::test(DictationFormComponent::class)
        ->mountFormComponentAction('body', 'dictateBodyWithPreview');

    /** @var DictationFormComponent $instance */
    $instance = $component->instance();
    $action = $instance->getMountedActions()[0];

    expect($action)->toBeInstanceOf(DictationFieldAction::class)
        ->and($action->getModalSubmitAction())->not->toBeNull()
        ->and($action->getModalCancelAction())->not->toBeNull();
});
