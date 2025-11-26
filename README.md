# StateFlow

**A powerful state workflow engine for PHP that handles complex state transitions with built-in observability and race condition prevention.**

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

---

## Why StateFlow?

Most state machines force you into rigid patterns. StateFlow is different:

- 🎯 **Delta-Based Transitions** - Specify only what changes, not the entire state
- ⚙️ **Granular Execution Control** - Manage workflow execution at the per-action level
- 🔒 **Race-Safe by Design** - Built-in mutex locking prevents concurrent modification
- 👀 **Fully Observable** - Events fired at every step for monitoring and debugging
- 🎨 **Flexible Validation** - Two-tier gates (transition-level + action-level)
- 📦 **Serializable Context** - Pause, store, and resume workflows hours or days later
- 🔧 **User-Controlled** - You define state structure, merge strategy, and lock behavior

## Perfect For

- E-commerce order processing with payment/inventory/shipping workflows
- Content publishing pipelines with approval stages and notifications
- Long-running batch jobs that need checkpointing
- Multi-step user onboarding flows
- Any scenario where state transitions need audit trails and concurrency control

## Quick Example

```php
use BenRowe\StateFlow\StateMachine;
use BenRowe\StateFlow\Configuration;

// Define your state
class Order implements State {
    public function __construct(
        private string $status,
        private ?string $paymentId = null,
    ) {}

    public function with(array $changes): State {
        return new self(
            status: $changes['status'] ?? $this->status,
            paymentId: $changes['paymentId'] ?? $this->paymentId,
        );
    }

    public function toArray(): array {
        return ['status' => $this->status, 'paymentId' => $this->paymentId];
    }
}

// Configure the workflow
$machine = new StateMachine(
    initialState: new Order('pending'),
    configProvider: fn($state, $delta) => new Configuration(
        transitionGates: [new CanProcessGate()],  // Must pass to proceed
        actions: [
            new ChargePaymentAction(),   // Execute in order
            new ReserveInventoryAction(), // Skip if guard fails
            new SendConfirmationAction(),
        ],
    ),
    eventDispatcher: new Logger(),      // See everything that happens
    lockProvider: new RedisLock($redis), // Prevent race conditions
);

// Execute transition with automatic locking
$context = $machine->transitionTo(['status' => 'processing']);

if ($context->isCompleted()) {
    echo "Order processed!";
} elseif ($context->isPaused()) {
    // Action paused (e.g., waiting for external API)
    // Lock is HELD across pause
    saveToDatabase($context->serialize());

    // Resume hours later...
    $machine->resume($context);
}
```

## Key Features

### 🎯 Delta-Based Transitions

Specify only what changes:

```php
// Just this
$machine->transitionTo(['status' => 'published']);

// Not this
$machine->transitionTo(['status' => 'published', 'author' => 'same', 'created' => 'same', ...]);
```

### ⚙️ Granular Execution Control

Control workflow execution at the per-action level:

```php
// Step through one action at a time
$machine->transitionTo(['status' => 'published']); // Executes first action, pauses
$machine->nextAction(); // Executes second action, pauses
$machine->nextAction(); // Executes third action, completes

// Or let actions pause themselves for async operations
class ProcessVideoAction implements Action {
    public function execute(ActionContext $context): ActionResult {
        $job = dispatch(new VideoProcessingJob());

        // Pause execution, lock is held
        return ActionResult::pause(metadata: ['jobId' => $job->id]);
    }
}

// Resume later when ready
$machine->resume($context);
```

### 🔒 Race Condition Prevention

Built-in mutex locking with multiple strategies:

```php
$machine->transitionTo(
    ['status' => 'published'],
    new LockConfiguration(
        strategy: LockStrategy::WAIT,  // or FAIL_FAST, SKIP
        ttl: 300,                       // 5 minute lock
    )
);
```

If another process tries to transition the same entity, it will wait, fail, or skip based on your strategy.

### 👀 Fully Observable

Every step emits events:

```php
class MyEventDispatcher implements EventDispatcher {
    public function dispatch(Event $event): void {
        match (true) {
            $event instanceof TransitionStarting => $this->log('Starting...'),
            $event instanceof GateEvaluated => $this->log('Gate: ' . $event->result),
            $event instanceof ActionExecuted => $this->log('Action done'),
            $event instanceof TransitionCompleted => $this->log('Complete!'),
        };
    }
}
```

### 🎨 Two-Tier Validation

**Transition Gates** - Must pass for transition to begin:

```php
class CanPublishGate implements Gate {
    public function evaluate(GateContext $context): GateResult {
        return $context->currentState->hasContent()
            ? GateResult::ALLOW
            : GateResult::DENY;
    }
}
```

**Action Gates** - Skip individual actions if guard fails:

```php
class NotifyAction implements Action, Guardable {
    public function gate(): Gate {
        return new HasSubscribersGate();
    }

    public function execute(ActionContext $context): ActionResult {
        // Only runs if HasSubscribersGate passes
    }
}
```

## Installation

```bash
composer require benrowe/stateflow
```

**Requirements:** PHP 8.2+

## Documentation

📚 Comprehensive documentation available in the [`docs/`](./docs) directory:

| Document | Description |
|----------|-------------|
| [Architecture Overview](./docs/architecture.md) | Design goals and principles |
| [Flow Diagrams](./docs/diagrams.md) | Visual flowcharts (Mermaid) |
| [Core Concepts](./docs/core-concepts.md) | State, Gates, Actions, Configuration |
| [Observability](./docs/observability.md) | Event system and monitoring |
| [Locking System](./docs/locking.md) | Race condition handling |
| [Interface Reference](./docs/interfaces.md) | Complete API documentation |
| [Usage Examples](./docs/examples.md) | Real-world patterns |
| [Open Questions](./docs/open-questions.md) | Design decisions to resolve |

## Real-World Example

### E-Commerce Order Processing

```php
// 1. Define state with your domain model
class OrderState implements State {
    public function __construct(
        private string $id,
        private string $status,
        private float $total,
        private ?string $paymentId = null,
    ) {}

    public function with(array $changes): State {
        return new self(
            id: $this->id,
            status: $changes['status'] ?? $this->status,
            total: $changes['total'] ?? $this->total,
            paymentId: $changes['paymentId'] ?? $this->paymentId,
        );
    }

    public function toArray(): array { /* ... */ }
}

// 2. Configure workflow based on transition type
$configProvider = function(State $state, array $delta): Configuration {
    return match ($delta['status'] ?? null) {
        'processing' => new Configuration(
            transitionGates: [new HasInventoryGate($inventory)],
            actions: [
                new ChargePaymentAction($paymentGateway),
                new ReserveInventoryAction($inventory),
                new SendEmailAction($mailer),
            ],
        ),
        'shipped' => new Configuration(
            transitionGates: [new HasPaymentGate()],
            actions: [new CreateShipmentAction($shipping)],
        ),
        default => new Configuration(),
    };
};

// 3. Create machine with observability and locking
$machine = new StateMachine(
    initialState: new OrderState('ORD-123', 'pending', 99.99),
    configProvider: $configProvider,
    eventDispatcher: new MetricsDispatcher(),
    lockProvider: new RedisLockProvider($redis),
    lockKeyProvider: new class implements LockKeyProvider {
        public function getLockKey(State $state, array $delta): string {
            return "order:" . $state->toArray()['id'];
        }
    },
);

// 4. Execute with race protection
try {
    $context = $machine->transitionTo(
        ['status' => 'processing'],
        new LockConfiguration(strategy: LockStrategy::FAIL_FAST)
    );

    if ($context->isCompleted()) {
        return response()->json(['status' => 'success']);
    }

} catch (LockAcquisitionException $e) {
    // Another request is processing this order
    return response()->json(['error' => 'Order is being processed'], 409);
}
```

## What Makes StateFlow Different?

| Feature | StateFlow | Traditional State Machines |
|---------|-----------|---------------------------|
| **Granular Control** | ✅ Per-action execution & pause/resume | ❌ Must complete in one execution |
| **Race-Safe** | ✅ Built-in mutex locking | ❌ Manual coordination required |
| **Observable** | ✅ Events at every step | ❌ Limited visibility |
| **Flexible State** | ✅ User-defined merge strategy | ❌ Rigid state structure |
| **Lazy Config** | ✅ Load gates/actions on-demand | ❌ All configured upfront |
| **Lock Persistence** | ✅ Lock held across pauses | ❌ N/A |
| **Execution Trace** | ✅ Complete audit trail | ❌ Limited history |

## Status

🚧 **Currently in Planning Phase**

The architecture is fully designed and documented. Implementation is pending. See [Open Questions](./docs/open-questions.md) for remaining design decisions.

## Contributing

Contributions welcome! See [Contributing Guide](./docs/contributing.md) for development setup and guidelines.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.

## Credits

- [Ben Rowe](https://github.com/benrowe)
- [All Contributors](../../contributors)

---

Built with ❤️ for developers who need powerful, observable, race-safe workflows.
