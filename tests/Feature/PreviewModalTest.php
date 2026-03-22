<?php

use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiAction;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiActionFake::reset();
});

afterEach(function () {
    AiActionFake::reset();
});

it('stores preview data without applying values when withPreview is enabled', function () {
    AiAction::fake(['summary' => 'A concise summary.']);

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'Test body content.',
        ])
        ->callAction('generateSummary');

    // Values should NOT be applied to the form
    expect($component->get('data.summary'))->toBe('');

    // Preview data should be stored
    expect($component->get('solarisPreviewData'))->not->toBeNull();
    expect($component->get('solarisPreviewData.values.summary'))->toBe('A concise summary.');
    expect($component->get('solarisPreviewData.actionName'))->toBe('generateSummary');

    AiAction::assertCalled();
});

it('applies values when preview is accepted', function () {
    AiAction::fake(['summary' => 'A concise summary.']);

    Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'Test body content.',
        ])
        ->callAction('generateSummary')
        ->call('solarisAcceptPreview')
        ->assertFormSet(['summary' => 'A concise summary.'])
        ->assertSet('solarisPreviewData', null)
        ->assertNotified();
});

it('discards preview without applying values', function () {
    AiAction::fake(['summary' => 'A concise summary.']);

    Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'Test body content.',
        ])
        ->callAction('generateSummary')
        ->call('solarisDiscardPreview')
        ->assertFormSet(['summary' => ''])
        ->assertSet('solarisPreviewData', null);
});

it('applies values directly without preview when withPreview is not set', function () {
    AiAction::fake(['summary' => 'Direct summary.']);

    Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'Test body content.',
        ])
        ->callAction('generateDirect')
        ->assertHasNoActionErrors()
        ->assertFormSet(['summary' => 'Direct summary.'])
        ->assertSet('solarisPreviewData', null);
});

it('stores preview data with correct display labels', function () {
    AiAction::fake(['summary' => 'Test summary text.']);

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'Test body content.',
        ])
        ->callAction('generateSummary');

    $displays = $component->get('solarisPreviewData.displays');

    expect($displays)->toHaveKey('summary');
    expect($displays['summary']['label'])->toBe('Summary');
    expect($displays['summary']['display'])->toBe('Test summary text.');
    expect($displays['summary']['type'])->toBe('text');
});

it('stores preview data for multiple target fields', function () {
    AiAction::fake([
        'summary' => 'Tech article summary.',
        'category' => 'Technology',
        'is_featured' => true,
    ]);

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Breaking Tech News',
            'body' => 'Revolutionary new technology discovered.',
        ])
        ->callAction('generateAll');

    // Values should NOT be applied
    $component->assertFormSet([
        'summary' => '',
        'category' => null,
        'is_featured' => false,
    ]);

    $previewData = $component->get('solarisPreviewData');

    expect($previewData['values'])->toHaveKeys(['summary', 'category', 'is_featured']);
    expect($previewData['values']['summary'])->toBe('Tech article summary.');
    expect($previewData['displays'])->toHaveKeys(['summary', 'category', 'is_featured']);
});

it('accepts preview with multiple target fields', function () {
    AiAction::fake([
        'summary' => 'Tech article summary.',
        'category' => 'Technology',
        'is_featured' => true,
    ]);

    Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Breaking Tech News',
            'body' => 'Revolutionary new technology discovered.',
        ])
        ->callAction('generateAll')
        ->call('solarisAcceptPreview')
        ->assertFormSet([
            'summary' => 'Tech article summary.',
            'category' => 'tech',
            'is_featured' => true,
        ])
        ->assertSet('solarisPreviewData', null);
});

it('handles partial failure in preview mode', function () {
    AiAction::fakePartial([
        'summary' => 'Valid summary.',
        'category' => null,
        'is_featured' => true,
    ]);

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'Test body content.',
        ])
        ->callAction('generateAll');

    $previewData = $component->get('solarisPreviewData');

    // Only successfully transformed fields should be in values
    expect($previewData['values'])->toHaveKeys(['summary', 'is_featured']);
    expect($previewData['values'])->not->toHaveKey('category');
    expect($previewData['failedLabels'])->not->toBeEmpty();
});

it('does nothing when accepting with no preview data', function () {
    Livewire::test(PreviewFormComponent::class)
        ->call('solarisAcceptPreview')
        ->assertSet('solarisPreviewData', null);
});

it('cleans up preview data on unmountAction', function () {
    AiAction::fake(['summary' => 'Test summary.']);

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm([
            'title' => 'Test Title',
            'body' => 'Test body content.',
        ])
        ->callAction('generateSummary');

    expect($component->get('solarisPreviewData'))->not->toBeNull();

    $component->call('unmountAction');

    expect($component->get('solarisPreviewData'))->toBeNull();
});
