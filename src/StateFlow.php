<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Configuration\CallableConfigurationProvider;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Configuration\ConfigurationProvider;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\NullEventDispatcher;
use BenRowe\StateFlow\Events\TransitionStarting;
use BenRowe\StateFlow\Locking\LockContext;
use Closure;

/**
 * StateFlow - A flexible state machine implementation
 */
class StateFlow
{
    private readonly EventDispatcher $eventDispatcher;

    public function __construct(
        private readonly Closure|ConfigurationProvider $configProvider,
        ?EventDispatcher $eventDispatcher = null,
        private readonly ?LockContext $lockContext = null,
    ) {
        $this->eventDispatcher = $eventDispatcher ?? new NullEventDispatcher();
    }

    public function transition(State $currentState, Delta $delta): StateWorker
    {
        $this->eventDispatcher->dispatch(new TransitionStarting($currentState, $delta));

        $configuration = $this->resolveConfig($currentState, $delta);
        $context = new TransitionContext($currentState, $delta, $configuration);

        return new StateWorker(
            $context,
            $this->eventDispatcher,
            $this->lockContext
        );
    }

    /**
     * Resume a paused workflow from an existing context
     */
    public function fromContext(TransitionContext $context): StateWorker
    {
        // Clear pause status to allow resumption (STOP cannot be resumed)
        $context->executionStatus()->clearPauseStatus();

        // Create worker with existing context and set starting action index
        $worker = new StateWorker(
            $context,
            $this->eventDispatcher,
            $this->lockContext
        );

        // Resume from where we left off - a held yield cursor resumes at its own index
        $worker->setNextActionIndex($context->executionHistory()->getResumeActionIndex());

        return $worker;
    }

    private function resolveConfig(State $currentState, Delta $delta): Configuration
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
