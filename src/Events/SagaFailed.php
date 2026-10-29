<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use Throwable;

/**
 * Dispatched when a saga reaches the FAILED state (e.g. compensation itself failed).
 */
readonly class SagaFailed
{
    public function __construct(
        public SagaState $state,
        public SagaContext $context,
        public Throwable $executionException,
        public ?Throwable $compensationException = null,
    ) {}
}

