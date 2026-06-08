<?php

namespace Statikbe\FilamentSolaris\Support;

use RuntimeException;

/**
 * Thrown by a BatchProcessor `generateResponse` closure when the AI call for a
 * batch fails (network/timeout/empty/fake-simulated). The processor catches this
 * specifically and marks the whole batch failed; any other throwable propagates.
 */
class BatchGenerationException extends RuntimeException {}
