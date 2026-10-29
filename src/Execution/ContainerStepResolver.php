<?php

declare(strict_types=1);

namespace PhpSaga\Execution;

use PhpSaga\Contracts\StepInterface;
use PhpSaga\Contracts\StepResolverInterface;
use PhpSaga\Exceptions\InvalidSagaDefinitionException;
use Psr\Container\ContainerInterface;

/**
 * Resolves steps using a PSR-11 container (like Laravel's $app or Symfony's container),
 * falling back to direct instantiation if no container is configured.
 */
class ContainerStepResolver implements StepResolverInterface
{
    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {}

    public function resolve(StepInterface|string $step): StepInterface
    {
        if ($step instanceof StepInterface) {
            return $step;
        }

        if (!class_exists($step)) {
            throw new InvalidSagaDefinitionException("Step class '{$step}' does not exist.");
        }

        $resolved = null;

        if ($this->container !== null) {
            if ($this->container->has($step)) {
                $resolved = $this->container->get($step);
            } else {
                try {
                    $resolved = $this->container->get($step);
                } catch (\Throwable) {
                    // Container could not resolve, proceed to direct instantiation
                }
            }
        }

        if ($resolved === null) {
            $resolved = new $step();
        }

        if (!$resolved instanceof StepInterface) {
            $actualType = is_object($resolved) ? get_class($resolved) : gettype($resolved);
            throw new InvalidSagaDefinitionException(
                "Resolved step class '{$step}' must implement " . StepInterface::class . ", got {$actualType}."
            );
        }

        return $resolved;
    }
}

