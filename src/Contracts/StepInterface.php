<?php

declare(strict_types=1);

namespace PhpSaga\Contracts;

use PhpSaga\SagaContext;

/**
 * Represents an individual step within a Saga workflow.
 * Each step defines both forward execution and backward compensation logic.
 */
interface StepInterface
{
    /**
     * Returns the unique name or identifier of this step.
     */
    public function getName(): string;

    /**
     * Executes the forward business action of this step.
     * Modifies the shared context with step outputs as needed.
     *
     * @param SagaContext $context The shared saga execution context.
     * @throws \Throwable If step execution fails, triggering saga compensation.
     */
    public function execute(SagaContext $context): void;

    /**
     * Compensates (undoes/rolls back) the effects of this step in case a subsequent step fails.
     * Should be idempotent whenever possible.
     *
     * @param SagaContext $context The shared saga context at the time compensation is invoked.
     * @throws \Throwable If compensation encounters an unrecoverable error.
     */
    public function compensate(SagaContext $context): void;
}

