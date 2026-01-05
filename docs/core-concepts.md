# Core Concepts

## Delta

### Interface Definition

```php
interface Delta
{
    /**
     * Get a value from the delta by key
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Check if a key exists in the delta
     */
    public function has(string $key): bool;

    /**
     * Get all delta values as an array
     */
    public function asArray(): array;
}
```

### Design Rationale

**Why a Delta interface?**

- Provides a clean API for accessing delta values without array access syntax
- Allows for different delta implementations (array-based, immutable, validated, etc.)
- Type-safe access to delta values
- Clear intent: this is a set of changes, not a full state

### ArrayDelta Implementation

StateFlow provides `ArrayDelta` as the default implementation:

```php
use BenRowe\StateFlow\Delta\ArrayDelta;

// Create a delta
$delta = new ArrayDelta(['status' => 'published', 'publishedAt' => '2025-01-01']);

// Access values
$status = $delta->get('status'); // 'published'
$author = $delta->get('author', 'unknown'); // 'unknown' (default)

// Check existence
if ($delta->has('status')) {
    // ...
}

// Get as array (for State::with())
$changes = $delta->asArray(); // ['status' => 'published', 'publishedAt' => '2025-01-01']
```

### Usage in Transitions

```php
use BenRowe\StateFlow\Delta\ArrayDelta;

$state = new OrderState('ORD-123', 'pending', 99.99);
$worker = $stateFlow->transition($state, new ArrayDelta(['status' => 'processing']));
$context = $worker->execute();
```

### Usage in Configuration Providers

```php
$configProvider = function(State $state, Delta $delta): Configuration {
    // Access delta values using ->get()
    return match ($delta->get('status')) {
        'published' => new Configuration(
            transitionGates: [new CanPublishGate()],
            actions: [new PublishAction()],
        ),
        'archived' => new Configuration(
            transitionGates: [new CanArchiveGate()],
            actions: [new ArchiveAction()],
        ),
        default => new Configuration(),
    };
};
```

### Usage in Gates

```php
class HasRequiredFieldsGate implements Gate
{
    public function evaluate(GateContext $context): GateResult
    {
        // Access delta via context
        $delta = $context->desiredDelta;

        // Use ->get() to access values
        if ($delta->has('status') && $delta->get('status') === 'published') {
            $final = $context->currentState->with($delta->asArray());
            $data = $final->toArray();

            if (empty($data['content'])) {
                return GateResult::DENY;
            }
        }

        return GateResult::ALLOW;
    }
}
```

### Custom Delta Implementations

You can create custom Delta implementations for specific use cases:

```php
// Validated delta that ensures only allowed fields
class ValidatedDelta implements Delta
{
    public function __construct(
        private array $data,
        private array $allowedFields
    ) {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowedFields)) {
                throw new \InvalidArgumentException("Field '$key' is not allowed");
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function asArray(): array
    {
        return $this->data;
    }
}

// Usage
$delta = new ValidatedDelta(
    ['status' => 'published'],
    allowedFields: ['status', 'publishedAt']
);
```

## State

### Interface Definition

```php
interface State
{
    /**
     * Get the state data as an array
     * Used for serialization and context passing
     */
    public function toArray(): array;

    /**
     * Create a new state instance with changes applied
     * USER IMPLEMENTS THEIR MERGE STRATEGY HERE
     */
    public function with(array $changes): static;
}
```

### Design Rationale

**Why an interface?**

- Users control state representation (class properties, arrays, immutable objects, etc.)
- Users implement their own merge strategy in `with()`
- Type safety at boundaries
- Flexibility for simple or complex state objects

**Why `with()` instead of machine-managed merging?**

- State merging can be complex (deep merge, shallow merge, null handling, etc.)
- Users know their domain and requirements
- The machine stays agnostic to state structure, and delegates the responsibility of merging to the `Action`s.

### Implementation Example

```php
class OrderState implements State
{
    public function __construct(
        private string $id,
        private string $status,
        private ?DateTimeImmutable $publishedAt = null,
        private array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'publishedAt' => $this->publishedAt?->format('c'),
            'metadata' => $this->metadata,
        ];
    }

    public function with(array $changes): static
    {
        // User's merge strategy - shallow merge example
        return new self(
            id: $changes['id'] ?? $this->id,
            status: $changes['status'] ?? $this->status,
            publishedAt: isset($changes['publishedAt'])
                ? new DateTimeImmutable($changes['publishedAt'])
                : $this->publishedAt,
            metadata: isset($changes['metadata'])
                ? array_merge($this->metadata, $changes['metadata'])
                : $this->metadata,
        );
    }

    // Domain methods
    public function getStatus(): string { return $this->status; }
    public function isPublished(): bool { return $this->status === 'published'; }
}
```

### Alternative Implementations

**Array-based (simple):**

```php
class ArrayState implements State
{
    public function __construct(private array $data) {}

    public function toArray(): array { return $this->data; }

    public function with(array $changes): static {
        return new self(array_merge($this->data, $changes));
    }
}
```

**Immutable with named constructors:**

```php
class ImmutableOrderState implements State
{
    private function __construct(/* ... */) {}

    public static function draft(string $id): self { /* ... */ }
    public static function published(string $id, DateTimeImmutable $at): self { /* ... */ }

    public function with(array $changes): static {
        // Smart merging based on what changed
        if (isset($changes['status']) && $changes['status'] === 'published') {
            return self::published($this->id, new DateTimeImmutable());
        }
        // ... etc
    }
}
```

## Gates

### Interface Definition

```php
enum GateResult
{
    case ALLOW;
    case DENY;
    case SKIP_IDEMPOTENT;
    // Future: DEFER, CONDITIONAL, etc.
}

interface Gate
{
    /**
     * Evaluate if the gate allows the transition/action
     */
    public function evaluate(GateContext $context): GateResult;

    /**
     * Optional: Provide context about why gate failed
     */
    public function message(): ?string;
}

/**
 * GateResult Behavior:
 *
 * - ALLOW: Gate passes, transition continues normally
 * - DENY: Gate fails, transition stops immediately (context.isStopped() = true)
 * - SKIP_IDEMPOTENT: Gate indicates operation is unnecessary (already in desired state),
 *   transition continues but actions are skipped (context.isCompleted() = true)
 *
 * SKIP_IDEMPOTENT is useful for idempotency checks where you want to succeed
 * gracefully without re-executing actions when already in the target state.
 */

class GateContext
{
    public function __construct(
        public readonly State $currentState,
        public readonly Delta $desiredDelta,
    ) {}
}
```

### Two Types of Gates

#### 1. Transition Gates

- Evaluated **before** any actions execute
- Failure **stops** the entire transition
- Configured in `Configuration::transitionGates`
- Use case: "Is this transition allowed at all?"

**Example:**

```php
class CanPublishGate implements Gate
{
    public function evaluate(GateContext $context): GateResult
    {
        $state = $context->currentState->toArray();

        // Must be in draft status to publish
        if ($state['status'] !== 'draft') {
            return GateResult::DENY;
        }

        // Must have content
        if (empty($state['content'])) {
            return GateResult::DENY;
        }

        return GateResult::ALLOW;
    }

    public function message(): ?string
    {
        return 'Cannot publish: must be draft with content';
    }
}
```

#### 2. Action Gates

- Evaluated **before** a specific action executes
- Failure **skips** that action, continues to next
- Actions implement `Guardable` interface
- Use case: "Should this specific action run?"

**Example:**

```php
interface Guardable
{
    public function gate(): Gate;
}

class NotifySubscribersAction implements Action, Guardable
{
    public function gate(): Gate
    {
        return new HasSubscribersGate();
    }

    public function execute(ActionContext $context): ActionResult
    {
        // Only runs if HasSubscribersGate passes
        // ...
    }
}

class HasSubscribersGate implements Gate
{
    public function evaluate(GateContext $context): GateResult
    {
        $state = $context->currentState->toArray();

        return isset($state['subscriberCount']) && $state['subscriberCount'] > 0
            ? GateResult::ALLOW
            : GateResult::DENY;
    }

    public function message(): ?string
    {
        return 'No subscribers to notify';
    }
}
```

### Gate Patterns

**Permission check:**

```php
class UserCanPublishGate implements Gate
{
    public function __construct(private User $user) {}

    public function evaluate(GateContext $context): GateResult
    {
        return $this->user->can('publish')
            ? GateResult::ALLOW
            : GateResult::DENY;
    }
}
```

**State validation:**

```php
class HasRequiredFieldsGate implements Gate
{
    public function evaluate(GateContext $context): GateResult
    {
        $final = $context->currentState->with($context->desiredDelta->asArray());
        $data = $final->toArray();

        $required = ['title', 'content', 'author'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return GateResult::DENY;
            }
        }

        return GateResult::ALLOW;
    }
}
```

**Idempotency check:**

```php
class NotAlreadyPublishedGate implements Gate
{
    public function evaluate(GateContext $context): GateResult
    {
        $current = $context->currentState->toArray();
        $desired = $context->desiredDelta;

        // If already in the desired state, skip actions but succeed
        // Use SKIP_IDEMPOTENT instead of DENY so the transition completes successfully
        // without re-running actions that would be redundant
        if ($desired->has('status') && $current['status'] === $desired->get('status')) {
            return GateResult::SKIP_IDEMPOTENT;
        }

        return GateResult::ALLOW;
    }

    public function message(): ?string
    {
        return 'Already in target state - transition skipped';
    }
}
```

## Actions

### Interface Definition

```php
enum ExecutionState
{
    case CONTINUE;  // Continue to next action
    case PAUSE;     // Pause execution (lock persists)
    case STOP;      // Stop execution (lock released)
}

class ActionResult
{
    public function __construct(
        public readonly ExecutionState $executionState,
        public readonly ?State $newState = null,
        public readonly mixed $metadata = null,
    ) {}

    public static function continue(?State $newState = null): self
    {
        return new self(ExecutionState::CONTINUE, $newState);
    }

    public static function pause(?State $newState = null, mixed $metadata = null): self
    {
        return new self(ExecutionState::PAUSE, $newState, $metadata);
    }

    public static function stop(?State $newState = null, mixed $metadata = null): self
    {
        return new self(ExecutionState::STOP, $newState, $metadata);
    }
}

interface Action
{
    /**
     * Execute the action
     * Return new state or signal pause/stop
     */
    public function execute(ActionContext $context): ActionResult;
}

class ActionContext
{
    public function __construct(
        public readonly State $currentState,
        public readonly Delta $desiredDelta,
        public readonly TransitionContext $executionContext,
    ) {}
}
```

### Action Patterns

**Simple state mutation:**

```php
class SetPublishDateAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        $newState = $context->currentState->with([
            'publishedAt' => new DateTimeImmutable(),
            'status' => 'published',
        ]);

        return ActionResult::continue($newState);
    }
}
```

**Side effect (no state change):**

```php
class SendEmailAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        $state = $context->currentState->toArray();

        Mail::send('published', $state);

        // No state change
        return ActionResult::continue();
    }
}
```

**Async operation with pause:**

```php
class GenerateThumbnailsAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        $state = $context->currentState->toArray();

        // Dispatch async job
        $job = dispatch(new GenerateThumbnailsJob($state['id']));

        // Pause until job completes
        return ActionResult::pause(
            metadata: ['jobId' => $job->id, 'reason' => 'Waiting for thumbnails']
        );
    }
}

// Later, when job completes, resume the workflow
```

**Conditional stop:**

```php
class ValidateContentAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        $state = $context->currentState->toArray();

        $errors = $this->validator->validate($state['content']);

        if (!empty($errors)) {
            // Stop the transition
            return ActionResult::stop(
                metadata: ['errors' => $errors, 'reason' => 'Validation failed']
            );
        }

        return ActionResult::continue();
    }
}
```

**Action with gate:**

```php
class NotifySubscribersAction implements Action, Guardable
{
    public function gate(): Gate
    {
        return new HasSubscribersGate();
    }

    public function execute(ActionContext $context): ActionResult
    {
        // Only executes if HasSubscribersGate::ALLOW
        $state = $context->currentState->toArray();

        foreach ($state['subscribers'] as $email) {
            Mail::to($email)->send(new PublishedNotification());
        }

        return ActionResult::continue();
    }
}
```

**Accessing execution context:**

```php
class AuditAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        $executionContext = $context->executionContext;

        // Access what gates were evaluated
        foreach ($executionContext->executionHistory()->getGateEvaluations() as $eval) {
            Log::info("Gate: " . get_class($eval->gate) . " => " . $eval->result->name);
        }

        // Access what actions already ran
        foreach ($executionContext->executionHistory()->getActionExecutions() as $result) {
            Log::info("Action executed with state: " . $result->executionState->name);
        }

        return ActionResult::continue();
    }
}
```

## Collections

### Typed Collections

StateFlow uses typed, immutable collections for runtime type safety. All collections are built on [Doctrine Collections](https://github.com/doctrine/collections) and extend `ArrayCollection`, providing the full Collection API while enforcing type constraints.

**Available Collections:**

- `GateCollection` - Collection of `Gate` objects
- `ActionCollection` - Collection of `Action` objects
- `ActionResultCollection` - Collection of `ActionResult` objects
- `GateEvaluationCollection` - Collection of `GateEvaluation` objects
- `ActionSkipCollection` - Collection of `ActionSkip` objects

### Collection Features

**Immutability:**
All collections are immutable. Use the `with()` method to add items, which returns a new collection instance:

```php
use BenRowe\StateFlow\Action\ActionCollection;

$actions = ActionCollection::empty();
$newActions = $actions->with(new MyAction());  // Returns new instance
```

**Type Safety:**
Collections enforce type constraints at runtime. Attempting to add the wrong type throws an `InvalidArgumentException`:

```php
$gates = GateCollection::empty();
$gates->set(0, new stdClass()); // InvalidArgumentException
```

**Doctrine Collection API:**
Since all collections extend `ArrayCollection`, you have access to the full Doctrine Collections API:

```php
use BenRowe\StateFlow\Gate\GateCollection;

$gates = GateCollection::fromArray([
    new PermissionGate(),
    new ValidationGate(),
]);

// Use Doctrine Collection methods
$filtered = $gates->filter(fn($gate) => $gate instanceof PermissionGate);
$mapped = $gates->map(fn($gate) => $gate->message());
$count = $gates->count();

// Iterate
foreach ($gates as $gate) {
    // ...
}
```

**Array Conversion:**
Use `toArray()` to convert collections back to plain PHP arrays:

```php
$gates = GateCollection::fromArray([new MyGate()]);
$array = $gates->toArray(); // Gate[]
```

## Configuration

### Interface Definition

```php
use BenRowe\StateFlow\Gate\GateCollection;
use BenRowe\StateFlow\Action\ActionCollection;

interface ConfigurationProvider
{
    /**
     * Provide configuration for a state transition
     * Lazy-loaded based on current state and desired changes
     */
    public function provide(State $currentState, Delta $desiredDelta): Configuration;
}

class Configuration
{
    /**
     * @param Gate[]|GateCollection $transitionGates Gates that must pass for transition to proceed
     * @param Action[]|ActionCollection $actions Actions to execute in order
     */
    public function __construct(
        GateCollection|array $transitionGates = [],
        ActionCollection|array $actions = [],
    ) {}

    public function getTransitionGates(): GateCollection;
    public function getActions(): ActionCollection;
}
```

**Note:** The Configuration constructor accepts both arrays and collection instances for backward compatibility. Arrays are automatically converted to typed collections internally. The getter methods return typed collections.

### Why Lazy Configuration?

**Problem:** Different transitions need different gates and actions.

**Solution:** Load configuration based on what's changing.

```php
$configProvider = function(State $currentState, Delta $desiredDelta): Configuration {
    // Dynamic configuration based on transition
    if ($desiredDelta->has('status')) {
        return match ($desiredDelta->get('status')) {
            'published' => new Configuration(
                transitionGates: [
                    new CanPublishGate(),
                    new HasContentGate(),
                ],
                actions: [
                    new SetPublishDateAction(),
                    new GenerateSEOMetaAction(),
                    new NotifySubscribersAction(),
                ],
            ),
            'archived' => new Configuration(
                transitionGates: [new CanArchiveGate()],
                actions: [new ArchiveAction()],
            ),
            'draft' => new Configuration(
                transitionGates: [new CanUnpublishGate()],
                actions: [new ClearPublishDateAction()],
            ),
            default => new Configuration(),
        };
    }

    // Metadata-only changes
    if ($desiredDelta->has('metadata')) {
        return new Configuration(
            actions: [new UpdateMetadataAction()],
        );
    }

    return new Configuration();
};

$stateFlow = new StateFlow(configProvider: $configProvider);

// Now the flow can be used for any state object
$worker = $stateFlow->transition($someOrderState, new ArrayDelta(['status' => 'published']));
$context = $worker->execute();
```

## StateFlow

The `StateFlow` is a **stateless, reusable service**. Its main responsibility is to take a state object and a desired change, and create a `StateWorker` to handle the transition.

### Key Methods

```php
class StateFlow
{
    public function __construct(
        callable|ConfigurationProvider $configProvider,
        ?EventDispatcher $eventDispatcher = null,
        ?LockContext $lockContext = null
    ) {}

    /**
     * Prepare a state transition.
     * Returns a StateWorker to execute the transition.
     */
    public function transition(
        State $currentState,
        Delta $desiredDelta
    ): StateWorker;

    /**
     * Create a StateWorker from a previously paused context.
     */
    public function fromContext(TransitionContext $context): StateWorker;
}
```

### Usage

**Simple Execution:**

```php
$lockContext = new LockContext(
    provider: new RedisLockProvider($redis),
    keyProvider: new EntityLockKeyProvider(),
    configuration: new LockConfiguration(
        strategy: LockStrategy::FAIL_FAST,
        ttl: 30
    )
);

$stateFlow = new StateFlow(
    configProvider: $configProvider,
    lockContext: $lockContext,
);
$initialState = new OrderState(/* ... */);

$worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));
$context = $worker->execute();

if ($context->isCompleted()) {
    $finalState = $context->getCurrentState();
    // Persist the final state
}
```

**Step-by-Step Execution:**

```php
$worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));

$gateResult = $worker->runGates();

if (!$gateResult->shouldStopTransition()) {
    $context = $worker->runActions();
    // ...
}
```

## TransitionContext

The `TransitionContext` is an object that **tracks everything about a single transition**. It is created and managed by the `StateWorker`. While you will interact with it to get the final result of a transition, you will rarely need to create or manage it yourself.

### Responsibilities

1. **State Management** - Owns the current state of the transition.
2. **Execution History** - Records all gates evaluated and actions executed.
3. **Status Tracking** - `Completed`, `Paused`, `Stopped`, `Failed`.
4. **Serialization** - Can be serialized to be resumed later.

### Key Methods

```php
use BenRowe\StateFlow\GateEvaluationCollection;
use BenRowe\StateFlow\Action\ActionResultCollection;
use BenRowe\StateFlow\ActionSkipCollection;

class TransitionContext
{
    // State access
    public function getCurrentState(): State;
    public function getDesiredDelta(): Delta;

    // Status checks (via executionStatus())
    public function executionStatus(): ExecutionStatus;

    // Execution history (via executionHistory())
    public function executionHistory(): ExecutionHistory;
}

class ExecutionStatus
{
    public function isCompleted(): bool;
    public function isPaused(): bool;
    public function isStopped(): bool;
}

class ExecutionHistory
{
    // Execution history (returns typed collections)
    public function getGateEvaluations(): GateEvaluationCollection;
    public function getActionExecutions(): ActionResultCollection;
    public function getActionSkips(): ActionSkipCollection;
}

class TransitionContextSerializer
{
    // Serialization
    public function serialize(TransitionContext $context): string;
    public function unserialize(
        string $data,
        StateFactory $stateFactory,
        ActionFactory $actionFactory,
        GateFactory $gateFactory
    ): TransitionContext;
}
```

**Note:** The execution history methods return typed collections. Use `->toArray()` if you need plain PHP arrays for iteration or inspection.

### Usage in Actions

Actions receive the `TransitionContext` via the `ActionContext`. This allows actions to inspect the history of the current transition.

```php
class SmartAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        $executionContext = $context->executionContext;

        // Get gate evaluations as collection
        $evaluations = $executionContext->executionHistory()->getGateEvaluations();

        // Use Doctrine Collection methods
        $notificationGate = $evaluations->filter(
            fn($eval) => $eval->gate instanceof NotificationGate
        )->first();

        if ($notificationGate && $notificationGate->result === GateResult::ALLOW) {
            // ...
        }

        // Or convert to array if needed
        foreach ($executionContext->executionHistory()->getGateEvaluations()->toArray() as $eval) {
            Log::info("Gate evaluated", [
                'gate' => get_class($eval->gate),
                'result' => $eval->result->name,
            ]);
        }

        return ActionResult::continue();
    }
}
```
