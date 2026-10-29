<?php

declare(strict_types=1);

namespace PhpSaga\Execution;

use Closure;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\SagaContext;

/**
 * Lightweight Step implementation backed by closures.
 * Allows defining forward execution and backward compensation inline.
 */
class CallableStep implements StepInterface
{
    private Closure $executeHandler;
    private ?Closure $compensateHandler;

    /**
     * @param string $name Name of the step
     * @param callable(SagaContext): mixed $execute Forward execution callback
     * @param (callable(SagaContext): mixed)|null $compensate Backward compensation callback
     */
    public function __construct(
        private readonly string $name,
        callable $execute,
        ?callable $compensate = null,
    ) {
        $this->executeHandler = $execute(...);
        $this->compensateHandler = $compensate !== null ? $compensate(...) : null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function execute(SagaContext $context): void
    {
        ($this->executeHandler)($context);
    }

    public function compensate(SagaContext $context): void
    {
        if ($this->compensateHandler !== null) {
            ($this->compensateHandler)($context);
        }
    }
}
