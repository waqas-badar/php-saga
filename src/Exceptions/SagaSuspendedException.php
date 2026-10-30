<?php

declare(strict_types=1);

namespace PhpSaga\Exceptions;

/**
 * Thrown by a step to intentionally halt/suspend execution without triggering compensation.
 * The saga can later be resumed via SagaCoordinator::resume().
 */
class SagaSuspendedException extends SagaException
{
}

