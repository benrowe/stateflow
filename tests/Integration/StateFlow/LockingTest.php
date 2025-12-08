<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\Event;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\LockAcquired;
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
            fn() => $config,
            $mockDispatcher,
            $lockProvider,
            $lockKeyProvider,
            $lockConfig
        );

        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        $worker = $stateFlow->transition($state, ['status' => 'processing']);
        $context = $worker->execute();

        // Verify LockAcquired event was dispatched
        $lockAcquiredEvents = array_filter($dispatchedEvents, fn($e) => $e instanceof LockAcquired);
        $this->assertCount(1, $lockAcquiredEvents, 'LockAcquired event should be dispatched');

        // Verify lock state
        $lockState = $context->getLockState();
        $this->assertTrue($lockState->isLocked(), 'Lock should be acquired');
        $this->assertSame('order:123', $lockState->lockKey);
    }
}
