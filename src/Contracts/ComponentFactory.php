<?php

namespace Statikbe\FilamentSolaris\Contracts;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Livewire\Component as LivewireComponent;

/**
 * Contract for ComponentFactory implementations.
 *
 * When writing a custom factory you only need to implement the three
 * **core** methods. The remaining four have safe defaults on the abstract
 * {@see \Statikbe\FilamentSolaris\Factories\ComponentFactory} base — only
 * override them when your component actually supports the capability.
 *
 * **Core (always implement):**
 * - {@see responseSchema()} — JSON-schema fragment for the AI's response
 * - {@see toFormValue()} — AI response → Filament form state
 * - {@see toPromptContext()} — Filament form state → prompt text
 *
 * **Optional common overrides:**
 * - {@see toPreviewDisplay()} — defaults to wrapping `toPromptContext()` as
 *   plain text. Override only for richer preview (HTML, image, etc.).
 * - {@see afterStateHydration()} — no-op by default. Override for JS-driven
 *   widgets (FilePond, TipTap) that hold their own browser-side state.
 *
 * **Optional file-shaped capabilities** (the base throws / returns `[]`):
 * - {@see toFormValueFromFile()} — for components that can receive generated
 *   files (image generation writing to a FileUpload, etc.).
 * - `static toAttachments()` on the base — for components whose state can be
 *   passed back to the AI as attachments (FileUpload, SpatieMediaLibrary).
 * - `static readState()` on the base — defaults to Filament's schema-based
 *   Get utility; override when the component holds non-normalised state
 *   (e.g. FileUpload's raw `[uuid => TempFile]` shape).
 *
 * Future versions may split the file-shaped capabilities into opt-in
 * interfaces (`SupportsFileOutput`, `SupportsAttachments`) — additive change,
 * tracked in `specs/missing-features.md`.
 */
interface ComponentFactory
{
    /**
     * JSON schema fragment describing valid AI output for this field.
     */
    public function responseSchema(JsonSchemaTypeFactory $schema): Type;

    /**
     * Transform AI response value into Filament form state.
     *
     * @param  mixed  $aiValue  The raw value from the AI's JSON response
     * @return mixed Value suitable for Filament's form state
     */
    public function toFormValue(mixed $aiValue): mixed;

    /**
     * Transform generated file content into Filament form state.
     *
     * Used by ImageGenerationAction to write generated images (or other files)
     * to the target component. Each factory decides the appropriate state format:
     * - FileUploadFactory: creates a Livewire TemporaryUploadedFile
     * - TextFactory: stores to disk and returns the path string
     *
     * @param  string  $content  Raw binary file content
     * @param  string  $mimeType  MIME type (e.g. 'image/png')
     * @return mixed Value suitable for Filament's form state
     */
    public function toFormValueFromFile(string $content, string $mimeType): mixed;

    /**
     * Transform form state into prompt-friendly context.
     *
     * @param  mixed  $formValue  The current value from Filament's form state
     * @return mixed Human-readable representation for the prompt
     */
    public function toPromptContext(mixed $formValue): mixed;

    /**
     * Transform form state into a human-readable display for the preview modal.
     *
     * @param  mixed  $formValue  The value after toFormValue() transformation
     * @return array{display: string, type: string} The display string and its type ('text' or 'html')
     */
    public function toPreviewDisplay(mixed $formValue): array;

    /**
     * Hook invoked after the pipeline has written a value into the component
     * via `$component->state($formValue)`.
     *
     * Most factories rely on Livewire's entangle-based state binding and can
     * leave this as a no-op. Factories wrapping JS-driven components that keep
     * their own internal state (FilePond, TipTap, code editors, …) override
     * this to dispatch a browser-side update via `$livewire->js()`.
     *
     * @param  mixed  $formValue  The value that was just written to component state
     */
    public function afterStateHydration(mixed $formValue, LivewireComponent $livewire): void;
}
