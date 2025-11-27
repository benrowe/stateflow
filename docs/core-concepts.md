# Core Concepts

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
    public function with(array $changes): State;
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

    public function with(array $changes): State
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

    public function with(array $changes): State {
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

    public function with(array $changes): State {
        // Smart merging based on what changed
        if (isset($changes['status']) && $changes['status'] === 'published') {
            return self::published($this->id, new DateTimeImmutable());
        }
        // ... etc
    }
}
```

---

## Gates

### Interface Definition

```php
enum GateResult
{
    case ALLOW;
    case DENY;
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

class GateContext
{
    public function __construct(
        public readonly State $currentState,
        public readonly array $desiredDelta,
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
        $final = $context->currentState->with($context->desiredDelta);
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

        // Assuming 'status' is a key in your state
        if (isset($desired['status']) && $current['status'] === $desired['status']) {
            return GateResult::SKIP_IDEMPOTENT;
        }

        return GateResult::ALLOW;
    }

    public function message(): ?string
    {
        return 'Transition not allowed: already in the target state.';
    }
}
```

---

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
        public readonly array $desiredDelta,
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
        foreach ($executionContext->getGateEvaluations() as $eval) {
            Log::info("Gate: {$eval['gate']} => {$eval['result']}");
        }

        // Access what actions already ran
        foreach ($executionContext->getActionExecutions() as $exec) {
            Log::info("Action: {$exec['action']}");
        }

        return ActionResult::continue();
    }
}
```

---

## Configuration

### Interface Definition

```php
interface ConfigurationProvider
{
    /**
     * Provide configuration for a state transition
     * Lazy-loaded based on current state and desired changes
     */
    public function provide(State $currentState, array $desiredDelta): Configuration;
}

class Configuration
{
    /**
     * @param Gate[] $transitionGates Gates that must pass for transition to proceed
     * @param Action[] $actions Actions to execute in order
     */
    public function __construct(
        private array $transitionGates = [],
        private array $actions = [],
    ) {}

    public function getTransitionGates(): array { return $this->transitionGates; }
    public function getActions(): array { return $this->actions; }
}
```

### Why Lazy Configuration?

**Problem:** Different transitions need different gates and actions.

**Solution:** Load configuration based on what's changing.

```php
$configProvider = function(State $currentState, array $desiredDelta): Configuration {
    // Dynamic configuration based on transition
    if (isset($desiredDelta['status'])) {
        return match ($desiredDelta['status']) {
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
    if (isset($desiredDelta['metadata'])) {
        return new Configuration(
            actions: [new UpdateMetadataAction()],
        );
    }

    return new Configuration();
};

$machine = new StateMachine(configProvider: $configProvider);

// Now the machine can be used for any state object
$worker = $machine->transition($someOrderState, ['status' => 'published']);
$context = $worker->execute();
```

---

## StateMachine

The `StateMachine` is a **stateless, reusable service**. Its main responsibility is to take a state object and a desired change, and create a `StateWorker` to handle the transition.

### Key Methods

```php
class StateMachine
{
    public function __construct(
        callable|ConfigurationProvider $configProvider,
        ?EventDispatcher $eventDispatcher = null,
        ?LockProvider $lockProvider = null,
        ?LockKeyProvider $lockKeyProvider = null
    ) {}

    /**
     * Prepare a state transition.
     * Returns a StateWorker to execute the transition.
     */
    public function transition(
        State $currentState,
        array $desiredDelta
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
$lockProvider = new RedisLockProvider($redis);
$machine = new StateMachine(
    configProvider: $configProvider,
    lockProvider: $lockProvider,
);
$initialState = new OrderState(/* ... */);

$worker = $machine->transition($initialState, ['status' => 'published']);
$context = $worker->execute();

if ($context->isCompleted()) {
    $finalState = $context->getCurrentState();
    // Persist the final state
}
```

**Step-by-Step Execution:**
```php
$worker = $machine->transition($initialState, ['status' => 'published']);

$gateResult = $worker->runGates();

if (!$gateResult->shouldStopTransition()) {
    $context = $worker->runActions();
    // ...
}
```

---

## TransitionContext

The `TransitionContext` is an object that **tracks everything about a single transition**. It is created and managed by the `StateWorker`. While you will interact with it to get the final result of a transition, you will rarely need to create or manage it yourself.

### Responsibilities

1.  **State Management** - Owns the current state of the transition.
2.  **Execution History** - Records all gates evaluated and actions executed.
3.  **Status Tracking** - `Completed`, `Paused`, `Stopped`, `Failed`.
4.  **Serialization** - Can be serialized to be resumed later.

### Key Methods

```php
class TransitionContext
{
    // State access
    public function getCurrentState(): State;
    public function getDesiredDelta(): array;

    // Status checks
    public function isCompleted(): bool;
    public function isPaused(): bool;
    public function isStopped(): bool;

    // Execution history
    public function getGateEvaluations(): array;
    public function getActionExecutions(): array;

    // Serialization
    public function serialize(): string;
    public static function unserialize(string $data, StateFactory $stateFactory, ActionFactory $actionFactory): self;
}
```

### Usage in Actions

Actions receive the `TransitionContext` via the `ActionContext`. This allows actions to inspect the history of the current transition.
```php
class SmartAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        $executionContext = $context->executionContext;
        
        // Check if a specific gate passed
        $canNotify = collect($executionContext->getGateEvaluations())
            ->first(fn($e) => $e['gate'] === NotificationGate::class)
            ?->result === 'ALLOW';

        if ($canNotify) {
            // ...
        }

        return ActionResult::continue();
    }
}
```

