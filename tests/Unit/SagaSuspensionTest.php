<?php

declare(strict_types=1);

use PhpSaga\Events\SagaSuspended;
use PhpSaga\Exceptions\SagaSuspendedException;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\InMemorySagaRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

it('halts execution when context->suspend() is called without rolling back', function () {
    $executed = [];
    $repo = new InMemorySagaRepository();

    $definition = SagaBuilder::new('ApprovalWorkflow')
        ->addCallableStep('Step1', function (SagaContext $c) use (&$executed) {
            $executed[] = 'Step1';
            $c->set('step1_done', true);
            // Intentionally halt workflow:
            $c->suspend('Waiting for manager approval');
        })
        ->addCallableStep('Step2', function (SagaContext $c) use (&$executed) {
            $executed[] = 'Step2';
            $c->set('step2_done', true);
        })
        ->build();

    $coordinator = new SagaCoordinator(repository: $repo);
    $result = $coordinator->run($definition, sagaId: 'halt-saga-1');

    expect($result->isSuspended())->toBeTrue()
        ->and($result->isSuccessful())->toBeFalse()
        ->and($result->isCompensated())->toBeFalse()
        ->and($result->getState()->status)->toBe(SagaStatus::SUSPENDED)
        ->and($result->getState()->exitReason)->toBe('Waiting for manager approval')
        ->and($result->getState()->completedSteps)->toBe(['Step1']);

    // Only Step1 executed
    expect($executed)->toBe(['Step1']);

    // Later: Webhook / approval arrives, call resume()
    $resumeResult = $coordinator->resume('halt-saga-1', $definition);

    expect($resumeResult->isSuccessful())->toBeTrue()
        ->and($resumeResult->getState()->status)->toBe(SagaStatus::COMPLETED)
        ->and($resumeResult->getContext()->get('step1_done'))->toBeTrue()
        ->and($resumeResult->getContext()->get('step2_done'))->toBeTrue();

    // Step1 was not re-executed, Step2 ran
    expect($executed)->toBe(['Step1', 'Step2']);
});

it('halts execution when SagaSuspendedException is thrown', function () {
    $executed = [];
    $repo = new InMemorySagaRepository();

    $definition = SagaBuilder::new('OtpWorkflow')
        ->addCallableStep('SendOtp', function (SagaContext $c) use (&$executed) {
            $executed[] = 'SendOtp';
            $c->set('otp_sent', true);
            throw new SagaSuspendedException('Awaiting user OTP code');
        })
        ->addCallableStep('VerifyAndFinalize', function (SagaContext $c) use (&$executed) {
            $executed[] = 'VerifyAndFinalize';
            $c->set('finalized', true);
        })
        ->build();

    $coordinator = new SagaCoordinator(repository: $repo);
    $result = $coordinator->run($definition, sagaId: 'otp-saga-1');

    expect($result->isSuspended())->toBeTrue()
        ->and($result->getState()->status)->toBe(SagaStatus::SUSPENDED)
        ->and($result->getState()->exitReason)->toBe('Awaiting user OTP code');

    expect($executed)->toBe(['SendOtp']);

    // Resume when OTP arrives
    $resumeResult = $coordinator->resume('otp-saga-1', $definition);
    expect($resumeResult->isSuccessful())->toBeTrue()
        ->and($resumeResult->getState()->status)->toBe(SagaStatus::COMPLETED)
        ->and($executed)->toBe(['SendOtp', 'VerifyAndFinalize']);
});

