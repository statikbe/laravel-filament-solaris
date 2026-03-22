<?php

namespace Statikbe\FilamentSolaris\Concerns;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;

trait HasPreviewModal
{
    /**
     * Check if the Livewire component has pending preview data.
     */
    private function hasPreviewData(): bool
    {
        $livewire = $this->getLivewire();

        return property_exists($livewire, 'solarisPreviewData') && $livewire->solarisPreviewData !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function getModalContent(): View|Htmlable|null
    {
        if ($this->hasPreviewData()) {
            /** @var view-string $viewName */
            $viewName = 'filament-solaris::preview-modal';

            return view($viewName, [
                'displays' => $this->getLivewire()->solarisPreviewData['displays'],
            ]);
        }

        return parent::getModalContent();
    }

    /**
     * {@inheritDoc}
     */
    public function getModalContentFooter(): View|Htmlable|null
    {
        if ($this->hasPreviewData()) {
            /** @var view-string $viewName */
            $viewName = 'filament-solaris::preview-modal-footer';

            return view($viewName);
        }

        return parent::getModalContentFooter();
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
        if ($this->hasPreviewData()) {
            return null;
        }

        return parent::getModalSubmitAction();
    }

    /**
     * {@inheritDoc}
     */
    public function getModalCancelAction(): ?Action
    {
        if ($this->hasPreviewData()) {
            return null;
        }

        return parent::getModalCancelAction();
    }
}
