<?php

declare(strict_types=1);

namespace PhpSaga;

use DateTimeImmutable;
use JsonSerializable;

/**
 * Immutable snapshot of a Saga workflow instance state.
 */
readonly class SagaState implements JsonSerializable
{
    /**
     * @param string $sagaId Unique saga identifier (UUID, order ID, etc.)
     * @param string $sagaType Identifier or class name of the saga workflow
     * @param SagaStatus $status Current lifecycle status of the saga
     * @param SagaContext $context Snapshot of shared context data
     * @param string|null $currentStep Currently executing or last attempted step
     * @param array<int, string> $completedSteps Ordered list of successfully executed step names
     * @param string|null $failureReason Description or exception message if failed
     * @param DateTimeImmutable $createdAt Timestamp when saga was created
     * @param DateTimeImmutable $updatedAt Timestamp of the most recent state change
     */
    public function __construct(
        public string $sagaId,
        public string $sagaType,
        public SagaStatus $status,
        public SagaContext $context,
        public ?string $currentStep = null,
        public array $completedSteps = [],
        public ?string $failureReason = null,
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {}

    /**
     * Returns a new instance with updated status and timestamp.
     */
    public function withStatus(SagaStatus $status, ?string $currentStep = null, ?string $failureReason = null): self
    {
        return new self(
            sagaId: $this->sagaId,
            sagaType: $this->sagaType,
            status: $status,
            context: $this->context,
            currentStep: $currentStep ?? $this->currentStep,
            completedSteps: $this->completedSteps,
            failureReason: $failureReason ?? $this->failureReason,
            createdAt: $this->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Returns a new instance with updated context.
     */
    public function withContext(SagaContext $context): self
    {
        return new self(
            sagaId: $this->sagaId,
            sagaType: $this->sagaType,
            status: $this->status,
            context: $context,
            currentStep: $this->currentStep,
            completedSteps: $this->completedSteps,
            failureReason: $this->failureReason,
            createdAt: $this->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * Returns a new instance marking a step as completed.
     */
    public function withCompletedStep(string $stepName): self
    {
        $steps = $this->completedSteps;
        if (!in_array($stepName, $steps, true)) {
            $steps[] = $stepName;
        }

        return new self(
            sagaId: $this->sagaId,
            sagaType: $this->sagaType,
            status: $this->status,
            context: $this->context,
            currentStep: $stepName,
            completedSteps: $steps,
            failureReason: $this->failureReason,
            createdAt: $this->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'saga_id' => $this->sagaId,
            'saga_type' => $this->sagaType,
            'status' => $this->status->value,
            'context' => $this->context->jsonSerialize(),
            'current_step' => $this->currentStep,
            'completed_steps' => $this->completedSteps,
            'failure_reason' => $this->failureReason,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Reconstruct a SagaState from serialized array data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sagaId = isset($data['saga_id']) && is_scalar($data['saga_id']) ? (string) $data['saga_id'] : '';
        $sagaType = isset($data['saga_type']) && is_scalar($data['saga_type']) ? (string) $data['saga_type'] : 'default';
        $statusRaw = isset($data['status']) && is_scalar($data['status']) ? (string) $data['status'] : '';
        $status = SagaStatus::from($statusRaw);

        $contextData = isset($data['context']) && is_array($data['context']) ? $data['context'] : [];
        /** @var array<string, mixed> $contextMap */
        $contextMap = [];
        foreach ($contextData as $key => $val) {
            $contextMap[(string) $key] = $val;
        }

        $currentStep = isset($data['current_step']) && is_scalar($data['current_step']) ? (string) $data['current_step'] : null;

        $completedSteps = [];
        if (isset($data['completed_steps']) && is_array($data['completed_steps'])) {
            foreach ($data['completed_steps'] as $step) {
                if (is_scalar($step)) {
                    $completedSteps[] = (string) $step;
                }
            }
        }

        $failureReason = isset($data['failure_reason']) && is_scalar($data['failure_reason']) ? (string) $data['failure_reason'] : null;
        $createdAt = isset($data['created_at']) && is_string($data['created_at'])
            ? new DateTimeImmutable($data['created_at'])
            : new DateTimeImmutable();
        $updatedAt = isset($data['updated_at']) && is_string($data['updated_at'])
            ? new DateTimeImmutable($data['updated_at'])
            : new DateTimeImmutable();

        return new self(
            sagaId: $sagaId,
            sagaType: $sagaType,
            status: $status,
            context: SagaContext::fromArray($contextMap),
            currentStep: $currentStep,
            completedSteps: $completedSteps,
            failureReason: $failureReason,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }
}
