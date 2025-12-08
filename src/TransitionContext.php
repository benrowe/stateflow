<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Locking\LockState;

class TransitionContext
{
    /**
     * @var ActionResult[]
     */
    private array $actions = [];

    /**
     * @var GateEvaluation[]
     */
    private array $gateEvaluations = [];

    /**
     * @var ActionSkip[]
     */
    private array $actionSkips = [];

    private ?ExecutionState $status = null;

    private State $currentState;

    private LockState $lockState;

    private bool $skippedDueToLock = false;

    /**
     * @param array<string, mixed> $desiredDelta
     */
    public function __construct(
        private readonly State $initialState,
        private readonly array $desiredDelta,
        private readonly Configuration $configuration,
    ) {
        $this->currentState = $initialState;
        $this->lockState = new LockState();
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getCurrentState(): State
    {
        return $this->currentState;
    }

    public function getInitialState(): State
    {
        return $this->initialState;
    }

    public function updateCurrentState(State $newState): void
    {
        $this->currentState = $newState;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDesiredDelta(): array
    {
        return $this->desiredDelta;
    }

    /**
     * @return ActionResult[]
     */
    public function getActionExecutions(): array
    {
        return $this->actions;
    }

    public function addActionResult(ActionResult $actionResult): void
    {
        $this->actions[] = $actionResult;
    }

    /**
     * @return GateEvaluation[]
     */
    public function getGateEvaluations(): array
    {
        return $this->gateEvaluations;
    }

    public function addGateEvaluation(Gate $gate, GateResult $result, bool $isActionGate): void
    {
        $this->gateEvaluations[] = new GateEvaluation($gate, $result, $isActionGate);
    }

    public function didGatesPass(): bool
    {
        $availableGates = count($this->getConfiguration()->getTransitionGates());
        if ($availableGates === 0) {
            return true;
        }

        if ($availableGates !== count($this->getGateEvaluations())) {
            return false;
        }
        foreach ($this->getGateEvaluations() as $gateEvaluation) {
            if ($gateEvaluation->result !== GateResult::ALLOW) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return ActionSkip[]
     */
    public function getActionSkips(): array
    {
        return $this->actionSkips;
    }

    public function addActionSkip(Action $action, GateResult $gateResult): void
    {
        $this->actionSkips[] = new ActionSkip($action, $gateResult);
    }

    public function markAsCompleted(): void
    {
        $this->status = ExecutionState::CONTINUE;
    }

    public function markAsPaused(): void
    {
        $this->status = ExecutionState::PAUSE;
    }

    public function markAsStopped(): void
    {
        $this->status = ExecutionState::STOP;
    }

    public function clearPauseStatus(): void
    {
        if ($this->status === ExecutionState::PAUSE) {
            $this->status = null;
        }
    }

    public function isPaused(): bool
    {
        return $this->status === ExecutionState::PAUSE;
    }

    public function isCompleted(): bool
    {
        return $this->status === ExecutionState::CONTINUE;
    }

    public function isStopped(): bool
    {
        return $this->status === ExecutionState::STOP;
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
        $this->skippedDueToLock = true;
    }

    public function wasSkippedDueToLock(): bool
    {
        return $this->skippedDueToLock;
    }
}
