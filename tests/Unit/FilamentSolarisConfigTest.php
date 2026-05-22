<?php

use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

it('defaults the rich editor toolbar button flag to false', function () {
    expect(FilamentSolaris::config()->shouldEnableRichEditorToolbarButton())->toBeFalse();
});

it('reads the rich editor toolbar button flag from config', function () {
    config()->set('filament-solaris.transcription.enable_rich_editor_toolbar_btn', true);

    expect(FilamentSolaris::config()->shouldEnableRichEditorToolbarButton())->toBeTrue();
});
