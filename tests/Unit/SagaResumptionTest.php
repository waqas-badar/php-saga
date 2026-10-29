<?php

declare(strict_types=1);

use PhpSaga\Exceptions\SagaNotFoundException;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\InMemorySagaRepository;

it('resumes an in-progress saga by skipping completed steps and executing pending ones', function () {
    $executed = [];

    $definition = SagaBuilder::new('ResumableWorkflow')
        ->addCallableStep('Step1', function (SagaContext $c) use (&$executed) {
            $executed[] = 'Step1';
            $c->set('s1', 'done');
        })
        ->addCallableStep('Step2', function (SagaContext $c) use (&$executed) {
            $executed[] = 'Step2';
            $c->set('s2', 'done');
        })
        ->addCallableStep('Step3', function (SagaContext $c) use (&$executed) {
            $executed[] = 'Step3';
            $c->set('s3', 'done');
        })
        ->build();

    $repo = new InMemorySagaRepository();

    // Simulate that Step1 has already completed and was saved in storage
    $initialState = new SagaState(
        sagaId: 'saga-resume-101',
        sagaType: 'ResumableWorkflow',
        status: SagaStatus::IN_PROGRESS,
        context: new SagaContext(['s1' => 'done']),
        currentStep: 'Step1',
        completedSteps: ['Step1']
    );
    $repo->save($initialState);

    $coordinator = new SagaCoordinator(repository: $repo);
    $result = $coordinator->resume('saga-resume-101', $definition);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->getState()->status)->toBe(SagaStatus::COMPLETED)
        ->and($result->getContext()->get('s1'))->toBe('done')
        ->and($result->getContext()->get('s2'))->toBe('done')
        ->and($result->getContext()->get('s3'))->toBe('done')
        ->and($result->getState()->completedSteps)->toBe(['Step1', 'Step2', 'Step3']);

    // Step1 was skipped because it was already completed; only Step2 and Step3 executed
    expect($executed)->toBe(['Step2', 'Step3']);
});

it('throws SagaNotFoundException when resuming a non-existent saga', function () {
    $repo = new InMemorySagaRepository();
    $coordinator = new SagaCoordinator(repository: $repo);
    $definition = SagaBuilder::new('Dummy')->addCallableStep('S', fn () => null)->build();

    expect(fn () => $coordinator->resume('missing-id', $definition))
        ->toThrow(SagaNotFoundException::class, "Saga instance with ID 'missing-id' was not found.");
});

it('returns existing result when resuming an already completed saga', function () {
    $repo = new InMemorySagaRepository();
    $state = new SagaState(
        sagaId: 'done-1',
        sagaType: 'FinishedSaga',
        status: SagaStatus::COMPLETED,
        context: new SagaContext(['result' => 'already_finished']),
        completedSteps: ['StepA']
    );
    $repo->save($state);

    $coordinator = new SagaCoordinator(repository: $repo);
    $definition = SagaBuilder::new('FinishedSaga')->addCallableStep('StepA', fn () => null)->build();

    $result = $coordinator->resume('done-1', $definition);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->getContext()->get('result'))->toBe('already_finished');
});

