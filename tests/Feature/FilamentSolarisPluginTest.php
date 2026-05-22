<?php

use Filament\Facades\Filament;
use Statikbe\FilamentSolaris\Actions\SolarisAction;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\FilamentSolarisPlugin;
use Statikbe\FilamentSolaris\Prompts\Presets\ClassificationPreset;
use Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset;

beforeEach(function () {
    // Activate the admin panel so FilamentSolarisPlugin::current() can resolve it.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function () {
    deregisterSolarisPlugin();
});

/**
 * Register a fresh FilamentSolarisPlugin instance on the current panel and
 * return it so the test can configure it fluently.
 */
function registerSolarisPlugin(): FilamentSolarisPlugin
{
    $plugin = FilamentSolarisPlugin::make();
    Filament::getCurrentPanel()->plugin($plugin);

    return $plugin;
}

/**
 * Remove the FilamentSolarisPlugin from the admin panel so tests don't leak
 * overrides into each other. Filament's HasPlugins trait does not expose a
 * public deregister method, so reach into the protected `plugins` array.
 */
function deregisterSolarisPlugin(): void
{
    $panel = Filament::getPanel('admin');
    $reflection = new ReflectionClass($panel);
    $property = $reflection->getProperty('plugins');
    $property->setAccessible(true);

    $plugins = $property->getValue($panel);
    unset($plugins['filament-solaris']);
    $property->setValue($panel, $plugins);
}

// ──────────────────────────────────────────────────────────────
//  Plugin internals — setters store, accessors retrieve
// ──────────────────────────────────────────────────────────────

it('records an override only when the setter is called', function () {
    $plugin = FilamentSolarisPlugin::make();

    expect($plugin->hasOverride('ai.default_provider'))->toBeFalse();

    $plugin->defaultProvider('anthropic', 'claude-sonnet-4-5');

    expect($plugin->hasOverride('ai.default_provider'))->toBeTrue()
        ->and($plugin->getOverride('ai.default_provider'))->toBe('anthropic')
        ->and($plugin->hasOverride('ai.default_model'))->toBeTrue()
        ->and($plugin->getOverride('ai.default_model'))->toBe('claude-sonnet-4-5');
});

it('defaults to visible and reports isVisible() true', function () {
    $plugin = FilamentSolarisPlugin::make();

    expect($plugin->isVisible())->toBeTrue();
});

it('disabled() makes isVisible() return false', function () {
    $plugin = FilamentSolarisPlugin::make()->disabled();

    expect($plugin->isVisible())->toBeFalse();
});

it('visible() with a closure is evaluated each call', function () {
    $allow = false;
    $plugin = FilamentSolarisPlugin::make()->visible(function () use (&$allow): bool {
        return $allow;
    });

    expect($plugin->isVisible())->toBeFalse();

    $allow = true;

    expect($plugin->isVisible())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
//  FilamentSolarisConfig consults the plugin first
// ──────────────────────────────────────────────────────────────

it('falls back to global config when no plugin is registered', function () {
    config()->set('filament-solaris.ai.default_provider', 'openai');
    config()->set('filament-solaris.ai.default_model', 'gpt-4o');

    expect(FilamentSolaris::config()->getDefaultProvider())->toBe('openai')
        ->and(FilamentSolaris::config()->getDefaultModel())->toBe('gpt-4o');
});

it('overrides defaultProvider/Model via the plugin', function () {
    config()->set('filament-solaris.ai.default_provider', 'openai');
    config()->set('filament-solaris.ai.default_model', 'gpt-4o');

    registerSolarisPlugin()->defaultProvider('anthropic', 'claude-sonnet-4-5');

    expect(FilamentSolaris::config()->getDefaultProvider())->toBe('anthropic')
        ->and(FilamentSolaris::config()->getDefaultModel())->toBe('claude-sonnet-4-5');
});

it('overrides defaultTimeout via the plugin', function () {
    config()->set('filament-solaris.ai.default_timeout', 30);

    registerSolarisPlugin()->defaultTimeout(120);

    expect(FilamentSolaris::config()->getDefaultTimeout())->toBe(120);
});

it('overrides text-generation options via the plugin', function () {
    registerSolarisPlugin()
        ->defaultTemperature(0.2)
        ->defaultMaxTokens(2048)
        ->defaultMaxSteps(3)
        ->defaultTopP(0.8);

    expect(FilamentSolaris::config()->getDefaultTemperature())->toBe(0.2)
        ->and(FilamentSolaris::config()->getDefaultMaxTokens())->toBe(2048)
        ->and(FilamentSolaris::config()->getDefaultMaxSteps())->toBe(3)
        ->and(FilamentSolaris::config()->getDefaultTopP())->toBe(0.8);
});

it('overrides transcription provider/model/timeout via the plugin', function () {
    registerSolarisPlugin()
        ->defaultTranscriptionProvider('openai', 'whisper-1')
        ->defaultTranscriptionTimeout(60);

    expect(FilamentSolaris::config()->getDefaultTranscriptionProvider())->toBe('openai')
        ->and(FilamentSolaris::config()->getDefaultTranscriptionModel())->toBe('whisper-1')
        ->and(FilamentSolaris::config()->getDefaultTranscriptionTimeout())->toBe(60);
});

it('overrides image generation settings via the plugin', function () {
    registerSolarisPlugin()
        ->defaultImageProvider('openai', 'gpt-image-1')
        ->defaultImageSize('3:2')
        ->defaultImageQuality('high')
        ->defaultImageDisk('s3')
        ->defaultImageDirectory('panel-admin/images');

    expect(FilamentSolaris::config()->getDefaultImageProvider())->toBe('openai')
        ->and(FilamentSolaris::config()->getDefaultImageModel())->toBe('gpt-image-1')
        ->and(FilamentSolaris::config()->getDefaultImageSize())->toBe('3:2')
        ->and(FilamentSolaris::config()->getDefaultImageQuality())->toBe('high')
        ->and(FilamentSolaris::config()->getDefaultImageDisk())->toBe('s3')
        ->and(FilamentSolaris::config()->getDefaultImageDirectory())->toBe('panel-admin/images');
});

it('overrides prompt-logging via the plugin', function () {
    config()->set('filament-solaris.prompt_logging.enabled', false);

    registerSolarisPlugin()
        ->promptLogging(true)
        ->promptLoggingChannel('stack');

    expect(FilamentSolaris::config()->isPromptLoggingEnabled())->toBeTrue()
        ->and(FilamentSolaris::config()->getPromptLoggingChannel())->toBe('stack');
});

it('overrides locales via the plugin', function () {
    registerSolarisPlugin()
        ->defaultLocale('fr')
        ->locales(['fr', 'nl']);

    expect(FilamentSolaris::config()->getDefaultLocale())->toBe('fr')
        ->and(FilamentSolaris::config()->getSupportedLocales())->toBe(['fr', 'nl']);
});

it('overrides icons via the plugin', function () {
    registerSolarisPlugin()
        ->actionIcon('heroicon-o-star')
        ->dictationIcon('heroicon-o-mic')
        ->imageGenerationIcon('heroicon-o-photo');

    expect(FilamentSolaris::config()->getActionIcon())->toBe('heroicon-o-star')
        ->and(FilamentSolaris::config()->getDictationIcon())->toBe('heroicon-o-mic')
        ->and(FilamentSolaris::config()->getImageGenerationIcon())->toBe('heroicon-o-photo');
});

it('overrides default tone via the plugin', function () {
    registerSolarisPlugin()->defaultTone('formal');

    expect(FilamentSolaris::config()->getDefaultTone())->toBe('formal');
});

// ──────────────────────────────────────────────────────────────
//  preset_providers — merge with global config
// ──────────────────────────────────────────────────────────────

it('overrides a preset via presetProvider() while leaving other preset entries from global config intact', function () {
    config()->set('filament-solaris.preset_providers', [
        SummaryPreset::class => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
        ClassificationPreset::class => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
    ]);

    registerSolarisPlugin()->presetProvider(
        SummaryPreset::class,
        provider: 'google',
        model: 'gemini-2.5-flash',
    );

    // Summary preset's plugin entry wins
    $summary = FilamentSolaris::config()->getPresetProvider(SummaryPreset::class);
    expect($summary['provider'])->toBe('google')
        ->and($summary['model'])->toBe('gemini-2.5-flash');

    // Classification preset's global config entry untouched
    $classification = FilamentSolaris::config()->getPresetProvider(ClassificationPreset::class);
    expect($classification['provider'])->toBe('anthropic')
        ->and($classification['model'])->toBe('claude-haiku-4-5');
});

it('bulk presetProviders() merges with prior presetProvider() calls', function () {
    registerSolarisPlugin()
        ->presetProvider(SummaryPreset::class, provider: 'openai')
        ->presetProviders([
            ClassificationPreset::class => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
        ]);

    expect(FilamentSolaris::config()->getPresetProvider(SummaryPreset::class)['provider'])->toBe('openai')
        ->and(FilamentSolaris::config()->getPresetProvider(ClassificationPreset::class)['provider'])->toBe('anthropic');
});

// ──────────────────────────────────────────────────────────────
//  Auth gate — visibility on Solaris actions
// ──────────────────────────────────────────────────────────────

it('SolarisAction::isAllowedInCurrentPanel() is true when no plugin is registered', function () {
    expect(SolarisAction::isAllowedInCurrentPanel())->toBeTrue();
});

it('SolarisAction::isAllowedInCurrentPanel() is false when plugin is disabled', function () {
    registerSolarisPlugin()->disabled();

    expect(SolarisAction::isAllowedInCurrentPanel())->toBeFalse();
});

it('SolarisAction::isAllowedInCurrentPanel() reflects the visible() closure', function () {
    $allow = false;
    registerSolarisPlugin()->visible(function () use (&$allow): bool {
        return $allow;
    });

    expect(SolarisAction::isAllowedInCurrentPanel())->toBeFalse();

    $allow = true;

    expect(SolarisAction::isAllowedInCurrentPanel())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
//  Null override semantics — plugin can explicitly clear a default
// ──────────────────────────────────────────────────────────────

it('honours an explicit null override (e.g. clearing a global default)', function () {
    config()->set('filament-solaris.image_generation.default_visibility', 'public');

    registerSolarisPlugin()->defaultImageVisibility(null);

    // Global says 'public'; plugin explicitly sets null. Plugin wins.
    expect(FilamentSolaris::config()->getDefaultImageVisibility())->toBeNull();
});

it('overrides the rich editor toolbar button flag via the plugin', function () {
    config()->set('filament-solaris.transcription.enable_rich_editor_toolbar_btn', false);

    registerSolarisPlugin()
        ->enableRichEditorToolbarButton();

    expect(FilamentSolaris::config()->shouldEnableRichEditorToolbarButton())->toBeTrue();
});
