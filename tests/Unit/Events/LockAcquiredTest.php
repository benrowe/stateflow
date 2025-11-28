<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\LockAcquired;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class LockAcquiredTest extends TestCase
{
    public function testGetters(): void
    {
        $lockKey = 'test-lock-key';
        $state = $this->createMock(State::class);

        $event = new LockAcquired($lockKey, $state);

        $this->assertSame($lockKey, $event->lockKey);
        $this->assertSame($state, $event->state);
    }
}
