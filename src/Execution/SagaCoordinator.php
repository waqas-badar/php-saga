<?php

declare(strict_types=1);

namespace PhpSaga\Execution;

use LogicException;
use PhpSaga\Contracts\SagaInterface;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Contracts\StepResolverInterface;
use PhpSaga\Events\CompensationExecuted;
use PhpSaga\Events\CompensationExecuting;
use PhpSaga\Events\SagaCompleted;
use PhpSaga\Events\SagaCompensated;
use PhpSaga\Events\SagaFailed;
use PhpSaga\Events\SagaStarted;
use PhpSaga\Events\StepExecuted;
use PhpSaga\Events\StepExecuting;
use PhpSaga\Events\StepFailed;
use PhpSaga\Exceptions\SagaNotFoundException;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Orchestrator-driven execution engine.
 * Sequentially executes saga steps forward and initiates LIFO backward compensation upon failure.
 * Supports PSR-14 lifecycle events, step resolution via DI containers, and state resumption.
 */
class SagaCoordinator
{
    private StepResolverInterface $stepResolver;

    public function __construct(
        private readonly ?SagaRepositoryInterface $repository = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?StepResolverInterface $stepResolver = null,
    ) {
        $this->stepResolver = $stepResolver ?? new ContainerStepResolver();
    }

    /**
     * Executes the Saga workflow from the beginning.
     *
     * @param SagaDefinition|SagaInterface $saga Saga workflow definition or class instance
     * @param SagaContext|array<string, mixed> $context Initial context or array of variables
     * @param string|null $sagaId Optional custom saga instance ID (UUID generated if null)
     * @return SagaResult Final execution outcome and snapshot state
     */
    public function run(
        SagaDefinition|SagaInterface $saga,
        SagaContext|array $context = [],
        ?string $sagaId = null,
    ): SagaResult {
        $definition = $saga instanceof SagaInterface ? $saga->getDefinition() : $saga;
        $sagaContext = $context instanceof SagaContext ? $context : SagaContext::fromArray($context);
        $id = $sagaId ?? $this->generateSagaId();

        $state = new SagaState(
            sagaId: $id,
            sagaType: $definition->getSagaType(),
            status: SagaStatus::PENDING,
            context: $sagaContext,
        );
        $this->repository?->save($state);

        $state = $state->withStatus(SagaStatus::IN_PROGRESS);
        $this->repository?->save($state);

        $this->dispatch(new SagaStarted($state, $sagaContext));

        return $this->executeSteps(
            definition: $definition,
            context: $sagaContext,
            state: $state,
            executedSteps: []
        );
    }

    /**
     * Resumes an existing saga workflow from storage by its ID.
     * Skips already completed steps and continues forward execution,
     * or resumes compensation if previously interrupted.
     *
     * @throws SagaNotFoundException If saga record does not exist in repository
     * @throws LogicException If no repository is configured
     */
    public function resume(string $sagaId, SagaDefinition|SagaInterface $saga): SagaResult
    {
        if ($this->repository === null) {
            throw new LogicException("Cannot resume saga '{$sagaId}' without a configured SagaRepository.");
        }

        $state = $this->repository->find($sagaId);
        if ($state === null) {
            throw SagaNotFoundException::forId($sagaId);
        }

        $definition = $saga instanceof SagaInterface ? $saga->getDefinition() : $saga;
        $context = $state->context;

        if ($state->status === SagaStatus::COMPLETED) {
            return new SagaResult(
                state: $state,
                context: $context,
                isSuccessful: true,
                isCompensated: false,
            );
        }

        if ($state->status === SagaStatus::COMPENSATED) {
            return new SagaResult(
                state: $state,
                context: $context,
                isSuccessful: false,
                isCompensated: true,
            );
        }

        if ($state->status === SagaStatus::FAILED) {
            return new SagaResult(
                state: $state,
                context: $context,
                isSuccessful: false,
                isCompensated: false,
            );
        }

        // Map completed step names to SagaStepDefinition
        /** @var array<int, SagaStepDefinition> $executedSteps */
        $executedSteps = [];
        foreach ($state->completedSteps as $completedName) {
            $stepDef = $definition->findStep($completedName);
            if ($stepDef !== null) {
                $executedSteps[] = $stepDef;
            }
        }

        if ($state->status === SagaStatus::COMPENSATING) {
            $lastStep = $executedSteps[array_key_last($executedSteps)] ?? $definition->getStep(0);
            assert($lastStep instanceof SagaStepDefinition);

            return $this->compensate(
                executedSteps: $executedSteps,
                failedStep: $lastStep,
                context: $context,
                state: $state,
                executionException: new LogicException($state->failureReason ?? 'Resuming compensation')
            );
        }

        return $this->executeSteps(
            definition: $definition,
            context: $context,
            state: $state,
            executedSteps: $executedSteps
        );
    }

    /**
     * Iterates through remaining steps, executing them with retry and error-handling policies.
     *
     * @param array<int, SagaStepDefinition> $executedSteps
     */
    private function executeSteps(
        SagaDefinition $definition,
        SagaContext $context,
        SagaState $state,
        array $executedSteps
    ): SagaResult {
        $completedSet = array_flip($state->completedSteps);

        foreach ($definition->getSteps() as $stepDefinition) {
            $stepName = $stepDefinition->getName();

            // Skip steps that have already been executed and persisted
            if (isset($completedSet[$stepName])) {
                continue;
            }

            $state = $state->withStatus(SagaStatus::IN_PROGRESS, currentStep: $stepName);
            $this->repository?->save($state);

            $stepInstance = $this->stepResolver->resolve($stepDefinition->step);
            $stepError = $this->executeWithRetries($stepInstance, $stepDefinition, $context, $state->sagaId);

            if ($stepError === null) {
                $executedSteps[] = $stepDefinition;
                $state = $state->withCompletedStep($stepName)->withContext($context);
                $this->repository?->save($state);
                $this->dispatch(new StepExecuted($state->sagaId, $stepName, $context));
                continue;
            }

            $this->dispatch(new StepFailed(
                sagaId: $state->sagaId,
                stepName: $stepName,
                exception: $stepError,
                context: $context,
                attempts: 1 + $stepDefinition->retries
            ));

            if ($stepDefinition->continueOnFailure) {
                $state = $state->withContext($context);
                $this->repository?->save($state);
                continue;
            }

            return $this->compensate(
                executedSteps: $executedSteps,
                failedStep: $stepDefinition,
                context: $context,
                state: $state,
                executionException: $stepError
            );
        }

        $state = $state->withStatus(SagaStatus::COMPLETED, currentStep: null);
        $this->repository?->save($state);
        $this->dispatch(new SagaCompleted($state, $context));

        return new SagaResult(
            state: $state,
            context: $context,
            isSuccessful: true,
            isCompensated: false,
        );
    }

    /**
     * Executes backward compensation for all previously completed steps in reverse order (LIFO).
     *
     * @param array<int, SagaStepDefinition> $executedSteps
     */
    private function compensate(
        array $executedSteps,
        SagaStepDefinition $failedStep,
        SagaContext $context,
        SagaState $state,
        Throwable $executionException
    ): SagaResult {
        $failureMessage = $executionException->getMessage();

        $state = $state->withStatus(
            status: SagaStatus::COMPENSATING,
            currentStep: $failedStep->getName(),
            failureReason: $failureMessage
        );
        $this->repository?->save($state);

        $stepsToCompensate = array_reverse($executedSteps);

        foreach ($stepsToCompensate as $stepDef) {
            $stepInstance = $this->stepResolver->resolve($stepDef->step);
            $stepName = $stepDef->getName();

            $state = $state->withStatus(
                status: SagaStatus::COMPENSATING,
                currentStep: $stepName,
                failureReason: $failureMessage
            );
            $this->repository?->save($state);

            $this->dispatch(new CompensationExecuting($state->sagaId, $stepName, $context));

            try {
                $stepInstance->compensate($context);
                $this->repository?->updateContext($state->sagaId, $context);
                $this->dispatch(new CompensationExecuted($state->sagaId, $stepName, $context));
            } catch (Throwable $compensationError) {
                $compReason = "Compensation failed on step '{$stepName}': {$compensationError->getMessage()}";
                $state = $state->withStatus(
                    status: SagaStatus::FAILED,
                    currentStep: $stepName,
                    failureReason: $compReason
                );
                $this->repository?->save($state);

                $this->dispatch(new SagaFailed($state, $context, $executionException, $compensationError));

                return new SagaResult(
                    state: $state,
                    context: $context,
                    isSuccessful: false,
                    isCompensated: false,
                    executionException: $executionException,
                    compensationException: $compensationError
                );
            }
        }

        $state = $state->withStatus(
            status: SagaStatus::COMPENSATED,
            currentStep: null,
            failureReason: $failureMessage
        );
        $this->repository?->save($state);

        $this->dispatch(new SagaCompensated($state, $context, $executionException));

        return new SagaResult(
            state: $state,
            context: $context,
            isSuccessful: false,
            isCompensated: true,
            executionException: $executionException,
            compensationException: null
        );
    }

    /**
     * Executes a step instance with configured retry policy.
     */
    private function executeWithRetries(
        StepInterface $stepInstance,
        SagaStepDefinition $stepDefinition,
        SagaContext $context,
        string $sagaId
    ): ?Throwable {
        $attempts = 0;
        $maxAttempts = 1 + $stepDefinition->retries;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            $attempts++;
            $this->dispatch(new StepExecuting($sagaId, $stepDefinition->getName(), $context, $attempts));

            try {
                $stepInstance->execute($context);

                return null;
            } catch (Throwable $e) {
                $lastException = $e;
                if ($attempts < $maxAttempts && $stepDefinition->retryDelayMs > 0) {
                    usleep($stepDefinition->retryDelayMs * 1000);
                }
            }
        }

        return $lastException;
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }

    private function generateSagaId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
