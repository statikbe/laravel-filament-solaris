<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Transcription;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Statikbe\FilamentSolaris\Concerns\HasFormPipeline;
use Statikbe\FilamentSolaris\Concerns\HasPromptPipeline;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;
use Statikbe\FilamentSolaris\Testing\AiActionFake;
use Statikbe\FilamentSolaris\Testing\DictationActionFake;

class DictationAction extends SolarisAction
{
    use HasFormPipeline;
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
     * Schema-state key the recorded audio is uploaded to.
     *
     * Lives inside the action modal's data array — addressed as
     * `mountedActions.{nestingIndex}.data.{KEY}` from the browser side.
     */
    public const AUDIO_FIELD = 'solaris_dictation_audio';

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon(FilamentSolaris::config()->getDictationIcon());

        $this->modalContent(function (DictationAction $action) {
            /** @var view-string $viewName */
            $viewName = 'filament-solaris::dictation-modal';

            return view($viewName, [
                'statePath' => $this->resolveAudioStatePath(),
            ]);
        });

        $this->modalHeading(fn () => filament_solaris_trans('dictation.modal_heading'));

        $this->modalSubmitActionLabel(fn () => filament_solaris_trans('dictation.submit_label'));

        $this->schema(function (DictationAction $action): array {
            if ($this->hasPreviewData()) {
                return [];
            }

            return [
                // Schema-bound FileUpload that receives the Alpine recorder's
                // upload via Filament's own state-binding machinery. The
                // dropzone UI is suppressed via `columnSpan('hidden')` (the
                // same trick {@see \Filament\Forms\Components\Hidden::setUp()}
                // uses) — the field is registered in the schema so its state
                // round-trips through the modal's data array, but no UI is
                // rendered. Storage is disabled so the temp upload is left
                // for us to read and discard.
                FileUpload::make(self::AUDIO_FIELD)
                    ->columnSpan(['default' => 'hidden'])
                    ->dehydrated()
                    ->storeFiles(false),
                ...($action->hasPromptBuilder() ? $action->getUserInputFormSchema() : []),
            ];
        });

        $this->action(function (DictationAction $action, array $data = []) {
            $action->processDictation($data);
        });
    }

    /**
     * Compute the full Livewire state path the Alpine recorder uploads to.
     *
     * Filament mounts each action's schema at `mountedActions.{N}.data` where
     * N is the nesting index (0 for the top-most action, 1 if nested inside
     * another action's modal — e.g. a dictation suffix inside an AiAction's
     * UserInput modal). Computed at render time so it survives modal stacking.
     */
    protected function resolveAudioStatePath(): string
    {
        $livewire = $this->getLivewire();
        $mounted = method_exists($livewire, 'getMountedActions')
            ? $livewire->getMountedActions()
            : [];
        $nestingIndex = max(0, count($mounted) - 1);

        return sprintf('mountedActions.%d.data.%s', $nestingIndex, self::AUDIO_FIELD);
    }

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
     * Process the dictation after audio upload.
     *
     * @param  array<string, mixed>  $data  Modal form data (user input)
     */
    public function processDictation(array $data = []): void
    {
        $this->validateDictationConfiguration();
        $this->validatePreviewConfiguration();

        // Check if faked
        if (DictationActionFake::isActive()) {
            $this->processFakeDictation($data);

            return;
        }

        $audioFile = $this->extractAudioFile($data[self::AUDIO_FIELD] ?? null);

        if (! $audioFile instanceof UploadedFile) {
            Notification::make()
                ->title(filament_solaris_trans('notifications.transcription_error'))
                ->danger()
                ->send();

            return;
        }

        $transcript = $this->transcribe($audioFile);

        // Filament's modal lifecycle discards the action's data array on
        // unmount, so the TemporaryUploadedFile gets garbage-collected
        // without an explicit cleanup step.

        if ($transcript === null) {
            return;
        }

        $this->processTranscript($transcript, $data);
    }

    /**
     * Normalise the FileUpload state value into a single UploadedFile.
     *
     * Filament FileUpload's state is either a `TemporaryUploadedFile`, an
     * `[uuid => TemporaryUploadedFile]` map, or null. The Alpine recorder
     * uploads a single file per session, so we accept any of those shapes
     * and return the first uploaded file (or null when nothing was uploaded).
     */
    protected function extractAudioFile(mixed $value): ?UploadedFile
    {
        if ($value instanceof UploadedFile) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $entry) {
                if ($entry instanceof UploadedFile) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * Transcribe the uploaded audio file.
     */
    protected function transcribe(UploadedFile $audioFile): ?string
    {
        ['provider' => $provider, 'model' => $model] = $this->resolveTranscriptionProviderAndModel();
        $timeout = $this->resolveTranscriptionTimeout();

        $pending = Transcription::fromUpload($audioFile)
            ->language($this->getTranscriptionLang());

        if ($timeout !== null) {
            $pending->timeout($timeout);
        }

        $response = $this->executeAiCall(
            fn () => $pending->generate($provider, $model),
            $provider,
            $model,
            function (AiException $e): void {
                report($e);

                $title = match (true) {
                    $e instanceof RateLimitedException => filament_solaris_trans('notifications.transcription_rate_limited'),
                    $e instanceof ProviderOverloadedException => filament_solaris_trans('notifications.transcription_overloaded'),
                    default => filament_solaris_trans('notifications.transcription_error'),
                };

                Notification::make()
                    ->title($title)
                    ->danger()
                    ->send();
            },
        );

        if ($response === null) {
            return null;
        }

        $text = (string) $response;

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

        ['provider' => $provider, 'model' => $model] = $this->resolveTranscriptionProviderAndModel();
        $this->dispatchFakeResponseReceived($provider, $model);

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

    // ──────────────────────────────────────────────────────────────
    //  Testing
    // ──────────────────────────────────────────────────────────────

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
     * Assert that no dictation action was called.
     */
    public static function assertNotCalled(): void
    {
        DictationActionFake::getInstance()->assertNotCalled();
    }

    /**
     * Assert the number of times a dictation action was called.
     */
    public static function assertCalledTimes(int $count): void
    {
        DictationActionFake::getInstance()->assertCalledTimes($count);
    }
}
