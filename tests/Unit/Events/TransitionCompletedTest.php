<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\TransitionCompleted;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class TransitionCompletedTest extends TestCase
{
    public function testGetters(): void
    {
        $finalState = $this->createMock(State::class);
        $context = $this->createMock(TransitionContext::class);

        $event = new TransitionCompleted($finalState, $context);

        $this->assertSame($finalState, $event->finalState);
        $this->assertSame($context, $event->context);
    }
}
