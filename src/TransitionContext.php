<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ActionResultCollection;
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

    public function getActionExecutions(): ActionResultCollection
    {
        return $this->history->getActionExecutions();
    }

    public function addActionResult(ActionResult $actionResult): void
    {
        $this->history = $this->history->recordActionExecution($actionResult);
    }

    public function getGateEvaluations(): GateEvaluationCollection
    {
        return $this->history->getGateEvaluations();
    }

    public function addGateEvaluation(Gate $gate, GateResult $result, bool $isActionGate): void
    {
        $this->history = $this->history->recordGateEvaluation($gate, $result, $isActionGate);
    }

    public function didGatesPass(): bool
    {
        $availableGates = $this->getConfiguration()->transitionGates->count();
        if ($availableGates === 0) {
            return true;
        }

        if ($availableGates !== count($this->getGateEvaluations())) {
            return false;
        }
        foreach ($this->getGateEvaluations()->toArray() as $gateEvaluation) {
            if ($gateEvaluation->result !== GateResult::ALLOW) {
                return false;
            }
        }

        return true;
    }

    public function getActionSkips(): ActionSkipCollection
    {
        return $this->history->getActionSkips();
    }

    public function addActionSkip(Action $action, GateResult $gateResult): void
    {
        $this->history = $this->history->recordActionSkip($action, $gateResult);
    }

    public function markAsCompleted(): void
    {
        $this->executionStatus->markCompleted();
    }

    public function markAsPaused(mixed $metadata = null): void
    {
        $this->executionStatus->markPaused($metadata);
    }

    public function markAsStopped(mixed $metadata = null): void
    {
        $this->executionStatus->markStopped($metadata);
    }

    public function clearPauseStatus(): void
    {
        $this->executionStatus->clearPauseStatus();
    }

    public function isPaused(): bool
    {
        return $this->executionStatus->isPaused();
    }

    public function isCompleted(): bool
    {
        return $this->executionStatus->isCompleted();
    }

    public function isStopped(): bool
    {
        return $this->executionStatus->isStopped();
    }

    public function getLockState(): LockState
    {
        return $this->lockState;
    }

    public function setLockState(LockState $lockState): void
    {
        $this->lockState = $lockState;
    }

    public function markAsSkippedDueToLock(): void
    {
        $this->executionStatus->markSkippedDueToLock();
    }

    public function wasSkippedDueToLock(): bool
    {
        return $this->executionStatus->wasSkippedDueToLock();
    }

    /**
     * Get metadata from the current execution status.
     *
     * Returns the metadata provided when marking as paused or stopped.
     * If no metadata was provided to ExecutionStatus, falls back to history.
     */
    public function getStatusMetadata(): mixed
    {
        $statusMetadata = $this->executionStatus->getMetadata();
        if ($statusMetadata !== null) {
            return $statusMetadata;
        }

        // Fallback to history for backward compatibility
        return $this->history->getStatusMetadata();
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

    /**
     * Serialize the context to a JSON string for persistence.
     */
    public function serialize(): string
    {
        return (new TransitionContextSerializer())->serialize($this);
    }

    /**
     * Deserialize a context from a JSON string.
     *
     * @param string $data
     * @param StateFactory $stateFactory
     * @param ActionFactory $actionFactory
     * @param GateFactory $gateFactory
     * @throws JsonException
     * @return self
     */
    public static function unserialize(
        string $data,
        StateFactory $stateFactory,
        ActionFactory $actionFactory,
        GateFactory $gateFactory
    ): self {
        return (new TransitionContextSerializer())->unserialize($data, $stateFactory, $actionFactory, $gateFactory);
    }
}
