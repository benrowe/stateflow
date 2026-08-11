<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Events\GateEvaluating;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateContext;
use CoverGenius\StateFlow\State;
use PHPUnit\Framework\TestCase;

class GateEvaluatingTest extends TestCase
{
    public function testGetters(): void
    {
        $gate = $this->createMock(Gate::class);
        $context = new GateContext(
            $this->createMock(State::class),
            new ArrayDelta(['foo' => 'bar'])
        );
        $isActionGate = false;

        $event = new GateEvaluating($gate, $context, $isActionGate);

        $this->assertSame($gate, $event->gate);
        $this->assertSame($context, $event->context);
        $this->assertSame($isActionGate, $event->isActionGate);
    }
}
