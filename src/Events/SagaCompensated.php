<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use Throwable;

/**
 * Dispatched when a failed saga has been successfully and fully rolled back.
 */
readonly class SagaCompensated
{
    public function __construct(
        public SagaState $state,
        public SagaContext $context,
        public Throwable $executionException,
    ) {}
}

