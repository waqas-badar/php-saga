<?php

declare(strict_types=1);

namespace PhpSaga\Execution;

use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use Throwable;

/**
 * Encapsulates the final outcome, resulting state, and context of a Saga execution.
 */
readonly class SagaResult
{
    public function __construct(
        public SagaState $state,
        public SagaContext $context,
        public bool $isSuccessful,
        public bool $isCompensated,
        public bool $isSuspended = false,
        public ?Throwable $executionException = null,
        public ?Throwable $compensationException = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    public function isCompensated(): bool
    {
        return $this->isCompensated;
    }

    public function isSuspended(): bool
    {
        return $this->isSuspended;
    }

    public function hasFailed(): bool
    {
        return !$this->isSuccessful && !$this->isSuspended;
    }

    public function getContext(): SagaContext
    {
        return $this->context;
    }

    public function getState(): SagaState
    {
        return $this->state;
    }

    public function getExecutionException(): ?Throwable
    {
        return $this->executionException;
    }

    public function getCompensationException(): ?Throwable
    {
        return $this->compensationException;
    }
}

