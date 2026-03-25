<?php

namespace Statikbe\FilamentSolaris\Concerns;

trait InteractsWithSolarisPreview
{
    /**
     * Preview data stored between halt() and accept/discard.
     *
     * @var array{values: array<string, mixed>, displays: array<string, array<string, string>>, filledLabels: array<string>, failedLabels: array<string>, actionName: string}|null
     */
    public ?array $solarisPreviewData = null;

    /**
     * Accept the preview and apply values to the form.
     *
     * Delegates to the mounted action's acceptPreview() method, allowing
     * each action type to handle acceptance differently (e.g. AiAction
     * writes field values, ImageGenerationAction stores and writes images).
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

        $action->refine($message);
    }

    /**
     * Discard the preview and close the modal.
     */
    public function solarisDiscardPreview(): void
    {
        $this->solarisPreviewData = null;
        $this->unmountAction();
    }

    /**
     * Clean up preview data when the action is unmounted.
     */
    public function unmountAction(bool $canCancelParentActions = true): void
    {
        $this->solarisPreviewData = null;

        parent::unmountAction($canCancelParentActions);
    }
}
