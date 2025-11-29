<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    public function testCanBeCreatedWithEmptyArrays(): void
    {
        $config = new Configuration([], []);

        $this->assertSame([], $config->getTransitionGates());
        $this->assertSame([], $config->getActions());
    }

    public function testCanBeCreatedWithTransitionGates(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);
        $gates = [$gate1, $gate2];

        $config = new Configuration($gates, []);

        $this->assertSame($gates, $config->getTransitionGates());
        $this->assertSame([], $config->getActions());
    }

    public function testCanBeCreatedWithActions(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);
        $actions = [$action1, $action2];

        $config = new Configuration([], $actions);

        $this->assertSame([], $config->getTransitionGates());
        $this->assertSame($actions, $config->getActions());
    }

    public function testCanBeCreatedWithBothGatesAndActions(): void
    {
        $gate = $this->createMock(Gate::class);
        $action = $this->createMock(Action::class);
        $gates = [$gate];
        $actions = [$action];

        $config = new Configuration($gates, $actions);

        $this->assertSame($gates, $config->getTransitionGates());
        $this->assertSame($actions, $config->getActions());
    }

}
