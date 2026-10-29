<?php

declare(strict_types=1);

namespace PhpSaga\Execution;

use PhpSaga\Contracts\StepInterface;

/**
 * Immutable metadata and execution policies for a single step in a Saga.
 */
readonly class SagaStepDefinition
{
    /**
     * @param StepInterface|class-string<StepInterface> $step Step instance or class-string
     * @param string|null $name Optional custom step name override
     * @param int $retries Max number of retries before treating the step as failed (min: 0)
     * @param int $retryDelayMs Delay in milliseconds between retries (min: 0)
     * @param bool $continueOnFailure Whether failure of this step can be ignored without aborting the saga
     */
    public function __construct(
        public StepInterface|string $step,
        public ?string $name = null,
        public int $retries = 0,
        public int $retryDelayMs = 0,
        public bool $continueOnFailure = false,
    ) {}

    public function getName(): string
    {
        if ($this->name !== null && trim($this->name) !== '') {
            return $this->name;
        }

        if ($this->step instanceof StepInterface) {
            return $this->step->getName();
        }

        $parts = explode('\\', $this->step);

        return end($parts);
    }
}
