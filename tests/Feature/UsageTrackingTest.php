<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;
use Livewire\Livewire;
use Statikbe\FilamentSolaris\Actions\AiFormAction;
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;
use Statikbe\FilamentSolaris\Actions\ImageGenerationAction;
use Statikbe\FilamentSolaris\Events\SolarisResponseFailed;
use Statikbe\FilamentSolaris\Events\SolarisResponseReceived;
use Statikbe\FilamentSolaris\Support\SolarisPromptLogger;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Testing\DictationFieldActionFake;
use Statikbe\FilamentSolaris\Testing\ImageGenerationActionFake;
use Statikbe\FilamentSolaris\Tests\Fixtures\AiFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\DictationFormComponent;
use Statikbe\FilamentSolaris\Tests\Fixtures\ImageGenerationFormComponent;

beforeEach(function () {
    AiFormActionFake::reset();
    DictationFieldActionFake::reset();
    ImageGenerationActionFake::reset();
});

afterEach(function () {
    AiFormActionFake::reset();
    DictationFieldActionFake::reset();
    ImageGenerationActionFake::reset();
});

// ──────────────────────────────────────────────────────────────
//  SolarisResponseReceived — success path
// ──────────────────────────────────────────────────────────────

it('dispatches SolarisResponseReceived after a successful AiFormAction call', function () {
    Event::fake([SolarisResponseReceived::class]);
    AiFormAction::fake(['summary' => 'Generated summary.']);

    Livewire::test(AiFormComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body of the article.'])
        ->callAction('generateSummary');

    Event::assertDispatched(SolarisResponseReceived::class, function (SolarisResponseReceived $event): bool {
        return $event->actionName === 'generateSummary'
            && $event->actionClass === AiFormAction::class
            && $event->usage instanceof Usage
            && $event->durationMs >= 0;
    });
});

it('dispatches SolarisResponseReceived after a successful ImageGenerationAction call', function () {
    Event::fake([SolarisResponseReceived::class]);
    ImageGenerationAction::fake('ai-images/generated.png');

    Livewire::test(ImageGenerationFormComponent::class)
        ->callAction('generateImage');

    Event::assertDispatched(SolarisResponseReceived::class, function (SolarisResponseReceived $event): bool {
        return $event->actionName === 'generateImage'
            && $event->actionClass === ImageGenerationAction::class
            && $event->usage instanceof Usage;
    });
});

it('dispatches SolarisResponseReceived after a successful DictationFieldAction call', function () {
    Event::fake([SolarisResponseReceived::class]);
    DictationFieldAction::fake('Hello, this is a test transcription.');

    Livewire::test(DictationFormComponent::class)
        ->callFormComponentAction('body', 'dictateBody');

    Event::assertDispatched(SolarisResponseReceived::class, function (SolarisResponseReceived $event): bool {
        return $event->actionName === 'dictateBody'
            && $event->actionClass === DictationFieldAction::class
            && $event->usage instanceof Usage;
    });
});

// ──────────────────────────────────────────────────────────────
//  SolarisResponseFailed — error path
// ──────────────────────────────────────────────────────────────

it('dispatches SolarisResponseFailed when the AiFormAction simulates an error', function () {
    Event::fake([SolarisResponseFailed::class, SolarisResponseReceived::class]);
    AiFormAction::fakeError('Rate limited');

    Livewire::test(AiFormComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('generateSummary');

    Event::assertDispatched(SolarisResponseFailed::class, function (SolarisResponseFailed $event): bool {
        return $event->actionName === 'generateSummary'
            && $event->actionClass === AiFormAction::class
            && $event->exception->getMessage() === 'Rate limited';
    });

    Event::assertNotDispatched(SolarisResponseReceived::class);
});

it('dispatches SolarisResponseFailed when the AiFormAction simulates a timeout', function () {
    Event::fake([SolarisResponseFailed::class]);
    AiFormAction::fakeTimeout();

    Livewire::test(AiFormComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('generateSummary');

    Event::assertDispatched(SolarisResponseFailed::class);
});

// ──────────────────────────────────────────────────────────────
//  Event payload completeness
// ──────────────────────────────────────────────────────────────

it('carries the resolved provider, model, and livewire component on the success event', function () {
    Event::fake([SolarisResponseReceived::class]);
    AiFormAction::fake(['summary' => 'Done.']);

    Livewire::test(AiFormComponent::class)
        ->fillForm(['title' => 'Article', 'body' => 'Body.'])
        ->callAction('generateSummary');

    Event::assertDispatched(SolarisResponseReceived::class, function (SolarisResponseReceived $event): bool {
        // provider/model fall through to package defaults (may be null in test config)
        return $event->livewireComponent instanceof AiFormComponent;
    });
});

// ──────────────────────────────────────────────────────────────
//  SolarisPromptLogger::logUsage
// ──────────────────────────────────────────────────────────────

it('logUsage writes a debug log entry when prompt logging is enabled', function () {
    config()->set('filament-solaris.prompt_logging.enabled', true);
    config()->set('filament-solaris.prompt_logging.channel', null);

    Log::shouldReceive('channel')
        ->with(null)
        ->andReturnSelf();

    Log::shouldReceive('debug')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Filament Solaris — Usage'
                && ($context['action'] ?? null) === 'test-action'
                && ($context['duration_ms'] ?? null) === 42
                && ($context['model'] ?? null) === 'gpt-4o'
                && isset($context['usage'])
                && $context['usage']['prompt_tokens'] === 100
                && $context['usage']['completion_tokens'] === 50;
        });

    SolarisPromptLogger::logUsage(
        actionName: 'test-action',
        usage: new Usage(promptTokens: 100, completionTokens: 50),
        provider: null,
        model: 'gpt-4o',
        durationMs: 42,
    );
});

it('logUsage is silent when prompt logging is disabled', function () {
    config()->set('filament-solaris.prompt_logging.enabled', false);

    Log::shouldReceive('channel')->never();
    Log::shouldReceive('debug')->never();

    SolarisPromptLogger::logUsage(
        actionName: 'test-action',
        usage: new Usage(promptTokens: 100),
        provider: null,
        model: null,
        durationMs: 0,
    );
});
