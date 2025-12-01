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
  - ❌ `releaseLock(): bool`
  - ✅ `getContext(): TransitionContext`
  - ✅ `setNextActionIndex(int $index): void`
- ✅ `src/TransitionContext.php`
  - ✅ `__construct(State $initialState, array $desiredDelta = [])`
  - ✅ `getCurrentState(): State`
  - ✅ `getDesiredDelta(): array`
  - ✅ `isCompleted(): bool`
  - ✅ `isPaused(): bool`
  - ✅ `isStopped(): bool`
  - ❌ `wasSkippedDueToLock(): bool`
  - ✅ `getGateEvaluations(): array`
  - ✅ `getActionExecutions(): array`
  - ✅ `getActionSkips(): array`
  - ✅ `clearPauseStatus(): void`
  - ❌ `getLockState(): LockState`
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
- 🔄 `src/Events/TransitionStopped.php` (class defined, ❌ not dispatched)
- 🔄 `src/Events/TransitionFailed.php` (class defined, ❌ not dispatched)

### Gate Events
- 🔄 `src/Events/GateEvaluating.php` (class defined, ❌ not dispatched)
- 🔄 `src/Events/GateEvaluated.php` (class defined, ❌ not dispatched)

### Action Events
- 🔄 `src/Events/ActionExecuting.php` (class defined, ❌ not dispatched)
- 🔄 `src/Events/ActionExecuted.php` (class defined, ❌ not dispatched)
- 🔄 `src/Events/ActionSkipped.php` (class defined, ❌ not dispatched)

### Lock Events (Future Feature)
- ✅ `src/Events/LockAcquiring.php` (class defined)
- ✅ `src/Events/LockAcquired.php` (class defined)
- ✅ `src/Events/LockFailed.php` (class defined)
- ✅ `src/Events/LockReleased.php` (class defined)
- ✅ `src/Events/LockLost.php` (class defined)
- ✅ `src/Events/LockRestored.php` (class defined)

## `src/Locking`
- ❌ `src/Locking/LockProvider.php`
  - ❌ `acquire(string $key, int $ttl = 30): bool`
  - ❌ `release(string $key): bool`
  - ❌ `exists(string $key): bool`
  - ❌ `renew(string $key, int $ttl): bool`
- ❌ `src/Locking/LockKeyProvider.php`
  - ❌ `getLockKey(State $state, array $desiredDelta): string`
- ❌ `src/Locking/LockStrategy.php`
  - ❌ `NONE`
  - ❌ `FAIL_FAST`
  - ❌ `WAIT`
  - ❌ `SKIP`
- ❌ `src/Locking/LockConfiguration.php`
  - ❌ `__construct(LockStrategy $strategy = LockStrategy::FAIL_FAST, int $ttl = 30, int $waitTimeout = 10, int $retryInterval = 100)`
- ❌ `src/Locking/LockState.php`
  - ❌ `__construct(?string $lockKey = null, ?float $acquiredAt = null, ?int $ttl = null)`
  - ❌ `isLocked(): bool`
  - ❌ `toArray(): array`
  - ❌ `fromArray(array $data): self`
