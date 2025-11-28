# Mutex Locking System

## Overview

StateFlow includes built-in mutex locking to prevent race conditions when multiple processes attempt to transition the same state simultaneously.

## The Problem

```
Time →

Process A: transitionTo(['status' => 'published'])
           ├─ Check: status=draft ✓
           ├─ Set publishedAt
           └─ Update: status=published

Process B: transitionTo(['status' => 'published'])
           ├─ Check: status=draft ✓  ← RACE! Both see draft
           ├─ Set publishedAt
           └─ Update: status=published ← Duplicate work, possible corruption
```

## The Solution

```
Process A: Acquire lock → Execute transition → Release lock
Process B: Wait for lock → Execute transition → Release lock
           (or fail/skip based on strategy)
```

---

## Core Interfaces

### LockProvider

Abstraction for lock storage backend.

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

Defines **what** to lock for a given transition.

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

---

## Lock Strategies

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

### Strategy Comparison

| Strategy | Behavior | Use Case |
|----------|----------|----------|
| `NONE` | No locking | Single-threaded, testing, or externally managed locks |
| `FAIL_FAST` | Throw exception if locked | Fail loudly, let caller decide retry logic |
| `WAIT` | Block until lock available | Background jobs where waiting is acceptable |
| `SKIP` | Return immediately with skip flag | API requests where user shouldn't wait |

---

## Lock Configuration

```php
class LockConfiguration
{
    public function __construct(
        public readonly LockStrategy $strategy = LockStrategy::FAIL_FAST,
        public readonly int $ttl = 30,           // Lock time-to-live (seconds)
        public readonly int $waitTimeout = 10,    // Max wait time (seconds)
        public readonly int $retryInterval = 100, // Retry interval (milliseconds)
    ) {}
}
```

### Configuration Examples

Lock behavior is configured once on the `StateMachine` constructor. Different use cases require different machine configurations:

**API Request (fail fast):**
```php
$machine = new StateMachine(
    configProvider: $config,
    lockProvider: new RedisLockProvider($redis),
    lockKeyProvider: new EntityLockKeyProvider(),
);

try {
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();
} catch (LockAcquisitionException $e) {
    // Another request is processing this entity
    return response()->json(['message' => 'Already processing'], 409);
}
```

**Background Job (with longer TTL):**
```php
// Configure lock provider with longer TTL for background jobs
$lockProvider = new RedisLockProvider($redis, ttl: 60);

$machine = new StateMachine(
    configProvider: $config,
    lockProvider: $lockProvider,
    lockKeyProvider: new EntityLockKeyProvider(),
);

$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();
```

**Critical Operation (fail on contention):**
```php
$machine = new StateMachine(
    configProvider: $config,
    lockProvider: new RedisLockProvider($redis),
    lockKeyProvider: new EntityLockKeyProvider(),
);

try {
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();
} catch (LockAcquisitionException $e) {
    Log::alert('Lock contention detected', ['entity' => $entity->id]);
    throw $e;
}
```

---

## Lock Lifecycle

### Lock Acquisition Points

Lock is acquired when `execute()` is called on the `StateWorker`.

```php
$worker = $machine->transition($state, $delta);
$context = $worker->execute();
// ↑ Lock acquired here (if lockProvider configured on machine)
```

### Lock Persistence on Pause

**Key Behavior:** Lock persists when execution pauses.

```php
// Action pauses
class AsyncAction implements Action {
    public function execute(ActionContext $context): ActionResult {
        return ActionResult::pause(); // Lock STILL HELD
    }
}

$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();
// Lock is held

if ($context->isPaused()) {
    // Lock STILL held
    saveToDatabase($context->serialize());
}

// Hours later...
$serializedContext = loadFromDatabase();
$context = TransitionContext::unserialize($serializedContext, $stateFactory, $actionFactory);
$worker = $machine->fromContext($context);
$finalContext = $worker->execute(); // Verifies lock still exists
```

### Lock Release Points

Lock is released when:
1. ✅ Transition completes (`isCompleted()`)
2. ✅ Transition stops (`isStopped()`)
3. ✅ Manual call to `$worker->releaseLock()`
4. ❌ **NOT on pause** - lock persists

```php
// Automatic release on completion
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();
if ($context->isCompleted()) {
    // Lock automatically released
}

// Manual release on error
try {
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();
} catch (\Exception $e) {
    $worker->releaseLock(); // Manual cleanup
    handleError($e);
}
```

---

## Lock State

Lock state is tracked and serializable.

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

### Accessing Lock State

```php
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();

$lockState = $context->getLockState();

if ($lockState->isLocked()) {
    echo "Lock key: {$lockState->lockKey}\n";
    echo "Acquired: " . date('Y-m-d H:i:s', (int)$lockState->acquiredAt) . "\n";
    echo "TTL: {$lockState->ttl}s\n";

    // Calculate remaining time
    $elapsed = microtime(true) - $lockState->acquiredAt;
    $remaining = $lockState->ttl - $elapsed;
    echo "Expires in: {$remaining}s\n";
}
```

---

## LockProvider Implementations

### Redis

```php
class RedisLockProvider implements LockProvider
{
    public function __construct(private \Redis $redis) {}

    public function acquire(string $key, int $ttl = 30): bool
    {
        // SET with NX (only if not exists) and EX (expiry)
        return $this->redis->set($key, '1', ['nx', 'ex' => $ttl]);
    }

    public function release(string $key): bool
    {
        return $this->redis->del($key) > 0;
    }

    public function exists(string $key): bool
    {
        return $this->redis->exists($key) > 0;
    }
}
```

### Database

```php
class DatabaseLockProvider implements LockProvider
{
    public function __construct(private Connection $db) {}

    public function acquire(string $key, int $ttl = 30): bool
    {
        try {
            $this->db->table('locks')->insert([
                'key' => $key,
                'acquired_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
            ]);
            return true;
        } catch (UniqueConstraintException $e) {
            // Lock already exists
            return false;
        }
    }

    public function release(string $key): bool
    {
        return $this->db->table('locks')
            ->where('key', $key)
            ->delete() > 0;
    }

    public function exists(string $key): bool
    {
        return $this->db->table('locks')
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
```

### File System

```php
class FileLockProvider implements LockProvider
{
    public function __construct(private string $lockDir) {}

    public function acquire(string $key, int $ttl = 30): bool
    {
        $filename = $this->getLockFilename($key);

        if (file_exists($filename)) {
            // Check if expired
            $acquiredAt = (int)file_get_contents($filename);
            if (time() - $acquiredAt < $ttl) {
                return false; // Still locked
            }
            // Expired, remove old lock
            unlink($filename);
        }

        return file_put_contents($filename, time(), LOCK_EX) !== false;
    }

    public function release(string $key): bool
    {
        $filename = $this->getLockFilename($key);
        if (file_exists($filename)) {
            unlink($filename);
            return true;
        }
        return false;
    }

    public function exists(string $key): bool
    {
        return file_exists($this->getLockFilename($key));
    }

    private function getLockFilename(string $key): string
    {
        return $this->lockDir . '/' . md5($key) . '.lock';
    }
}
```

### In-Memory (Testing)

```php
class InMemoryLockProvider implements LockProvider
{
    private array $locks = [];

    public function acquire(string $key, int $ttl = 30): bool
    {
        if (isset($this->locks[$key])) {
            // Check expiration
            if ($this->locks[$key] > microtime(true)) {
                return false; // Still locked
            }
        }

        $this->locks[$key] = microtime(true) + $ttl;
        return true;
    }

    public function release(string $key): bool
    {
        if (isset($this->locks[$key])) {
            unset($this->locks[$key]);
            return true;
        }
        return false;
    }

    public function exists(string $key): bool
    {
        return isset($this->locks[$key]) && $this->locks[$key] > microtime(true);
    }

    public function clear(): void
    {
        $this->locks = [];
    }
}
```

---

## LockKeyProvider Implementations

### Entity-Based Locking

Lock the entire entity regardless of transition type.

```php
class EntityLockKeyProvider implements LockKeyProvider
{
    public function getLockKey(State $state, array $desiredDelta): string
    {
        $data = $state->toArray();
        $entityId = $data['id'] ?? 'unknown';

        return "stateflow:entity:{$entityId}";
    }
}

// Result: "stateflow:entity:order-123"
// Effect: Only one transition can happen on order-123 at a time
```

### Transition-Specific Locking

Lock only the specific transition type.

```php
class TransitionLockKeyProvider implements LockKeyProvider
{
    public function getLockKey(State $state, array $desiredDelta): string
    {
        $data = $state->toArray();
        $entityId = $data['id'] ?? 'unknown';
        $currentStatus = $data['status'] ?? 'unknown';
        $targetStatus = $desiredDelta['status'] ?? 'unknown';

        return "stateflow:entity:{$entityId}:transition:{$currentStatus}->{$targetStatus}";
    }
}

// Result: "stateflow:entity:order-123:transition:draft->published"
// Effect: Can have concurrent transitions if they're different types
//         e.g., "draft->published" and "metadata update" can run simultaneously
```

### State Snapshot Locking

Lock based on exact state contents.

```php
class SnapshotLockKeyProvider implements LockKeyProvider
{
    public function getLockKey(State $state, array $desiredDelta): string
    {
        $stateHash = md5(json_encode($state->toArray()));
        $deltaHash = md5(json_encode($desiredDelta));

        return "stateflow:snapshot:{$stateHash}:{$deltaHash}";
    }
}

// Result: "stateflow:snapshot:a3f5c2...:b7d9e1..."
// Effect: Only exact same state+delta combinations are locked
//         Different states can transition independently
```

---

## Exception Handling

```php
class LockAcquisitionException extends \RuntimeException {}
class LockExpiredException extends \RuntimeException {}
class LockLostException extends \RuntimeException {}
```

### LockAcquisitionException

Thrown when lock can't be acquired (default behavior when `lockProvider` is configured).

```php
try {
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();
} catch (LockAcquisitionException $e) {
    // Another process holds the lock
    Log::warning('Lock contention', ['entity' => $entity->id]);

    // Options:
    // 1. Retry later
    // 2. Queue for background processing
    // 3. Return error to user
}
```

### LockExpiredException

Thrown by `runNextAction()` when lock has expired during pause.

```php
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute(); // Lock acquired
sleep(100); // Oops, lock TTL was 30 seconds

try {
    $context = $worker->runNextAction(); // Verifies lock
} catch (LockExpiredException $e) {
    // Lock expired during pause
    Log::error('Lock expired', ['lockKey' => $e->getMessage()]);

    // Options:
    // 1. Restart transition from beginning
    // 2. Mark as failed
    // 3. Alert ops team
}
```

### LockLostException

Thrown when resuming a paused transition if the lock no longer exists.

```php
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();
saveToDatabase($context->serialize());

// Hours later...
$serializedContext = loadFromDatabase();
$context = TransitionContext::unserialize($serializedContext, $stateFactory, $actionFactory);

try {
    $worker = $machine->fromContext($context);
    $finalContext = $worker->execute();
} catch (LockLostException $e) {
    // Lock expired or manually released
    Log::error('Cannot resume: lock lost', [
        'lockKey' => $context->getLockState()->lockKey,
        'ttl' => $context->getLockState()->ttl,
    ]);

    // Options:
    // 1. Acquire new lock and retry
    // 2. Check if state already transitioned (idempotency)
    // 3. Mark workflow as failed
}
```

---

## Best Practices

### 1. Choose Appropriate TTL

Configure TTL on your `LockProvider` based on workflow duration:

```php
// Short-lived synchronous transition (30 seconds)
$lockProvider = new RedisLockProvider($redis, ttl: 30);

// Async transition with external dependency (1 hour)
$lockProvider = new RedisLockProvider($redis, ttl: 3600);

// Very long-running workflow (24 hours)
$lockProvider = new RedisLockProvider($redis, ttl: 86400);

$machine = new StateMachine(
    configProvider: $config,
    lockProvider: $lockProvider,
    lockKeyProvider: new EntityLockKeyProvider(),
);
```

**Rule of thumb:** TTL should be 2-3x the expected max duration.

### 2. Handle Lock Expiration in Paused Workflows

```php
class ResumeWorkflowJob
{
    public function handle()
    {
        $context = TransitionContext::unserialize(
            $this->serializedContext,
            $this->stateFactory,
            $this->actionFactory
        );

        try {
            $machine = $this->buildMachine();
            $worker = $machine->fromContext($context);
            $finalContext = $worker->execute();
        } catch (LockLostException $e) {
            // Check if already completed
            if ($this->isAlreadyCompleted()) {
                Log::info('Workflow already completed elsewhere');
                return;
            }

            // Try to recover
            $this->retryWithNewLock();
        }
    }
}
```

### 3. Use Events to Monitor Lock Contention

```php
class LockMonitoringDispatcher implements EventDispatcher
{
    public function dispatch(Event $event): void
    {
        if ($event instanceof LockFailed) {
            Metrics::increment('stateflow.lock.contention', [
                'lock_key' => $event->lockKey,
            ]);

            Log::warning('Lock contention detected', [
                'lock_key' => $event->lockKey,
                'state' => $event->state->toArray(),
            ]);
        }

        if ($event instanceof LockLost) {
            Metrics::increment('stateflow.lock.lost', [
                'lock_key' => $event->lockKey,
            ]);
        }
    }
}
```

### 4. Manual Lock Release for Error Recovery

```php
try {
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();
} catch (\Throwable $e) {
    // Always release lock on fatal errors
    $worker->releaseLock();

    Log::error('Transition failed, lock released', [
        'exception' => $e->getMessage(),
    ]);

    throw $e;
}
```

### 5. Idempotency for Resume Operations

```php
public function resume(string $contextId)
{
    $serializedContext = $this->loadSerializedContext($contextId);
    $context = TransitionContext::unserialize(
        $serializedContext,
        $this->stateFactory,
        $this->actionFactory
    );

    // Check if already completed before resuming
    $currentState = $this->loadCurrentState();
    if ($currentState->status === 'published') {
        Log::info('Already published, skipping resume');
        return;
    }

    try {
        $machine = $this->buildMachine();
        $worker = $machine->fromContext($context);
        $finalContext = $worker->execute();
    } catch (LockLostException $e) {
        // Double-check state again
        $currentState = $this->loadCurrentState();
        if ($currentState->status === 'published') {
            Log::info('Published by another process');
            return;
        }

        throw $e; // Actually lost, not a race
    }
}
```

---

## Testing with Locks

```php
use Tests\TestCase;

class StateTransitionTest extends TestCase
{
    public function test_concurrent_transitions_are_prevented()
    {
        $lockProvider = new InMemoryLockProvider();

        $machine1 = new StateMachine(
            configProvider: /* ... */,
            lockProvider: $lockProvider,
            lockKeyProvider: new EntityLockKeyProvider(),
        );

        $machine2 = new StateMachine(
            configProvider: /* ... */,
            lockProvider: $lockProvider, // Same lock provider
            lockKeyProvider: new EntityLockKeyProvider(),
        );

        $state = new OrderState('ORD-123', 'draft', 99.99);

        // Machine 1 acquires lock
        $worker1 = $machine1->transition($state, ['status' => 'published']);
        $context1 = $worker1->execute();
        $this->assertTrue($context1->getLockState()->isLocked());

        // Machine 2 fails to acquire lock
        $this->expectException(LockAcquisitionException::class);
        $worker2 = $machine2->transition($state, ['status' => 'published']);
        $worker2->execute();
    }
}
```
