<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Statikbe\FilamentSolaris\Support\SolarisNotification;

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
     */
    public function solarisAcceptPreview(): void
    {
        if ($this->solarisPreviewData === null) {
            return;
        }

        $data = $this->solarisPreviewData;
        $this->solarisPreviewData = null;

        $firstField = array_key_first($data['values']);

        if ($firstField === null) {
            $this->unmountAction();

            return;
        }

        $component = $this->getSchemaComponent("form.{$firstField}");

        if ($component === null) {
            $this->unmountAction();

            return;
        }

        $set = $component
            ->makeSetUtility()
            ->skipComponentsChildContainersWhileSearching(false);

        foreach ($data['values'] as $fieldName => $formValue) {
            $set($fieldName, $formValue);
        }

        SolarisNotification::sendResultNotifications($data['filledLabels'], $data['failedLabels']);
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
