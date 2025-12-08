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
}
