<?php

declare(strict_types=1);

namespace PhpSaga\Events;

use PhpSaga\SagaContext;
use PhpSaga\SagaState;

/**
 * Dispatched when a saga is intentionally halted/suspended awaiting external resumption.
 */
readonly class SagaSuspended
{
    public function __construct(
        public SagaState $state,
        public SagaContext $context,
        public ?string $reason = null,
    ) {}
}

