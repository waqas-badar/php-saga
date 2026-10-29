<?php

declare(strict_types=1);

use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\InMemorySagaRepository;

it('can save, find, and check existence of saga state', function () {
    $repo = new InMemorySagaRepository();
    $context = new SagaContext(['order_id' => 'ORD-101']);
    $state = new SagaState(
        sagaId: 'saga-001',
        sagaType: 'OrderFulfillmentSaga',
        status: SagaStatus::PENDING,
        context: $context
    );

    expect($repo->exists('saga-001'))->toBeFalse()
        ->and($repo->find('saga-001'))->toBeNull();

    $repo->save($state);

    expect($repo->exists('saga-001'))->toBeTrue();
    $retrieved = $repo->find('saga-001');

    expect($retrieved)->not->toBeNull();
    assert($retrieved instanceof SagaState);
    expect($retrieved->sagaId)->toBe('saga-001')
        ->and($retrieved->status)->toBe(SagaStatus::PENDING)
        ->and($retrieved->context->get('order_id'))->toBe('ORD-101');
});

it('can update status and current step', function () {
    $repo = new InMemorySagaRepository();
    $state = new SagaState(
        sagaId: 'saga-002',
        sagaType: 'PaymentSaga',
        status: SagaStatus::IN_PROGRESS,
        context: new SagaContext()
    );
    $repo->save($state);

    $repo->updateStatus('saga-002', SagaStatus::FAILED, currentStep: 'ChargeCreditCard', failureReason: 'Card expired');
    $updated = $repo->find('saga-002');

    expect($updated)->not->toBeNull();
    assert($updated instanceof SagaState);
    expect($updated->status)->toBe(SagaStatus::FAILED)
        ->and($updated->currentStep)->toBe('ChargeCreditCard')
        ->and($updated->failureReason)->toBe('Card expired');
});

it('can update context and delete saga state', function () {
    $repo = new InMemorySagaRepository();
    $state = new SagaState(
        sagaId: 'saga-003',
        sagaType: 'UserRegistrationSaga',
        status: SagaStatus::IN_PROGRESS,
        context: new SagaContext(['step' => 1])
    );
    $repo->save($state);

    $newContext = new SagaContext(['step' => 2, 'user_id' => 789]);
    $repo->updateContext('saga-003', $newContext);

    $found = $repo->find('saga-003');
    expect($found)->not->toBeNull();
    assert($found instanceof SagaState);
    expect($found->context->get('user_id'))->toBe(789);

    $repo->delete('saga-003');
    expect($repo->exists('saga-003'))->toBeFalse();
});

it('serializes and deserializes SagaState to and from array', function () {
    $state = new SagaState(
        sagaId: 'saga-004',
        sagaType: 'OrderSaga',
        status: SagaStatus::COMPLETED,
        context: new SagaContext(['paid' => true]),
        currentStep: 'SendConfirmation',
        completedSteps: ['CreateOrder', 'ProcessPayment', 'SendConfirmation']
    );

    $serialized = $state->jsonSerialize();
    expect($serialized['status'])->toBe('completed')
        ->and($serialized['completed_steps'])->toBe(['CreateOrder', 'ProcessPayment', 'SendConfirmation']);

    $reconstituted = SagaState::fromArray($serialized);
    expect($reconstituted->sagaId)->toBe('saga-004')
        ->and($reconstituted->status)->toBe(SagaStatus::COMPLETED)
        ->and($reconstituted->context->get('paid'))->toBeTrue()
        ->and($reconstituted->completedSteps)->toBe(['CreateOrder', 'ProcessPayment', 'SendConfirmation']);
});

