<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Statikbe\FilamentSolaris\Actions\SolarisAction;

/**
 * Wires a Livewire component for Solaris's preview / conversational modal.
 *
 * Add this trait to the Livewire component that hosts a form using
 * `->withPreview()` or `->conversational()` actions. Solaris's runtime
 * validation throws if the trait is missing (see
 * {@see SolarisAction::validatePreviewConfiguration()}).
 *
 * The trait's public properties and methods exist so the preview-modal
 * Blade views and the conversational chat Alpine binding can entangle and
 * dispatch into the Livewire component — they're plumbing for the modal,
 * not part of the user-facing API. Consumer code typically only ever
 * `use`s the trait; it doesn't call the methods directly.
 */
trait InteractsWithSolarisPreview
{
    /**
     * Preview data stored between halt() and accept/discard.
     *
     * @internal Livewire-entangled state for the preview modal Blade view.
     *
     * @var array{values: array<string, mixed>, displays: array<string, array<string, string>>, filledLabels: array<string>, failedLabels: array<string>, actionName: string}|null
     */
    public ?array $solarisPreviewData = null;

    /**
     * Files attached to the next conversational refinement turn.
     *
     * Holds the standard Livewire file-upload shape
     * (`[uuid => TemporaryUploadedFile, ...]`) until consumed by `refine()`.
     *
     * @internal Livewire-entangled state for the conversational chat UI.
     *
     * @var array<string, mixed>
     */
    public array $solarisRefinementAttachments = [];

    /**
     * Accept the preview and apply values to the form.
     *
     * Delegates to the mounted action's acceptPreview() method, allowing
     * each action type to handle acceptance differently (e.g. AiAction
     * writes field values, ImageGenerationAction stores and writes images).
     *
     * @internal Dispatched from the preview modal's Accept button via Livewire.
     */
    public function solarisAcceptPreview(): void
    {
        if ($this->solarisPreviewData === null) {
            return;
        }

        $data = $this->solarisPreviewData;
        $this->solarisPreviewData = null;

        $action = $this->getMountedAction();

        if ($action !== null && method_exists($action, 'acceptPreview')) {
            $action->acceptPreview($data);
        }

        $this->unmountAction();
    }

    /**
     * Refine the preview results with a conversational follow-up message.
     *
     * @internal Dispatched from the conversational chat send button via Livewire.
     */
    public function solarisRefinePreview(string $message): void
    {
        if ($this->solarisPreviewData === null) {
            return;
        }

        if (! ($this->solarisPreviewData['isConversational'] ?? false)) {
            return;
        }

        $action = $this->getMountedAction();

        if ($action === null || ! method_exists($action, 'refine')) {
            return;
        }

        $turnAttachments = $this->solarisRefinementAttachments;
        $this->solarisRefinementAttachments = [];

        $action->refine($message, $turnAttachments);

        $this->dispatch('solaris-clear-refinement-attachments');
    }

    /**
     * Discard the preview and close the modal.
     *
     * @internal Dispatched from the preview modal's Cancel/Discard button via Livewire.
     */
    public function solarisDiscardPreview(): void
    {
        $this->solarisPreviewData = null;
        $this->unmountAction();
    }

    /**
     * Clean up preview data when the action is unmounted.
     *
     * @internal Filament hook — invoked by the action lifecycle, not by user code.
     */
    public function unmountAction(bool $canCancelParentActions = true): void
    {
        $this->solarisPreviewData = null;
        $this->solarisRefinementAttachments = [];

        parent::unmountAction($canCancelParentActions);
    }
}
