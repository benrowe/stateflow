<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\TransitionStarting;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

class EventDispatchingTest extends TestCase
{
    use CreatesTestStates;

    public function testDispatchesTransitionStartingEvent(): void
    {
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = ['status' => 'review'];

        $mockDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (TransitionStarting $event) use ($initialState, $delta) {
                $this->assertInstanceOf(TransitionStarting::class, $event);
                $this->assertSame($initialState, $event->currentState);
                $this->assertSame($delta, $event->desiredDelta);

                return true;
            }));

        $stateFlow = new StateFlow(fn () => new Configuration([], []), $mockDispatcher);

        $stateFlow->transition($initialState, $delta);
    }
}
