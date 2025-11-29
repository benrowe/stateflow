<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Configuration\CallableConfigurationProvider;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class CallableConfigurationProviderTest extends TestCase
{
    public function testProvideCallsCallableWithStateAndDelta(): void
    {
        $state = $this->createMock(State::class);
        $delta = ['status' => 'active'];
        $expectedConfig = new Configuration([], []);

        $callableInvoked = false;
        $callable = function (State $configState, array $configDelta) use ($state, $delta, $expectedConfig, &$callableInvoked) {
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

        $callable = fn (State $s, array $d) => new Configuration([$gate], [$action]);

        $provider = new CallableConfigurationProvider($callable);
        $config = $provider->provide($state, ['foo' => 'bar']);

        $this->assertCount(1, $config->getTransitionGates());
        $this->assertCount(1, $config->getActions());
        $this->assertSame($gate, $config->getTransitionGates()[0]);
        $this->assertSame($action, $config->getActions()[0]);
    }

    public function testProvideWithDifferentStatesReturnsDifferentConfigurations(): void
    {
        $state1 = $this->createMock(State::class);
        $state1->method('toArray')->willReturn(['status' => 'pending']);

        $state2 = $this->createMock(State::class);
        $state2->method('toArray')->willReturn(['status' => 'active']);

        $pendingGate = $this->createMock(Gate::class);
        $activeGate = $this->createMock(Gate::class);

        $callable = function (State $state, array $delta) use ($pendingGate, $activeGate) {
            $stateData = $state->toArray();
            if ($stateData['status'] === 'pending') {
                return new Configuration([$pendingGate], []);
            }

            return new Configuration([$activeGate], []);
        };

        $provider = new CallableConfigurationProvider($callable);

        $config1 = $provider->provide($state1, []);
        $config2 = $provider->provide($state2, []);

        $this->assertSame($pendingGate, $config1->getTransitionGates()[0]);
        $this->assertSame($activeGate, $config2->getTransitionGates()[0]);
    }

    public function testProvideWithDifferentDeltasReturnsDifferentConfigurations(): void
    {
        $state = $this->createMock(State::class);
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $callable = function (State $state, array $delta) use ($action1, $action2) {
            if (isset($delta['priority']) && $delta['priority'] === 'high') {
                return new Configuration([], [$action1]);
            }

            return new Configuration([], [$action2]);
        };

        $provider = new CallableConfigurationProvider($callable);

        $config1 = $provider->provide($state, ['priority' => 'high']);
        $config2 = $provider->provide($state, ['priority' => 'low']);

        $this->assertSame($action1, $config1->getActions()[0]);
        $this->assertSame($action2, $config2->getActions()[0]);
    }
}
