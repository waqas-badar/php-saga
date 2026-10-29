<?php

declare(strict_types=1);

use PhpSaga\Events\CompensationExecuted;
use PhpSaga\Events\CompensationExecuting;
use PhpSaga\Events\SagaCompleted;
use PhpSaga\Events\SagaCompensated;
use PhpSaga\Events\SagaStarted;
use PhpSaga\Events\StepExecuted;
use PhpSaga\Events\StepExecuting;
use PhpSaga\Events\StepFailed;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;
use Psr\EventDispatcher\EventDispatcherInterface;

it('dispatches lifecycle events on successful execution', function () {
    /** @var array<int, object> $events */
    $events = [];
    $dispatcher = new class($events) implements EventDispatcherInterface {
        /**
         * @param array<int, object> $events
         */
        public function __construct(public array &$events) {}

        public function dispatch(object $event): object
        {
            $this->events[] = $event;

            return $event;
        }
    };

    $definition = SagaBuilder::new('EventSuccessSaga')
        ->addCallableStep('Step1', fn (SagaContext $c) => $c->set('s1', true))
        ->addCallableStep('Step2', fn (SagaContext $c) => $c->set('s2', true))
        ->build();

    $coordinator = new SagaCoordinator(dispatcher: $dispatcher);
    $result = $coordinator->run($definition);

    expect($result->isSuccessful())->toBeTrue();

    $eventClasses = array_map(fn ($e) => get_class($e), $events);
    expect($eventClasses)->toBe([
        SagaStarted::class,
        StepExecuting::class,
        StepExecuted::class,
        StepExecuting::class,
        StepExecuted::class,
        SagaCompleted::class,
    ]);
});

it('dispatches failure and compensation events when a step fails', function () {
    /** @var array<int, object> $events */
    $events = [];
    $dispatcher = new class($events) implements EventDispatcherInterface {
        /**
         * @param array<int, object> $events
         */
        public function __construct(public array &$events) {}

        public function dispatch(object $event): object
        {
            $this->events[] = $event;

            return $event;
        }
    };

    $definition = SagaBuilder::new('EventCompensationSaga')
        ->addCallableStep(
            'Reserve',
            execute: fn (SagaContext $c) => $c->set('reserved', true),
            compensate: fn (SagaContext $c) => $c->set('reserved', false)
        )
        ->addCallableStep(
            'Charge',
            execute: fn () => throw new RuntimeException('Card declined')
        )
        ->build();

    $coordinator = new SagaCoordinator(dispatcher: $dispatcher);
    $result = $coordinator->run($definition);

    expect($result->isCompensated())->toBeTrue();

    $eventClasses = array_map(fn ($e) => get_class($e), $events);
    expect($eventClasses)->toBe([
        SagaStarted::class,
        StepExecuting::class,
        StepExecuted::class,
        StepExecuting::class,
        StepFailed::class,
        CompensationExecuting::class,
        CompensationExecuted::class,
        SagaCompensated::class,
    ]);
});
