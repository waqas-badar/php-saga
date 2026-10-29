<?php

declare(strict_types=1);

namespace PhpSaga\Contracts;

/**
 * Resolves step instances from step classes or objects.
 * Allows seamless integration with Dependency Injection containers (e.g. Laravel, Symfony).
 */
interface StepResolverInterface
{
    /**
     * Resolves a StepInterface instance given either an existing instance or a class name string.
     *
     * @param StepInterface|string $step
     */
    public function resolve(StepInterface|string $step): StepInterface;
}
