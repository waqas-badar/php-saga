<?php

declare(strict_types=1);

use PhpSaga\Contracts\StepInterface;
use PhpSaga\Exceptions\InvalidSagaDefinitionException;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\SagaContext;

it('builds a valid saga definition with callable steps', function () {
    $definition = SagaBuilder::new('OrderCheckout')
        ->addCallableStep('ReserveInventory', fn (SagaContext $c) => $c->set('inventory_reserved', true))
        ->addCallableStep('ProcessPayment', fn (SagaContext $c) => $c->set('payment_processed', true))
        ->build();

    expect($definition->getSagaType())->toBe('OrderCheckout')
        ->and($definition->count())->toBe(2)
        ->and($definition->hasStep('ReserveInventory'))->toBeTrue()
        ->and($definition->hasStep('ProcessPayment'))->toBeTrue()
        ->and($definition->hasStep('NonExistent'))->toBeFalse()
        ->and($definition->getStep(0)?->getName())->toBe('ReserveInventory')
        ->and($definition->findStep('ProcessPayment'))->not->toBeNull();
});

it('throws exception if saga type is empty', function () {
    expect(fn () => SagaBuilder::new('   ')->addCallableStep('Step1', fn () => null)->build())
        ->toThrow(InvalidSagaDefinitionException::class, 'Saga type cannot be empty.');
});

it('throws exception if no steps are provided', function () {
    expect(fn () => SagaBuilder::new('EmptySaga')->build())
        ->toThrow(InvalidSagaDefinitionException::class, "Saga 'EmptySaga' must contain at least one step.");
});

it('throws exception on duplicate step names', function () {
    expect(fn () => SagaBuilder::new('DuplicateStepsSaga')
        ->addCallableStep('DuplicateStep', fn () => null)
        ->addCallableStep('DuplicateStep', fn () => null)
        ->build())
        ->toThrow(InvalidSagaDefinitionException::class, "Duplicate step name 'DuplicateStep' detected in Saga 'DuplicateStepsSaga'.");
});

it('throws exception on negative retry parameters', function () {
    /** @var StepInterface&Mockery\MockInterface $stepMock */
    $stepMock = Mockery::mock(StepInterface::class);
    $stepMock->shouldReceive('getName')->andReturn('FailingConfigStep');

    expect(fn () => (new SagaBuilder('TestSaga'))->addStep($stepMock, retries: -1))
        ->toThrow(InvalidSagaDefinitionException::class, "Step retries cannot be negative for step 'FailingConfigStep'.");

    expect(fn () => (new SagaBuilder('TestSaga'))->addStep($stepMock, retryDelayMs: -10))
        ->toThrow(InvalidSagaDefinitionException::class, "Step retry delay cannot be negative for step 'FailingConfigStep'.");
});
