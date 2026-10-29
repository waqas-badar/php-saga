<?php

declare(strict_types=1);

namespace PhpSaga;

/**
 * Workflow states representing the lifecycle of a Saga execution.
 */
enum SagaStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case COMPENSATING = 'compensating';
    case COMPENSATED = 'compensated';
    case FAILED = 'failed';

    /**
     * Determines whether the saga is currently active (running forward or compensating).
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::IN_PROGRESS, self::COMPENSATING => true,
            default => false,
        };
    }

    /**
     * Determines whether the saga has reached a terminal state (cannot transition further).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::COMPENSATED, self::FAILED => true,
            default => false,
        };
    }

    /**
     * Determines whether the saga completed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Determines whether the saga encountered a failure (compensated or compensation failed).
     */
    public function isFailed(): bool
    {
        return match ($this) {
            self::COMPENSATED, self::FAILED => true,
            default => false,
        };
    }
}

