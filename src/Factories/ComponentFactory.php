<?php

namespace Statikbe\FilamentSolaris\Factories;

use Closure;
use Filament\Schemas\Components\Component;
use Laravel\Ai\Files\File;
use Livewire\Component as LivewireComponent;
use Statikbe\FilamentSolaris\Concerns\HasAttachments;
use Statikbe\FilamentSolaris\Concerns\HasSourceFields;
use Statikbe\FilamentSolaris\Contracts\ComponentFactory as ComponentFactoryContract;
use Statikbe\FilamentSolaris\Exceptions\UnsupportedFactoryOperationException;

abstract class ComponentFactory implements ComponentFactoryContract
{
    protected ?string $hint = null;

    public function __construct(
        protected Component $component,
        protected ?Closure $scope = null,
    ) {}

    /**
     * Set a behavioral hint that will be appended to the schema description.
     */
    public function hint(?string $hint): static
    {
        $this->hint = $hint;

        return $this;
    }

    /**
     * Build the final description by appending the hint (if set) to the structural description.
     */
    protected function buildDescription(string $structural): string
    {
        if (! $this->hint) {
            return $structural;
        }

        return $structural !== '' ? "{$structural} {$this->hint}" : $this->hint;
    }

    /**
     * Create a new factory instance.
     */
    public static function make(Component $component, ?Closure $scope = null): static
    {
        return new static($component, $scope);
    }

    /**
     * Get the Filament component this factory wraps.
     */
    public function getComponent(): Component
    {
        return $this->component;
    }

    /**
     * Get the optional scope closure.
     */
    public function getScope(): ?Closure
    {
        return $this->scope;
    }

    /**
     * {@inheritDoc}
     *
     * By default, factories do not support file output. Override this method
     * in factories for components that can receive generated files.
     *
     * @throws UnsupportedFactoryOperationException
     */
    public function toFormValueFromFile(string $content, string $mimeType): mixed
    {
        throw UnsupportedFactoryOperationException::fileOutput(static::class);
    }

    /**
     * Convert form-state value(s) into laravel/ai attachment objects.
     *
     * Default behaviour returns no attachments — only file-shaped components
     * (FileUpload, SpatieMediaLibraryFileUpload) override this to produce
     * `Files\File` instances. Called statically by `HasAttachments` so the
     * dispatch can pick the right subclass without instantiating a factory.
     *
     * @return array<int, File>
     */
    public static function toAttachments(mixed $value, ?string $disk): array
    {
        return [];
    }

    /**
     * Read this field's current value from the Livewire component.
     *
     * The default goes through Filament's schema-based `Get` utility when a
     * `$schemaComponent` is provided — that works for every "normal" field
     * (TextInput, Textarea, Select, Toggle, CheckboxList, RichEditor, etc.)
     * and is the same path target reads use. Falls back to `$livewire->data`
     * when no schema is bound (standalone tests, components built without a
     * Filament schema).
     *
     * Factories whose component holds **raw, non-normalised state** override
     * this — most notably `FileUploadFactory`, where the live Livewire shape
     * `[uuid => TemporaryUploadedFile]` doesn't survive `Get`'s normalisation
     * and is what {@see toAttachments()} was designed to interpret.
     *
     * Called by both source-field collection ({@see HasSourceFields})
     * and attachment resolution ({@see HasAttachments})
     * so the asymmetry between text-shaped and file-shaped state lives in one
     * factory override rather than as a special case in either trait.
     */
    public static function readState(
        LivewireComponent $livewire,
        string $field,
        ?Component $schemaComponent = null,
    ): mixed {
        if ($schemaComponent !== null) {
            return $schemaComponent->makeGetUtility()($field);
        }

        return data_get($livewire->data ?? [], $field);
    }

    /**
     * {@inheritDoc}
     */
    public function toPreviewDisplay(mixed $formValue): array
    {
        return [
            'display' => (string) $this->toPromptContext($formValue),
            'type' => 'text',
        ];
    }

    /**
     * {@inheritDoc}
     *
     * No-op by default. JS-driven widgets (FilePond, TipTap, code editors, …)
     * override this to push a browser-side update via `$livewire->js()`.
     */
    public function afterStateHydration(mixed $formValue, LivewireComponent $livewire): void
    {
        // Intentionally empty.
    }
}
