<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionCollection;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Exceptions\InvalidConfigurationException;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateCollection;
use PHPUnit\Framework\TestCase;
use stdClass;

class ConfigurationTest extends TestCase
{
    public function testCanBeCreatedWithEmptyArrays(): void
    {
        $config = new Configuration([], []);

        $this->assertSame([], $config->transitionGates->toArray());
        $this->assertSame([], $config->actions->toArray());
    }

    public function testCanBeCreatedWithTransitionGates(): void
    {
        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);
        $gates = [$gate1, $gate2];

        $config = new Configuration($gates, []);

        $this->assertSame($gates, $config->transitionGates->toArray());
        $this->assertSame([], $config->actions->toArray());
    }

    public function testCanBeCreatedWithActions(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);
        $actions = [$action1, $action2];

        $config = new Configuration([], $actions);

        $this->assertSame([], $config->transitionGates->toArray());
        $this->assertSame($actions, $config->actions->toArray());
    }

    public function testCanBeCreatedWithBothGatesAndActions(): void
    {
        $gate = $this->createMock(Gate::class);
        $action = $this->createMock(Action::class);
        $gates = [$gate];
        $actions = [$action];

        $config = new Configuration($gates, $actions);

        $this->assertSame($gates, $config->transitionGates->toArray());
        $this->assertSame($actions, $config->actions->toArray());
    }

    public function testGateConfigIsValidated(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('Gate at index 0 must implement %s, %s given', Gate::class, stdClass::class));

        new Configuration([new stdClass()], []);
    }

    public function testActionsConfigIsValidated(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('Action at index %d must implement %s, %s given', 0, Action::class, stdClass::class));

        new Configuration([], [new stdClass()]);
    }

    public function testCanCreateConfigWithCollections(): void
    {
        $gates = GateCollection::empty();
        $gates->add($this->createMock(Gate::class));

        $actions = ActionCollection::empty();
        $actions->add($this->createMock(Action::class));

        $config = new Configuration($gates, $actions);
        $this->assertSame($gates, $config->transitionGates);
        $this->assertSame($actions, $config->actions);
    }
}
