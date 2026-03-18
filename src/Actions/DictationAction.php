<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Transcription;
use RuntimeException;
use Statikbe\FilamentSolaris\Concerns\HasPromptPipeline;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Testing\DictationActionFake;

class DictationAction extends Action
{
    use HasPromptPipeline;

    protected bool|Closure $append = false;

    protected string|Closure|null $transcriptionLang = null;

    /**
     * @var Lab|array<string, string>|array<int, string>|string|Closure|null
     */
    protected Lab|array|string|Closure|null $transcriptionProvider = null;

    protected string|Closure|null $transcriptionModel = null;

    protected int|Closure|null $transcriptionTimeout = null;

    /**
     * Whether to append the transcript to existing field content.
     */
    public function append(bool|Closure $append = true): static
    {
        $this->append = $append;

        return $this;
    }

    /**
     * Check if append mode is enabled.
     */
    public function shouldAppend(): bool
    {
        return (bool) $this->evaluate($this->append);
    }

    /**
     * Set the BCP 47 language tag for the transcription engine.
     */
    public function lang(string|Closure $lang): static
    {
        $this->transcriptionLang = $lang;

        return $this;
    }

    /**
     * Get the transcription language, falling back to the action's locale.
     */
    public function getTranscriptionLang(): string
    {
        return $this->evaluate($this->transcriptionLang) ?? $this->getLocale();
    }

    /**
     * Set the transcription provider (and optionally model).
     *
     * @param  Lab|array<string, string>|array<int, string>|string|Closure  $provider
     */
    public function transcriptionProvider(Lab|array|string|Closure $provider, string|Closure|null $model = null): static
    {
        $this->transcriptionProvider = $provider;

        if ($model !== null) {
            $this->transcriptionModel = $model;
        }

        return $this;
    }

    /**
     * Set the timeout in seconds for the transcription call.
     */
    public function transcriptionTimeout(int|Closure $timeout): static
    {
        $this->transcriptionTimeout = $timeout;

        return $this;
    }

    /**
     * Resolve the transcription provider and model.
     *
     * @return array{provider: Lab|array|string|null, model: ?string}
     */
    protected function resolveTranscriptionProviderAndModel(): array
    {
        $provider = $this->evaluate($this->transcriptionProvider);
        if ($provider !== null) {
            return [
                'provider' => $provider,
                'model' => $this->evaluate($this->transcriptionModel),
            ];
        }

        $config = FilamentSolaris::config();

        return [
            'provider' => $config->getDefaultTranscriptionProvider(),
            'model' => $config->getDefaultTranscriptionModel(),
        ];
    }

    /**
     * Resolve the transcription timeout.
     */
    protected function resolveTranscriptionTimeout(): ?int
    {
        $timeout = $this->evaluate($this->transcriptionTimeout);

        if ($timeout !== null) {
            return $timeout;
        }

        return FilamentSolaris::config()->getDefaultTranscriptionTimeout();
    }

    /**
     * Activate the fake for testing.
     *
     * @param  array<string, mixed>|null  $aiResponse
     */
    public static function fake(string $transcript = '', ?array $aiResponse = null): DictationActionFake
    {
        return DictationActionFake::activate($transcript, $aiResponse);
    }

    /**
     * Assert that a dictation action was called.
     */
    public static function assertCalled(): void
    {
        DictationActionFake::getInstance()->assertCalled();
    }

    /**
     * Assert that a transcription was processed.
     */
    public static function assertTranscribed(): void
    {
        DictationActionFake::getInstance()->assertTranscribed();
    }

    /**
     * Assert the transcript with a callback.
     */
    public static function assertTranscribedWith(Closure $callback): void
    {
        DictationActionFake::getInstance()->assertTranscribedWith($callback);
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon('heroicon-o-microphone');

        $this->alpineClickHandler('toggle()');

        $this->extraAttributes(fn (DictationAction $action): array => [
            'x-load' => '',
            'x-load-src' => FilamentAsset::getAlpineComponentSrc('dictation', 'statikbe/filament-solaris'),
            'x-data' => "dictation({ actionName: '".$action->getName()."' })",
            'x-show' => 'supported',
            'x-bind:class' => "{ 'animate-pulse': recording }",
        ]);

        $this->schema(fn (DictationAction $action): array => $action->hasPromptBuilder() ? $action->getUserInputFormSchema() : []);

        $this->action(function (DictationAction $action, array $arguments = [], array $data = []) {
            $action->processDictation($arguments, $data);
        });
    }

    /**
     * Process the dictation after audio upload.
     *
     * @param  array<string, mixed>  $arguments  Arguments from mountAction (e.g. error codes from Alpine)
     * @param  array<string, mixed>  $data  Modal form data (user input)
     */
    public function processDictation(array $arguments = [], array $data = []): void
    {
        $this->validateDictationConfiguration();

        // Handle errors dispatched from the Alpine component
        if (isset($arguments['__dictationError'])) {
            $this->handleClientError($arguments['__dictationError']);

            return;
        }

        // Check if faked
        if (DictationActionFake::isActive()) {
            $this->processFakeDictation($data);

            return;
        }

        // Get the uploaded audio file
        $livewire = $this->getLivewire();
        $audioFile = $livewire->componentFileAttachments['dictation_audio'] ?? null;

        if (! $audioFile instanceof UploadedFile) {
            Notification::make()
                ->title(filament_solaris_trans('notifications.transcription_error'))
                ->danger()
                ->send();

            return;
        }

        try {
            $transcript = $this->transcribe($audioFile);
        } finally {
            // Clean up the temporary audio file
            unset($livewire->componentFileAttachments['dictation_audio']);
        }

        if ($transcript === null) {
            return;
        }

        $this->processTranscript($transcript, $data);
    }

    /**
     * Handle an error dispatched from the client-side Alpine component.
     */
    protected function handleClientError(string $errorCode): void
    {
        $key = match ($errorCode) {
            'microphone_denied' => 'notifications.microphone_denied',
            default => 'notifications.transcription_error',
        };

        Notification::make()
            ->title(filament_solaris_trans($key))
            ->danger()
            ->send();
    }

    /**
     * Transcribe the uploaded audio file.
     */
    protected function transcribe(UploadedFile $audioFile): ?string
    {
        try {
            ['provider' => $provider, 'model' => $model] = $this->resolveTranscriptionProviderAndModel();
            $timeout = $this->resolveTranscriptionTimeout();

            $pending = Transcription::fromUpload($audioFile)
                ->language($this->getTranscriptionLang());

            if ($timeout !== null) {
                $pending->timeout($timeout);
            }

            $response = $pending->generate($provider, $model);

            $text = (string) $response;
        } catch (RateLimitedException $e) {
            report($e);
            Notification::make()
                ->title(filament_solaris_trans('notifications.transcription_rate_limited'))
                ->danger()
                ->send();

            return null;
        } catch (AiException $e) {
            report($e);
            Notification::make()
                ->title(filament_solaris_trans('notifications.transcription_error'))
                ->danger()
                ->send();

            return null;
        }

        if (blank($text)) {
            Notification::make()
                ->title(filament_solaris_trans('notifications.transcription_empty'))
                ->warning()
                ->send();

            return null;
        }

        return $text;
    }

    /**
     * Process a transcript: either write directly or run through the AI pipeline.
     *
     * @param  array<string, mixed>  $data  User input data
     */
    protected function processTranscript(string $transcript, array $data = []): void
    {
        if ($this->hasPromptBuilder()) {
            // AI processing mode — feed transcript as source data into the pipeline
            $sourceData = ['transcription' => $transcript];

            if (AiActionFake::isActive()) {
                $this->runFakePipeline($sourceData, $data);
            } else {
                $this->runPipeline($sourceData, $data);
            }

            return;
        }

        // Pure transcription mode — write directly to target field
        $this->writeTranscript($transcript);
    }

    /**
     * Write the transcript directly to the first target field.
     */
    protected function writeTranscript(string $transcript): void
    {
        $schemaComponent = $this->resolveFormSchemaComponent();

        if ($schemaComponent === null) {
            throw new RuntimeException('DictationAction could not resolve a form schema component.');
        }

        $targetField = $this->getTargetFields()[0];
        $set = $schemaComponent
            ->makeSetUtility()
            ->skipComponentsChildContainersWhileSearching(false);

        if ($this->shouldAppend()) {
            $get = $schemaComponent->makeGetUtility();
            $existing = $get($targetField);

            if (filled($existing)) {
                $transcript = $existing."\n".$transcript;
            }
        }

        $set($targetField, $transcript);

        $label = $this->resolveFieldLabel($targetField);

        Notification::make()
            ->title(filament_solaris_trans('notifications.transcription_success', [
                'fields' => "'{$label}'",
            ]))
            ->success()
            ->send();
    }

    /**
     * Process with fake transcript for testing.
     *
     * @param  array<string, mixed>  $data  User input data
     */
    protected function processFakeDictation(array $data = []): void
    {
        $fake = DictationActionFake::getInstance();
        $transcript = $fake->getTranscript();

        $fake->recordCall($this->getName(), $transcript);

        $this->processTranscript($transcript, $data);
    }

    /**
     * Validate the dictation action configuration.
     *
     * @throws RuntimeException
     */
    protected function validateDictationConfiguration(): void
    {
        if (empty($this->getTargetFields())) {
            throw new RuntimeException('DictationAction requires at least one target field.');
        }
    }
}
