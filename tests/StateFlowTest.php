<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests;

use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\StateWorker;
use PHPUnit\Framework\TestCase;

class StateFlowTest extends TestCase
{
    public function testItCanBeInitialised(): void
    {
        $stateFlow = new StateFlow();

        $this->assertInstanceOf(StateFlow::class, $stateFlow);
        $this->assertInstanceOf(StateWorker::class, $stateFlow->transition(new class () implements State {}, []));
    }
}
