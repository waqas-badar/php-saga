<?php

declare(strict_types=1);

namespace PhpSaga\Execution;

use PhpSaga\Contracts\StepInterface;
use PhpSaga\Exceptions\InvalidSagaDefinitionException;

/**
 * Fluent builder for assembling and validating a SagaDefinition.
 */
class SagaBuilder
{
    /**
     * @var array<int, SagaStepDefinition>
     */
    private array $steps = [];

    public function __construct(
        private readonly string $sagaType
    ) {}

    /**
     * Named constructor for fluent chaining.
     */
    public static function new(string $sagaType): self
    {
        return new self($sagaType);
    }

    /**
     * Add a step implementation to the workflow.
     *
     * @param StepInterface|class-string<StepInterface> $step The step instance or class-string
     * @param string|null $name Optional custom name for the step
     * @param int $retries Number of retry attempts on failure (min: 0)
     * @param int $retryDelayMs Milliseconds delay between retry attempts (min: 0)
     * @param bool $continueOnFailure If true, step failure will not abort or compensate the saga
     * @throws InvalidSagaDefinitionException If retry counts or delays are negative
     */
    public function addStep(
        StepInterface|string $step,
        ?string $name = null,
        int $retries = 0,
        int $retryDelayMs = 0,
        bool $continueOnFailure = false
    ): self {
        $stepDef = new SagaStepDefinition(
            step: $step,
            name: $name,
            retries: $retries,
            retryDelayMs: $retryDelayMs,
            continueOnFailure: $continueOnFailure
        );

        $stepName = $stepDef->getName();

        if ($retries < 0) {
            throw new InvalidSagaDefinitionException("Step retries cannot be negative for step '{$stepName}'.");
        }

        if ($retryDelayMs < 0) {
            throw new InvalidSagaDefinitionException("Step retry delay cannot be negative for step '{$stepName}'.");
        }

        $this->steps[] = $stepDef;

        return $this;
    }

    /**
     * Convenience method to add a step using closures/callables.
     *
     * @param string $name Step name
     * @param callable(\PhpSaga\SagaContext): mixed $execute Forward execution callback
     * @param (callable(\PhpSaga\SagaContext): mixed)|null $compensate Backward compensation callback
     * @param int $retries Number of retry attempts on failure (min: 0)
     * @param int $retryDelayMs Milliseconds delay between retry attempts (min: 0)
     * @param bool $continueOnFailure If true, step failure will not abort or compensate the saga
     */
    public function addCallableStep(
        string $name,
        callable $execute,
        ?callable $compensate = null,
        int $retries = 0,
        int $retryDelayMs = 0,
        bool $continueOnFailure = false
    ): self {
        $step = new CallableStep($name, $execute, $compensate);

        return $this->addStep(
            step: $step,
            name: $name,
            retries: $retries,
            retryDelayMs: $retryDelayMs,
            continueOnFailure: $continueOnFailure
        );
    }

    /**
     * Finalizes and builds the immutable SagaDefinition.
     *
     * @throws InvalidSagaDefinitionException
     */
    public function build(): SagaDefinition
    {
        return new SagaDefinition($this->sagaType, $this->steps);
    }
}
