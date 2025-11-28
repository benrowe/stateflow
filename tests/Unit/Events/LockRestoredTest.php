<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\LockRestored;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class LockRestoredTest extends TestCase
{
    public function testGetters(): void
    {
        $lockKey = 'test-lock-key';
        $state = $this->createMock(State::class);

        $event = new LockRestored($lockKey, $state);

        $this->assertSame($lockKey, $event->lockKey);
        $this->assertSame($state, $event->state);
    }
}
