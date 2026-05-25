<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Statikbe\FilamentSolaris\Concerns\HandlesDictation;
use Statikbe\FilamentSolaris\Concerns\HasFormPipeline;
use Statikbe\FilamentSolaris\Concerns\HasPromptPipeline;
use Statikbe\FilamentSolaris\Testing\AiFormActionFake;
use Statikbe\FilamentSolaris\Testing\DictationFieldActionFake;

/**
 * Dictation action attached to any Filament form field.
 *
 * Attach via the universally-available `->hintAction(...)` (works on every
 * Filament `Field` including `Textarea` and `RichEditor`) or via
 * `->suffixAction(...)` on `TextInput` specifically:
 *
 * ```php
 * Textarea::make('voice_input')->hintAction(DictationFieldAction::make())
 * TextInput::make('voice')->suffixAction(DictationFieldAction::make())
 * ```
 *
 * Clicking the button opens the recording modal. On submit the audio is
 * transcribed and the resulting text is written into the **host field**
 * (auto-resolved via `$this->getSchemaComponent()->getName()`) — no
 * `->targetField()` call needed at the consumer site, though it's still
 * available as an explicit override.
 *
 * Supports the AI-chaining pattern from the (removed) top-level dictation
 * action: configure a preset/prompt and the transcript flows through the
 * AI pipeline before being written:
 *
 * ```php
 * Textarea::make('voice')->hintAction(
 *     DictationFieldAction::make()
 *         ->preset(\Statikbe\FilamentSolaris\Prompts\Presets\SummaryPreset::make())
 * )
 * ```
 *
 * Composes naturally inside an `AiFormAction`'s UserInput modal — the action's
 * own modal opens layered over the parent, transcribes, and the result
 * lands in the host field of the outer modal's schema.
 */
class DictationFieldAction extends SolarisAction
{
    use HandlesDictation;
    use HasFormPipeline;
    use HasPromptPipeline;

    /**
     * Whether to append the transcript to the host field's existing value.
     */
    protected bool|Closure $append = false;

    /**
     * Default name when the consumer omits it. Filament requires a name on
     * every Action; this lets `->hintAction(DictationFieldAction::make())`
     * work without `make('dictate')` at every call site.
     */
    public static function make(?string $name = 'dictate'): static
    {
        return parent::make($name);
    }

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDictationModal(
            // When AI chaining is configured, surface the preset's user-input
            // fields inside the same dictation modal so the user can record
            // AND provide additional instructions in one flow.
            fn (): array => $this->hasPromptBuilder() ? $this->getUserInputFormSchema() : [],
        );

        $this->action(function (DictationFieldAction $action, array $data = []) {
            $action->processDictation($data);
        });
    }

    /**
     * Whether to append the transcript to the host field's existing content
     * instead of replacing it.
     */
    public function append(bool|Closure $append = true): static
    {
        $this->append = $append;

        return $this;
    }

    /**
     * Dictation never enters the {@see HasPreviewModal} "loading spinner"
     * intermediate state. Its modal goes recorder → (server-side transcribe +
     * optional AI pipeline) → close (pure transcription) or preview view
     * (when a prompt/preset is configured). Letting the default
     * `shouldPreview() && !hasUserInput() && !hasPreviewData()` test fire would
     * replace the recorder UI with the spinner on initial mount AND null out
     * the submit/cancel actions, causing Filament to auto-execute the action
     * with empty `$data` (then the missing audio surfaces as the generic
     * "Could not transcribe the audio" notification).
     */
    protected function isPreviewLoading(): bool
    {
        return false;
    }

    /**
     * Whether append mode is enabled.
     */
    public function shouldAppend(): bool
    {
        return (bool) $this->evaluate($this->append);
    }

    /**
     * Resolve the target field — explicit `->targetField()` wins; otherwise
     * fall back to the host field this suffix is attached to.
     *
     * Used by both the pure-transcription write-back path and the AI
     * pipeline's `transformResults` / `writeResults` machinery. Reads the
     * trait property directly because `HasTargetFields` is mixed in via
     * {@see HasPromptPipeline} — there's no `parent::getTargetFields()` to
     * defer to.
     *
     * @return array<string>
     */
    public function getTargetFields(): array
    {
        $configured = $this->evaluate($this->targetFieldNames);

        if (! empty($configured)) {
            return $configured;
        }

        $host = $this->getSchemaComponent();

        return $host instanceof Field ? [$host->getName()] : [];
    }

    /**
     * Process the dictation after audio upload.
     *
     * @param  array<string, mixed>  $data  Modal form data (FileUpload state + any UserInput fields)
     */
    public function processDictation(array $data = []): void
    {
        $this->validatePreviewConfiguration();

        if (DictationFieldActionFake::isActive()) {
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

        if ($transcript === null) {
            return;
        }

        $this->processTranscript($transcript, $data);
    }

    /**
     * Either write the transcript directly to the host field or feed it
     * through the AI pipeline first.
     *
     * @param  array<string, mixed>  $data
     */
    protected function processTranscript(string $transcript, array $data = []): void
    {
        if ($this->hasPromptBuilder()) {
            $sourceData = ['transcription' => $transcript];

            if (AiFormActionFake::isActive()) {
                $this->runFakePipeline($sourceData, $data);
            } else {
                $this->runPipeline($sourceData, $data);
            }

            return;
        }

        $this->writeTranscript($transcript);
    }

    /**
     * Write the transcript directly to the resolved target field.
     */
    protected function writeTranscript(string $transcript): void
    {
        $schemaComponent = $this->resolveFormSchemaComponent();

        if ($schemaComponent === null) {
            throw new RuntimeException('DictationFieldAction could not resolve a form schema component.');
        }

        $targetField = $this->getTargetFields()[0] ?? null;

        if ($targetField === null) {
            throw new RuntimeException('DictationFieldAction could not resolve a target field. Attach the action to a TextInput/Textarea via ->suffixAction(...) or set ->targetField() explicitly.');
        }

        $set = $schemaComponent
            ->makeSetUtility()
            ->skipComponentsChildContainersWhileSearching(false);

        if ($this->shouldAppend()) {
            $existing = $schemaComponent->makeGetUtility()($targetField);

            if (filled($existing)) {
                $transcript = $existing."\n".$transcript;
            }
        }

        $set($targetField, $transcript);

        Notification::make()
            ->title(filament_solaris_trans('notifications.transcription_success', [
                'fields' => "'".$this->resolveFieldLabel($targetField)."'",
            ]))
            ->success()
            ->send();
    }

    /**
     * Process with a faked transcript (and optionally a faked AI response).
     *
     * @param  array<string, mixed>  $data
     */
    protected function processFakeDictation(array $data = []): void
    {
        $fake = DictationFieldActionFake::getInstance();
        $transcript = $fake->getTranscript();

        $fake->recordCall($this->getName(), $transcript);

        ['provider' => $provider, 'model' => $model] = $this->resolveTranscriptionProviderAndModel();
        $this->dispatchFakeResponseReceived($provider, $model);

        $this->processTranscript($transcript, $data);
    }

    // ──────────────────────────────────────────────────────────────
    //  Testing
    // ──────────────────────────────────────────────────────────────

    /**
     * Activate the fake for testing.
     *
     * @param  array<string, mixed>|null  $aiResponse
     */
    public static function fake(string $transcript = '', ?array $aiResponse = null): DictationFieldActionFake
    {
        return DictationFieldActionFake::activate($transcript, $aiResponse);
    }

    public static function assertCalled(): void
    {
        DictationFieldActionFake::getInstance()->assertCalled();
    }

    public static function assertTranscribed(): void
    {
        DictationFieldActionFake::getInstance()->assertTranscribed();
    }

    public static function assertTranscribedWith(Closure $callback): void
    {
        DictationFieldActionFake::getInstance()->assertTranscribedWith($callback);
    }

    public static function assertNotCalled(): void
    {
        DictationFieldActionFake::getInstance()->assertNotCalled();
    }

    public static function assertCalledTimes(int $count): void
    {
        DictationFieldActionFake::getInstance()->assertCalledTimes($count);
    }
}
