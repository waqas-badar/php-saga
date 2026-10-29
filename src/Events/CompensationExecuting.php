<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;

/**
 * Dispatched immediately before a step's compensate() method is called.
 */
readonly class CompensationExecuting
{
    public function __construct(
        public string $sagaId,
        public string $stepName,
        public SagaContext $context,
    ) {}
}

