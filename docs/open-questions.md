# Open Questions & Design Decisions

This document tracks unresolved design decisions that need to be addressed during implementation.

## 1. Serialization & Deserialization

### The Problem

`TransitionContext` needs to be serializable for pause/resume workflows, but it contains:
- `State` interface instances (user-defined types)
- `Action` interface instances (user-defined types)
- `Configuration` with Gate and Action arrays

When we serialize the context:
```php
$serialized = $context->serialize();
// Context is converted to:
[
    'currentState' => ['status' => 'draft', ...],  // State::toArray()
    'actions' => ['PublishAction', 'NotifyAction'], // Class names
    // ...
]
```

When we unserialize:
```php
$context = TransitionContext::unserialize($serialized, $stateFactory, $actionFactory);
// How do we reconstruct?
$this->currentState = ???; // How to convert array back to State?
$this->actions = ???;      // How to convert class names to Action instances?
```

### Possible Solutions

#### Option 1: User Provides Factories

```php
interface StateFactory
{
    public function fromArray(array $data): State;
}

interface ActionFactory
{
    public function fromClassName(string $className): Action;
}

class TransitionContext
{
    public function __construct(
        State $initialState,
        ?StateFactory $stateFactory = null,
        ?ActionFactory $actionFactory = null,
    ) {}

    public function unserialize(string $data): void
    {
        $data = unserialize($data);
        $this->currentState = $this->stateFactory->fromArray($data['currentState']);
        $this->actions = array_map(
            fn($class) => $this->actionFactory->fromClassName($class),
            $data['actions']
        );
    }
}
```

**Pros:**
- User has full control
- Works with any State/Action implementation
- Explicit dependencies

**Cons:**
- More boilerplate
- Factories need to be available at unserialize time



### Decision

After review, **Option 1: User Provides Factories** is the chosen approach.

This method offers the best balance of flexibility and explicitness. It gives the user full control over how their `State` and `Action` objects are reconstructed, which is essential for a library that doesn't impose specific implementation details.

While it introduces some boilerplate (requiring factories), this is a reasonable trade-off for the level of control it provides. The contracts are clear, and since users are already expected to implement the `State` interface, creating a corresponding factory is a natural extension of that responsibility.

The implementation will require the user to provide `StateFactory` and `ActionFactory` instances when working with serializable contexts.



## 2. State Merge Location & Timing

### The Problem

Currently, the delta is passed through gates and actions, but when does the actual merging happen?

```php
$worker = $stateFlow->transition($state, ['status' => 'published']);
$context = $worker->execute();

// Option A: Merge before gates?
$mergedState = $currentState->with($delta);
$gate->evaluate(new GateContext($currentState, $delta, $mergedState));

// Option B: Merge in actions?
class PublishAction implements Action {
    public function execute(ActionContext $context): ActionResult {
        $newState = $context->currentState->with($context->desiredDelta);
        return ActionResult::continue($newState);
    }
}

// Option C: Machine merges automatically after all actions?
// Not feasible - state changes during action execution
```

### Current Design

Currently assumes **Option B** - actions handle merging:
```php
$newState = $context->currentState->with(['status' => 'published']);
return ActionResult::continue($newState);
```

### Decision

State merging is the responsibility of the **Action**.

This approach provides the most flexibility:
-   **Actions Control State:** Since actions are the components that perform the actual work, they are in the best position to know how the state should be updated.
-   **Complex Merging Logic:** It allows for complex merging logic that goes beyond a simple `array_merge`. An action can decide to conditionally apply, transform, or ignore parts of the `desiredDelta`.
-   **Sequential State Changes:** Each action in a sequence receives the state as updated by the previous action, allowing for a chain of mutations.

Gates will only receive the `currentState` and the `desiredDelta`. They are for validation *before* the change, not for validating the *outcome* of the change. If the outcome needs validation, a subsequent `Action` or `Gate` should be used.

There will be no "default merge action." The responsibility is explicitly on the user-provided actions. If no action updates the state, the state remains unchanged.



## 3. Lock Renewal for Long-Running Workflows

### The Problem

If a workflow pauses for longer than the lock TTL, the lock expires:

```php
$lockProvider = new RedisLockProvider($redis, ttl: 60);  // 60 seconds
$stateFlow = new StateFlow(
    configProvider: $config,
    lockProvider: $lockProvider,
);

$worker = $stateFlow->transition($state, ['status' => 'published']);
$context = $worker->execute();

if ($context->isPaused()) {
    saveToDatabase($context->serialize());
}

// 5 minutes later...
$restoredContext = TransitionContext::unserialize(loadFromDatabase(), $stateFactory, $actionFactory);
$worker = $stateFlow->fromContext($restoredContext);
$finalContext = $worker->execute();  // Lock expired! Throws LockLostException
```

### Decision

The `LockProvider` interface will be extended to provide a method for **manually extending the TTL of an existing lock**.

This allows users to manage long-running paused workflows by explicitly renewing the lock when the workflow is resumed or periodically through an external process.

**Automatic lock renewal will not be supported within StateFlow.** Implementing auto-renewal effectively creates a "never expiring" lock, which can hide underlying issues and create maintenance challenges. The responsibility for managing the lock's lifetime and explicit renewal lies with the user's application logic.



## 4. Action Dependencies & Ordering

### The Problem

What if actions have dependencies on each other?

```php
new Configuration(
    actions: [
        new ChargePaymentAction(),     // Must run first
        new SendEmailAction(),         // Depends on payment succeeding
        new UpdateInventoryAction(),   // Depends on payment succeeding
    ],
);
```

Currently, actions run in order. But what if:
- `ChargePaymentAction` stops (payment fails)
- Should `SendEmailAction` still run?

### Decision

**Action execution will be strictly FIFO (First In, First Out).**

StateFlow will **not support explicit action dependencies or Directed Acyclic Graphs (DAGs)** for orchestrating actions. This decision maintains the simplicity of the core engine and avoids introducing significant complexity for edge cases that can often be handled in other ways.

If complex sequencing, conditional execution based on prior action outcomes, or external dependencies are required, this logic should be encapsulated **within a single action**. An action can:

*   Call other methods or services.
*   Perform internal conditional logic.
*   Query the `TransitionContext` to see which gates have passed or which actions have already executed.

If an action returns an `ActionResult::stop()`, it explicitly halts the current transition, meaning any subsequent actions in the queue will not be executed. Users must design their actions with this sequential execution model in mind.



## 5. Idempotency & Duplicate Detection

### The Problem

What if a transition is executed twice?

```php
// Request 1
$worker = $stateFlow->transition($state, ['status' => 'published']);
$context = $worker->execute();

// Request 2 (duplicate/retry)
$worker = $stateFlow->transition($state, ['status' => 'published']);
$context = $worker->execute();
```

### Decision

**Idempotency checks will be handled by Transition Gates.**

This approach provides maximum flexibility to the user, allowing them to define idempotency logic that is specific to their domain and transition requirements. Since idempotency often involves comparing the current state with the desired delta, and potentially other business rules, a `Gate` is the most appropriate place for this validation.

For example, a `Gate` can check if the entity is already in the target state and, if so, return `GateResult::DENY` or a custom `GateResult` that signals to skip the transition entirely.

While not built-in initially, the framework could provide **common, reusable Idempotency Gates** in the future to simplify common scenarios, without enforcing a specific idempotency strategy on the user.



## 6. Nested/Sub-Workflows

### The Problem

What if an action needs to trigger another state flow?

```php
class ProcessOrderAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        // Start a payment workflow (separate state machine)
        $paymentFlow = new StateFlow(/* ... */);
        $paymentState = new PaymentState('pending');
        $worker = $paymentFlow->transition($paymentState, ['status' => 'charged']);
        $paymentContext = $worker->execute();

        if (!$paymentContext->isCompleted()) {
            // Payment workflow failed/paused
            return ActionResult::stop();
        }

        return ActionResult::continue();
    }
}
```

### Decision

StateFlow will **not provide explicit, built-in support for nested workflows**.

Adding this feature introduces significant complexity, including tracking the state of child workflows, handling nested pauses, and managing context serialization across parent and child machines.

The current design is sufficient for users to implement this pattern themselves if needed. An `Action` can instantiate and run another `StateFlow`, then `pause` or `stop` the parent workflow based on the outcome of the child workflow. This approach keeps the core library simple while providing the necessary flexibility for advanced use cases.



## 7. Rollback & Compensation

### The Problem

What if a transition fails midway and we need to undo previous actions?

```php
Action1: ChargePayment   ✓ (succeeded)
Action2: ReserveInventory ✓ (succeeded)
Action3: ShipOrder       ✗ (failed)

// Now we need to:
// - Refund payment
// - Release inventory
```

### Decision

StateFlow will **not provide built-in automatic rollback or compensation mechanisms**.

The responsibility for handling rollback and compensation logic will lie entirely with the user. This decision is made to keep the core StateFlow solution simple and focused on state orchestration rather than distributed transaction management.

Users who require compensation for failed actions should implement this logic within their own actions (e.g., by creating separate compensation actions or by handling it outside the state machine workflow).



## 8. Partial State Updates

### The Problem

What if actions only update specific fields, not related to the desired delta?

```php
$worker = $stateFlow->transition($state, ['status' => 'published']);
$context = $worker->execute();

// But Action adds a timestamp:
class AuditAction implements Action {
    public function execute(ActionContext $context): ActionResult {
        $newState = $context->currentState->with([
            'lastModifiedAt' => now(),  // Not in desiredDelta!
        ]);

        return ActionResult::continue($newState);
    }
}
```

### Decision

Partial state updates are **allowed and explicitly supported**.

Actions are free to update any part of the state they deem necessary, even if those fields are not part of the `desiredDelta` provided to the `transitionTo` method. This means side effects are possible and a composite state (where one change alters another part of the state, such as automatically updating a `lastModifiedAt` timestamp when `status` changes) is fully within an `Action`'s domain.

This decision aligns with the principle that `Actions` are responsible for determining the `newState` based on the `currentState` and `desiredDelta`, and can incorporate any additional logic required.



## 9. Machine State vs Entity State

### The Problem

The `StateFlow` holds state internally, but should it be tied to an entity?

```php
// Approach A: Machine per entity instance (REJECTED)
$stateFlow = new StateFlow();
$worker = $stateFlow->transition($order->getState(), $order->getState(), ['status' => 'shipped']);
$context = $worker->execute();

// Approach B: Machine as service, state passed in (CHOSEN)
$stateFlow = app(StateFlow::class);
$worker = $stateFlow->transition($order->getState(), ['status' => 'shipped']);
$context = $worker->execute();
```

### Decision

The `StateFlow` will be refactored into a stateless service, and a new `StateWorker` class will be introduced to manage the execution of a single transition.

This decision moves away from the "machine per entity" model (Approach A) and fully embraces the "machine as a service" model (Approach B), which offers better reusability and a clearer separation of concerns.

The new workflow will be as follows:

1.  **`StateFlow` as a Service:** The `StateFlow` will no longer hold an internal state. It will be a reusable service that you can inject where needed.

2.  **`transition()` Method:** The primary interaction method will be `$stateFlow->transition($existingState, $delta)`. This method takes the current state of an entity and the desired changes.

3.  **`StateWorker` Object:** The `transition()` method will not execute the transition immediately. Instead, it will return a `StateWorker` object, which is responsible for the lifecycle of that specific transition.

4.  **`StateWorker` Methods:** The `StateWorker` will provide fine-grained control over the transition's execution with the following public methods:
    *   `runGates()`: Executes all configured transition gates.
    *   `runActions()`: Executes all configured actions sequentially.
    *   `runNextAction()`: Executes only the next action in the queue (for step-by-step execution).
    *   `execute()`: A helper method that runs the entire transition lifecycle (`runGates` then `runActions`).

This new design provides a more flexible and powerful API, allowing users to choose between a simple, one-shot `execute()` call or a more controlled, step-by-step execution. It also makes the `StateFlow` itself truly stateless and easier to manage in dependency injection containers.



## Summary of Decisions Needed

| # | Question | Priority | Proposed Solution |
|---|----------|----------|-------------------|
| 1 | Serialization | High | Decided: User provides factories |
| 2 | State merge location | High | Decided: Actions handle merging |
| 3 | Lock renewal | Medium | Decided: Manual TTL extension via LockProvider, no auto-renewal |
| 4 | Action dependencies | Low | Decided: Strictly FIFO, no explicit dependencies |
| 5 | Idempotency | Medium | Decided: Handled by Transition Gates (with future common gates) |
| 6 | Nested workflows | Low | Decided: Not built-in, user manages |
| 7 | Rollback | Medium | Decided: Not built-in, user manages |
| 8 | Partial updates | Low | Decided: Allowed |
| 9 | Machine state | Low | Decided: Stateless machine, state passed in, `StateWorker` returned |



## Notes for Implementation

These questions should be revisited as we implement and test the system. Some may be answered through usage patterns, others may require explicit design decisions.

Document any decisions made during implementation in this file.
