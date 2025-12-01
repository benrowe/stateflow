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
        $configuration = $this->resolveConfig($currentState, $delta);
        $context = new TransitionContext($currentState, $delta, $configuration);

        return new StateWorker($context);
    }

    /**
     * Resume a paused workflow from an existing context
     */
    public function fromContext(TransitionContext $context): StateWorker
    {
        // Clear pause status to allow resumption (STOP cannot be resumed)
        $context->clearPauseStatus();

        // Create worker with existing context and set starting action index
        $worker = new StateWorker($context);

        // Resume from where we left off - count already executed actions
        $executedActionCount = count($context->getActionExecutions());
        $worker->setNextActionIndex($executedActionCount);

        return $worker;
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
