<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Events\LockAcquired;
use CoverGenius\StateFlow\Locking\LockState;
use PHPUnit\Framework\TestCase;

class LockAcquiredTest extends TestCase
{
    public function testGetters(): void
    {
        $lockKey = 'test-lock-key';
        $lockState = new LockState('test-lock-key', 1234567890.0, 30);

        $event = new LockAcquired($lockKey, $lockState);

        $this->assertSame($lockKey, $event->lockKey);
        $this->assertSame($lockState, $event->lockState);
    }
}
