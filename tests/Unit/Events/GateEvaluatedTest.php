<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Events\GateEvaluated;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateContext;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\State;
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
