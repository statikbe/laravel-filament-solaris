<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\DictationToolbarAction;
use Statikbe\FilamentSolaris\Testing\DictationToolbarActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\DictationToolbarFormComponent;

beforeEach(fn () => DictationToolbarActionFake::reset());
afterEach(fn () => DictationToolbarActionFake::reset());

it('inserts the transcript at the editor cursor via runCommands', function () {
    DictationToolbarAction::fake('Dictated sentence.');

    $selection = ['type' => 'text', 'anchor' => 3, 'head' => 3];

    Livewire::test(DictationToolbarFormComponent::class)
        ->callFormComponentAction('body', 'solarisDictation', arguments: ['editorSelection' => $selection])
        ->assertHasNoFormComponentActionErrors()
        ->assertDispatched('run-rich-editor-commands', function (string $event, array $params) use ($selection) {
            return ($params['commands'][0]['name'] ?? null) === 'insertContent'
                && ($params['commands'][0]['arguments'][0] ?? null) === 'Dictated sentence.'
                && ($params['editorSelection'] ?? null) === $selection;
        });

    DictationToolbarAction::assertTranscribedWith(fn (string $t) => expect($t)->toBe('Dictated sentence.'));
});

it('shows a success notification after inserting', function () {
    DictationToolbarAction::fake('Hello.');

    Livewire::test(DictationToolbarFormComponent::class)
        ->callFormComponentAction('body', 'solarisDictation', arguments: ['editorSelection' => null])
        ->assertNotified();
});

it('tracks call count', function () {
    DictationToolbarAction::fake('Text.');

    $component = Livewire::test(DictationToolbarFormComponent::class);
    $component->callFormComponentAction('body', 'solarisDictation', arguments: ['editorSelection' => null]);
    $component->callFormComponentAction('body', 'solarisDictation', arguments: ['editorSelection' => null]);

    DictationToolbarAction::assertCalledTimes(2);
});
