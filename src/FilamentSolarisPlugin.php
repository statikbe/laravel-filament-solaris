<?php

namespace Statikbe\FilamentSolaris;

use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Laravel\Ai\Enums\Lab;

/**
 * Per-panel Solaris configuration.
 *
 * Registers Solaris on a Filament panel and lets that panel override any of
 * the package's runtime defaults — AI provider, generation options,
 * transcription/image settings, logging, locales, icons, the
 * preset-provider map — and gate visibility of every Solaris action.
 *
 * ```php
 * $panel->plugin(
 *     FilamentSolarisPlugin::make()
 *         ->defaultProvider('anthropic', 'claude-sonnet-4-5')
 *         ->defaultTemperature(0.3)
 *         ->visible(fn () => auth()->user()?->can('use-ai'))
 * );
 * ```
 *
 * Overrides apply when {@see Filament::getCurrentPanel()} returns a panel
 * with this plugin registered. Outside a panel context (CLI, queued jobs,
 * non-panel Livewire components) the lookup falls through to global
 * `config/filament-solaris.php`.
 */
class FilamentSolarisPlugin implements Plugin
{
    /**
     * Config-file-keyed overrides. Each setter writes here; lookups via
     * {@see hasOverride()} and {@see getOverride()} read here.
     *
     * @var array<string, mixed>
     */
    protected array $overrides = [];

    /**
     * Visibility predicate for every Solaris action on this panel.
     * `true` (default) leaves visibility entirely to the action's own rules.
     * `false` or a Closure returning `false` hides every Solaris action.
     */
    protected bool|Closure $visibility = true;

    public static function make(): static
    {
        /** @phpstan-ignore-next-line new.static */
        return new static;
    }

    /**
     * Retrieve the plugin instance from the current panel.
     *
     * @return static
     */
    public static function get(): self
    {
        /** @var static */
        return filament(static::class);
    }

    public function getId(): string
    {
        return 'filament-solaris';
    }

    public function register(Panel $panel): void
    {
        // Config-only plugin — nothing to register globally.
    }

    public function boot(Panel $panel): void
    {
        // Config-only plugin — nothing to boot.
    }

    // ──────────────────────────────────────────────────────────────
    //  AI provider / model / timeout
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  Lab|array<string, string>|array<int, string>|string  $provider
     */
    public function defaultProvider(Lab|array|string $provider, ?string $model = null): static
    {
        $this->override('ai.default_provider', $provider);

        if ($model !== null) {
            $this->override('ai.default_model', $model);
        }

        return $this;
    }

    public function defaultModel(string $model): static
    {
        return $this->override('ai.default_model', $model);
    }

    public function defaultTimeout(int $seconds): static
    {
        return $this->override('ai.default_timeout', $seconds);
    }

    // ──────────────────────────────────────────────────────────────
    //  Text-generation options
    // ──────────────────────────────────────────────────────────────

    public function defaultTemperature(float|int $temperature): static
    {
        return $this->override('ai.default_temperature', (float) $temperature);
    }

    public function defaultMaxTokens(int $maxTokens): static
    {
        return $this->override('ai.default_max_tokens', $maxTokens);
    }

    public function defaultMaxSteps(int $maxSteps): static
    {
        return $this->override('ai.default_max_steps', $maxSteps);
    }

    public function defaultTopP(float|int $topP): static
    {
        return $this->override('ai.default_top_p', (float) $topP);
    }

    // ──────────────────────────────────────────────────────────────
    //  Transcription
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  Lab|array<string, string>|array<int, string>|string  $provider
     */
    public function defaultTranscriptionProvider(Lab|array|string $provider, ?string $model = null): static
    {
        $this->override('transcription.default_provider', $provider);

        if ($model !== null) {
            $this->override('transcription.default_model', $model);
        }

        return $this;
    }

    public function defaultTranscriptionModel(string $model): static
    {
        return $this->override('transcription.default_model', $model);
    }

    public function defaultTranscriptionTimeout(int $seconds): static
    {
        return $this->override('transcription.default_timeout', $seconds);
    }

    // ──────────────────────────────────────────────────────────────
    //  Image generation
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  Lab|array<string, string>|array<int, string>|string  $provider
     */
    public function defaultImageProvider(Lab|array|string $provider, ?string $model = null): static
    {
        $this->override('image_generation.default_provider', $provider);

        if ($model !== null) {
            $this->override('image_generation.default_model', $model);
        }

        return $this;
    }

    public function defaultImageModel(string $model): static
    {
        return $this->override('image_generation.default_model', $model);
    }

    public function defaultImageTimeout(int $seconds): static
    {
        return $this->override('image_generation.default_timeout', $seconds);
    }

    public function defaultImageSize(string $size): static
    {
        return $this->override('image_generation.default_size', $size);
    }

    public function defaultImageQuality(string $quality): static
    {
        return $this->override('image_generation.default_quality', $quality);
    }

    public function defaultImageDisk(string $disk): static
    {
        return $this->override('image_generation.default_disk', $disk);
    }

    public function defaultImageDirectory(string $directory): static
    {
        return $this->override('image_generation.default_directory', $directory);
    }

    public function defaultImageVisibility(?string $visibility): static
    {
        return $this->override('image_generation.default_visibility', $visibility);
    }

    // ──────────────────────────────────────────────────────────────
    //  Logging
    // ──────────────────────────────────────────────────────────────

    public function promptLogging(bool $enabled = true): static
    {
        return $this->override('prompt_logging.enabled', $enabled);
    }

    public function promptLoggingChannel(?string $channel): static
    {
        return $this->override('prompt_logging.channel', $channel);
    }

    // ──────────────────────────────────────────────────────────────
    //  Option matching
    // ──────────────────────────────────────────────────────────────

    public function optionFuzzyMatching(bool $enabled = true): static
    {
        return $this->override('option_matching.fuzzy', $enabled);
    }

    public function optionFuzzyThreshold(float $ratio): static
    {
        return $this->override('option_matching.fuzzy_threshold', $ratio);
    }

    public function optionFuzzyMinLength(int $length): static
    {
        return $this->override('option_matching.fuzzy_min_length', $length);
    }

    // ──────────────────────────────────────────────────────────────
    //  Locales
    // ──────────────────────────────────────────────────────────────

    public function defaultLocale(?string $locale): static
    {
        return $this->override('locale.default', $locale);
    }

    /**
     * @param  array<int|string, string>  $locales  Flat list or [code => name] map.
     */
    public function locales(array $locales): static
    {
        return $this->override('locale.supported', $locales);
    }

    // ──────────────────────────────────────────────────────────────
    //  Icons
    // ──────────────────────────────────────────────────────────────

    public function actionIcon(string|BackedEnum $icon): static
    {
        return $this->override('icons.action', $icon);
    }

    public function dictationIcon(string|BackedEnum $icon): static
    {
        return $this->override('icons.dictation', $icon);
    }

    public function imageGenerationIcon(string|BackedEnum $icon): static
    {
        return $this->override('icons.image_generation', $icon);
    }

    public function conversationSendIcon(string|BackedEnum $icon): static
    {
        return $this->override('icons.conversation_send', $icon);
    }

    public function conversationAttachmentIcon(string|BackedEnum $icon): static
    {
        return $this->override('icons.conversation_attachment', $icon);
    }

    // ──────────────────────────────────────────────────────────────
    //  Tone
    // ──────────────────────────────────────────────────────────────

    public function defaultTone(string $tone): static
    {
        return $this->override('default_tone', $tone);
    }

    // ──────────────────────────────────────────────────────────────
    //  Preset providers
    // ──────────────────────────────────────────────────────────────

    /**
     * Override provider/model/options for a single preset class.
     *
     * Repeatable — each call adds or replaces one entry; other preset
     * entries (in this plugin or in the global config) remain intact.
     *
     * @param  class-string  $presetClass
     * @param  Lab|array<string, string>|array<int, string>|string|null  $provider
     */
    public function presetProvider(
        string $presetClass,
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
        float|int|null $temperature = null,
        ?int $maxTokens = null,
        ?int $maxSteps = null,
        float|int|null $topP = null,
    ): static {
        $entry = array_filter([
            'provider' => $provider,
            'model' => $model,
            'timeout' => $timeout,
            'temperature' => $temperature !== null ? (float) $temperature : null,
            'max_tokens' => $maxTokens,
            'max_steps' => $maxSteps,
            'top_p' => $topP !== null ? (float) $topP : null,
        ], fn (mixed $value): bool => $value !== null);

        $this->overrides['preset_providers'][$presetClass] = $entry;

        return $this;
    }

    /**
     * Bulk-register preset overrides, merged with any prior {@see presetProvider()} calls.
     *
     * @param  array<class-string, array<string, mixed>>  $providers
     */
    public function presetProviders(array $providers): static
    {
        $this->overrides['preset_providers'] = array_merge(
            $this->overrides['preset_providers'] ?? [],
            $providers,
        );

        return $this;
    }

    // ──────────────────────────────────────────────────────────────
    //  Visibility gate
    // ──────────────────────────────────────────────────────────────

    /**
     * Set a visibility predicate for every Solaris action on this panel.
     *
     * The predicate is AND'd with each action's own visibility — a Solaris
     * action is shown only when both this predicate and the action's own
     * `->visible()` agree.
     */
    public function visible(bool|Closure $check): static
    {
        $this->visibility = $check;

        return $this;
    }

    /**
     * Disable every Solaris action on this panel. Shorthand for
     * `->visible(false)`.
     */
    public function disabled(): static
    {
        $this->visibility = false;

        return $this;
    }

    /**
     * Evaluate the visibility predicate.
     */
    public function isVisible(): bool
    {
        $value = $this->visibility;

        if ($value instanceof Closure) {
            $value = $value();
        }

        return $value === true;
    }

    // ──────────────────────────────────────────────────────────────
    //  Override accessors (consumed by FilamentSolarisConfig)
    // ──────────────────────────────────────────────────────────────

    /**
     * Record a single override. Internal helper backing every fluent setter
     * so the storage shape stays in one place.
     */
    protected function override(string $key, mixed $value): static
    {
        $this->overrides[$key] = $value;

        return $this;
    }

    public function hasOverride(string $key): bool
    {
        return array_key_exists($key, $this->overrides);
    }

    public function getOverride(string $key): mixed
    {
        return $this->overrides[$key] ?? null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Current-panel lookup
    // ──────────────────────────────────────────────────────────────

    /**
     * Resolve the plugin instance registered on the current Filament panel,
     * if any. Returns null when outside a panel context (CLI, queue,
     * non-panel Livewire) or when the panel doesn't register this plugin.
     */
    public static function current(): ?self
    {
        if (! class_exists(Filament::class)) {
            return null;
        }

        $panel = Filament::getCurrentPanel();

        if ($panel === null) {
            return null;
        }

        try {
            $plugin = $panel->getPlugin('filament-solaris');
        } catch (\Throwable) {
            return null;
        }

        return $plugin instanceof self ? $plugin : null;
    }
}
