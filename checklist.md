# StateFlow Checklist

This checklist is generated from the documentation to track the creation of all the necessary files for the `stateflow` project.

## `src`

### Core Machine
- ✅ `src/StateFlow.php`
  - ✅ `__construct(...)`
  - ✅ `transition(State $currentState, array $desiredDelta): StateWorker`
  - ❌ `fromContext(TransitionContext $context): StateWorker`
- ✅ `src/StateWorker.php`
  - ❌ `runGates(): GateResult`
  - ❌ `runActions(): TransitionContext`
  - ❌ `runNextAction(): TransitionContext`
  - ✅ `execute(): TransitionContext`
  - ❌ `releaseLock(): bool`
  - ❌ `getContext(): TransitionContext`
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
  - ❌ `getLockState(): LockState`
  - ❌ `getStatusMetadata(): mixed`
  - ❌ `serialize(): string`
  - ❌ `unserialize(string $data, StateFactory $stateFactory, ActionFactory $actionFactory): self`

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
