<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;
use Throwable;

/**
 * Dispatched when a step encounters an exception during forward execution.
 */
readonly class StepFailed
{
    public function __construct(
        public string $sagaId,
        public string $stepName,
        public Throwable $exception,
        public SagaContext $context,
        public int $attempts = 1,
    ) {}
}

