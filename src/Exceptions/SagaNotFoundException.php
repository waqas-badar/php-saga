<?php

declare(strict_types=1);

namespace PhpSaga\Exceptions;

/**
 * Thrown when attempting to find or operate on a saga instance that does not exist in storage.
 */
class SagaNotFoundException extends SagaException
{
    public static function forId(string $sagaId): self
    {
        return new self("Saga instance with ID '{$sagaId}' was not found.");
    }
}

