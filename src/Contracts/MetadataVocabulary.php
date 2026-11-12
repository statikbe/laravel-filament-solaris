<?php

namespace Statikbe\FilamentSolaris\Contracts;

/**
 * A backed enum whose cases form the vocabulary of a metadata key (e.g. evidence, confidence).
 */
interface MetadataVocabulary
{
    /**
     * Prompt language (English), describing what this case means to the model.
     */
    public function promptDescription(): string;

    /**
     * Whether a field carrying this case starts ticked in the preview selection.
     */
    public function startsSelected(): bool;
}
