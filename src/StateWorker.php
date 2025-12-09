<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\ActionExecuted;
use BenRowe\StateFlow\Events\ActionExecuting;
use BenRowe\StateFlow\Events\ActionSkipped;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\GateEvaluated;
use BenRowe\StateFlow\Events\GateEvaluating;
use BenRowe\StateFlow\Events\LockAcquired;
use BenRowe\StateFlow\Events\LockFailed;
use BenRowe\StateFlow\Events\LockReleased;
use BenRowe\StateFlow\Events\TransitionCompleted;
use BenRowe\StateFlow\Events\TransitionFailed;
use BenRowe\StateFlow\Events\TransitionPaused;
use BenRowe\StateFlow\Events\TransitionStopped;
use BenRowe\StateFlow\Exceptions\InvalidGateResultException;
use BenRowe\StateFlow\Exceptions\LockAcquisitionException;
use BenRowe\StateFlow\Exceptions\TransitionException;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;
use BenRowe\StateFlow\Locking\LockConfiguration;
use BenRowe\StateFlow\Locking\LockKeyProvider;
use BenRowe\StateFlow\Locking\LockProvider;
use BenRowe\StateFlow\Locking\LockState;
use BenRowe\StateFlow\Locking\LockStrategy;
use Throwable;
use TypeError;

class StateWorker
{
    private int $nextActionIndex = 0;

    private readonly Configuration $configuration;

    public function __construct(
        private readonly TransitionContext $context,
        private readonly EventDispatcher $eventDispatcher,
        private readonly ?LockProvider $lockProvider = null,
        private readonly ?LockKeyProvider $lockKeyProvider = null,
        private readonly ?LockConfiguration $lockConfiguration = null,
    ) {
        $this->configuration = $this->context->getConfiguration();
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
        return $this->evaluateGates();
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
        $this->acquireLock();

        // If transition was skipped due to lock, return early
        if ($this->context->wasSkippedDueToLock()) {
            return $this->context;
        }

        try {
            // Run transition gates first
            $gateResult = $this->evaluateGates();

            // Skip actions if gates denied or returned SKIP_IDEMPOTENT
            if ($gateResult->shouldSkipAction()) {
                $this->skipAllActions($gateResult);

                // SKIP_IDEMPOTENT should complete successfully (idempotent transitions are considered complete)
                if ($gateResult === GateResult::SKIP_IDEMPOTENT) {
                    $this->context->markAsCompleted();
                    $this->eventDispatcher->dispatch(new TransitionCompleted($this->context->getCurrentState(), $this->context));
                }

                return $this->context;
            }

            // Only run actions if all gates allowed
            $this->executeActions();

            // Mark as completed if we got through all actions
            if (!$this->context->isPaused() && !$this->context->isStopped()) {
                $this->context->markAsCompleted();
                $this->eventDispatcher->dispatch(new TransitionCompleted($this->context->getCurrentState(), $this->context));
            }

            return $this->context;
        } finally {
            // Always release lock if it was acquired (including on exception or pause/stop)
            // Only keep lock if paused (scenario 9.8) - but we haven't implemented that yet
            if ($this->context->getLockState()->isLocked() && !$this->context->isPaused()) {
                $this->releaseLock();
            }
        }
    }

    private function evaluateGates(): GateResult
    {
        $gateContext = new GateContext(
            $this->context->getCurrentState(),
            $this->context->getDesiredDelta()
        );

        foreach ($this->configuration->getTransitionGates() as $gate) {
            // Dispatch GateEvaluating event
            $this->eventDispatcher->dispatch(new GateEvaluating($gate, $gateContext, false));

            $result = $this->evaluateGate($gate, $gateContext);

            // Dispatch GateEvaluated event
            $this->eventDispatcher->dispatch(new GateEvaluated($gate, $gateContext, $result, false));

            // Track the gate evaluation
            $this->context->addGateEvaluation($gate, $result, false);

            // Short-circuit if gate denies or skips
            if ($result->shouldSkipAction()) {
                return $result;
            }
        }

        return GateResult::ALLOW;
    }

    private function executeActions(): void
    {
        $actions = $this->configuration->getActions();
        $actionCount = count($actions);

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

        $actions = $this->configuration->getActions();

        // If we've already run all actions, do nothing
        if ($this->nextActionIndex >= count($actions)) {
            return;
        }

        $action = $actions[$this->nextActionIndex];
        $this->nextActionIndex++;

        // Check if action has a gate (implements Guardable)
        if ($action instanceof Guardable) {
            $gate = $action->gate();
            $gateContext = new GateContext(
                $this->context->getCurrentState(),
                $this->context->getDesiredDelta()
            );

            // Dispatch GateEvaluating event (isActionGate=true)
            $this->eventDispatcher->dispatch(new GateEvaluating($gate, $gateContext, true));

            $gateResult = $this->evaluateGate($gate, $gateContext);

            // Dispatch GateEvaluated event (isActionGate=true)
            $this->eventDispatcher->dispatch(new GateEvaluated($gate, $gateContext, $gateResult, true));

            // Track the gate evaluation with isActionGate=true
            $this->context->addGateEvaluation($gate, $gateResult, true);

            // Skip action if gate denies or returns SKIP_IDEMPOTENT
            if ($gateResult->shouldSkipAction()) {
                $this->context->addActionSkip($action, $gateResult);
                // Dispatch ActionSkipped event
                $this->eventDispatcher->dispatch(new ActionSkipped($action, $gateResult));

                return;
            }
        }

        // Execute the action
        $context = new ActionContext(
            $this->context->getCurrentState(),
            $this->context->getDesiredDelta(),
            $this->context
        );

        // Dispatch ActionExecuting event
        $this->eventDispatcher->dispatch(new ActionExecuting($action, $context));

        try {
            $result = $action->execute($context);
        } catch (Throwable $exception) {
            // Dispatch TransitionFailed event
            $this->eventDispatcher->dispatch(new TransitionFailed($this->context->getCurrentState(), $exception, $this->context));
            throw $exception;
        }

        // Dispatch ActionExecuted event
        $this->eventDispatcher->dispatch(new ActionExecuted($action, $context, $result));

        $this->context->addActionResult($result);

        // Update current state if action returned a new state
        if ($result->newState !== null) {
            $this->context->updateCurrentState($result->newState);
        }

        // Update status if action paused or stopped
        if ($result->executionState === ExecutionState::PAUSE) {
            $this->context->markAsPaused();
            $this->eventDispatcher->dispatch(new TransitionPaused($this->context->getCurrentState(), $this->context, $result->metadata));
        }

        if ($result->executionState === ExecutionState::STOP) {
            $this->context->markAsStopped();
            $this->eventDispatcher->dispatch(new TransitionStopped($this->context->getCurrentState(), $this->context, $result->metadata));
        }
    }

    private function skipAllActions(GateResult $reason): void
    {
        foreach ($this->configuration->getActions() as $action) {
            $this->context->addActionSkip($action, $reason);
            // Dispatch ActionSkipped event
            $this->eventDispatcher->dispatch(new ActionSkipped($action, $reason));
        }
    }

    private function evaluateGate(Gate $gate, GateContext $context): GateResult
    {
        try {
            $result = $gate->evaluate($context);
        } catch (TypeError $exception) {
            throw new InvalidGateResultException(
                sprintf(
                    'Gate %s must return a %s, invalid return type encountered',
                    get_debug_type($gate),
                    GateResult::class
                ),
                0,
                $exception
            );
        }

        return $result;
    }

    private function acquireLock(): void
    {
        // Skip if no locking configured or strategy is NONE
        if (
            $this->lockProvider === null
            || $this->lockKeyProvider === null
            || $this->lockConfiguration === null
            || $this->lockConfiguration->strategy === LockStrategy::NONE
        ) {
            return;
        }

        // Generate lock key
        $lockKey = $this->lockKeyProvider->getLockKey(
            $this->context->getCurrentState(),
            $this->context->getDesiredDelta()
        );

        // Attempt to acquire lock
        $acquired = $this->lockProvider->acquire($lockKey, $this->lockConfiguration->ttl);

        if ($acquired) {
            // Store lock state in context
            $lockState = new LockState($lockKey, microtime(true), $this->lockConfiguration->ttl);
            $this->context->setLockState($lockState);

            // Dispatch LockAcquired event
            $this->eventDispatcher->dispatch(new LockAcquired($lockKey, $lockState));

            return;
        }

        // Handle lock acquisition failure based on strategy
        match ($this->lockConfiguration->strategy) {
            LockStrategy::FAIL_FAST => $this->handleFailFast($lockKey),
            LockStrategy::SKIP => $this->handleSkip($lockKey),
            LockStrategy::WAIT => $this->handleWait($lockKey),
            LockStrategy::NONE => null, // @phpstan-ignore-line This case is unreachable but needed for match exhaustiveness
        };
    }

    private function handleFailFast(string $lockKey): void
    {
        // Dispatch LockFailed event
        $this->eventDispatcher->dispatch(
            new LockFailed(
                $lockKey,
                $this->context->getCurrentState(),
                'Lock is already held by another process'
            )
        );

        // Throw exception to stop execution
        throw new LockAcquisitionException(
            sprintf('Failed to acquire lock for key: %s', $lockKey)
        );
    }

    private function handleSkip(string $lockKey): void
    {
        // Dispatch LockFailed event
        $this->eventDispatcher->dispatch(
            new LockFailed(
                $lockKey,
                $this->context->getCurrentState(),
                'Lock is already held by another process'
            )
        );

        // Mark context as skipped due to lock
        $this->context->markAsSkippedDueToLock();
    }

    private function handleWait(string $lockKey): void
    {
        assert($this->lockProvider !== null);
        assert($this->lockConfiguration !== null);

        $startTime = microtime(true);
        $timeoutSeconds = $this->lockConfiguration->waitTimeout;
        $retryIntervalMs = $this->lockConfiguration->retryInterval;

        // Keep retrying until timeout
        while (true) {
            // Check if timeout has been reached
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $timeoutSeconds) {
                // Dispatch LockFailed event
                $this->eventDispatcher->dispatch(
                    new LockFailed(
                        $lockKey,
                        $this->context->getCurrentState(),
                        sprintf('Failed to acquire lock after %.2f seconds', $elapsed)
                    )
                );

                throw new LockAcquisitionException(
                    sprintf('Failed to acquire lock for key: %s after %.2f seconds', $lockKey, $elapsed)
                );
            }

            // Sleep before retrying
            usleep($retryIntervalMs * 1000); // Convert milliseconds to microseconds

            // Try to acquire lock again
            $acquired = $this->lockProvider->acquire($lockKey, $this->lockConfiguration->ttl);

            if ($acquired) {
                // Store lock state in context
                $lockState = new LockState($lockKey, microtime(true), $this->lockConfiguration->ttl);
                $this->context->setLockState($lockState);

                // Dispatch LockAcquired event
                $this->eventDispatcher->dispatch(new LockAcquired($lockKey, $lockState));

                return;
            }
        }
    }

    private function releaseLock(): void
    {
        // Skip if no locking configured or no lock was acquired
        if (
            $this->lockProvider === null
            || $this->lockKeyProvider === null
            || !$this->context->getLockState()->isLocked()
        ) {
            return;
        }

        $lockState = $this->context->getLockState();
        $lockKey = $lockState->lockKey;

        assert($lockKey !== null);

        // Release the lock
        $this->lockProvider->release($lockKey);

        // Dispatch LockReleased event
        $this->eventDispatcher->dispatch(
            new LockReleased($lockKey, $this->context->getCurrentState())
        );
    }
}
