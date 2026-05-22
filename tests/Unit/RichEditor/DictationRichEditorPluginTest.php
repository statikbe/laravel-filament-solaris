<?php

use Filament\Forms\Components\RichEditor\RichEditorTool;
use Statikbe\FilamentSolaris\Actions\DictationToolbarAction;
use Statikbe\FilamentSolaris\RichEditor\DictationRichEditorPlugin;

it('registers a single dictation tool when visible', function () {
    $tools = DictationRichEditorPlugin::make()->getEditorTools();

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(RichEditorTool::class)
        ->and($tools[0]->getName())->toBe('solarisDictation');
});

it('registers the dictation action when visible', function () {
    $actions = DictationRichEditorPlugin::make()->getEditorActions();

    expect($actions)->toHaveCount(1)
        ->and($actions[0])->toBeInstanceOf(DictationToolbarAction::class)
        ->and($actions[0]->getName())->toBe('solarisDictation');
});

it('enables the toolbar button and disables nothing when visible', function () {
    $plugin = DictationRichEditorPlugin::make();

    expect($plugin->getEnabledToolbarButtons())->toBe(['solarisDictation'])
        ->and($plugin->getDisabledToolbarButtons())->toBe([]);
});

it('opts out: hides + disables the button when not visible', function () {
    $plugin = DictationRichEditorPlugin::make()->visible(false);

    expect($plugin->getEditorTools())->toBe([])
        ->and($plugin->getEditorActions())->toBe([])
        ->and($plugin->getEnabledToolbarButtons())->toBe([])
        ->and($plugin->getDisabledToolbarButtons())->toBe(['solarisDictation']);
});

it('exposes no tiptap extensions', function () {
    $plugin = DictationRichEditorPlugin::make();

    expect($plugin->getTipTapPhpExtensions())->toBe([])
        ->and($plugin->getTipTapJsExtensions())->toBe([]);
});

it('proxies the language config onto the built action', function () {
    $action = DictationRichEditorPlugin::make()->lang('nl-BE')->getEditorActions()[0];

    expect($action->getTranscriptionLang())->toBe('nl-BE');
});
