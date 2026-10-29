<?php

declare(strict_types=1);

namespace PhpSaga\Storage;

use DateTimeImmutable;
use PDO;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

/**
 * PDO database repository for persisting saga execution state.
 * Works with SQLite, MySQL, PostgreSQL, or framework PDO connections (e.g. Laravel DB::getPdo()).
 */
class PdoSagaRepository implements SagaRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tableName = 'sagas'
    ) {}

    /**
     * Creates the sagas table schema if it does not already exist.
     */
    public function createSchema(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (
            saga_id VARCHAR(64) PRIMARY KEY,
            saga_type VARCHAR(128) NOT NULL,
            status VARCHAR(32) NOT NULL,
            context TEXT NOT NULL,
            current_step VARCHAR(128) NULL,
            completed_steps TEXT NOT NULL,
            failure_reason TEXT NULL,
            created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL
        )";

        $this->pdo->exec($sql);
    }

    public function save(SagaState $state): void
    {
        if ($this->exists($state->sagaId)) {
            $sql = "UPDATE {$this->tableName} SET
                saga_type = :saga_type,
                status = :status,
                context = :context,
                current_step = :current_step,
                completed_steps = :completed_steps,
                failure_reason = :failure_reason,
                updated_at = :updated_at
                WHERE saga_id = :saga_id";
        } else {
            $sql = "INSERT INTO {$this->tableName} (
                saga_id, saga_type, status, context, current_step, completed_steps, failure_reason, created_at, updated_at
            ) VALUES (
                :saga_id, :saga_type, :status, :context, :current_step, :completed_steps, :failure_reason, :created_at, :updated_at
            )";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':saga_id', $state->sagaId);
        $stmt->bindValue(':saga_type', $state->sagaType);
        $stmt->bindValue(':status', $state->status->value);
        $stmt->bindValue(':context', $state->context->toJson());
        $stmt->bindValue(':current_step', $state->currentStep);
        $stmt->bindValue(':completed_steps', json_encode($state->completedSteps, JSON_THROW_ON_ERROR));
        $stmt->bindValue(':failure_reason', $state->failureReason);
        $stmt->bindValue(':updated_at', $state->updatedAt->format(DateTimeImmutable::ATOM));

        if (!str_contains($sql, 'UPDATE')) {
            $stmt->bindValue(':created_at', $state->createdAt->format(DateTimeImmutable::ATOM));
        }

        $stmt->execute();
    }

    public function find(string $sagaId): ?SagaState
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tableName} WHERE saga_id = :saga_id LIMIT 1");
        $stmt->execute([':saga_id' => $sagaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !is_array($row)) {
            return null;
        }

        $contextJson = is_string($row['context']) ? $row['context'] : '{}';
        $completedStepsJson = is_string($row['completed_steps']) ? $row['completed_steps'] : '[]';

        $completedSteps = json_decode($completedStepsJson, true);
        /** @var array<int, string> $safeCompletedSteps */
        $safeCompletedSteps = is_array($completedSteps) ? array_values(array_map('strval', $completedSteps)) : [];

        return new SagaState(
            sagaId: (string) $row['saga_id'],
            sagaType: (string) $row['saga_type'],
            status: SagaStatus::from((string) $row['status']),
            context: SagaContext::fromJson($contextJson),
            currentStep: is_string($row['current_step']) ? $row['current_step'] : null,
            completedSteps: $safeCompletedSteps,
            failureReason: is_string($row['failure_reason']) ? $row['failure_reason'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    public function exists(string $sagaId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tableName} WHERE saga_id = :saga_id LIMIT 1");
        $stmt->execute([':saga_id' => $sagaId]);

        return $stmt->fetchColumn() !== false;
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
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE saga_id = :saga_id");
        $stmt->execute([':saga_id' => $sagaId]);
    }
}

