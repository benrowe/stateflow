<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\LockReleased;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class LockReleasedTest extends TestCase
{
    public function testGetters(): void
    {
        $lockKey = 'test-lock-key';
        $state = $this->createMock(State::class);

        $event = new LockReleased($lockKey, $state);

        $this->assertSame($lockKey, $event->lockKey);
        $this->assertSame($state, $event->state);
    }
}
