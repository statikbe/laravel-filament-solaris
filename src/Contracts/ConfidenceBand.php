<?php

namespace Statikbe\FilamentSolaris\Contracts;

/**
 * A metadata vocabulary whose cases are buckets of a numeric confidence score.
 */
interface ConfidenceBand extends MetadataVocabulary
{
    /**
     * Minimum score (0.0–1.0) for a value to fall into this band.
     */
    public function threshold(): float;
}
