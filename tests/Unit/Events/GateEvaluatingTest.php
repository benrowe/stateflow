<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Events\GateEvaluating;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class GateEvaluatingTest extends TestCase
{
    public function testGetters(): void
    {
        $gate = $this->createMock(Gate::class);
        $context = new GateContext(
            $this->createMock(State::class),
            ['foo' => 'bar']
        );
        $isActionGate = false;

        $event = new GateEvaluating($gate, $context, $isActionGate);

        $this->assertSame($gate, $event->gate);
        $this->assertSame($context, $event->context);
        $this->assertSame($isActionGate, $event->isActionGate);
    }
}
