<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Events\TransitionYielded;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class TransitionYieldedTest extends TestCase
{
    public function testGetters(): void
    {
        $currentState = $this->createMock(State::class);
        $context = $this->createMock(TransitionContext::class);
        $metadata = ['checkId' => 'abc123'];

        $event = new TransitionYielded($currentState, $context, $metadata);

        $this->assertSame($currentState, $event->currentState);
        $this->assertSame($context, $event->context);
        $this->assertSame($metadata, $event->metadata);
    }
}
