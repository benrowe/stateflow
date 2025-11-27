# Complete Interface Reference

This document contains all interface definitions for the StateFlow package.

## State Management

### State

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
     * User implements their merge strategy here
     */
    public function with(array $changes): State;
}```

### StateFactory

```php
interface StateFactory
{
    /**
     * Create a State object from its array representation.
     */
    public function fromArray(array $data): State;
}
```

### ActionFactory

```php
interface ActionFactory
{
    /**
     * Create an Action object from its class name.
     */
    public function fromClassName(string $className): Action;
}
```

---

## Validation (Gates)

### Gate

```php
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
```

### GateResult

```php
enum GateResult
{
    case ALLOW;
    case DENY;
    case SKIP_IDEMPOTENT; // Added for idempotency checks
    // Future: DEFER, CONDITIONAL, etc.

    public function shouldStopTransition(): bool
    {
        return $this === self::DENY;
    }

    public function shouldSkipAction(): bool
    {
        return $this === self::DENY || $this === self::SKIP_IDEMPOTENT;
    }
}
```

### GateContext

```php
class GateContext
{
    public function __construct(
        public readonly State $currentState,
        public readonly array $desiredDelta,
    ) {}
}
```

### Guardable

```php
interface Guardable
{
    /**
     * Get the gate that should be evaluated before this action
     */
    public function gate(): Gate;
}
```

---

## Actions

### Action

```php
interface Action
{
    /**
     * Execute the action
     * Return new state or signal pause/stop
     */
    public function execute(ActionContext $context): ActionResult;
}
```

### ActionResult

```php
class ActionResult
{
    public function __construct(
        public readonly ExecutionState $executionState,
        public readonly ?State $newState = null,
        public readonly mixed $metadata = null,
    ) {}

    public static function continue(?State $newState = null): self;
    public static function pause(?State $newState = null, mixed $metadata = null): self;
    public static function stop(?State $newState = null, mixed $metadata = null): self;
}
```

### ExecutionState

```php
enum ExecutionState
{
    case CONTINUE;  // Continue to next action
    case PAUSE;     // Pause execution (lock persists)
    case STOP;      // Stop execution (lock released)
}
```

### ActionContext

```php
class ActionContext
{
    public function __construct(
        public readonly State $currentState,
        public readonly array $desiredDelta,
        public readonly TransitionContext $executionContext,
    ) {}
}
```

---

## Configuration

### ConfigurationProvider

```php
interface ConfigurationProvider
{
    /**
     * Provide configuration for a state transition
     * Lazy-loaded based on current state and desired changes
     */
    public function provide(State $currentState, array $desiredDelta): Configuration;
}
```

### Configuration

```php
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

    public function getTransitionGates(): array;
    public function getActions(): array;
}
```

---

## Events & Observability

### EventDispatcher

```php
interface EventDispatcher
{
    public function dispatch(Event $event): void;
}
```

### Event (Base Class)

```php
abstract class Event
{
    public function __construct(
        public readonly float $timestamp = 0.0,
    ) {
        if ($this->timestamp === 0.0) {
            $this->timestamp = microtime(true);
        }
    }
}
```

### Transition Events

```php
class TransitionStarting extends Event
{
    public function __construct(
        public readonly State $currentState,
        public readonly array $desiredDelta,
    ) {
        parent::__construct();
    }
}

class TransitionCompleted extends Event
{
    public function __construct(
        public readonly State $finalState,
        public readonly TransitionContext $context,
    ) {
        parent::__construct();
    }
}

class TransitionPaused extends Event
{
    public function __construct(
        public readonly State $currentState,
        public readonly TransitionContext $context,
        public readonly mixed $metadata,
    ) {
        parent::__construct();
    }
}

class TransitionStopped extends Event
{
    public function __construct(
        public readonly State $currentState,
        public readonly TransitionContext $context,
        public readonly mixed $metadata,
    ) {
        parent::__construct();
    }
}

class TransitionFailed extends Event
{
    public function __construct(
        public readonly State $currentState,
        public readonly \Throwable $exception,
        public readonly TransitionContext $context,
    ) {
        parent::__construct();
    }
}
```

### Gate Events

```php
class GateEvaluating extends Event
{
    public function __construct(
        public readonly Gate $gate,
        public readonly GateContext $context,
        public readonly bool $isActionGate,
    ) {
        parent::__construct();
    }
}

class GateEvaluated extends Event
{
    public function __construct(
        public readonly Gate $gate,
        public readonly GateContext $context,
        public readonly GateResult $result,
        public readonly bool $isActionGate,
    ) {
        parent::__construct();
    }
}
```

### Action Events

```php
class ActionExecuting extends Event
{
    public function __construct(
        public readonly Action $action,
        public readonly ActionContext $context,
    ) {
        parent::__construct();
    }
}

class ActionExecuted extends Event
{
    public function __construct(
        public readonly Action $action,
        public readonly ActionContext $context,
        public readonly ActionResult $result,
    ) {
        parent::__construct();
    }
}

class ActionSkipped extends Event
{
    public function __construct(
        public readonly Action $action,
        public readonly GateResult $gateResult,
    ) {
        parent::__construct();
    }
}
```

### Lock Events

```php
class LockAcquiring extends Event
{
    public function __construct(
        public readonly string $lockKey,
        public readonly State $state,
    ) {
        parent::__construct();
    }
}

class LockAcquired extends Event
{
    public function __construct(
        public readonly string $lockKey,
        public readonly State $state,
    ) {
        parent::__construct();
    }
}

class LockReleased extends Event
{
    public function __construct(
        public readonly string $lockKey,
        public readonly State $state,
    ) {
        parent::__construct();
    }
}

class LockFailed extends Event
{
    public function __construct(
        public readonly string $lockKey,
        public readonly State $state,
        public readonly string $reason,
    ) {
        parent::__construct();
    }
}

class LockRestored extends Event
{
    public function __construct(
        public readonly string $lockKey,
        public readonly State $state,
    ) {
        parent::__construct();
    }
}

class LockLost extends Event
{
    public function __construct(
        public readonly string $lockKey,
        public readonly State $state,
    ) {
        parent::__construct();
    }
}
```

---

## Locking

### LockProvider

```php
interface LockProvider
{
    /**
     * Acquire a lock for the given key
     *
     * @param string $key Unique lock identifier
     * @param int $ttl Time-to-live in seconds (for deadlock prevention)
     * @return bool True if lock acquired, false otherwise
     */
    public function acquire(string $key, int $ttl = 30): bool;

    /**
     * Release a lock
     *
     * @param string $key Unique lock identifier
     * @return bool True if lock released, false if lock didn't exist
     */
    public function release(string $key): bool;

    /**
     * Check if a lock exists
     */
    public function exists(string $key): bool;
}
```

### LockKeyProvider

```php
interface LockKeyProvider
{
    /**
     * Generate a unique lock key for a state transition
     *
     * This determines what gets locked during a transition.
     * Examples:
     * - Lock entire entity: "order:123"
     * - Lock specific transition: "order:123:draft->published"
     * - Lock state snapshot: hash($state->toArray())
     */
    public function getLockKey(State $state, array $desiredDelta): string;
}
```

### LockStrategy

```php
enum LockStrategy
{
    /**
     * Don't acquire lock - allow concurrent transitions
     */
    case NONE;

    /**
     * Fail immediately if lock can't be acquired
     */
    case FAIL_FAST;

    /**
     * Wait and retry until lock acquired or timeout
     */
    case WAIT;

    /**
     * Skip transition if locked, return special context
     */
    case SKIP;
}
```

### LockConfiguration

```php
class LockConfiguration
{
    public function __construct(
        public readonly LockStrategy $strategy = LockStrategy::FAIL_FAST,
        public readonly int $ttl = 30,
        public readonly int $waitTimeout = 10,
        public readonly int $retryInterval = 100, // milliseconds
    ) {}
}
```

### LockState

```php
class LockState
{
    public function __construct(
        public readonly ?string $lockKey = null,
        public readonly ?float $acquiredAt = null,
        public readonly ?int $ttl = null,
    ) {}

    public function isLocked(): bool;
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

---

## Core Machine

### StateMachine

```php
class StateMachine
{
    public function __construct(
        State $initialState,
        callable|ConfigurationProvider $configProvider,
        ?EventDispatcher $eventDispatcher = null,
        ?LockProvider $lockProvider = null,
        ?LockKeyProvider $lockKeyProvider = null,
    ) {}

    /**
     * Execute a state transition
     */
    public function transitionTo(
        array $desiredDelta,
        ?LockConfiguration $lockConfig = null
    ): TransitionContext;

    /**
     * Execute the next action in the queue
     * Lock must still be held from previous pause
     */
    public function nextAction(): TransitionContext;

    /**
     * Resume from serialized context
     * Handles lock state recovery
     */
    public function resume(
        TransitionContext $context,
        bool $requireLock = true
    ): TransitionContext;

    /**
     * Manually release the current lock
     * Use this for error recovery or cleanup
     */
    public function releaseLock(): bool;

    /**
     * Check if machine currently holds a lock
     */
    public function isLocked(): bool;

    /**
     * Get current lock state
     */
    public function getLockState(): LockState;

    /**
     * Get current context
     */
    public function getContext(): TransitionContext;
}
```

### TransitionContext

```php
class TransitionContext implements \Serializable
{
    public function __construct(State $initialState) {}

    // State access
    public function getCurrentState(): State;
    public function getDesiredDelta(): array;
    public function updateState(State $newState): void;

    // Status checks
    public function isCompleted(): bool;
    public function isPaused(): bool;
    public function isStopped(): bool;
    public function wasSkippedDueToLock(): bool;

    // Execution history
    public function getGateEvaluations(): array;
    public function getActionExecutions(): array;
    public function getActionSkips(): array;

    // Lock state
    public function getLockState(): LockState;
    public function setLockState(LockState $state): void;

    // Status metadata
    public function getStatusMetadata(): mixed;

    // Serialization
    public function serialize(): string;
    public static function unserialize(string $data, StateFactory $stateFactory, ActionFactory $actionFactory): self;

    // Internal methods (used by StateMachine)
    public function beginTransition(array $desiredDelta, Configuration $config): void;
    public function getNextAction(): ?Action;
    public function recordGateEvaluation(Gate $gate, GateContext $context, GateResult $result, bool $isActionGate): void;
    public function recordActionExecution(Action $action, ActionContext $context, ActionResult $result): void;
    public function recordActionSkipped(Action $action, GateResult $result): void;
    public function markCompleted(): void;
    public function markPaused(mixed $metadata = null): void;
    public function markStopped(mixed $metadata = null): void;
    public function markSkippedDueToLock(): self;
}
```

---

## Exceptions

```php
class LockAcquisitionException extends \RuntimeException {}

class LockExpiredException extends \RuntimeException {}

class LockLostException extends \RuntimeException {}

class TransitionException extends \RuntimeException {}
```

---

## Helper Classes

### CallableConfigurationProvider

```php
class CallableConfigurationProvider implements ConfigurationProvider
{
    public function __construct(
        private $callable
    ) {}

    public function provide(State $currentState, array $desiredDelta): Configuration
    {
        return ($this->callable)($currentState, $desiredDelta);
    }
}
```
