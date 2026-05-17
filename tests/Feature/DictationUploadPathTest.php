<?php

use Illuminate\Support\Str;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\DictationAction;
use Statikbe\FilamentSolaris\Tests\Fixtures\DictationFormComponent;

/**
 * Verifies the post-refactor data flow: the audio file lands in the action
 * modal's `$data[DictationAction::AUDIO_FIELD]` slot (via Filament's standard
 * FileUpload + modal-data binding), instead of the previous
 * `$livewire->componentFileAttachments['dictation_audio']` mechanism.
 *
 * Doesn't exercise the real `Transcription::generate()` call (that needs a
 * mocked provider or HTTP fake); covers the spike's actual change — the
 * upload destination + read path. The transcribe step is tested via
 * DictationActionFake elsewhere.
 */
it('exposes AUDIO_FIELD as a class constant', function () {
    expect(DictationAction::AUDIO_FIELD)
        ->toBeString()
        ->not->toBeEmpty();
});

it('mounts the action and accepts a file uploaded to the schema FileUpload state', function () {
    $audio = createTempUploadedFile('recording.webm', 'audio/webm', 'fake-audio-bytes');

    $component = Livewire::test(DictationFormComponent::class)
        ->mountAction('dictateBody');

    // Simulate the Alpine recorder's $wire.upload() landing in Filament's
    // FileUpload state shape: [uuid => TemporaryUploadedFile].
    $statePath = sprintf('mountedActions.0.data.%s', DictationAction::AUDIO_FIELD);
    $component->set($statePath, [Str::uuid()->toString() => $audio]);

    // Verify Filament accepted the value at the expected path.
    $stored = $component->get($statePath);
    expect($stored)->toBeArray()
        ->and(reset($stored))->toBe($audio);
});

it('does not leak Filament FileUpload UI into the rendered dictation modal', function () {
    $html = Livewire::test(DictationFormComponent::class)
        ->mountAction('dictateBody')
        ->html();

    // Filament FileUpload's FilePond dropzone classes / wrappers are NOT
    // rendered — `columnSpan('hidden')` prevents the field from being shown.
    expect($html)
        ->not->toContain('filepond--root')
        ->not->toContain('fi-fo-file-upload');
});
