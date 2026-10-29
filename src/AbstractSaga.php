<?php

declare(strict_types=1);

namespace PhpSaga;

use PhpSaga\Contracts\SagaInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaDefinition;

/**
 * Base class for object-oriented Saga workflows.
 * Subclasses implement configure(SagaBuilder $builder) to register their step sequence.
 */
abstract class AbstractSaga implements SagaInterface
{
    private ?SagaDefinition $definition = null;

    /**
     * By default, returns the short class name as the saga identifier.
     */
    public function getName(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }

    /**
     * Subclasses register steps, retry rules, and failure policies onto the builder.
     */
    abstract public function configure(SagaBuilder $builder): void;

    /**
     * Lazily compiles and returns the SagaDefinition.
     */
    public function getDefinition(): SagaDefinition
    {
        if ($this->definition === null) {
            $builder = new SagaBuilder($this->getName());
            $this->configure($builder);
            $this->definition = $builder->build();
        }

        return $this->definition;
    }
}

