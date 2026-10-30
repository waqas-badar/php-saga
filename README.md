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
- **Workflow Halting (Suspension)**: Intentionally halt execution (e.g. awaiting webhooks, payment approvals, or human verification) without rolling back.
- **Crash Recovery & Resumption**: Rehydrate interrupted or suspended workflows from storage and resume from the exact pending step.
- **Configurable Retries**: Per-step retry policies with millisecond backoff for transient network glitches.
- **PSR-11 & PSR-14 Compliant**: On-demand dependency injection for steps (`class-string`) and native event dispatching for observability.
- **Pluggable Persistence**: Includes `FileSagaRepository` (disk-based JSON), `PdoSagaRepository` (SQLite, MySQL, Postgres), and `InMemorySagaRepository`.

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

### ⏸️ Workflow Halting (Suspension) & Resumption

Real-world workflows frequently need to pause and wait for external asynchronous events:
- A customer must complete a **3D-Secure card verification** or bank redirect.
- Waiting for a **webhook callback** from an external payment gateway.
- Waiting for **human approval** or an **email/SMS OTP** code.
- Handling unexpected crashes (worker reboot or deployment restart) mid-execution.

Rather than blocking a PHP process or restarting from scratch, `php-saga` allows steps to **halt execution safely**:
1. Call `$context->suspend('Reason')` or throw `new SagaSuspendedException('Reason')`.
2. The coordinator marks status as **`suspended`**, records the **`exit_reason`**, saves the current state to the repository, and stops without rolling back.
3. Later, when the external trigger arrives, simply call `$coordinator->resume($sagaId, $saga)` (or `$coordinator->run(...)` with the same ID).
4. Already completed steps are **skipped**, and execution smoothly resumes from the exact pending step.

---

#### Complete End-to-End Example (Using File Storage)

This example uses **`FileSagaRepository`** to persist workflow progress directly as JSON files on disk.

```php
use PhpSaga\Contracts\StepInterface;
use PhpSaga\Execution\SagaBuilder;
use PhpSaga\Execution\SagaCoordinator;
use PhpSaga\SagaContext;
use PhpSaga\Storage\FileSagaRepository;

// 1. Setup File Storage (saves JSON files in storage/sagas/)
$storageDir = __DIR__ . '/storage/sagas';
$repository = new FileSagaRepository($storageDir);

// 2. Define Steps
class CreateOrderStep implements StepInterface
{
    public function getName(): string { return 'CreateOrder'; }

    public function execute(SagaContext $c): void
    {
        echo "[Run] Step 1: Order #{$c->get('order_id')} created." . PHP_EOL;
        $c->set('order_created', true);
    }

    public function compensate(SagaContext $c): void
    {
        echo "[Rollback] Step 1: Order cancelled." . PHP_EOL;
    }
}

class RequestBankAuthorizationStep implements StepInterface
{
    public function getName(): string { return 'RequestBankAuthorization'; }

    public function execute(SagaContext $c): void
    {
        echo "[Run] Step 2: Payment requested ($" . $c->get('amount') . ")." . PHP_EOL;
        $c->set('auth_redirect_url', 'https://bank.example.com/verify/3ds-999');

        // Intentionally HALT the workflow here awaiting customer 3D-Secure verification:
        echo ">>> HALTING SAGA: Awaiting 3D-Secure customer verification <<<" . PHP_EOL;
        $c->suspend('Awaiting customer 3D-Secure bank verification');
    }

    public function compensate(SagaContext $c): void
    {
        echo "[Rollback] Step 2: Authorization cancelled." . PHP_EOL;
    }
}

class FulfillAndShipStep implements StepInterface
{
    public function getName(): string { return 'FulfillAndShip'; }

    public function execute(SagaContext $c): void
    {
        echo "[Run] Step 3: Payment verified! Dispatched with tracking #TRK-8888." . PHP_EOL;
        $c->set('tracking_number', 'TRK-8888');
    }

    public function compensate(SagaContext $c): void
    {
        echo "[Rollback] Step 3: Shipment recalled." . PHP_EOL;
    }
}

// 3. Assemble the Saga Definition
$saga = SagaBuilder::new('EcommerceCheckout')
    ->addStep(new CreateOrderStep())
    ->addStep(new RequestBankAuthorizationStep())
    ->addStep(new FulfillAndShipStep())
    ->build();

$coordinator = new SagaCoordinator(repository: $repository);
$sagaId = 'order-xyz-100';

// =========================================================================
// PHASE 1: INITIAL RUN (Executes Step 1 & 2, then HALTS and saves to disk)
// =========================================================================
$result = $coordinator->run(
    saga: $saga,
    context: ['order_id' => 'ORD-7001', 'amount' => 199.99],
    sagaId: $sagaId
);

echo "Status: " . $result->getState()->status->value . PHP_EOL; // "suspended"
echo "Exit Reason: " . $result->getState()->exitReason . PHP_EOL; 
// Output: "Awaiting customer 3D-Secure bank verification"
```

**Console Output (Phase 1)**:
```text
[Run] Step 1: Order #ORD-7001 created.
[Run] Step 2: Payment requested ($199.99).
>>> HALTING SAGA: Awaiting 3D-Secure customer verification <<<
Status: suspended
Exit Reason: Awaiting customer 3D-Secure bank verification
```

**Saved File Snapshot (`storage/sagas/saga_order-xyz-100.json`)**:
```json
{
    "saga_id": "order-xyz-100",
    "saga_type": "EcommerceCheckout",
    "status": "suspended",
    "context": {
        "order_id": "ORD-7001",
        "amount": 199.99,
        "order_created": true,
        "auth_redirect_url": "https://bank.example.com/verify/3ds-999"
    },
    "current_step": "RequestBankAuthorization",
    "completed_steps": [
        "CreateOrder",
        "RequestBankAuthorization"
    ],
    "exit_reason": "Awaiting customer 3D-Secure bank verification",
    "created_at": "2026-10-30T10:00:00+00:00",
    "updated_at": "2026-10-30T10:00:00+00:00"
}
```

---

#### Resuming the Workflow (Phase 2)

Hours or minutes later, when the bank webhook arrives, resume the saga. You can pass any new incoming webhook payload to be merged into the restored context:

```php
// =========================================================================
// PHASE 2: RESUMPTION (Incoming webhook arrives with payment approval)
// =========================================================================

// Step 1 and Step 2 are automatically SKIPPED. Execution resumes at Step 3!
$finalResult = $coordinator->resume(
    sagaId: $sagaId,
    saga: $saga,
    additionalContext: [
        'payment_status' => 'APPROVED',
        'auth_token'     => 'bank_tok_555444333',
    ]
);

if ($finalResult->isSuccessful()) {
    echo "Status: " . $finalResult->getState()->status->value . PHP_EOL; // "completed"
    echo "Completed Steps: " . implode(', ', $finalResult->getState()->completedSteps) . PHP_EOL;
    echo "Tracking: " . $finalResult->getContext()->get('tracking_number') . PHP_EOL;
}
```

**Console Output (Phase 2)**:
```text
[Run] Step 3: Payment verified! Dispatched with tracking #TRK-8888.
Status: completed
Completed Steps: CreateOrder, RequestBankAuthorization, FulfillAndShip
Tracking: TRK-8888
```

> **Tip**: `$coordinator->run($saga, $context, $sagaId)` is also fully **idempotent**. If passed an existing suspended saga ID, it automatically resumes instead of starting over.

---

### 📁 File Storage (Disk-Based JSON Repository)

For CLI apps, microservices, local development, or environments without a database, persist state directly as JSON files:

```php
use PhpSaga\Storage\FileSagaRepository;

// Automatically creates the directory if needed
$repository = new FileSagaRepository(__DIR__ . '/storage/sagas');

$coordinator = new SagaCoordinator(repository: $repository);
```
- Each saga is safely stored as `saga_{sagaId}.json` with atomic file locking (`LOCK_EX`).
- Supports `find()`, `save()`, `updateStatus()`, `updateContext()`, `delete()`, `count()`, and `clear()`.

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
- `SagaSuspended`
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

## 🤝 Credits & Acknowledgments

- **Author & Maintainer**: Waqas Badar ([@waqas-badar](https://github.com/waqas-badar))
- **Architecture & Development**: Developed in collaboration with **Antigravity** (Google DeepMind).

---

## 📄 License

This package is open-source software licensed under the [MIT license](LICENSE).
