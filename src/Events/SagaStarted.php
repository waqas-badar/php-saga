<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;
use PhpSaga\SagaState;

/**
 * Dispatched when a saga begins execution.
 */
readonly class SagaStarted
{
    public function __construct(
        public SagaState $state,
        public SagaContext $context,
    ) {}
}

