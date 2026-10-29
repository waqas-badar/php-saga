<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;
use PhpSaga\SagaState;

/**
 * Dispatched when all steps in a saga have completed successfully.
 */
readonly class SagaCompleted
{
    public function __construct(
        public SagaState $state,
        public SagaContext $context,
    ) {}
}

