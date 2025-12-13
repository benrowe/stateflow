<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Locking\LockState;
use JsonException;

class TransitionContext
{
    private ExecutionHistory $history;

    private ExecutionStatus $executionStatus;

    private LockState $lockState;

    public function __construct(
        private readonly State $initialState,
        private readonly Delta $desiredDelta,
        private readonly Configuration $configuration,
    ) {
        $this->history = new ExecutionHistory($initialState);
        $this->executionStatus = new ExecutionStatus();
        $this->lockState = new LockState();
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getCurrentState(): State
    {
        return $this->history->getCurrentState();
    }

    public function getInitialState(): State
    {
        return $this->initialState;
    }

    public function updateCurrentState(State $newState): void
    {
        $this->history = $this->history->updateCurrentState($newState);
    }

    public function getDesiredDelta(): Delta
    {
        return $this->desiredDelta;
    }

    /**
     * Get the execution history for reading action executions, gate evaluations, and skips.
     */
    public function executionHistory(): ExecutionHistory
    {
        return $this->history;
    }

    /**
     * Record an action execution result.
     *
     * This is a mutation wrapper that handles ExecutionHistory's immutability internally.
     */
    public function recordActionExecution(ActionResult $actionResult): void
    {
        $this->history = $this->history->recordActionExecution($actionResult);
    }

    /**
     * Record a gate evaluation result.
     *
     * This is a mutation wrapper that handles ExecutionHistory's immutability internally.
     */
    public function recordGateEvaluation(Gate $gate, GateResult $result, bool $isActionGate): void
    {
        $this->history = $this->history->recordGateEvaluation($gate, $result, $isActionGate);
    }

    /**
     * Check if all transition gates passed.
     *
     * Returns true if there are no gates, or if all gates evaluated to ALLOW.
     */
    public function didGatesPass(): bool
    {
        $availableGates = $this->getConfiguration()->transitionGates->count();
        if ($availableGates === 0) {
            return true;
        }

        if ($availableGates !== count($this->history->getGateEvaluations())) {
            return false;
        }
        foreach ($this->history->getGateEvaluations()->toArray() as $gateEvaluation) {
            if ($gateEvaluation->result !== GateResult::ALLOW) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record an action skip.
     *
     * This is a mutation wrapper that handles ExecutionHistory's immutability internally.
     */
    public function recordActionSkip(Action $action, GateResult $gateResult): void
    {
        $this->history = $this->history->recordActionSkip($action, $gateResult);
    }

    /**
     * Get the execution status for checking and mutating workflow state.
     */
    public function executionStatus(): ExecutionStatus
    {
        return $this->executionStatus;
    }

    /**
     * Get the lock state.
     */
    public function lockState(): LockState
    {
        return $this->lockState;
    }

    /**
     * Set the lock state.
     *
     * @internal Used by LockManager
     */
    public function setLockState(LockState $lockState): void
    {
        $this->lockState = $lockState;
    }

    /**
     * Get metadata from the current execution status.
     *
     * Returns the metadata provided when marking as paused or stopped.
     * If no metadata was provided to ExecutionStatus, falls back to history.
     */
    public function getStatusMetadata(): mixed
    {
        $statusMetadata = $this->executionStatus()->getMetadata();
        if ($statusMetadata !== null) {
            return $statusMetadata;
        }

        // Fallback to history for backward compatibility
        return $this->executionHistory()->getStatusMetadata();
    }

    /**
     * Set the execution history (used during deserialization)
     *
     * @internal
     */
    public function setExecutionHistory(ExecutionHistory $history): void
    {
        $this->history = $history;
    }
}
