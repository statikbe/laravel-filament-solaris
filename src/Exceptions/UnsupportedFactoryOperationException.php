<?php

namespace Statikbe\FilamentSolaris\Exceptions;

use RuntimeException;

class UnsupportedFactoryOperationException extends RuntimeException
{
    /**
     * Create an exception for an unsupported file output operation.
     */
    public static function fileOutput(string $factoryClass): self
    {
        return new self(
            "{$factoryClass} does not support file output. Implement toFormValueFromFile() to add support."
        );
    }
}
