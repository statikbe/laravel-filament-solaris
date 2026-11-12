<?php

use Filament\Schemas\Components\Text;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Support\Metadata\MetadataBadgeRenderer;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\ConversationalFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\PreviewFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
    PreviewFormComponent::$badgeCalls = [];
});

afterEach(function () {
    AiFormActionFake::reset();
});

function evidenceEnvelopes(): array
{
    return [
        'summary' => ['value' => 'S', 'evidence' => 'verbatim'],
        'category' => ['value' => 'tech', 'evidence' => 'derived'],
        'is_featured' => ['value' => true, 'evidence' => 'derived'],
    ];
}

function badgeModalHtml(string $component, string $action): string
{
    $livewire = Livewire::test($component)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction($action);

    return $livewire->instance()->getMountedAction()->getModalContent()->render();
}

it('renders the preset badge beside the label and keeps the info icon', function () {
    AiFormAction::fake(evidenceEnvelopes());

    $html = badgeModalHtml(PreviewFormComponent::class, 'previewEvidence');

    expect($html)
        ->toContain(filament_solaris_trans('metadata.evidence.verbatim'))
        ->toContain(filament_solaris_trans('metadata.evidence.derived'))
        ->toContain('fi-color-success')
        ->toContain('fi-color-warning')
        ->and(substr_count($html, 'x-tooltip'))->toBe(3)
        // Inline: the schema's grid / component wrappers are not rendered.
        ->and($html)->not->toContain('fi-sc-component')
        ->not->toContain('filamentSchema(');
});

it('lets fieldMetadataBadge override the preset badge', function () {
    AiFormAction::fake(evidenceEnvelopes());

    $html = badgeModalHtml(PreviewFormComponent::class, 'previewBadgeOverride');

    expect(PreviewFormComponent::$badgeCalls[0])->toBe([
        'metadata' => ['evidence' => 'verbatim'],
        'fieldName' => 'summary',
        'value' => 'S',
    ])
        ->and(array_unique(array_column(PreviewFormComponent::$badgeCalls, 'fieldName')))->toBe(['summary', 'category', 'is_featured'])
        ->and(substr_count($html, 'fi-sc-icon'))->toBe(3)
        ->and($html)->not->toContain(filament_solaris_trans('metadata.evidence.verbatim'))
        ->not->toContain(filament_solaris_trans('metadata.evidence.derived'));
});

it('shows no badge when the closure returns null', function () {
    AiFormAction::fake(evidenceEnvelopes());

    $html = badgeModalHtml(PreviewFormComponent::class, 'previewBadgeNull');

    expect($html)->not->toContain('fi-badge')
        ->and(substr_count($html, 'x-tooltip'))->toBe(3);
});

it('throws when the badge closure returns a non-component', function () {
    AiFormAction::fake(evidenceEnvelopes());

    try {
        badgeModalHtml(PreviewFormComponent::class, 'previewBadgeInvalid');
    } catch (Throwable $e) {
        // The modal renders inside a view, so Blade wraps the exception.
        while (! $e instanceof InvalidArgumentException && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        expect($e)->toBeInstanceOf(InvalidArgumentException::class)
            ->and($e->getMessage())->toContain('"summary"')->toContain('string');

        return;
    }

    test()->fail('Expected an InvalidArgumentException.');
});

it('renders badges in the conversational preview', function () {
    AiFormAction::fake([
        'summary' => ['value' => 'S', 'evidence' => 'verbatim'],
        'category' => ['value' => 'tech', 'evidence' => 'derived'],
    ]);

    $html = badgeModalHtml(ConversationalFormComponent::class, 'generateEvidenceSelectable');

    expect($html)
        ->toContain(filament_solaris_trans('metadata.evidence.verbatim'))
        ->toContain(filament_solaris_trans('metadata.evidence.derived'))
        ->toContain('fi-color-success')
        ->toContain('fi-color-warning');
});

it('shows no badge for a field without metadata or an unknown case', function () {
    AiFormAction::fake([
        'summary' => ['value' => 'S', 'evidence' => 'guessed'],
        'category' => ['value' => 'tech', 'evidence' => 'verbatim'],
        'is_featured' => ['value' => true],
    ]);

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('previewEvidence');

    $badges = $component->instance()->getMountedAction()->getPreviewBadges($component->get('solarisPreviewData'));

    expect(array_keys($badges))->toBe(['category'])
        ->and((string) $badges['category']->toHtml())->toContain(filament_solaris_trans('metadata.evidence.verbatim'));
});

it('renders without badges when the metadata closure turns null after the preview was stored', function () {
    AiFormAction::fake(evidenceEnvelopes());

    $component = Livewire::test(PreviewFormComponent::class)
        ->fillForm(['title' => 'T', 'body' => 'B'])
        ->callAction('previewRuntimeEvidence')
        ->set('metadataOff', true);

    $html = $component->instance()->getMountedAction()->getModalContent()->render();

    expect($html)->not->toContain('fi-badge')
        ->and(substr_count($html, 'x-tooltip'))->toBe(3);
});

it('makes the info icon keyboard-reachable', function () {
    AiFormAction::fake(evidenceEnvelopes());

    $html = badgeModalHtml(PreviewFormComponent::class, 'previewEvidence');

    expect($html)->toContain('tabindex="0"')
        ->toContain('aria-label="evidence: verbatim"');
});

it('renders a badge inline through a schema', function () {
    $livewire = Livewire::test(PreviewFormComponent::class)->instance();

    $html = MetadataBadgeRenderer::render(
        Text::make('Hi')->badge()->color('success'),
        $livewire,
    )->toHtml();

    expect($html)->toContain('Hi')
        ->toContain('fi-color-success')
        ->not->toContain('fi-sc-component')
        ->not->toContain('fi-grid');
});
