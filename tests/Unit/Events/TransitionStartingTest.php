<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Events\TransitionStarting;
use CoverGenius\StateFlow\State;
use PHPUnit\Framework\TestCase;

class TransitionStartingTest extends TestCase
{
    public function testGetters(): void
    {
        $currentState = $this->createMock(State::class);
        $desiredDelta = new ArrayDelta(['status' => 'active', 'priority' => 'high']);

        $event = new TransitionStarting($currentState, $desiredDelta);

        $this->assertSame($currentState, $event->currentState);
        $this->assertSame($desiredDelta, $event->desiredDelta);
    }
}
