<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Configuration\CallableConfigurationProvider;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Configuration\ConfigurationProvider;
use Closure;

/**
 * StateFlow - A flexible state machine implementation
 */
class StateFlow
{
    public function __construct(private Closure|ConfigurationProvider $configProvider)
    {
    }

    /**
     * @param array<string, mixed> $delta
     */
    public function transition(State $currentState, array $delta): StateWorker
    {
        $context = new TransitionContext($currentState);

        return new StateWorker($context, $this->resolveConfig($currentState, $delta));
    }

    /**
     * @param array<string, mixed> $delta
     */
    private function resolveConfig(State $currentState, array $delta): Configuration
    {
        return $this
            ->resolveProvider()
            ->provide($currentState, $delta);
    }

    private function resolveProvider(): ConfigurationProvider
    {
        $provider = $this->configProvider;

        return $provider instanceof Closure ? new CallableConfigurationProvider($provider) : $provider;
    }
}
