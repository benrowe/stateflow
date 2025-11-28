<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\LockFailed;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class LockFailedTest extends TestCase
{
    public function testGetters(): void
    {
        $lockKey = 'test-lock-key';
        $state = $this->createMock(State::class);
        $reason = 'Lock is already held';

        $event = new LockFailed($lockKey, $state, $reason);

        $this->assertSame($lockKey, $event->lockKey);
        $this->assertSame($state, $event->state);
        $this->assertSame($reason, $event->reason);
    }
}
