<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\TransitionFailed;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TransitionFailedTest extends TestCase
{
    public function testGetters(): void
    {
        $currentState = $this->createMock(State::class);
        $exception = new RuntimeException('Test exception');
        $context = $this->createMock(TransitionContext::class);

        $event = new TransitionFailed($currentState, $exception, $context);

        $this->assertSame($currentState, $event->currentState);
        $this->assertSame($exception, $event->exception);
        $this->assertSame($context, $event->context);
    }
}
