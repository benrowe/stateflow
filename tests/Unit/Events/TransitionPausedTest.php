<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Events\TransitionPaused;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class TransitionPausedTest extends TestCase
{
    public function testGetters(): void
    {
        $currentState = $this->createMock(State::class);
        $context = $this->createMock(TransitionContext::class);
        $metadata = ['reason' => 'user requested'];

        $event = new TransitionPaused($currentState, $context, $metadata);

        $this->assertSame($currentState, $event->currentState);
        $this->assertSame($context, $event->context);
        $this->assertSame($metadata, $event->metadata);
    }
}
