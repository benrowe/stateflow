<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\TransitionStopped;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class TransitionStoppedTest extends TestCase
{
    public function testGetters(): void
    {
        $currentState = $this->createMock(State::class);
        $context = $this->createMock(TransitionContext::class);
        $metadata = ['reason' => 'cancelled'];

        $event = new TransitionStopped($currentState, $context, $metadata);

        $this->assertSame($currentState, $event->currentState);
        $this->assertSame($context, $event->context);
        $this->assertSame($metadata, $event->metadata);
    }
}
