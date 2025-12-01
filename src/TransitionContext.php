<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;

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

    public function __construct(private State $initialState)
    {
    }

    public function getCurrentState(): State
    {
        return $this->initialState;
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
}
