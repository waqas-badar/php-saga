<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;

/**
 * Dispatched immediately before a step's execute() method is called.
 */
readonly class StepExecuting
{
    public function __construct(
        public string $sagaId,
        public string $stepName,
        public SagaContext $context,
        public int $attempt = 1,
    ) {}
}

