<?php

declare(strict_types=1);

namespace PhpSaga\Storage;

use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

/**
 * In-memory implementation of SagaRepositoryInterface.
 * Useful for tests, synchronous workflows, or transient sagas.
 */
class InMemorySagaRepository implements SagaRepositoryInterface
{
    /**
     * @var array<string, SagaState>
     */
    private array $storage = [];

    public function save(SagaState $state): void
    {
        $this->storage[$state->sagaId] = $state;
    }

    public function find(string $sagaId): ?SagaState
    {
        return $this->storage[$sagaId] ?? null;
    }

    public function exists(string $sagaId): bool
    {
        return isset($this->storage[$sagaId]);
    }

    public function updateStatus(
        string $sagaId,
        SagaStatus $status,
        ?string $currentStep = null,
        ?string $failureReason = null
    ): void {
        $state = $this->find($sagaId);
        if ($state !== null) {
            $this->save($state->withStatus($status, $currentStep, $failureReason));
        }
    }

    public function updateContext(string $sagaId, SagaContext $context): void
    {
        $state = $this->find($sagaId);
        if ($state !== null) {
            $this->save($state->withContext($context));
        }
    }

    public function delete(string $sagaId): void
    {
        unset($this->storage[$sagaId]);
    }

    /**
     * Helper to clear all records from memory.
     */
    public function clear(): void
    {
        $this->storage = [];
    }

    /**
     * Helper to count records in memory.
     */
    public function count(): int
    {
        return count($this->storage);
    }
}

