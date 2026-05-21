<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Transcription;
use Statikbe\FilamentSolaris\Actions\DictationFieldAction;
use Statikbe\FilamentSolaris\Actions\SolarisAction;
use Statikbe\FilamentSolaris\Facades\FilamentSolaris;

/**
 * Shared dictation plumbing for any Solaris action that records audio,
 * transcribes it via `laravel/ai`, and hands the resulting string back to
 * its consumer.
 *
 * Consumed by:
 *   - {@see DictationFieldAction} — attached
 *     to a Filament `Field` via `->hintAction(...)` (universal) or to a
 *     `TextInput` via `->suffixAction(...)`; writes the transcript back into
 *     the host field.
 *   - (Planned)  `DictationToolbarAction` — owns the same recording modal but
 *     is triggered by a `RichEditorTool` in the editor's toolbar and inserts
 *     the transcript at the editor's cursor.
 *
 * The trait owns: the recording modal schema + content + heading, the audio
 * state-path resolution (incl. modal-stacking), the file-extraction helper,
 * the language/provider/timeout setters and resolution chains, and the
 * `transcribe()` call with its 3-way error notification. What it does NOT own
 * is the write-back step — each consumer decides how to use the transcribed
 * string.
 */
trait HandlesDictation
{
    /**
     * Schema-state key the recorded audio is uploaded to.
     *
     * Addressed as `mountedActions.{nestingIndex}.data.{KEY}` from the
     * browser side — see {@see resolveAudioStatePath()}.
     */
    public const AUDIO_FIELD = 'solaris_dictation_audio';

    protected string|Closure|null $transcriptionLang = null;

    /**
     * @var Lab|array<string, string>|array<int, string>|string|Closure|null
     */
    protected Lab|array|string|Closure|null $transcriptionProvider = null;

    protected string|Closure|null $transcriptionModel = null;

    protected int|Closure|null $transcriptionTimeout = null;

    /**
     * Set the language hint for the transcription engine.
     *
     * **Format is provider-specific.** Most transcription APIs (OpenAI Whisper,
     * Mistral, etc.) want ISO 639-1 alpha-2 like `'en'`, `'nl'`, `'fr'`.
     * A handful (Google Speech, Azure Speech) accept BCP 47 with a region tag
     * like `'nl-BE'`. Passing `'nl-BE'` to Mistral or Whisper yields a 422
     * "Invalid language alpha2 code" — strip the region for those.
     *
     * When you need provider-specific values, pair `->lang()` with
     * `->transcriptionProvider()` rather than relying on the package default.
     */
    public function lang(string|Closure $lang): static
    {
        $this->transcriptionLang = $lang;

        return $this;
    }

    /**
     * Resolve the transcription language, falling back to the action's locale.
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
     * Resolve the transcription timeout in seconds.
     */
    protected function resolveTranscriptionTimeout(): ?int
    {
        return $this->evaluate($this->transcriptionTimeout)
            ?? FilamentSolaris::config()->getDefaultTranscriptionTimeout();
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
     * Configure the dictation recording modal — icon, heading, submit-label,
     * the Alpine recorder view as modal content, and the hidden FileUpload
     * field in the schema that receives the upload.
     *
     * Call from the consuming action's `setUp()` after `parent::setUp()`.
     * The consumer is responsible for adding `$this->action(...)` so the
     * submit handler runs its own post-transcription logic.
     *
     * @param  Closure|null  $extraSchema  optional fn(): array of additional schema fields to append after the FileUpload (e.g. UserInput form fields when chaining the transcript into an AI pipeline)
     */
    protected function setUpDictationModal(?Closure $extraSchema = null): void
    {
        $this->icon(FilamentSolaris::config()->getDictationIcon());

        $this->modalContent(function () {
            /** @var view-string $viewName */
            $viewName = 'filament-solaris::dictation-modal';

            return view($viewName, [
                'statePath' => $this->resolveAudioStatePath(),
            ]);
        });

        $this->modalHeading(fn () => filament_solaris_trans('dictation.modal_heading'));

        $this->modalSubmitActionLabel(fn () => filament_solaris_trans('dictation.submit_label'));

        // Block submit while the recorder is busy (recording or uploading).
        // Without this, a fast click on submit during recording would fire the
        // action callback with no audio file in `$data` and surface a generic
        // "Could not transcribe the audio" notification.
        //
        // We park a small `solarisDictationBusy` flag in an x-data on the
        // modal window itself — it's an ancestor of both the recorder
        // (modalContent) and the submit button (modal footer), so Alpine's
        // lexical-scope inheritance makes the flag visible to both. The
        // recorder $dispatches a custom event on state change; the modal
        // window's listener updates the flag; the submit button's
        // x-bind:disabled reads it. No global store, no service-provider
        // bootstrap, modal-scoped state.
        $this->extraModalWindowAttributes([
            'x-data' => '{ solarisDictationBusy: false }',
            'x-on:solaris-dictation-busy' => 'solarisDictationBusy = $event.detail.busy',
        ]);

        $this->modalSubmitAction(fn (Action $action): Action => $action->extraAttributes([
            'x-bind:disabled' => 'solarisDictationBusy',
        ]));

        $this->schema(fn (): array => [
            // Schema-bound FileUpload that receives the Alpine recorder's
            // upload via Filament's own state-binding machinery. The
            // dropzone UI is suppressed via `columnSpan('hidden')` — the
            // field is registered in the schema so its state round-trips
            // through the modal's data array, but no UI is rendered.
            // Storage is disabled so the temp upload is left for us to
            // read and discard.
            FileUpload::make(self::AUDIO_FIELD)
                ->columnSpan(['default' => 'hidden'])
                ->dehydrated()
                ->storeFiles(false),
            ...($this->evaluate($extraSchema) ?? []),
        ]);
    }

    /**
     * Transcribe the uploaded audio file.
     *
     * Routes through {@see SolarisAction::executeAiCall()}
     * so the SolarisResponseReceived/Failed events fire, then matches the
     * specific AiException subtype to render the right user-facing
     * notification (rate-limited / overloaded / generic). Returns the
     * transcribed text on success, null on error or empty transcript.
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
}
