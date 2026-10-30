<?php

declare(strict_types=1);

use PhpSaga\SagaStatus;

it('has all required backed string values', function () {
    expect(SagaStatus::PENDING->value)->toBe('pending')
        ->and(SagaStatus::IN_PROGRESS->value)->toBe('in_progress')
        ->and(SagaStatus::SUSPENDED->value)->toBe('suspended')
        ->and(SagaStatus::COMPLETED->value)->toBe('completed')
        ->and(SagaStatus::COMPENSATING->value)->toBe('compensating')
        ->and(SagaStatus::COMPENSATED->value)->toBe('compensated')
        ->and(SagaStatus::FAILED->value)->toBe('failed');
});

it('correctly identifies active states', function () {
    expect(SagaStatus::IN_PROGRESS->isActive())->toBeTrue()
        ->and(SagaStatus::COMPENSATING->isActive())->toBeTrue()
        ->and(SagaStatus::PENDING->isActive())->toBeFalse()
        ->and(SagaStatus::COMPLETED->isActive())->toBeFalse()
        ->and(SagaStatus::COMPENSATED->isActive())->toBeFalse()
        ->and(SagaStatus::FAILED->isActive())->toBeFalse();
});

it('correctly identifies terminal states', function () {
    expect(SagaStatus::COMPLETED->isTerminal())->toBeTrue()
        ->and(SagaStatus::COMPENSATED->isTerminal())->toBeTrue()
        ->and(SagaStatus::FAILED->isTerminal())->toBeTrue()
        ->and(SagaStatus::PENDING->isTerminal())->toBeFalse()
        ->and(SagaStatus::IN_PROGRESS->isTerminal())->toBeFalse()
        ->and(SagaStatus::COMPENSATING->isTerminal())->toBeFalse();
});

it('correctly identifies successful and failed states', function () {
    expect(SagaStatus::COMPLETED->isSuccessful())->toBeTrue()
        ->and(SagaStatus::FAILED->isSuccessful())->toBeFalse()
        ->and(SagaStatus::COMPENSATED->isFailed())->toBeTrue()
        ->and(SagaStatus::FAILED->isFailed())->toBeTrue()
        ->and(SagaStatus::COMPLETED->isFailed())->toBeFalse();
});

