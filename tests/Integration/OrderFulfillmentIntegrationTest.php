<?php

declare(strict_types=1);

use PhpSaga\AbstractSaga;
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Events\SagaCompleted;
use PhpSaga\Events\SagaCompensated;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\PdoSagaRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

// Realistic External Mock Services
class InventoryService
{
    public static int $stock = 10;
}

class PaymentGateway
{
    /** @var array<string, float> */
    public static array $charges = [];
    /** @var array<string, float> */
    public static array $refunds = [];
}

class CourierService
{
    public static bool $shouldFail = false;
    /** @var array<int, string> */
    public static array $dispatched = [];
}

// Step 1: Inventory Reservation
class ReserveInventoryStep implements StepInterface
{
    public function getName(): string
    {
        return 'ReserveInventory';
    }

    public function execute(SagaContext $context): void
    {
        $rawQty = $context->get('quantity', 1);
        $quantity = is_numeric($rawQty) ? (int) $rawQty : 1;
        if (InventoryService::$stock < $quantity) {
            throw new RuntimeException('Insufficient stock available.');
        }

        InventoryService::$stock -= $quantity;
        $context->set('inventory_reserved', true);
    }

    public function compensate(SagaContext $context): void
    {
        $rawQty = $context->get('quantity', 1);
        $quantity = is_numeric($rawQty) ? (int) $rawQty : 1;
        InventoryService::$stock += $quantity;
        $context->set('inventory_reserved', false);
    }
}

// Step 2: Payment Processing
class ChargePaymentStep implements StepInterface
{
    public function getName(): string
    {
        return 'ChargePayment';
    }

    public function execute(SagaContext $context): void
    {
        $rawAmount = $context->get('amount', 0.0);
        $amount = is_numeric($rawAmount) ? (float) $rawAmount : 0.0;
        $chargeId = 'ch_' . bin2hex(random_bytes(6));

        PaymentGateway::$charges[$chargeId] = $amount;
        $context->set('charge_id', $chargeId);
        $context->set('is_paid', true);
    }

    public function compensate(SagaContext $context): void
    {
        $rawChargeId = $context->get('charge_id');
        $chargeId = is_string($rawChargeId) ? $rawChargeId : '';
        if (isset(PaymentGateway::$charges[$chargeId])) {
            $amount = PaymentGateway::$charges[$chargeId];
            PaymentGateway::$refunds[$chargeId] = $amount;
            unset(PaymentGateway::$charges[$chargeId]);
        }
        $context->set('is_paid', false);
        $context->set('is_refunded', true);
    }
}

// Step 3: Courier Dispatch
class DispatchCourierStep implements StepInterface
{
    public function getName(): string
    {
        return 'DispatchCourier';
    }

    public function execute(SagaContext $context): void
    {
        if (CourierService::$shouldFail) {
            throw new RuntimeException('Courier API gateway timeout.');
        }

        $trackingNumber = 'TRK-' . bin2hex(random_bytes(4));
        CourierService::$dispatched[] = $trackingNumber;
        $context->set('tracking_number', $trackingNumber);
    }

    public function compensate(SagaContext $context): void
    {
        $rawTracking = $context->get('tracking_number');
        $trackingNumber = is_string($rawTracking) ? $rawTracking : '';
        $key = array_search($trackingNumber, CourierService::$dispatched, true);
        if ($key !== false) {
            unset(CourierService::$dispatched[$key]);
        }
    }
}

// Reusable E-Commerce Saga Definition
class OrderFulfillmentSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(new ReserveInventoryStep())
            ->addStep(new ChargePaymentStep(), retries: 1)
            ->addStep(new DispatchCourierStep());
    }
}

beforeEach(function () {
    InventoryService::$stock = 10;
    PaymentGateway::$charges = [];
    PaymentGateway::$refunds = [];
    CourierService::$shouldFail = false;
    CourierService::$dispatched = [];
});

it('executes end-to-end order workflow successfully with SQLite database storage', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $repo = new PdoSagaRepository($pdo);
    $repo->createSchema();

    /** @var array<int, object> $events */
    $events = [];
    $dispatcher = new class($events) implements EventDispatcherInterface {
        /** @param array<int, object> $events */
        public function __construct(public array &$events) {}
        public function dispatch(object $event): object
        {
            $this->events[] = $event;

            return $event;
        }
    };

    $coordinator = new SagaCoordinator(repository: $repo, dispatcher: $dispatcher);
    $saga = new OrderFulfillmentSaga();

    $result = $coordinator->run(
        saga: $saga,
        context: ['order_id' => 'ORD-5001', 'quantity' => 2, 'amount' => 79.99],
        sagaId: 'e2e-saga-success-1'
    );

    // Verify workflow result
    expect($result->isSuccessful())->toBeTrue()
        ->and($result->getState()->status)->toBe(SagaStatus::COMPLETED)
        ->and($result->getContext()->get('inventory_reserved'))->toBeTrue()
        ->and($result->getContext()->get('is_paid'))->toBeTrue()
        ->and($result->getContext()->has('tracking_number'))->toBeTrue();

    // Verify external service states
    expect(InventoryService::$stock)->toBe(8)
        ->and(count(PaymentGateway::$charges))->toBe(1)
        ->and(count(PaymentGateway::$refunds))->toBe(0)
        ->and(count(CourierService::$dispatched))->toBe(1);

    // Verify persisted SQLite database state
    $persisted = $repo->find('e2e-saga-success-1');
    expect($persisted)->not->toBeNull();
    assert($persisted instanceof \PhpSaga\SagaState);
    expect($persisted->status)->toBe(SagaStatus::COMPLETED)
        ->and($persisted->completedSteps)->toBe(['ReserveInventory', 'ChargePayment', 'DispatchCourier'])
        ->and($persisted->context->get('order_id'))->toBe('ORD-5001');

    // Verify lifecycle event
    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(SagaCompleted::class);
});

it('triggers complete backward compensation and restores state when a step fails', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $repo = new PdoSagaRepository($pdo);
    $repo->createSchema();

    /** @var array<int, object> $events */
    $events = [];
    $dispatcher = new class($events) implements EventDispatcherInterface {
        /** @param array<int, object> $events */
        public function __construct(public array &$events) {}
        public function dispatch(object $event): object
        {
            $this->events[] = $event;

            return $event;
        }
    };

    // Force failure on courier dispatch
    CourierService::$shouldFail = true;

    $coordinator = new SagaCoordinator(repository: $repo, dispatcher: $dispatcher);
    $saga = new OrderFulfillmentSaga();

    $result = $coordinator->run(
        saga: $saga,
        context: ['order_id' => 'ORD-5002', 'quantity' => 3, 'amount' => 120.00],
        sagaId: 'e2e-saga-failure-1'
    );

    // Verify workflow failure outcome
    expect($result->isSuccessful())->toBeFalse()
        ->and($result->isCompensated())->toBeTrue()
        ->and($result->getState()->status)->toBe(SagaStatus::COMPENSATED)
        ->and($result->getExecutionException()?->getMessage())->toBe('Courier API gateway timeout.');

    // Verify that inventory was rolled back to original 10
    expect(InventoryService::$stock)->toBe(10);

    // Verify that payment was refunded
    expect(count(PaymentGateway::$charges))->toBe(0)
        ->and(count(PaymentGateway::$refunds))->toBe(1);

    // Verify persisted record in SQLite database
    $persisted = $repo->find('e2e-saga-failure-1');
    expect($persisted)->not->toBeNull();
    assert($persisted instanceof \PhpSaga\SagaState);
    expect($persisted->status)->toBe(SagaStatus::COMPENSATED)
        ->and($persisted->context->get('is_refunded'))->toBeTrue()
        ->and($persisted->exitReason)->toBe('Courier API gateway timeout.');

    // Verify last event was SagaCompensated
    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(SagaCompensated::class);
});
