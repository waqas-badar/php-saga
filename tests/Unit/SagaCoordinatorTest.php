<?php

declare(strict_types=1);

use PhpSaga\AbstractSaga;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\InMemorySagaRepository;

it('executes all steps sequentially and completes successfully', function () {
    $executionLog = [];

    $definition = SagaBuilder::new('OrderWorkflow')
        ->addCallableStep('ReserveInventory', function (SagaContext $ctx) use (&$executionLog) {
            $executionLog[] = 'execute:ReserveInventory';
            $ctx->set('inventory_id', 'INV-100');
        })
        ->addCallableStep('ProcessPayment', function (SagaContext $ctx) use (&$executionLog) {
            $executionLog[] = 'execute:ProcessPayment';
            $ctx->set('payment_id', 'PAY-200');
        })
        ->addCallableStep('SendNotification', function (SagaContext $ctx) use (&$executionLog) {
            $executionLog[] = 'execute:SendNotification';
            $ctx->set('notification_sent', true);
        })
        ->build();

    $coordinator = new SagaCoordinator();
    $result = $coordinator->run($definition, ['order_id' => 'ORD-1']);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->isCompensated())->toBeFalse()
        ->and($result->hasFailed())->toBeFalse()
        ->and($result->getState()->status)->toBe(SagaStatus::COMPLETED)
        ->and($result->getContext()->get('inventory_id'))->toBe('INV-100')
        ->and($result->getContext()->get('payment_id'))->toBe('PAY-200')
        ->and($result->getContext()->get('notification_sent'))->toBeTrue()
        ->and($executionLog)->toBe([
            'execute:ReserveInventory',
            'execute:ProcessPayment',
            'execute:SendNotification',
        ]);
});

it('triggers LIFO backward compensation when a step fails', function () {
    $log = [];

    $definition = SagaBuilder::new('FailingOrderWorkflow')
        ->addCallableStep(
            'ReserveInventory',
            execute: function (SagaContext $ctx) use (&$log) {
                $log[] = 'execute:ReserveInventory';
                $ctx->set('inventory_reserved', true);
            },
            compensate: function (SagaContext $ctx) use (&$log) {
                $log[] = 'compensate:ReserveInventory';
                $ctx->set('inventory_reserved', false);
            }
        )
        ->addCallableStep(
            'ChargeCard',
            execute: function (SagaContext $ctx) use (&$log) {
                $log[] = 'execute:ChargeCard';
                $ctx->set('card_charged', true);
            },
            compensate: function (SagaContext $ctx) use (&$log) {
                $log[] = 'compensate:ChargeCard';
                $ctx->set('card_charged', false);
            }
        )
        ->addCallableStep(
            'BookShipment',
            execute: function (SagaContext $ctx) use (&$log) {
                $log[] = 'execute:BookShipment';
                throw new RuntimeException('Shipping courier service unavailable');
            },
            compensate: function (SagaContext $ctx) use (&$log) {
                $log[] = 'compensate:BookShipment';
            }
        )
        ->build();

    $coordinator = new SagaCoordinator();
    $result = $coordinator->run($definition);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->isCompensated())->toBeTrue()
        ->and($result->getState()->status)->toBe(SagaStatus::COMPENSATED)
        ->and($result->getExecutionException()?->getMessage())->toBe('Shipping courier service unavailable')
        ->and($result->getCompensationException())->toBeNull()
        ->and($result->getContext()->get('inventory_reserved'))->toBeFalse()
        ->and($result->getContext()->get('card_charged'))->toBeFalse();

    // Verify LIFO order: ChargeCard compensated before ReserveInventory, BookShipment never compensated
    expect($log)->toBe([
        'execute:ReserveInventory',
        'execute:ChargeCard',
        'execute:BookShipment',
        'compensate:ChargeCard',
        'compensate:ReserveInventory',
    ]);
});

it('marks state as FAILED when compensation itself fails', function () {
    $definition = SagaBuilder::new('DisasterWorkflow')
        ->addCallableStep(
            'StepA',
            execute: fn (SagaContext $c) => $c->set('step_a', 'done'),
            compensate: fn (SagaContext $c) => throw new RuntimeException('Compensation fatal error')
        )
        ->addCallableStep(
            'StepB',
            execute: fn () => throw new RuntimeException('StepB crashed')
        )
        ->build();

    $coordinator = new SagaCoordinator();
    $result = $coordinator->run($definition);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->isCompensated())->toBeFalse()
        ->and($result->hasFailed())->toBeTrue()
        ->and($result->getState()->status)->toBe(SagaStatus::FAILED)
        ->and($result->getExecutionException()?->getMessage())->toBe('StepB crashed')
        ->and($result->getCompensationException()?->getMessage())->toBe('Compensation fatal error');
});

it('retries failing steps according to retry policy', function () {
    $attempts = 0;

    $definition = SagaBuilder::new('RetryWorkflow')
        ->addCallableStep(
            'FlakyStep',
            execute: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new RuntimeException("Temporary network glitch attempt {$attempts}");
                }
            },
            retries: 3,
            retryDelayMs: 0
        )
        ->build();

    $coordinator = new SagaCoordinator();
    $result = $coordinator->run($definition);

    expect($result->isSuccessful())->toBeTrue()
        ->and($attempts)->toBe(3)
        ->and($result->getState()->status)->toBe(SagaStatus::COMPLETED);
});

it('continues execution when an optional step fails with continueOnFailure', function () {
    $definition = SagaBuilder::new('OptionalStepWorkflow')
        ->addCallableStep('MandatoryFirst', fn (SagaContext $c) => $c->set('first', true))
        ->addCallableStep(
            'OptionalAuditMetric',
            execute: fn () => throw new RuntimeException('Analytics timeout'),
            continueOnFailure: true
        )
        ->addCallableStep('MandatoryLast', fn (SagaContext $c) => $c->set('last', true))
        ->build();

    $coordinator = new SagaCoordinator();
    $result = $coordinator->run($definition);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->getState()->status)->toBe(SagaStatus::COMPLETED)
        ->and($result->getContext()->get('first'))->toBeTrue()
        ->and($result->getContext()->get('last'))->toBeTrue();
});

it('supports class-based sagas extending AbstractSaga', function () {
    $saga = new class extends AbstractSaga {
        public function getName(): string
        {
            return 'SubscriptionSaga';
        }

        public function configure(SagaBuilder $builder): void
        {
            $builder
                ->addCallableStep('CreateCustomer', fn (SagaContext $c) => $c->set('cust_id', 42))
                ->addCallableStep('CreateSubscription', fn (SagaContext $c) => $c->set('sub_id', 99));
        }
    };

    $coordinator = new SagaCoordinator();
    $result = $coordinator->run($saga);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->getState()->sagaType)->toBe('SubscriptionSaga')
        ->and($result->getContext()->get('cust_id'))->toBe(42)
        ->and($result->getContext()->get('sub_id'))->toBe(99);
});

it('persists state snapshots into repository across lifecycle steps', function () {
    $repo = new InMemorySagaRepository();
    $coordinator = new SagaCoordinator($repo);

    $definition = SagaBuilder::new('PersistentSaga')
        ->addCallableStep('Step1', fn (SagaContext $c) => $c->set('s1', true))
        ->addCallableStep('Step2', fn (SagaContext $c) => $c->set('s2', true))
        ->build();

    $result = $coordinator->run($definition, ['initial' => 'data'], sagaId: 'custom-saga-123');

    expect($repo->exists('custom-saga-123'))->toBeTrue();
    $savedState = $repo->find('custom-saga-123');

    expect($savedState)->not->toBeNull();
    assert($savedState instanceof \PhpSaga\SagaState);
    expect($savedState->status)->toBe(SagaStatus::COMPLETED)
        ->and($savedState->context->get('s1'))->toBeTrue()
        ->and($savedState->context->get('s2'))->toBeTrue()
        ->and($savedState->context->get('initial'))->toBe('data')
        ->and($savedState->completedSteps)->toBe(['Step1', 'Step2']);
});
