# Architecture Overview

## Vision

StateFlow is a **stateful workflow engine with execution tracing and saga-like capabilities**. It orchestrates state transitions through a series of gates (validations) and actions (mutations), with full observability and support for pause/resume.

## Core Design Goals

### 1. Deterministic State Management

- Machine holds internal state
- Transitions are explicit via `transitionTo($delta)`
- State changes only occur through actions
- Full execution trace is maintained

### 2. Delta-Based Transitions

Users specify only what should change, not the entire final state:

```php
// Good: Delta approach
$machine->transitionTo(['status' => 'published']);

// Avoided: Full state (verbose and error-prone)
$machine->transitionTo(['status' => 'published', 'author' => 'same', 'created' => 'same', ...]);
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

### 4. Pausable Execution

**Step-Through Mode:**
```php
$machine->transitionTo(['status' => 'published']); // Executes action 1, pauses
$machine->nextAction(); // Executes action 2, pauses
$machine->nextAction(); // Executes action 3, completes
```

**Async Workflow:**
```php
// Action signals pause
class GenerateThumbnailsAction implements Action {
    public function execute(ActionContext $context): ActionResult {
        $job = dispatch(new ThumbnailJob());
        return ActionResult::pause(metadata: ['jobId' => $job->id]);
    }
}

// Serialize and wait
$context = $machine->transitionTo(['status' => 'published']);
if ($context->isPaused()) {
    saveToDatabase($context->serialize());
}

// Resume later after external event
$context = unserialize(loadFromDatabase());
$machine->resume($context);
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

**The Problem:**
Two concurrent requests try to transition the same entity:
```
Request A: Draft → Published (starts)
Request B: Draft → Published (starts simultaneously)
```

**The Solution:**
```php
$machine->transitionTo(
    ['status' => 'published'],
    new LockConfiguration(strategy: LockStrategy::WAIT)
);
```

- Request A acquires lock
- Request B waits or fails based on strategy
- Lock persists through pauses
- Lock released on completion/stop/manual release

## Execution Flow

```
┌─────────────────────────────────────────────────────┐
│ User: transitionTo(['status' => 'published'])      │
└─────────────────────┬───────────────────────────────┘
                      │
                      ▼
         ┌────────────────────────┐
         │  Acquire Lock          │ (if configured)
         │  Strategy: WAIT/FAIL/  │
         │  SKIP/NONE             │
         └────────┬───────────────┘
                  │
                  ▼
         ┌────────────────────────┐
         │  Load Configuration    │ (lazy, based on delta)
         │  - Transition Gates    │
         │  - Actions             │
         └────────┬───────────────┘
                  │
                  ▼
         ┌────────────────────────┐
         │  Evaluate Transition   │ ◄── Events: GateEvaluating/Evaluated
         │  Gates                 │
         └────────┬───────────────┘
                  │
                  ├─── DENY ──► STOP (release lock)
                  │
                  ▼ ALLOW
         ┌────────────────────────┐
         │  For each Action:      │
         └────────┬───────────────┘
                  │
         ┌────────▼───────────────┐
         │  Action has Gate?      │
         └────────┬───────────────┘
                  │
                  ├─── YES ──► Evaluate ──► DENY ──► Skip Action
                  │                    │
                  │                    └─► ALLOW
                  ▼                         │
         ┌────────────────────────┐        │
         │  Execute Action        │ ◄──────┘
         │                        │ ◄── Events: ActionExecuting/Executed
         └────────┬───────────────┘
                  │
                  ├─── Returns PAUSE ──► Store Context (keep lock)
                  │                       Return to user
                  │
                  ├─── Returns STOP ──► Release lock, return
                  │
                  ▼ CONTINUE
         ┌────────────────────────┐
         │  More actions?         │
         └────────┬───────────────┘
                  │
                  ├─── YES ──► Next Action (loop)
                  │
                  ▼ NO
         ┌────────────────────────┐
         │  Mark Completed        │
         │  Release Lock          │
         │  Dispatch Event        │
         │  Return Context        │
         └────────────────────────┘
```

## Key Architectural Decisions

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
    if (isset($desiredDelta['status'])) {
        return match ($desiredDelta['status']) {
            'published' => new Configuration(
                transitionGates: [new CanPublishGate()],
                actions: [new SetPublishDateAction(), new NotifyAction()],
            ),
            'archived' => new Configuration(
                transitionGates: [new CanArchiveGate()],
                actions: [new ArchiveAction()],
            ),
        };
    }

    return new Configuration();
};
```

### Context Owns the State

**Why:** Enables serialization and execution tracing.

The `StateMachine` orchestrates, but `TransitionContext` owns:
- Current state
- Desired delta
- Execution history (gates evaluated, actions executed)
- Lock state
- Completion status

### Enums Over Booleans

**Why:** Extensibility and clarity.

```php
// Not: bool evaluate()
// But: GateResult evaluate()
enum GateResult {
    case ALLOW;
    case DENY;
    // Future: DEFER, CONDITIONAL, etc.
}

enum ExecutionState {
    case CONTINUE;
    case PAUSE;
    case STOP;
}
```

### Lock Persists on Pause

**Why:** Prevents race conditions during long-running workflows.

```php
// Lock acquired
$context = $machine->transitionTo(['status' => 'published']);

if ($context->isPaused()) {
    // Lock STILL HELD
    saveToDatabase($context->serialize()); // Includes lock state
}

// Hours later...
$context = unserialize(loadFromDatabase());
$machine->resume($context); // Verifies lock still held
```

## Extension Points

Users provide implementations for:

1. **State** - State representation and merge strategy
2. **ConfigurationProvider** - Which gates/actions for which transitions
3. **Gate** - Validation logic
4. **Action** - State mutation logic
5. **EventDispatcher** - Event handling and routing
6. **LockProvider** - Lock storage (Redis, DB, etc.)
7. **LockKeyProvider** - What to lock (entity, transition type, etc.)

## Design Trade-offs

### Chose: Delta + Context Object
**Instead of:** Full state transitions
**Trade-off:** More complex gate evaluation, but much better UX

### Chose: State interface
**Instead of:** Arrays everywhere
**Trade-off:** More boilerplate, but user control over merge strategy

### Chose: Lock persistence on pause
**Instead of:** Release and re-acquire
**Trade-off:** Risk of long-held locks, but guaranteed consistency

### Chose: Serializable context
**Instead of:** In-memory only
**Trade-off:** Serialization complexity, but enables async workflows

## Future Considerations

See [Open Questions](./open-questions.md) for unresolved design decisions, including:
- Serialization/deserialization of State and Action objects
- State merge location and timing
- Lock renewal for very long-running workflows
