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
$context = unserialize($serialized);
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

---

## 2. State Merge Location & Timing

### The Problem

Currently, the delta is passed through gates and actions, but when does the actual merging happen?

```php
$machine->transitionTo(['status' => 'published']);

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

---

## 3. Lock Renewal for Long-Running Workflows

### The Problem

If a workflow pauses for longer than the lock TTL, the lock expires:

```php
$context = $machine->transitionTo(
    ['status' => 'published'],
    new LockConfiguration(ttl: 60)  // 60 seconds
);

if ($context->isPaused()) {
    saveToDatabase($context->serialize());
}

// 5 minutes later...
$machine->resume($context);  // Lock expired! Throws LockLostException
```

### Questions

1. Should we support lock renewal?
   ```php
   $machine->renewLock(); // Extend TTL
   ```

2. Should there be a background job that auto-renews locks?
   ```php
   new LockConfiguration(
       ttl: 60,
       autoRenew: true,  // Keep renewing every 30s
   );
   ```

3. Or should users just set very large TTLs?
   ```php
   new LockConfiguration(ttl: 86400); // 24 hours
   ```

### Decision Needed

How to handle long-running paused workflows?

---

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

### Current Behavior

If an action returns `ActionResult::stop()`, execution stops and remaining actions don't run.

### Questions

1. Is linear ordering sufficient, or do we need a DAG (directed acyclic graph)?

2. Should actions declare dependencies?
   ```php
   class SendEmailAction implements Action
   {
       public function dependencies(): array
       {
           return [ChargePaymentAction::class];
       }
   }
   ```

3. Should there be action groups?
   ```php
   new Configuration(
       actions: [
           new ActionGroup([
               new ChargePaymentAction(),
               new UpdateInventoryAction(),
           ], strategy: 'all-or-nothing'),

           new SendEmailAction(),
       ],
   );
   ```

### Decision Needed

Is simple linear ordering enough, or do we need more sophisticated dependency management?

---

## 5. Idempotency & Duplicate Detection

### The Problem

What if a transition is executed twice?

```php
// Request 1
$machine->transitionTo(['status' => 'published']);

// Request 2 (duplicate/retry)
$machine->transitionTo(['status' => 'published']);
```

### Questions

1. Should the machine detect duplicate transitions?
   ```php
   if ($currentState->status === $desiredDelta['status']) {
       // Already in target state, skip transition
       return TransitionContext::alreadyCompleted();
   }
   ```

2. Should this be configurable?
   ```php
   $machine->transitionTo(
       ['status' => 'published'],
       allowIdempotent: true  // Skip if already in target state
   );
   ```

3. Or should users handle this in gates?
   ```php
   class NotAlreadyPublishedGate implements Gate
   {
       public function evaluate(GateContext $context): GateResult
       {
           $current = $context->currentState->toArray();
           $desired = $context->desiredDelta;

           return $current['status'] === $desired['status']
               ? GateResult::DENY
               : GateResult::ALLOW;
       }
   }
   ```

### Decision Needed

Should the machine provide built-in idempotency checks?

---

## 6. Nested/Sub-Workflows

### The Problem

What if an action needs to trigger another state machine?

```php
class ProcessOrderAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        // Start a payment workflow (separate state machine)
        $paymentMachine = new StateMachine(/* ... */);
        $paymentContext = $paymentMachine->transitionTo(['status' => 'charged']);

        if (!$paymentContext->isCompleted()) {
            // Payment workflow failed/paused
            return ActionResult::stop();
        }

        return ActionResult::continue();
    }
}
```

### Questions

1. Should nested workflows be supported explicitly?

2. Should nested workflow context be tracked in parent?
   ```php
   $context->getActionExecutions()[0]['nestedWorkflows'] = [
       ['machine' => 'PaymentMachine', 'context' => /* ... */],
   ];
   ```

3. How do we handle pause in nested workflows?
   - If child pauses, should parent pause?
   - Should parent serialize child context?

### Decision Needed

Do we need explicit support for nested workflows, or is the current design sufficient?

---

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

### Questions

1. Should actions support compensation/rollback?
   ```php
   interface CompensatableAction extends Action
   {
       public function compensate(ActionContext $context): void;
   }
   ```

2. Should the machine track successful actions for rollback?
   ```php
   if ($context->isStopped()) {
       foreach (array_reverse($context->getActionExecutions()) as $exec) {
           if ($exec['action'] instanceof CompensatableAction) {
               $exec['action']->compensate();
           }
       }
   }
   ```

3. Or should users handle this explicitly?
   ```php
   try {
       $context = $machine->transitionTo(['status' => 'shipped']);
   } catch (\Exception $e) {
       $this->manuallyRollback($context);
   }
   ```

### Decision Needed

Should StateFlow support automatic rollback/compensation?

---

## 8. Partial State Updates

### The Problem

What if actions only update specific fields, not related to the desired delta?

```php
$machine->transitionTo(['status' => 'published']);

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

---

## 9. Machine State vs Entity State

### The Problem

The `StateMachine` holds state internally, but should it be tied to an entity?

```php
// Approach A: Machine per entity instance
$orderMachine = new StateMachine(initialState: $order->getState());
$orderMachine->transitionTo(['status' => 'shipped']);

// Approach B: Machine as service, state passed in
$machineService = app(OrderStateMachine::class);
$machineService->transition($order, ['status' => 'shipped']);
```

### Current Design

Approach A - machine holds state.

### Questions

1. Should machines be reusable for multiple entities?
2. Should state be injected per transition instead of at construction?
3. How does this affect serialization?

### Decision Needed

Is the current design (machine owns state) correct?

---

## Summary of Decisions Needed

| # | Question | Priority | Proposed Solution |
|---|----------|----------|-------------------|
| 1 | Serialization | High | Decided: User provides factories |
| 2 | State merge location | High | Decided: Actions handle merging |
| 3 | Lock renewal | Medium | Large TTLs, manual renewal method |
| 4 | Action dependencies | Low | Linear ordering sufficient for now |
| 5 | Idempotency | Medium | User handles in gates |
| 6 | Nested workflows | Low | Not built-in, user manages |
| 7 | Rollback | Medium | Not built-in, user manages |
| 8 | Partial updates | Low | Decided: Allowed |
| 9 | Machine state | Low | Current design is fine |

---

## Notes for Implementation

These questions should be revisited as we implement and test the system. Some may be answered through usage patterns, others may require explicit design decisions.

Document any decisions made during implementation in this file.
