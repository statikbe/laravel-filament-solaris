<?php

use Statikbe\FilamentSolaris\Actions\DictationToolbarAction;

it('defaults its name to solarisDictation', function () {
    expect(DictationToolbarAction::make()->getName())->toBe('solarisDictation');
});

it('throws when the preview modal is accessed (unsupported)', function () {
    DictationToolbarAction::make()->acceptPreview([]);
})->throws(LogicException::class);

it('throws when conversational refinement is accessed (unsupported)', function () {
    DictationToolbarAction::make()->refine('hello');
})->throws(LogicException::class);
