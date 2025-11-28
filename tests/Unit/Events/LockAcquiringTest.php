<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\LockAcquiring;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class LockAcquiringTest extends TestCase
{
    public function testGetters(): void
    {
        $lockKey = 'test-lock-key';
        $state = $this->createMock(State::class);

        $event = new LockAcquiring($lockKey, $state);

        $this->assertSame($lockKey, $event->lockKey);
        $this->assertSame($state, $event->state);
    }
}
