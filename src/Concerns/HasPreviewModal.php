<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;

/**
 * Adds the full preview-modal lifecycle to a Solaris action.
 */
trait HasPreviewModal
{
    /**
     * Whether preview mode is enabled (e.g. via `->withPreview()`).
     */
    abstract public function shouldPreview(): bool;

    /**
     * Whether a user-input form is configured for the action.
     */
    abstract public function hasUserInput(): bool;

    /**
     * Check if the Livewire component has pending preview data.
     */
    private function hasPreviewData(): bool
    {
        $livewire = $this->getLivewire();

        return property_exists($livewire, 'solarisPreviewData') && $livewire->solarisPreviewData !== null;
    }

    /**
     * Whether the modal is in the loading state — preview enabled, no user
     * input form to gate execution, and no preview data yet.
     */
    private function isPreviewLoading(): bool
    {
        return $this->shouldPreview() && ! $this->hasUserInput() && ! $this->hasPreviewData();
    }

    /**
     * Whether the active preview is in conversational refinement mode.
     */
    private function isConversationalPreview(): bool
    {
        if (! $this->hasPreviewData()) {
            return false;
        }

        return (bool) ($this->getLivewire()->solarisPreviewData['isConversational'] ?? false);
    }

    /**
     * Render a Solaris preview view by short name.
     *
     * @param  array<string, mixed>  $data
     */
    private function previewView(string $name, array $data = []): View
    {
        /** @var view-string $viewName */
        $viewName = "filament-solaris::{$name}";

        return view($viewName, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function getModalContent(): View|Htmlable|null
    {
        if ($this->hasPreviewData()) {
            $previewData = $this->getLivewire()->solarisPreviewData;

            if ($this->isConversationalPreview()) {
                return $this->previewView('preview-conversational', [
                    'displays' => $previewData['displays'],
                    'messages' => $previewData['messages'] ?? [],
                ]);
            }

            return $this->previewView('preview-modal', [
                'displays' => $previewData['displays'],
            ]);
        }

        if ($this->isPreviewLoading()) {
            return $this->previewView('preview-loading');
        }

        return parent::getModalContent();
    }

    /**
     * {@inheritDoc}
     */
    public function getModalContentFooter(): View|Htmlable|null
    {
        if (! $this->hasPreviewData()) {
            return parent::getModalContentFooter();
        }

        if ($this->isConversationalPreview()) {
            return null;
        }

        return $this->previewView('preview-modal-footer');
    }

    /**
     * {@inheritDoc}
     */
    public function getModalHeading(): string|Htmlable
    {
        if ($this->hasPreviewData()) {
            return filament_solaris_trans('preview.modal_heading');
        }

        return parent::getModalHeading();
    }

    /**
     * {@inheritDoc}
     */
    public function getModalSubmitAction(): ?Action
    {
        if ($this->hasPreviewData() || $this->isPreviewLoading()) {
            return null;
        }

        return parent::getModalSubmitAction();
    }

    /**
     * {@inheritDoc}
     */
    public function getModalCancelAction(): ?Action
    {
        if ($this->hasPreviewData() || $this->isPreviewLoading()) {
            return null;
        }

        return parent::getModalCancelAction();
    }
}
