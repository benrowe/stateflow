<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Events\TransitionFailed;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
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
