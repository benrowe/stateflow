<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Locking;

use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\LockAcquired;
use BenRowe\StateFlow\Exceptions\LockAcquisitionException;
use BenRowe\StateFlow\Locking\LockConfiguration;
use BenRowe\StateFlow\Locking\LockKeyProvider;
use BenRowe\StateFlow\Locking\LockManager;
use BenRowe\StateFlow\Locking\LockProvider;
use BenRowe\StateFlow\Locking\LockStrategy;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

final class LockManagerTest extends TestCase
{
    public function testAcquireLockReturnsNullWhenNoLockingConfigured(): void
    {
        $context = $this->createMock(TransitionContext::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $manager = new LockManager(null, null, null, $context, $dispatcher);

        $this->assertNull($manager->acquireLock());
    }

    public function testAcquireLockReturnsLockKeyWhenSuccessful(): void
    {
        $provider = $this->createMock(LockProvider::class);
        $keyProvider = $this->createMock(LockKeyProvider::class);
        $config = new LockConfiguration(LockStrategy::FAIL_FAST, 30);
        $context = $this->createMock(TransitionContext::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $keyProvider->expects($this->once())
            ->method('getLockKey')
            ->willReturn('test-key');

        $provider->expects($this->once())
            ->method('acquire')
            ->with('test-key', 30)
            ->willReturn(true);

        $context->expects($this->once())
            ->method('setLockState');

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(LockAcquired::class));

        $manager = new LockManager($provider, $keyProvider, $config, $context, $dispatcher);

        $result = $manager->acquireLock();

        $this->assertSame('test-key', $result);
    }

    public function testFailFastThrowsExceptionWhenLockFails(): void
    {
        $provider = $this->createMock(LockProvider::class);
        $keyProvider = $this->createMock(LockKeyProvider::class);
        $config = new LockConfiguration(LockStrategy::FAIL_FAST, 30);
        $context = $this->createMock(TransitionContext::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $keyProvider->method('getLockKey')->willReturn('test-key');
        $provider->method('acquire')->willReturn(false);

        $manager = new LockManager($provider, $keyProvider, $config, $context, $dispatcher);

        $this->expectException(LockAcquisitionException::class);

        $manager->acquireLock();
    }
}
