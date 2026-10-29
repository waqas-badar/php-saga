<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;

/**
 * Dispatched immediately after a step's execute() method successfully finishes.
 */
readonly class StepExecuted
{
    public function __construct(
        public string $sagaId,
        public string $stepName,
        public SagaContext $context,
    ) {}
}

