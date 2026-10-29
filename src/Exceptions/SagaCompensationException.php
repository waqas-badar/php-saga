<?php

declare(strict_types=1);

namespace PhpSaga\Exceptions;

use Throwable;

/**
 * Thrown when backward compensation encounters an error.
 */
class SagaCompensationException extends SagaException
{
    public function __construct(
        string $message,
        private readonly string $stepName,
        private readonly string $sagaId,
        private readonly ?Throwable $originalExecutionException = null,
        ?Throwable $previous = null,
        int $code = 0
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStepName(): string
    {
        return $this->stepName;
    }

    public function getSagaId(): string
    {
        return $this->sagaId;
    }

    public function getOriginalExecutionException(): ?Throwable
    {
        return $this->originalExecutionException;
    }
}

