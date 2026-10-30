<?php

declare(strict_types=1);

namespace PhpSaga\Contracts;

use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

/**
 * Storage abstraction for persisting saga state, execution progress, and context snapshots.
 */
interface SagaRepositoryInterface
{
    /**
     * Persists or updates the complete saga state snapshot.
     */
    public function save(SagaState $state): void;

    /**
     * Retrieves the persisted saga state by its unique ID, or null if not found.
     */
    public function find(string $sagaId): ?SagaState;

    /**
     * Checks if a saga instance exists in storage.
     */
    public function exists(string $sagaId): bool;

    /**
     * Updates the status and optional execution details of an existing saga.
     */
    public function updateStatus(
        string $sagaId,
        SagaStatus $status,
        ?string $currentStep = null,
        ?string $exitReason = null
    ): void;

    /**
     * Persists an updated context snapshot for an existing saga.
     */
    public function updateContext(string $sagaId, SagaContext $context): void;

    /**
     * Removes a saga state record from storage.
     */
    public function delete(string $sagaId): void;
}

