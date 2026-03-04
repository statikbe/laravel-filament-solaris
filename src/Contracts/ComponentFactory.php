<?php

namespace Statikbe\FilamentSolaris\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

interface ComponentFactory
{
    /**
     * JSON schema fragment describing valid AI output for this field.
     */
    public function responseSchema(JsonSchema $schema): Type;

    /**
     * Transform AI response value into Filament form state.
     *
     * @param  mixed  $aiValue  The raw value from the AI's JSON response
     * @return mixed Value suitable for Filament's form state
     */
    public function toFormValue(mixed $aiValue): mixed;

    /**
     * Transform form state into prompt-friendly context.
     *
     * @param  mixed  $formValue  The current value from Filament's form state
     * @return mixed Human-readable representation for the prompt
     */
    public function toPromptContext(mixed $formValue): mixed;
}
