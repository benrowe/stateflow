<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Events\TransitionCompleted;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
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
