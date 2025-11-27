# Architecture Overview

## Vision

StateFlow is a **workflow engine for PHP that orchestrates state transitions with execution tracing and saga-like capabilities**. It orchestrates state transitions through a series of gates (validations) and actions (mutations), with full observability and support for pause/resume.

## Core Design Goals

### 1. Stateless and Reusable Machine

- `StateMachine` is a stateless service.
- State is passed in for each transition.
- A single machine instance can be used for multiple entities.

### 2. Delta-Based Transitions

Users specify only what should change, not the entire final state:

```php
// Good: Delta approach
$worker = $machine->transition($orderState, ['status' => 'published']);
$context = $worker->execute();

// Avoided: Full state (verbose and error-prone)
$machine->transition($orderState, ['status' => 'published', 'author' => 'same', 'created' => 'same', ...]);
```

**Rationale:** With rich state objects, deltas are more ergonomic and show clear intent.

### 3. Two-Tier Validation

**Transition Gates** - Must pass for transition to begin
- Evaluated before any actions execute
- Failure stops the entire transition
- Example: "Can this user publish content?"

**Action Gates** - Guard individual actions
- Actions implement `Guardable` interface
- Failure skips that action, continues to next
- Example: "Should we send notification email?"

### 4. Pausable and Step-Through Execution

The `StateWorker` allows for fine-grained control over the execution flow.

**One-Shot Execution:**
```php
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute(); // Runs gates and actions
```

**Step-Through Execution:**
```php
$worker = $machine->transition($state, ['status' => 'published']);
$gateResult = $worker->runGates();
if ($gateResult->shouldStopTransition()) {
    // Handle failed transition
}
$context = $worker->runActions();
```

**Async Workflow with Pause/Resume:**
```php
// An action can signal a pause
class GenerateThumbnailsAction implements Action {
    public function execute(ActionContext $context): ActionResult {
        $job = dispatch(new ThumbnailJob());
        return ActionResult::pause(metadata: ['jobId' => $job->id]);
    }
}

// Serialize and wait
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();
if ($context->isPaused()) {
    saveToDatabase($context->serialize());
}

// Resume later after external event
$serializedContext = loadFromDatabase();
$stateFactory = new MyStateFactory();
$actionFactory = new MyActionFactory();
$resumedContext = TransitionContext::unserialize($serializedContext, $stateFactory, $actionFactory);

// Create a new worker from the resumed context
$resumedWorker = $machine->fromContext($resumedContext);
$finalContext = $resumedWorker->execute(); // Continues from where it left off
```

### 5. Observable Orchestration

Every step emits events:
- `TransitionStarting`, `TransitionCompleted`, `TransitionPaused`, `TransitionStopped`, `TransitionFailed`
- `GateEvaluating`, `GateEvaluated`
- `ActionExecuting`, `ActionExecuted`, `ActionSkipped`
- `LockAcquiring`, `LockAcquired`, `LockReleased`, `LockFailed`

**Benefits:**
- Real-time monitoring and debugging
- Audit trails for compliance
- Custom behavior injection
- Metrics and logging integration

### 6. Race-Safe with Mutex Locking

Locking is configured on the `StateMachine` itself, making it an integral part of the workflow orchestration.

```php
$lockProvider = new RedisLockProvider($redis);
$machine = new StateMachine(
    configProvider: $configProvider,
    lockProvider: $lockProvider,
);

// The worker will use the machine's lock provider
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();
```
- Request A acquires a lock when `execute()` is called.
- Request B, using the same machine (and thus the same lock provider), will wait or fail based on the lock provider's behavior.
- The lock persists through pauses.
- The lock is released on completion, stop, or manual release.

## Execution Flow

The new architecture separates the setup of a transition from its execution.

```
┌─────────────────────────────────────────────────────┐
│ User: $machine->transition($state, $delta)         │
└─────────────────────┬───────────────────────────────┘
                      │
                      ▼
         ┌────────────────────────┐
         │  Returns StateWorker   │
         └────────┬───────────────┘
                      │
┌─────────────────────▼───────────────────────────────┐
│ User: $worker->execute()                             │
└─────────────────────┬───────────────────────────────┘
                      │
                      ▼
         ┌────────────────────────┐
         │  Acquire Lock          │ (using machine's provider)
         └────────┬───────────────┘
                  │
                  ▼
         ┌────────────────────────┐
         │  Load Configuration    │
         └────────┬───────────────┘
                  │
                  ▼
         ┌────────────────────────┐
         │  Run Transition Gates  │ ◄── $worker->runGates()
         └────────┬───────────────┘
                  │
                  ├─── DENY ──► STOP
                  │
                  ▼ ALLOW
         ┌────────────────────────┐
         │  Run Actions           │ ◄── $worker->runActions()
         └────────┬───────────────┘
                  │
                  ├─── PAUSE ──► Store Context, return
                  ├─── STOP  ──► Release Lock, return
                  │
                  ▼ CONTINUE (after each action)
         ┌────────────────────────┐
         │  Mark Completed        │
         │  Release Lock          │
         │  Return Context        │
         └────────────────────────┘
```

## Key Architectural Decisions

### StateMachine is a Stateless Service

**Why:** Decouples the machine from any single entity, making it a reusable and easily injectable service. State is provided on a per-transition basis.

### StateWorker Manages Transition Lifecycle

**Why:** Encapsulates the state, logic, and execution of a single transition into one object. This provides a clear and flexible API for both simple one-shot transitions (`execute()`) and complex step-by-step or async workflows.

### State is an Interface, Not an Array

**Why:** Gives users full control over state representation and merge strategy.

```php
interface State {
    public function toArray(): array;
    public function with(array $changes): State;
}
```
Users implement their own merge logic in `with()`.

### Configuration is Lazy-Loaded

**Why:** Allows dynamic gate/action selection based on what's changing.

```php
$configProvider = function(State $currentState, array $desiredDelta): Configuration {
    // ... return Configuration based on state and delta
};
```

### Enums Over Booleans

**Why:** Extensibility and clarity.

```php
// Not: bool evaluate()
// But: GateResult evaluate()
enum GateResult {
    case ALLOW;
    case DENY;
    case SKIP_IDEMPOTENT; // Added for idempotency checks
    // Future: DEFER, CONDITIONAL, etc.
}

enum ExecutionState {
    case CONTINUE;
    case PAUSE;
    case STOP;
}
```

## Extension Points

Users provide implementations for:
1. **State** - State representation and merge strategy
2. **ConfigurationProvider** - Which gates/actions for which transitions
3. **Gate** - Validation logic
4. **Action** - State mutation and side-effect logic
5. **EventDispatcher** - Event handling and routing
6. **LockProvider** - Lock storage (Redis, DB, etc.)
7. **LockKeyProvider** - What to lock (entity, transition type, etc.)

## Design Trade-offs

### Chose: Stateless Machine + StateWorker
**Instead of:** Stateful machine per entity
**Trade-off:** Slightly more verbose for a single transition (`$machine->transition()->execute()`), but provides a much more flexible and powerful API for complex scenarios and dependency injection.

### Chose: Delta + Context Object
**Instead of:** Full state transitions
**Trade-off:** More complex gate evaluation, but much better UX

### Chose: State interface
**Instead of:** Arrays everywhere
**Trade-off:** More boilerplate, but user control over merge strategy

### Chose: Serializable context
**Instead of:** In-memory only
**Trade-off:** Serialization complexity, but enables async workflows

### Chose: Factory-Based Serialization
**Instead of:** Standard `serialize()`/`unserialize()`
**Trade-off:** Requires users to provide factories, but enables reconstruction of custom `State` and `Action` objects without tying the serialized data to a specific class structure.

## Future Considerations

See [Open Questions](./open-questions.md) for unresolved design decisions.
