<?php

declare(strict_types=1);

namespace PhpSaga\Contracts;

use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaDefinition;

/**
 * Contract for class-based Saga workflow declarations.
 */
interface SagaInterface
{
    /**
     * Returns the name/type identifier of this saga.
     */
    public function getName(): string;

    /**
     * Configures steps and policies using the fluent SagaBuilder.
     */
    public function configure(SagaBuilder $builder): void;

    /**
     * Compiles and returns the immutable SagaDefinition.
     */
    public function getDefinition(): SagaDefinition;
}

