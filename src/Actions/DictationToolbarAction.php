<?php

namespace Statikbe\FilamentSolaris\Actions;

use Closure;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use LogicException;
use RuntimeException;
use Statikbe\FilamentSolaris\Concerns\HandlesDictation;
use Statikbe\FilamentSolaris\Concerns\HasPreviewModal;
use Statikbe\FilamentSolaris\RichEditor\DictationRichEditorPlugin;
use Statikbe\FilamentSolaris\Testing\DictationToolbarActionFake;

/**
 * Dictation action triggered by a RichEditor toolbar button.
 *
 * Internal collaborator of {@see DictationRichEditorPlugin};
 * consumers don't construct it directly. It reuses the shared recording modal /
 * transcription plumbing from {@see HandlesDictation} and, on submit, inserts
 * the transcript at the editor's cursor via `RichEditor::runCommands()` —
 * mirroring how Filament's built-in AttachFilesAction inserts an image.
 *
 * Scope is pure transcription: there is no AI preset/prompt pipeline (so no
 * HasPromptPipeline / HasFormPipeline) and no preview / conversational mode.
 */
class DictationToolbarAction extends SolarisAction
{
    use HandlesDictation;

    /**
     * Default name; must match the tool name the plugin registers so the
     * tool's generated `$wire.mountAction('solarisDictation', …)` resolves here.
     */
    public static function make(?string $name = 'solarisDictation'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // No extra schema: pure transcription, no UserInput / pipeline fields.
        $this->setUpDictationModal();

        $this->action(function (DictationToolbarAction $action, array $data = [], array $arguments = []): void {
            $action->processDictation($data, $arguments);
        });
    }

    /**
     * This action has no user-input fields — always false.
     *
     * Required by {@see HasPreviewModal}, which
     * declares it abstract. Actions that mix in HasUserInput (via HasPromptPipeline)
     * get a real implementation; we supply the trivial one here.
     */
    public function hasUserInput(): bool
    {
        return false;
    }

    /**
     * Resolve the locale for the transcription language fallback.
     *
     * {@see HandlesDictation::getTranscriptionLang()} calls `$this->getLocale()`
     * when no explicit `->lang()` is set. Actions that use {@see HasPromptPipeline}
     * get this method for free (it also handles a `->locale()` override); this
     * action doesn't use that trait, so we provide the simple app-locale fallback.
     */
    public function getLocale(): string
    {
        return app()->getLocale();
    }

    /**
     * The recorder modal must never be replaced by the preview loading spinner.
     * Same rationale as DictationFieldAction::isPreviewLoading().
     */
    protected function isPreviewLoading(): bool
    {
        return false;
    }

    /**
     * Transcribe the recorded audio and insert it at the editor cursor.
     *
     * @param  array<string, mixed>  $data  Modal form data (the hidden FileUpload state)
     * @param  array<string, mixed>  $arguments  Mounted-action arguments (carries `editorSelection`)
     */
    public function processDictation(array $data = [], array $arguments = []): void
    {
        if (DictationToolbarActionFake::isActive()) {
            $this->processFakeDictation($data, $arguments);

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

        $this->insertTranscript($transcript, $arguments['editorSelection'] ?? null);
    }

    /**
     * Insert the transcript as plain text at the editor's current selection.
     *
     * @param  array<string, mixed>|null  $editorSelection
     */
    protected function insertTranscript(string $transcript, ?array $editorSelection): void
    {
        $editor = $this->getSchemaComponent();

        if (! $editor instanceof RichEditor) {
            throw new RuntimeException(
                'DictationToolbarAction could not resolve the host RichEditor. '
                .'It must be registered via DictationRichEditorPlugin.'
            );
        }

        $editor->runCommands(
            [EditorCommand::make('insertContent', [$transcript])],
            editorSelection: $editorSelection,
        );

        Notification::make()
            ->title(filament_solaris_trans('notifications.transcription_inserted'))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    protected function processFakeDictation(array $data, array $arguments): void
    {
        $fake = DictationToolbarActionFake::getInstance();
        $transcript = $fake->getTranscript();

        $fake->recordCall($this->getName(), $transcript);

        ['provider' => $provider, 'model' => $model] = $this->resolveTranscriptionProviderAndModel();
        $this->dispatchFakeResponseReceived($provider, $model);

        $this->insertTranscript($transcript, $arguments['editorSelection'] ?? null);
    }

    /**
     * The toolbar action has no preview modal — unreachable guard.
     *
     * @param  array<string, mixed>  $data
     */
    public function acceptPreview(array $data): void
    {
        throw new LogicException('DictationToolbarAction does not support the preview modal.');
    }

    /**
     * The toolbar action has no conversational refinement — unreachable guard.
     *
     * @param  array<string, mixed>  $turnAttachments
     */
    public function refine(string $message, array $turnAttachments = []): void
    {
        throw new LogicException('DictationToolbarAction does not support conversational refinement.');
    }

    // ──────────────────────────────────────────────────────────────
    //  Testing
    // ──────────────────────────────────────────────────────────────

    public static function fake(string $transcript = ''): DictationToolbarActionFake
    {
        return DictationToolbarActionFake::activate($transcript);
    }

    public static function assertCalled(): void
    {
        DictationToolbarActionFake::getInstance()->assertCalled();
    }

    public static function assertTranscribed(): void
    {
        DictationToolbarActionFake::getInstance()->assertTranscribed();
    }

    public static function assertTranscribedWith(Closure $callback): void
    {
        DictationToolbarActionFake::getInstance()->assertTranscribedWith($callback);
    }

    public static function assertNotCalled(): void
    {
        DictationToolbarActionFake::getInstance()->assertNotCalled();
    }

    public static function assertCalledTimes(int $count): void
    {
        DictationToolbarActionFake::getInstance()->assertCalledTimes($count);
    }
}
