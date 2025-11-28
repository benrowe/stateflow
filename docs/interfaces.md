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

    /**
     * Extend the TTL of an existing lock.
     *
     * @param string $key Unique lock identifier
     * @param int $ttl New time-to-live in seconds
     * @return bool True if the lock was renewed, false otherwise (e.g., lock didn't exist or wasn't owned).
     */
    public function renew(string $key, int $ttl): bool;
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



## Core Machine

### StateFlow

```php
class StateFlow
{
    public function __construct(
        callable|ConfigurationProvider $configProvider,
        ?EventDispatcher $eventDispatcher = null,
        ?LockProvider $lockProvider = null,
        ?LockKeyProvider $lockKeyProvider = null,
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

### StateWorker

```php
class StateWorker
{
    /**
     * Run all transition gates.
     * Returns the final GateResult.
     */
    public function runGates(): GateResult;

    /**
     * Run all actions sequentially.
     * Assumes gates have already been run and passed.
     */
    public function runActions(): TransitionContext;

    /**
     * Run the next action in the queue.
     * Useful for step-by-step execution of a paused workflow.
     */
    public function runNextAction(): TransitionContext;

    /**
     * A helper method to run the entire transition (gates and then actions).
     * This is the most common way to use the worker.
     */
    public function execute(): TransitionContext;

    /**
     * Manually release the current lock.
     */
    public function releaseLock(): bool;

    /**
     * Get the current TransitionContext.
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

    // Status metadata
    public function getStatusMetadata(): mixed;

    // Serialization
    public function serialize(): string;
    public static function unserialize(string $data, StateFactory $stateFactory, ActionFactory $actionFactory): self;
}
```



## Exceptions

```php
class LockAcquisitionException extends \RuntimeException {}

class LockExpiredException extends \RuntimeException {}

class LockLostException extends \RuntimeException {}

class TransitionException extends \RuntimeException {}
```



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
