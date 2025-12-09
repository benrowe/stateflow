<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\Event;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\LockAcquired;
use BenRowe\StateFlow\Events\LockFailed;
use BenRowe\StateFlow\Events\LockReleased;
use BenRowe\StateFlow\Exceptions\LockAcquisitionException;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Locking\LockConfiguration;
use BenRowe\StateFlow\Locking\LockKeyProvider;
use BenRowe\StateFlow\Locking\LockProvider;
use BenRowe\StateFlow\Locking\LockStrategy;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for locking functionality
 *
 * Scenarios from acceptance-tests.md section 9
 */
class LockingTest extends TestCase
{
    use CreatesTestStates;

    /**
     * Scenario 9.1: Acquire lock before transition
     *
     * Given a StateFlow with LockProvider configured
     * And LockStrategy is FAIL_FAST
     * When I start a transition
     * Then a lock should be acquired using the lock key
     * And a LockAcquired event should be dispatched
     * And getLockState().isLocked() should return true
     */
    public function testAcquireLockBeforeTransition(): void
    {
        // Create a mock lock provider that tracks calls
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->once())
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturn(true);

        // Create a lock key provider
        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->once())
            ->method('getLockKey')
            ->willReturn('order:123');

        // Track events
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create StateFlow with locking
        $lockConfig = new LockConfiguration(LockStrategy::FAIL_FAST, 30);
        $config = new Configuration([], []);

        $stateFlow = new StateFlow(
            fn () => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        $worker = $stateFlow->transition($state, ['status' => 'processing']);
        $context = $worker->execute();

        // Verify LockAcquired event was dispatched
        $lockAcquiredEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockAcquired);
        $this->assertCount(1, $lockAcquiredEvents, 'LockAcquired event should be dispatched');

        // Verify lock state
        $lockState = $context->getLockState();
        $this->assertTrue($lockState->isLocked(), 'Lock should be acquired');
        $this->assertSame('order:123', $lockState->lockKey);
    }

    /**
     * Scenario 9.2: Lock already held - FAIL_FAST strategy
     *
     * Given a StateFlow with FAIL_FAST lock strategy
     * And the lock is already held by another process
     * When I attempt a transition
     * Then a LockAcquisitionException should be thrown
     * And a LockFailed event should be dispatched
     * And no gates or actions should execute
     */
    public function testLockAlreadyHeldFailFastThrowsException(): void
    {
        // Create a mock lock provider that returns false (lock already held)
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->once())
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturn(false); // Lock already held

        // Create a lock key provider
        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->once())
            ->method('getLockKey')
            ->willReturn('order:123');

        // Track events
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create gate and action to verify they don't execute
        $gateExecuted = false;
        $gate = new class ($gateExecuted) implements Gate {
            /** @phpstan-ignore-next-line */
            public function __construct(private bool &$executed)
            {
            }

            public function evaluate(GateContext $context): GateResult
            {
                $this->executed = true;

                return GateResult::ALLOW;
            }

            public function message(): string
            {
                return 'test gate';
            }
        };

        $actionExecuted = false;
        $action = new class ($actionExecuted) implements Action {
            /** @phpstan-ignore-next-line */
            public function __construct(private bool &$executed)
            {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->executed = true;

                return ActionResult::continue();
            }
        };

        // Create StateFlow with locking and FAIL_FAST strategy
        $lockConfig = new LockConfiguration(LockStrategy::FAIL_FAST, 30);
        $config = new Configuration([$gate], [$action]);

        $stateFlow = new StateFlow(
            fn () => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        // Expect exception to be thrown
        $this->expectException(LockAcquisitionException::class);
        $this->expectExceptionMessage('Failed to acquire lock');

        try {
            $worker = $stateFlow->transition($state, ['status' => 'processing']);
            $worker->execute();
        } finally {
            // Verify LockFailed event was dispatched
            $lockFailedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockFailed);
            $this->assertCount(1, $lockFailedEvents, 'LockFailed event should be dispatched');

            // Verify gate and action were NOT executed
            $this->assertFalse($gateExecuted, 'Gate should not execute when lock fails');
            $this->assertFalse($actionExecuted, 'Action should not execute when lock fails');
        }
    }

    /**
     * Scenario 9.3: Lock already held - SKIP strategy
     *
     * Given a StateFlow with SKIP lock strategy
     * And the lock is already held
     * When I attempt a transition
     * Then no exception should be thrown
     * And the transition should be skipped
     * And wasSkippedDueToLock() should return true
     * And no gates or actions should execute
     */
    public function testLockAlreadyHeldSkipStrategy(): void
    {
        // Create a mock lock provider that returns false (lock already held)
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->once())
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturn(false); // Lock already held

        // Create a lock key provider
        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->once())
            ->method('getLockKey')
            ->willReturn('order:123');

        // Track events
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create gate and action to verify they don't execute
        $gateExecuted = false;
        $gate = new class ($gateExecuted) implements Gate {
            /** @phpstan-ignore-next-line */
            public function __construct(private bool &$executed)
            {
            }

            public function evaluate(GateContext $context): GateResult
            {
                $this->executed = true;

                return GateResult::ALLOW;
            }

            public function message(): string
            {
                return 'test gate';
            }
        };

        $actionExecuted = false;
        $action = new class ($actionExecuted) implements Action {
            /** @phpstan-ignore-next-line */
            public function __construct(private bool &$executed)
            {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->executed = true;

                return ActionResult::continue();
            }
        };

        // Create StateFlow with locking and SKIP strategy
        $lockConfig = new LockConfiguration(LockStrategy::SKIP, 30);
        $config = new Configuration([$gate], [$action]);

        $stateFlow = new StateFlow(
            fn () => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        // Execute - should NOT throw exception
        $worker = $stateFlow->transition($state, ['status' => 'processing']);
        $context = $worker->execute();

        // Verify LockFailed event was dispatched
        $lockFailedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockFailed);
        $this->assertCount(1, $lockFailedEvents, 'LockFailed event should be dispatched');

        // Verify the transition was skipped due to lock
        $this->assertTrue($context->wasSkippedDueToLock(), 'Transition should be marked as skipped due to lock');

        // Verify gate and action were NOT executed
        $this->assertFalse($gateExecuted, 'Gate should not execute when lock cannot be acquired with SKIP strategy');
        $this->assertFalse($actionExecuted, 'Action should not execute when lock cannot be acquired with SKIP strategy');

        // Verify the context is not completed (it was skipped)
        $this->assertFalse($context->isCompleted(), 'Skipped transition should not be marked as completed');
    }

    /**
     * Scenario 9.4: Lock already held - WAIT strategy
     *
     * Given a StateFlow with WAIT lock strategy
     * And wait timeout of 5 seconds
     * And the lock is held but released after 2 seconds
     * When I attempt a transition
     * Then it should retry acquiring the lock
     * And it should succeed within 5 seconds
     * And the transition should proceed normally
     */
    public function testLockAlreadyHeldWaitStrategySucceeds(): void
    {
        $attemptCount = 0;
        $lockReleaseTime = microtime(true) + 0.2; // Release lock after 200ms

        // Create a mock lock provider that fails initially, then succeeds
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->atLeastOnce())
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturnCallback(function () use (&$attemptCount, $lockReleaseTime) {
                $attemptCount++;

                // Lock is held for first few attempts, then released
                return microtime(true) >= $lockReleaseTime;
            });

        // Create a lock key provider
        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->once())
            ->method('getLockKey')
            ->willReturn('order:123');

        // Track events
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create a simple action to verify execution
        $actionExecuted = false;
        $action = new class ($actionExecuted) implements Action {
            /** @phpstan-ignore-next-line */
            public function __construct(private bool &$executed)
            {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->executed = true;

                return ActionResult::continue();
            }
        };

        // Create StateFlow with locking and WAIT strategy (5 second timeout, 50ms retry interval)
        $lockConfig = new LockConfiguration(
            strategy: LockStrategy::WAIT,
            ttl: 30,
            waitTimeout: 5,
            retryInterval: 50
        );
        $config = new Configuration([], [$action]);

        $stateFlow = new StateFlow(
            fn () => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        $startTime = microtime(true);
        $worker = $stateFlow->transition($state, ['status' => 'processing']);
        $context = $worker->execute();
        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // Verify lock was eventually acquired
        $lockState = $context->getLockState();
        $this->assertTrue($lockState->isLocked(), 'Lock should be acquired after waiting');

        // Verify it took some time (at least 200ms) but less than timeout (5 seconds)
        $this->assertGreaterThanOrEqual(0.2, $duration, 'Should have waited for lock to be released');
        $this->assertLessThan(5, $duration, 'Should not have timed out');

        // Verify multiple acquire attempts were made
        $this->assertGreaterThan(1, $attemptCount, 'Should have retried acquiring the lock');

        // Verify action was executed
        $this->assertTrue($actionExecuted, 'Action should execute after lock acquired');

        // Verify transition completed successfully
        $this->assertTrue($context->isCompleted(), 'Transition should complete successfully');

        // Verify LockAcquired event was dispatched
        $lockAcquiredEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockAcquired);
        $this->assertCount(1, $lockAcquiredEvents, 'LockAcquired event should be dispatched');
    }

    /**
     * Scenario 9.5: Lock already held - WAIT timeout
     *
     * Given a StateFlow with WAIT lock strategy
     * And wait timeout of 2 seconds
     * And the lock remains held for longer than timeout
     * When I attempt a transition
     * Then it should retry for 2 seconds
     * And a LockAcquisitionException should be thrown
     * And no transition should occur
     */
    public function testLockAlreadyHeldWaitStrategyTimesOut(): void
    {
        $attemptCount = 0;

        // Create a mock lock provider that always fails (lock never released)
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->atLeastOnce())
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturnCallback(function () use (&$attemptCount) {
                $attemptCount++;

                // Lock is never released
                return false;
            });

        // Create a lock key provider
        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->once())
            ->method('getLockKey')
            ->willReturn('order:123');

        // Track events
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create a simple action to verify it does NOT execute
        $actionExecuted = false;
        $action = new class ($actionExecuted) implements Action {
            /** @phpstan-ignore-next-line */
            public function __construct(private bool &$executed)
            {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->executed = true;

                return ActionResult::continue();
            }
        };

        // Create StateFlow with locking and WAIT strategy (0.5 second timeout, 50ms retry interval)
        $lockConfig = new LockConfiguration(
            strategy: LockStrategy::WAIT,
            ttl: 30,
            waitTimeout: 1, // 1 second timeout for faster test
            retryInterval: 50
        );
        $config = new Configuration([], [$action]);

        $stateFlow = new StateFlow(
            fn () => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        // Expect exception to be thrown
        $this->expectException(LockAcquisitionException::class);
        $this->expectExceptionMessage('Failed to acquire lock');

        $startTime = microtime(true);

        try {
            $worker = $stateFlow->transition($state, ['status' => 'processing']);
            $worker->execute();
        } finally {
            $endTime = microtime(true);
            $duration = $endTime - $startTime;

            // Verify it waited for approximately the timeout duration
            $this->assertGreaterThanOrEqual(1.0, $duration, 'Should have waited for at least timeout duration');
            $this->assertLessThan(1.5, $duration, 'Should not have waited significantly longer than timeout');

            // Verify multiple acquire attempts were made
            $this->assertGreaterThan(1, $attemptCount, 'Should have retried acquiring the lock multiple times');

            // Verify LockFailed event was dispatched
            $lockFailedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockFailed);
            $this->assertCount(1, $lockFailedEvents, 'LockFailed event should be dispatched on timeout');

            // Verify action was NOT executed
            $this->assertFalse($actionExecuted, 'Action should not execute when lock acquisition times out');
        }
    }

    /**
     * Scenario 9.6: Release lock after completion
     *
     * Given a successful transition with lock acquired
     * When the transition completes
     * Then the lock should be automatically released
     * And a LockReleased event should be dispatched
     */
    public function testReleaseLockAfterCompletion(): void
    {
        // Create a mock lock provider that tracks calls
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->once())
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturn(true);

        $lockProvider
            ->expects($this->once())
            ->method('release')
            ->with('order:123')
            ->willReturn(true);

        // Create a lock key provider
        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->once())
            ->method('getLockKey')
            ->willReturn('order:123');

        // Track events
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create StateFlow with locking
        $lockConfig = new LockConfiguration(LockStrategy::FAIL_FAST, 30);
        $config = new Configuration([], []);

        $stateFlow = new StateFlow(
            fn () => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        $worker = $stateFlow->transition($state, ['status' => 'processing']);
        $context = $worker->execute();

        // Verify lock was acquired
        $this->assertTrue($context->getLockState()->isLocked());

        // Verify transition completed
        $this->assertTrue($context->isCompleted());

        // Verify LockReleased event was dispatched
        $lockReleasedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockReleased);
        $this->assertCount(1, $lockReleasedEvents, 'LockReleased event should be dispatched');

        $lockReleasedEvent = array_values($lockReleasedEvents)[0];
        $this->assertSame('order:123', $lockReleasedEvent->lockKey);
    }

    /**
     * Scenario 9.7: Release lock after failure
     *
     * Given a transition that fails with an exception
     * And a lock was acquired
     * When the exception is thrown
     * Then the lock should be automatically released
     * And the exception should propagate
     */
    public function testReleaseLockAfterFailure(): void
    {
        // Create a mock lock provider that tracks calls
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->once())
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturn(true);

        $lockProvider
            ->expects($this->once())
            ->method('release')
            ->with('order:123')
            ->willReturn(true);

        // Create a lock key provider
        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->once())
            ->method('getLockKey')
            ->willReturn('order:123');

        // Track events
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create an action that throws an exception
        $action = new class () implements Action {
            public function execute(ActionContext $context): ActionResult
            {
                throw new \RuntimeException('Action failed');
            }
        };

        // Create StateFlow with locking
        $lockConfig = new LockConfiguration(LockStrategy::FAIL_FAST, 30);
        $config = new Configuration([], [$action]);

        $stateFlow = new StateFlow(
            fn () => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        // Expect exception to be thrown
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Action failed');

        try {
            $worker = $stateFlow->transition($state, ['status' => 'processing']);
            $worker->execute();
        } finally {
            // Verify LockReleased event was dispatched even though exception was thrown
            $lockReleasedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockReleased);
            $this->assertCount(1, $lockReleasedEvents, 'LockReleased event should be dispatched even on failure');

            $lockReleasedEvent = array_values($lockReleasedEvents)[0];
            $this->assertSame('order:123', $lockReleasedEvent->lockKey);
        }
    }

    /**
     * Scenario 9.8: Maintain lock during pause
     *
     * Given a transition that pauses mid-execution
     * When the action returns PAUSE
     * Then the lock should remain held
     * And the lock TTL should be extended if needed
     * And getLockState() should show the lock is still held
     */
    public function testMaintainLockDuringPause(): void
    {
        // Create a mock lock provider that tracks calls
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->once())
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturn(true);

        // Lock should NOT be released when paused
        $lockProvider
            ->expects($this->never())
            ->method('release');

        // Create a lock key provider
        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->once())
            ->method('getLockKey')
            ->willReturn('order:123');

        // Track events
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create an action that pauses execution
        $action = new class () implements Action {
            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::pause();
            }
        };

        // Create StateFlow with locking
        $lockConfig = new LockConfiguration(LockStrategy::FAIL_FAST, 30);
        $config = new Configuration([], [$action]);

        $stateFlow = new StateFlow(
            fn () => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        $worker = $stateFlow->transition($state, ['status' => 'processing']);
        $context = $worker->execute();

        // Verify lock is still held
        $this->assertTrue($context->getLockState()->isLocked(), 'Lock should remain held when paused');
        $this->assertSame('order:123', $context->getLockState()->lockKey);

        // Verify transition is paused
        $this->assertTrue($context->isPaused(), 'Transition should be paused');

        // Verify NO LockReleased event was dispatched
        $lockReleasedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockReleased);
        $this->assertCount(0, $lockReleasedEvents, 'LockReleased event should NOT be dispatched when paused');

        // Verify LockAcquired event was dispatched
        $lockAcquiredEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockAcquired);
        $this->assertCount(1, $lockAcquiredEvents, 'LockAcquired event should be dispatched');
    }
}
