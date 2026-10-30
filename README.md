# php-saga

[![Latest Version](https://img.shields.io/packagist/v/waqas-badar/php-saga.svg?style=flat-square)](https://packagist.org/packages/waqas-badar/php-saga)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg?style=flat-square)](https://www.php.net/)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen.svg?style=flat-square)](https://pestphp.com/)
[![PHPStan](https://img.shields.io/badge/phpstan-level%20max-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

A lightweight, framework-agnostic implementation of the **Saga Pattern** (orchestrator-driven execution with backward compensation) in modern **PHP 8.2+**. 

Handle complex, distributed, multi-step business transactions without external runtime dependencies (like Temporal, Camunda, or Temporal Server).

---

## ⚡ Highlights

- **Framework-Agnostic & Lightweight**: Zero runtime framework coupling. Runs in vanilla PHP, Laravel, Symfony, or background queue workers.
- **Strictly Typed & Modern**: Built for PHP 8.2+ with backed Enums, `readonly` classes, and match expressions.
- **Automatic LIFO Rollback**: When any step fails, previously completed steps are safely compensated in reverse order (Last-In, First-Out).
- **Configurable Retries**: Per-step retry policies with millisecond backoff for transient network glitches.
- **Crash Recovery & Resumption**: Rehydrate interrupted or queued workflows from storage and resume from the exact pending step.
- **PSR-11 & PSR-14 Compliant**: On-demand dependency injection for steps (`class-string`) and native event dispatching for observability.
- **Pluggable Persistence**: Includes `PdoSagaRepository` (SQLite, MySQL, Postgres), `FileSagaRepository` (disk-based JSON), and `InMemorySagaRepository`.

---

## 📦 Installation

Install via Composer:

```bash
composer require waqas-badar/php-saga
```

---

## 🚀 Quick Start

### 1. Fluent Closure-Based Saga

For quick or inline workflows, define steps with callables and closures:

```php
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;

$saga = SagaBuilder::new('OrderCheckout')
    ->addCallableStep(
        name: 'ReserveInventory',
        execute: function (SagaContext $ctx) {
            $reservationId = InventoryApi::reserve($ctx->get('product_id'), $ctx->get('qty'));
            $ctx->set('reservation_id', $reservationId);
        },
        compensate: function (SagaContext $ctx) {
            InventoryApi::release($ctx->get('reservation_id'));
        },
        retries: 2
    )
    ->addCallableStep(
        name: 'ChargePayment',
        execute: function (SagaContext $ctx) {
            $chargeId = PaymentGateway::charge($ctx->get('amount'));
            $ctx->set('charge_id', $chargeId);
        },
        compensate: function (SagaContext $ctx) {
            PaymentGateway::refund($ctx->get('charge_id'));
        }
    )
    ->build();

$coordinator = new SagaCoordinator();
$result = $coordinator->run($saga, [
    'product_id' => 'PROD-101',
    'qty' => 1,
    'amount' => 89.99,
]);

if ($result->isSuccessful()) {
    echo "Order completed! Charge: " . $result->getContext()->get('charge_id');
} elseif ($result->isCompensated()) {
    echo "Order failed and was safely refunded: " . $result->getExecutionException()?->getMessage();
}
```

---

### 2. Object-Oriented Class-Based Saga

For structured enterprise codebases, encapsulate steps into dedicated classes and extend `AbstractSaga`:

#### Define Individual Steps
```php
use PhpSaga\Contracts\StepInterface;
use PhpSaga\SagaContext;

class ReserveInventoryStep implements StepInterface
{
    public function getName(): string
    {
        return 'ReserveInventory';
    }

    public function execute(SagaContext $context): void
    {
        // Reserve stock
        $context->set('stock_reserved', true);
    }

    public function compensate(SagaContext $context): void
    {
        // Release stock
        $context->set('stock_reserved', false);
    }
}
```

#### Define the Saga
```php
use PhpSaga\AbstractSaga;
use PhpSaga\Execution\SagaBuilder;

class OrderFulfillmentSaga extends AbstractSaga
{
    public function configure(SagaBuilder $builder): void
    {
        $builder
            ->addStep(ReserveInventoryStep::class, retries: 1)
            ->addStep(ProcessPaymentStep::class)
            ->addStep(DispatchCourierStep::class, continueOnFailure: false);
    }
}
```

#### Execute Through Coordinator
```php
$coordinator = new SagaCoordinator($repository);
$result = $coordinator->run(new OrderFulfillmentSaga(), [
    'customer_id' => 456,
    'order_total' => 129.50,
]);
```

---

## 🛠️ Advanced Capabilities

### 🔁 Crash Recovery & Workflow Resumption

In distributed architectures, background workers, or queue processing, workflows are vulnerable to external interruptions:
- A worker process terminates or times out mid-execution.
- A deployment or pod restart kills an active saga.
- A database or third-party API is temporarily unreachable.

Rather than re-running the entire saga from the start (which could charge a customer twice or duplicate external mutations), `php-saga` provides native **snapshot rehydration and execution resumption**.

#### How Resumption Works Under the Hood
1. **Continuous State Snapshotting**: After each successful step, `SagaCoordinator` saves the updated `SagaContext`, list of `completedSteps`, and current status to the `SagaRepositoryInterface`.
2. **Step Skipping**: When `$coordinator->resume($sagaId, $saga)` is called, the coordinator reads the persisted record and builds a set of already completed step names. Steps present in `completedSteps` are **skipped**.
3. **Seamless Continuation**: Execution picks up directly at the first pending step, passing the restored context.
4. **Unified LIFO Compensation**: If a step fails *after* resumption, the coordinator triggers backward compensation across **all** completed steps (both the steps executed in the initial run and those executed during the resumed run) in strict reverse order (Last-In, First-Out).

---

#### End-to-End Example: Crash and Resumption

Below is a complete, runnable example demonstrating how an interrupted saga is resumed:

```php
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;
use PhpSaga\SagaStatus;
use PhpSaga\Storage\PdoSagaRepository;

// 1. Setup Database Repository
$pdo = new PDO('sqlite::memory:');
$repository = new PdoSagaRepository($pdo);
$repository->createSchema();

// 2. Define Steps
class ReserveStockStep implements StepInterface
{
    public function getName(): string { return 'ReserveStock'; }
    public function execute(SagaContext $c): void {
        echo "[Run] Stock reserved for Order #" . $c->get('order_id') . PHP_EOL;
        $c->set('stock_reserved', true);
    }
    public function compensate(SagaContext $c): void {
        echo "[Rollback] Stock released." . PHP_EOL;
        $c->set('stock_reserved', false);
    }
}

class ChargeCustomerStep implements StepInterface
{
    public function getName(): string { return 'ChargeCustomer'; }
    public function execute(SagaContext $c): void {
        echo "[Run] Charged $" . $c->get('amount') . PHP_EOL;
        $c->set('payment_id', 'PAY-987');
    }
    public function compensate(SagaContext $c): void {
        echo "[Rollback] Payment refunded." . PHP_EOL;
        $c->set('payment_id', null);
    }
}

class SendConfirmationStep implements StepInterface
{
    public function getName(): string { return 'SendConfirmation'; }
    public function execute(SagaContext $c): void {
        echo "[Run] Confirmation email sent." . PHP_EOL;
        $c->set('email_sent', true);
    }
    public function compensate(SagaContext $c): void {}
}

// 3. Assemble the Saga Definition
$saga = SagaBuilder::new('OrderCheckoutWorkflow')
    ->addStep(new ReserveStockStep())
    ->addStep(new ChargeCustomerStep())
    ->addStep(new SendConfirmationStep())
    ->build();

// =========================================================================
// SCENARIO: Initial Run Crashes After Step 1 (ReserveStock)
// =========================================================================
$sagaId = 'order-saga-xyz-001';

// Simulate that Step 1 completed before a server crash by saving state snapshot:
$repository->save(new \PhpSaga\SagaState(
    sagaId: $sagaId,
    sagaType: 'OrderCheckoutWorkflow',
    status: SagaStatus::IN_PROGRESS,
    context: new SagaContext(['order_id' => 1001, 'amount' => 150.00, 'stock_reserved' => true]),
    currentStep: 'ReserveStock',
    completedSteps: ['ReserveStock']
));

// =========================================================================
// RESUMPTION: Worker restarts and calls resume()
// =========================================================================
echo "--- Resuming Saga: {$sagaId} ---" . PHP_EOL;
$coordinator = new SagaCoordinator(repository: $repository);

$result = $coordinator->resume(
    sagaId: $sagaId,
    saga: $saga
);

if ($result->isSuccessful()) {
    echo "--- Saga Successfully Resumed and Completed! ---" . PHP_EOL;
    echo "Status: " . $result->getState()->status->value . PHP_EOL; // "completed"
    echo "Completed Steps: " . implode(', ', $result->getState()->completedSteps) . PHP_EOL;
    // Output: ReserveStock, ChargeCustomer, SendConfirmation
}
```

**Console Output During Resumption**:
```text
--- Resuming Saga: order-saga-xyz-001 ---
[Run] Charged $150.00
[Run] Confirmation email sent.
--- Saga Successfully Resumed and Completed! ---
Status: completed
Completed Steps: ReserveStock, ChargeCustomer, SendConfirmation
```
Notice that **`ReserveStock` was not re-executed**; its state was preserved, and only the remaining steps executed!

---

### 🗄️ Database Storage (PDO Repository)

Persist saga snapshots directly in PostgreSQL, MySQL, or SQLite:

```php
use PhpSaga\Storage\PdoSagaRepository;

$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'root', 'secret');
$repository = new PdoSagaRepository($pdo, tableName: 'sagas');

// Automatically creates the table schema if needed
$repository->createSchema();

$coordinator = new SagaCoordinator(repository: $repository);
```

---

### 📁 File Storage (Disk-Based JSON Repository)

For CLI apps, microservices, or local environments without a database, persist state directly as JSON files:

```php
use PhpSaga\Storage\FileSagaRepository;

// Automatically creates the directory if needed
$repository = new FileSagaRepository(__DIR__ . '/storage/sagas');

$coordinator = new SagaCoordinator(repository: $repository);
```
- Each saga is safely stored as `saga_{sagaId}.json` with atomic file locking (`LOCK_EX`).
- Supports `find()`, `save()`, `updateStatus()`, `updateContext()`, `delete()`, `count()`, and `clear()`.

---

### 📡 Observability & Events (PSR-14)

Attach any PSR-14 event dispatcher to track execution telemetry:

```php
$coordinator = new SagaCoordinator(
    repository: $repository,
    dispatcher: $eventDispatcher
);
```

Dispatched events (under `PhpSaga\Events\`):
- `SagaStarted`
- `StepExecuting` / `StepExecuted` / `StepFailed`
- `CompensationExecuting` / `CompensationExecuted`
- `SagaCompleted` / `SagaCompensated` / `SagaFailed`

---

## 🧪 Testing & Quality Gates

Run the test suite via Pest:

```bash
composer test
```

Run PHPStan at maximum level (Level MAX):

```bash
composer stan
```

Run both test and static analysis checks:

```bash
composer check
```

---

## 📄 License

This package is open-source software licensed under the [MIT license](LICENSE).
