<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\TransitionStarting;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class TransitionStartingTest extends TestCase
{
    public function testGetters(): void
    {
        $currentState = $this->createMock(State::class);
        $desiredDelta = ['status' => 'active', 'priority' => 'high'];

        $event = new TransitionStarting($currentState, $desiredDelta);

        $this->assertSame($currentState, $event->currentState);
        $this->assertSame($desiredDelta, $event->desiredDelta);
    }
}
