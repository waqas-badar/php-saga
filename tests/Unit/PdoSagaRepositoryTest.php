<?php

declare(strict_types=1);

use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\PdoSagaRepository;

it('creates schema and saves/retrieves saga state via PDO', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $repo = new PdoSagaRepository($pdo);
    $repo->createSchema();

    $context = new SagaContext(['order_id' => 'ORD-999', 'amount' => 149.99]);
    $state = new SagaState(
        sagaId: 'pdo-saga-1',
        sagaType: 'OrderWorkflow',
        status: SagaStatus::PENDING,
        context: $context,
        completedSteps: ['Step1']
    );

    expect($repo->exists('pdo-saga-1'))->toBeFalse()
        ->and($repo->find('pdo-saga-1'))->toBeNull();

    $repo->save($state);

    expect($repo->exists('pdo-saga-1'))->toBeTrue();
    $found = $repo->find('pdo-saga-1');

    expect($found)->not->toBeNull();
    assert($found instanceof SagaState);
    expect($found->sagaId)->toBe('pdo-saga-1')
        ->and($found->sagaType)->toBe('OrderWorkflow')
        ->and($found->status)->toBe(SagaStatus::PENDING)
        ->and($found->context->get('order_id'))->toBe('ORD-999')
        ->and($found->context->get('amount'))->toBe(149.99)
        ->and($found->completedSteps)->toBe(['Step1']);
});

it('updates status and context in PDO repository', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $repo = new PdoSagaRepository($pdo);
    $repo->createSchema();

    $state = new SagaState(
        sagaId: 'pdo-saga-2',
        sagaType: 'InvoiceSaga',
        status: SagaStatus::IN_PROGRESS,
        context: new SagaContext(['inv_id' => 1])
    );
    $repo->save($state);

    $repo->updateStatus('pdo-saga-2', SagaStatus::FAILED, currentStep: 'SendInvoice', failureReason: 'Mailgun down');
    $updated = $repo->find('pdo-saga-2');

    expect($updated)->not->toBeNull();
    assert($updated instanceof SagaState);
    expect($updated->status)->toBe(SagaStatus::FAILED)
        ->and($updated->currentStep)->toBe('SendInvoice')
        ->and($updated->failureReason)->toBe('Mailgun down');

    $repo->updateContext('pdo-saga-2', new SagaContext(['inv_id' => 1, 'retry_scheduled' => true]));
    $contextUpdated = $repo->find('pdo-saga-2');

    expect($contextUpdated)->not->toBeNull();
    assert($contextUpdated instanceof SagaState);
    expect($contextUpdated->context->get('retry_scheduled'))->toBeTrue();

    $repo->delete('pdo-saga-2');
    expect($repo->exists('pdo-saga-2'))->toBeFalse();
});

