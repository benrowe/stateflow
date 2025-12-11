<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Events\GateEvaluated;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class GateEvaluatedTest extends TestCase
{
    public function testGetters(): void
    {
        $gate = $this->createMock(Gate::class);
        $context = new GateContext(
            $this->createMock(State::class),
            new ArrayDelta(['foo' => 'bar'])
        );
        $result = GateResult::ALLOW;
        $isActionGate = true;

        $event = new GateEvaluated($gate, $context, $result, $isActionGate);

        $this->assertSame($gate, $event->gate);
        $this->assertSame($context, $event->context);
        $this->assertSame($result, $event->result);
        $this->assertSame($isActionGate, $event->isActionGate);
    }
}
