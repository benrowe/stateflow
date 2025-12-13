<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\ActionExecutionService;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\EventOrchestrator;
use BenRowe\StateFlow\Exceptions\TransitionException;
use BenRowe\StateFlow\Gate\GateEvaluationService;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Locking\LockConfiguration;
use BenRowe\StateFlow\Locking\LockKeyProvider;
use BenRowe\StateFlow\Locking\LockManager;
use BenRowe\StateFlow\Locking\LockProvider;

class StateWorker
{
    private int $nextActionIndex = 0;

    private readonly Configuration $configuration;

    private readonly LockManager $lockManager;

    private readonly EventOrchestrator $eventOrchestrator;

    private readonly GateEvaluationService $gateEvaluator;

    private readonly ActionExecutionService $actionExecutor;

    public function __construct(
        private readonly TransitionContext $context,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ?LockProvider $lockProvider = null,
        private readonly ?LockKeyProvider $lockKeyProvider = null,
        private readonly ?LockConfiguration $lockConfiguration = null,
    ) {
        $this->configuration = $this->context->getConfiguration();

        // Create event orchestrator
        $this->eventOrchestrator = new EventOrchestrator($this->eventDispatcher);

        // Create lock manager
        $this->lockManager = new LockManager(
            $this->lockProvider,
            $this->lockKeyProvider,
            $this->lockConfiguration,
            $this->context,
            $this->eventDispatcher
        );

        // Create gate evaluator
        $this->gateEvaluator = new GateEvaluationService($this->eventOrchestrator);

        // Create action executor
        $this->actionExecutor = new ActionExecutionService($this->eventOrchestrator, $this->gateEvaluator);
    }

    public function getContext(): TransitionContext
    {
        return $this->context;
    }

    public function setNextActionIndex(int $index): void
    {
        $this->nextActionIndex = $index;
    }

    public function runGates(): GateResult
    {
        return $this->gateEvaluator->evaluateTransitionGates(
            $this->configuration->transitionGates,
            $this->context
        );
    }

    public function runActions(): TransitionContext
    {
        if (!$this->context->didGatesPass()) {
            throw new TransitionException('Gates not evaluated');
        }

        $this->executeActions();

        // Mark as completed if we got through all actions
        if (!$this->context->isPaused() && !$this->context->isStopped()) {
            $this->context->markAsCompleted();
        }

        return $this->context;
    }

    public function runNextAction(): TransitionContext
    {
        // Execute the next action if there is one
        $this->executeNextAction();

        return $this->context;
    }

    public function execute(): TransitionContext
    {
        // Acquire lock if locking is configured
        $this->lockManager->acquireLock();

        // If transition was skipped due to lock, return early
        if ($this->context->wasSkippedDueToLock()) {
            return $this->context;
        }

        try {
            // Run transition gates first
            $gateResult = $this->gateEvaluator->evaluateTransitionGates(
                $this->configuration->transitionGates,
                $this->context
            );

            // Skip actions if gates denied or returned SKIP_IDEMPOTENT
            if ($gateResult->shouldSkipAction()) {
                $this->actionExecutor->skipAllActions(
                    $this->configuration->actions,
                    $gateResult,
                    $this->context
                );

                // SKIP_IDEMPOTENT should complete successfully (idempotent transitions are considered complete)
                if ($gateResult === GateResult::SKIP_IDEMPOTENT) {
                    $this->context->markAsCompleted();
                    $this->eventOrchestrator->transitionCompleted(
                        $this->context->getCurrentState(),
                        $this->context
                    );
                }

                return $this->context;
            }

            // Only run actions if all gates allowed
            $this->executeActions();

            // Mark as completed if we got through all actions
            if (!$this->context->isPaused() && !$this->context->isStopped()) {
                $this->context->markAsCompleted();
                $this->eventOrchestrator->transitionCompleted(
                    $this->context->getCurrentState(),
                    $this->context
                );
            }

            return $this->context;
        } finally {
            // Always release lock if it was acquired (including on exception or pause/stop)
            // Only keep lock if paused (scenario 9.8) - but we haven't implemented that yet
            if ($this->context->getLockState()->isLocked() && !$this->context->isPaused()) {
                $this->lockManager->releaseLock();
            }
        }
    }

    private function executeActions(): void
    {
        $actionCount = $this->configuration->actions->count();

        // Execute remaining actions using runNextAction logic
        while ($this->nextActionIndex < $actionCount) {
            // Execute the next action
            $this->executeNextAction();

            // Stop if workflow is paused or stopped
            if ($this->context->isPaused() || $this->context->isStopped()) {
                break;
            }
        }
    }

    private function executeNextAction(): void
    {
        // Don't execute if workflow is stopped (PAUSE can be resumed)
        if ($this->context->isStopped()) {
            return;
        }

        // Check if lock is still held (detect if it was lost/expired)
        $this->lockManager->checkLockStillHeld();

        // Renew lock if it's about to expire
        $this->lockManager->renewLockIfNeeded();

        $actions = $this->configuration->actions->toArray();

        // If we've already run all actions, do nothing
        if ($this->nextActionIndex >= count($actions)) {
            return;
        }

        $action = $actions[$this->nextActionIndex];
        $this->nextActionIndex++;

        $this->actionExecutor->executeAction($action, $this->context);
    }
}
