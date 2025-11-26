<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests;

use BenRowe\StateFlow\StateMachine;
use PHPUnit\Framework\TestCase;

class StateMachineTest extends TestCase
{
    public function test_it_can_be_created_with_initial_state(): void
    {
        $sm = new StateMachine();

        $this->assertInstanceOf(StateMachine::class, $sm);
    }
}
