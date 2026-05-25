<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiGenerateAction;
use Statikbe\FilamentSolaris\Testing\AiGenerateActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\GenerateFormComponent;

beforeEach(fn () => AiGenerateActionFake::reset());
afterEach(fn () => AiGenerateActionFake::reset());

it('runs the handler with the decoded structured data (custom schema)', function () {
    AiGenerateAction::fake(['taxonomy' => [['name' => 'Tech'], ['name' => 'Science']]]);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('buildTaxonomy')
        ->assertSet('handledData', ['taxonomy' => [['name' => 'Tech'], ['name' => 'Science']]]);

    AiGenerateAction::assertHandledWith(fn (array $data) => expect($data['taxonomy'])->toHaveCount(2));
});

it('errors when no handler is configured', function () {
    AiGenerateAction::fake(['a' => 'x']);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('missingHandler'))
        ->toThrow(RuntimeException::class, 'requires a ->handleUsing()');
});

it('errors when no schema source is configured', function () {
    AiGenerateAction::fake(['a' => 'x']);

    expect(fn () => Livewire::test(GenerateFormComponent::class)->callAction('missingSchema'))
        ->toThrow(RuntimeException::class, 'requires a schema source: ->outputSchema()');
});

it('shows an error notification when the handler throws', function () {
    AiGenerateAction::fake(['a' => 'x']);

    Livewire::test(GenerateFormComponent::class)
        ->callAction('throwingHandler')
        ->assertNotified();
});
