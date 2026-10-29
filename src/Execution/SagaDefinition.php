<?php

declare(strict_types=1);

namespace PhpSaga\Execution;

use Countable;
use PhpSaga\Exceptions\InvalidSagaDefinitionException;

/**
 * Immutable definition of a Saga workflow and its sequence of steps.
 */
class SagaDefinition implements Countable
{
    /**
     * @var array<string, SagaStepDefinition> Map of step names to definitions
     */
    private array $stepMap = [];

    /**
     * @param string $sagaType Identifier or name of the saga workflow
     * @param array<int, SagaStepDefinition> $steps Ordered sequence of step definitions
     * @throws InvalidSagaDefinitionException If steps are empty or contain duplicate names
     */
    public function __construct(
        private readonly string $sagaType,
        private readonly array $steps
    ) {
        if (trim($this->sagaType) === '') {
            throw new InvalidSagaDefinitionException('Saga type cannot be empty.');
        }

        if (count($this->steps) === 0) {
            throw new InvalidSagaDefinitionException("Saga '{$this->sagaType}' must contain at least one step.");
        }

        foreach ($this->steps as $stepDef) {
            $name = $stepDef->getName();
            if (isset($this->stepMap[$name])) {
                throw new InvalidSagaDefinitionException(
                    "Duplicate step name '{$name}' detected in Saga '{$this->sagaType}'."
                );
            }
            $this->stepMap[$name] = $stepDef;
        }
    }

    public function getSagaType(): string
    {
        return $this->sagaType;
    }

    /**
     * @return array<int, SagaStepDefinition>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function count(): int
    {
        return count($this->steps);
    }

    public function getStep(int $index): ?SagaStepDefinition
    {
        return $this->steps[$index] ?? null;
    }

    public function findStep(string $name): ?SagaStepDefinition
    {
        return $this->stepMap[$name] ?? null;
    }

    public function hasStep(string $name): bool
    {
        return isset($this->stepMap[$name]);
    }
}

