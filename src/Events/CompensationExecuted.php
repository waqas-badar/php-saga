<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;

/**
 * Dispatched immediately after a step's compensate() method successfully finishes.
 */
readonly class CompensationExecuted
{
    public function __construct(
        public string $sagaId,
        public string $stepName,
        public SagaContext $context,
    ) {}
}

