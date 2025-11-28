<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\TransitionPaused;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class TransitionPausedTest extends TestCase
{
    public function testGetters(): void
    {
        $currentState = $this->createMock(State::class);
        $context = new TransitionContext();
        $metadata = ['reason' => 'user requested'];

        $event = new TransitionPaused($currentState, $context, $metadata);

        $this->assertSame($currentState, $event->currentState);
        $this->assertSame($context, $event->context);
        $this->assertSame($metadata, $event->metadata);
    }
}
