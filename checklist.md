# StateFlow Checklist

This checklist is generated from the documentation to track the creation of all the necessary files for the `stateflow` project.

## `src`

### Core Machine
- ✅ `src/StateFlow.php`
  - ✅ `__construct(...)`
  - ✅ `transition(State $currentState, array $desiredDelta): StateWorker`
  - ✅ `fromContext(TransitionContext $context): StateWorker`
- ✅ `src/StateWorker.php`
  - ✅ `runGates(): GateResult`
  - ✅ `runActions(): TransitionContext`
  - ✅ `runNextAction(): TransitionContext`
  - ✅ `execute(): TransitionContext`
  - ✅ `releaseLock(): void` (implemented as private method)
  - ✅ `getContext(): TransitionContext`
  - ✅ `setNextActionIndex(int $index): void`
- ✅ `src/TransitionContext.php`
  - ✅ `__construct(State $initialState, array $desiredDelta = [])`
  - ✅ `getCurrentState(): State`
  - ✅ `getDesiredDelta(): array`
  - ✅ `isCompleted(): bool`
  - ✅ `isPaused(): bool`
  - ✅ `isStopped(): bool`
  - ✅ `wasSkippedDueToLock(): bool`
  - ✅ `getGateEvaluations(): array`
  - ✅ `getActionExecutions(): array`
  - ✅ `getActionSkips(): array`
  - ✅ `clearPauseStatus(): void`
  - ✅ `getLockState(): LockState`
  - ❌ `getStatusMetadata(): mixed`
  - ❌ `serialize(): string`
  - ❌ `unserialize(string $data, StateFactory $stateFactory, ActionFactory $actionFactory): self`

## `src/Events`
- ✅ `src/Events/EventDispatcher.php` (interface)
- ✅ `src/Events/NullEventDispatcher.php`
- ✅ `src/Events/Event.php` (base class)

### Transition Events
- ✅ `src/Events/TransitionStarting.php` (class defined, ✅ dispatched)
- ✅ `src/Events/TransitionCompleted.php` (class defined, ✅ dispatched)
- ✅ `src/Events/TransitionPaused.php` (class defined, ✅ dispatched)
- ✅ `src/Events/TransitionStopped.php` (class defined, ✅ dispatched)
- ✅ `src/Events/TransitionFailed.php` (class defined, ✅ dispatched)

### Gate Events
- ✅ `src/Events/GateEvaluating.php` (class defined, ✅ dispatched)
- ✅ `src/Events/GateEvaluated.php` (class defined, ✅ dispatched)

### Action Events
- ✅ `src/Events/ActionExecuting.php` (class defined, ✅ dispatched)
- ✅ `src/Events/ActionExecuted.php` (class defined, ✅ dispatched)
- ✅ `src/Events/ActionSkipped.php` (class defined, ✅ dispatched)

### Lock Events
- ✅ `src/Events/LockAcquiring.php` (class defined, ✅ dispatched)
- ✅ `src/Events/LockAcquired.php` (class defined, ✅ dispatched)
- ✅ `src/Events/LockFailed.php` (class defined, ✅ dispatched)
- ✅ `src/Events/LockReleased.php` (class defined, ✅ dispatched)
- ✅ `src/Events/LockLost.php` (class defined, ✅ dispatched)
- ✅ `src/Events/LockRestored.php` (class defined, ✅ dispatched)

## `src/Locking`
- ✅ `src/Locking/LockProvider.php`
  - ✅ `acquire(string $key, int $ttl = 30): bool`
  - ✅ `release(string $key): bool`
  - ✅ `exists(string $key): bool`
  - ✅ `renew(string $key, int $ttl): bool`
- ✅ `src/Locking/LockKeyProvider.php`
  - ✅ `getLockKey(State $state, array $desiredDelta): string`
- ✅ `src/Locking/LockStrategy.php`
  - ✅ `NONE`
  - ✅ `FAIL_FAST`
  - ✅ `WAIT`
  - ✅ `SKIP`
- ✅ `src/Locking/LockConfiguration.php`
  - ✅ `__construct(LockStrategy $strategy = LockStrategy::FAIL_FAST, int $ttl = 30, int $waitTimeout = 10, int $retryInterval = 100)`
- ✅ `src/Locking/LockState.php`
  - ✅ `__construct(?string $lockKey = null, ?float $acquiredAt = null, ?int $ttl = null)`
  - ✅ `isLocked(): bool`
  - ✅ `toArray(): array`
  - ✅ `fromArray(array $data): self`

## `src/Exceptions`
- ✅ `src/Exceptions/TransitionException.php` (base exception)
- ✅ `src/Exceptions/InvalidConfigurationException.php` (for invalid gates/actions)
- ✅ `src/Exceptions/InvalidGateResultException.php` (for invalid gate results)
- ✅ `src/Exceptions/LockAcquisitionException.php` (future feature)
- ✅ `src/Exceptions/LockExpiredException.php` (future feature)
- ✅ `src/Exceptions/LockLostException.php` (future feature)

### Error Handling Implementation
- ✅ Configuration validation (gates and actions must implement correct interfaces)
- ✅ Exception propagation from actions (with and without EventDispatcher)
- ✅ Gate result validation (ensures GateResult enum is returned)
- ✅ TransitionFailed event dispatching on exceptions
