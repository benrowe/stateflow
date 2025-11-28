# StateFlow Architecture Documentation

This directory contains the architectural design and planning documents for the StateFlow package.

## Overview

StateFlow is a stateful workflow engine that determines if we can transition from a 'current' state to a 'preferred' state. The preferred state is provided as a delta (only the fields that should change).

## Key Features

- **State Interface** - User-defined state objects with custom merge strategies
- **Two-Tier Gates** - Transition gates (must pass) and action gates (skip on fail)
- **Pausable Execution** - Step-through execution with serializable context
- **Observable Orchestration** - Events fired before/after every gate and action
- **Mutex Locking** - Race condition prevention with lock persistence across pauses
- **Lazy Configuration** - Dynamic gate/action loading based on transition type

## Documentation Structure

1. [Architecture Overview](./architecture.md) - High-level design goals and principles
2. [Flow Diagrams](./diagrams.md) - Visual flowcharts and sequence diagrams
3. [Core Concepts](./core-concepts.md) - State, Gates, Actions, Configuration
4. [Observability](./observability.md) - Event system and monitoring
5. [Locking System](./locking.md) - Mutex locks and race condition handling
6. [Interface Definitions](./interfaces.md) - Complete API reference
7. [Usage Examples](./examples.md) - Common usage patterns
8. [Open Questions](./open-questions.md) - Unresolved design decisions
9. [Contributing Guide](./contributing.md) - Development setup and guidelines

## Quick Start Example

```php
// 1. Define state
class OrderState implements State {
    public function __construct(
        private string $status,
        private ?DateTimeImmutable $publishedAt = null,
    ) {}

    public function toArray(): array { /* ... */ }
    public function with(array $changes): State { /* ... */ }
}

// 2. Configure flow
$stateFlow = new StateFlow(
    configProvider: fn($state, $delta) => new Configuration(
        transitionGates: [new CanPublishGate()],
        actions: [new SetPublishDateAction(), new NotifyAction()],
    ),
    eventDispatcher: new MyEventDispatcher(),
    lockProvider: new RedisLockProvider($redis),
    lockKeyProvider: new EntityLockKeyProvider(),
);

// 3. Execute transition
$state = new OrderState('draft');
$worker = $stateFlow->transition($state, ['status' => 'published']);
$context = $worker->execute();

// 4. Handle result
if ($context->isCompleted()) {
    echo "Success!";
} elseif ($context->isPaused()) {
    // Serialize and resume later
    $serialized = $context->serialize();
}
```

## Design Principles

1. **User Control** - Users define state merge strategy, lock keys, and configuration
2. **Observability First** - Every step emits events for monitoring and debugging
3. **Pausable by Design** - Support for long-running async workflows
4. **Race-Safe** - Built-in mutex locking with configurable strategies
5. **Type Safe** - Enums and interfaces over booleans and primitives
6. **Serializable** - Full execution context can be stored and resumed

## Current Status

**Phase:** Planning / Pre-Implementation

All core concepts have been designed and documented. Implementation is pending.

See [Open Questions](./open-questions.md) for remaining design decisions.
