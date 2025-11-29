<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\StateWorker;
use PHPUnit\Framework\TestCase;

class StateFlowTest extends TestCase
{
    public function testItCanBeInitialised(): void
    {
        $stateFlow = new StateFlow(fn () => new Configuration([], []));

        $this->assertInstanceOf(StateFlow::class, $stateFlow);
        $this->assertInstanceOf(
            StateWorker::class,
            $stateFlow->transition($this->createMock(State::class), [])
        );
    }
}
