<?php

declare(strict_types=1);

namespace PhpSaga\Exceptions;

use Throwable;

/**
 * Thrown when a step fails during forward execution.
 */
class SagaExecutionException extends SagaException
{
    public function __construct(
        string $message,
        private readonly string $stepName,
        private readonly string $sagaId,
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
}

