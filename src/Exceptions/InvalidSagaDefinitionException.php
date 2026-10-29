<?php

declare(strict_types=1);

namespace PhpSaga\Exceptions;

/**
 * Thrown when a Saga definition is invalid (e.g. empty steps, duplicate step names).
 */
class InvalidSagaDefinitionException extends SagaException
{
}

