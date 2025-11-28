<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests;

use BenRowe\StateFlow\StateFlow;
use PHPUnit\Framework\TestCase;

class StateFlowTest extends TestCase
{
    public function test_it_can_be_created_with_initial_state(): void
    {
        $stateFlow = new StateFlow();

        $this->assertInstanceOf(StateFlow::class, $stateFlow);
    }
}
