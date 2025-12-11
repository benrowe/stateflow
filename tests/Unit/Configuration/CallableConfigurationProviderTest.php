<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\CallableConfigurationProvider;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Delta;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class CallableConfigurationProviderTest extends TestCase
{
    public function testProvideCallsCallableWithStateAndDelta(): void
    {
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta(['status' => 'active']);
        $expectedConfig = new Configuration([], []);

        $callableInvoked = false;
        $callable = function (State $configState, Delta $configDelta) use ($state, $delta, $expectedConfig, &$callableInvoked) {
            $this->assertSame($state, $configState);
            $this->assertSame($delta, $configDelta);
            $callableInvoked = true;

            return $expectedConfig;
        };

        $provider = new CallableConfigurationProvider($callable);
        $result = $provider->provide($state, $delta);

        $this->assertTrue($callableInvoked);
        $this->assertSame($expectedConfig, $result);
    }

    public function testProvideReturnsConfigurationFromCallable(): void
    {
        $state = $this->createMock(State::class);
        $gate = $this->createMock(Gate::class);
        $action = $this->createMock(Action::class);

        $callable = fn (State $s, Delta $d) => new Configuration([$gate], [$action]);

        $provider = new CallableConfigurationProvider($callable);
        $config = $provider->provide($state, new ArrayDelta(['foo' => 'bar']));

        $this->assertCount(1, $config->transitionGates);
        $this->assertCount(1, $config->actions);
        $this->assertSame($gate, $config->transitionGates->toArray()[0]);
        $this->assertSame($action, $config->actions->toArray()[0]);
    }

    public function testProvideWithDifferentStatesReturnsDifferentConfigurations(): void
    {
        $state1 = $this->createMock(State::class);
        $state1->method('toArray')->willReturn(['status' => 'pending']);

        $state2 = $this->createMock(State::class);
        $state2->method('toArray')->willReturn(['status' => 'active']);

        $pendingGate = $this->createMock(Gate::class);
        $activeGate = $this->createMock(Gate::class);

        $callable = function (State $state, Delta $delta) use ($pendingGate, $activeGate) {
            $stateData = $state->toArray();
            if ($stateData['status'] === 'pending') {
                return new Configuration([$pendingGate], []);
            }

            return new Configuration([$activeGate], []);
        };

        $provider = new CallableConfigurationProvider($callable);

        $config1 = $provider->provide($state1, new ArrayDelta([]));
        $config2 = $provider->provide($state2, new ArrayDelta([]));

        $this->assertSame($pendingGate, $config1->transitionGates->toArray()[0]);
        $this->assertSame($activeGate, $config2->transitionGates->toArray()[0]);
    }

    public function testProvideWithDifferentDeltasReturnsDifferentConfigurations(): void
    {
        $state = $this->createMock(State::class);
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $callable = function (State $state, Delta $delta) use ($action1, $action2) {
            if ($delta->has('priority') && $delta->get('priority') === 'high') {
                return new Configuration([], [$action1]);
            }

            return new Configuration([], [$action2]);
        };

        $provider = new CallableConfigurationProvider($callable);

        $config1 = $provider->provide($state, new ArrayDelta(['priority' => 'high']));
        $config2 = $provider->provide($state, new ArrayDelta(['priority' => 'low']));

        $this->assertSame($action1, $config1->actions->toArray()[0]);
        $this->assertSame($action2, $config2->actions->toArray()[0]);
    }
}
