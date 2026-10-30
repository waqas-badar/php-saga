<?php

declare(strict_types=1);

use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\FileSagaRepository;

function createTestDirectory(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php_saga_test_' . bin2hex(random_bytes(6));
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return $dir;
}

function cleanTestDirectory(string $dir): void
{
    if (is_dir($dir)) {
        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($dir);
    }
}

it('creates directory and saves/retrieves saga state to JSON file', function () {
    $tempDir = createTestDirectory();
    $repo = new FileSagaRepository($tempDir);

    try {
        $state = new SagaState(
            sagaId: 'file-saga-1',
            sagaType: 'FileWorkflow',
            status: SagaStatus::IN_PROGRESS,
            context: new SagaContext(['order_id' => 'ORD-FILE-1', 'amount' => 55.00]),
            currentStep: 'StepA',
            completedSteps: ['StepA']
        );

        expect($repo->exists('file-saga-1'))->toBeFalse()
            ->and($repo->find('file-saga-1'))->toBeNull();

        $repo->save($state);

        expect($repo->exists('file-saga-1'))->toBeTrue();
        $found = $repo->find('file-saga-1');

        expect($found)->not->toBeNull();
        assert($found instanceof SagaState);
        expect($found->sagaId)->toBe('file-saga-1')
            ->and($found->status)->toBe(SagaStatus::IN_PROGRESS)
            ->and($found->context->get('order_id'))->toBe('ORD-FILE-1')
            ->and($found->completedSteps)->toBe(['StepA']);
    } finally {
        cleanTestDirectory($tempDir);
    }
});

it('updates status with exitReason and updates context in file repository', function () {
    $tempDir = createTestDirectory();
    $repo = new FileSagaRepository($tempDir);

    try {
        $state = new SagaState(
            sagaId: 'file-saga-2',
            sagaType: 'InvoiceWorkflow',
            status: SagaStatus::IN_PROGRESS,
            context: new SagaContext(['inv_id' => 10])
        );
        $repo->save($state);

        $repo->updateStatus(
            'file-saga-2',
            SagaStatus::SUSPENDED,
            currentStep: 'AwaitingPayment',
            exitReason: 'Waiting for customer bank transfer'
        );

        $updated = $repo->find('file-saga-2');
        expect($updated)->not->toBeNull();
        assert($updated instanceof SagaState);
        expect($updated->status)->toBe(SagaStatus::SUSPENDED)
            ->and($updated->currentStep)->toBe('AwaitingPayment')
            ->and($updated->exitReason)->toBe('Waiting for customer bank transfer');

        $repo->updateContext('file-saga-2', new SagaContext(['inv_id' => 10, 'paid' => true]));
        $contextUpdated = $repo->find('file-saga-2');

        expect($contextUpdated)->not->toBeNull();
        assert($contextUpdated instanceof SagaState);
        expect($contextUpdated->context->get('paid'))->toBeTrue();

        $repo->delete('file-saga-2');
        expect($repo->exists('file-saga-2'))->toBeFalse();
    } finally {
        cleanTestDirectory($tempDir);
    }
});

it('correctly reports count and clears files', function () {
    $tempDir = createTestDirectory();
    $repo = new FileSagaRepository($tempDir);

    try {
        expect($repo->count())->toBe(0);

        $repo->save(new SagaState(
            sagaId: 'item-1',
            sagaType: 'T1',
            status: SagaStatus::PENDING,
            context: new SagaContext()
        ));

        $repo->save(new SagaState(
            sagaId: 'item-2',
            sagaType: 'T2',
            status: SagaStatus::PENDING,
            context: new SagaContext()
        ));

        expect($repo->count())->toBe(2);

        $repo->clear();
        expect($repo->count())->toBe(0);
    } finally {
        cleanTestDirectory($tempDir);
    }
});
