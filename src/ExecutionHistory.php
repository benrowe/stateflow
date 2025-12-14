<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ActionResultCollection;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;

/**
 * Immutable execution history tracking for state transitions.
 *
 * Tracks gate evaluations, action executions, and action skips throughout a transition's lifecycle.
 * Follows an immutable pattern - all recording methods return new instances.
 */
final readonly class ExecutionHistory
{
    private State $initialState;

    private State $currentState;

    private GateEvaluationCollection $gateEvaluations;

    private ActionResultCollection $actionExecutions;

    private ActionSkipCollection $actionSkips;

    /**
     * Create a new execution history.
     *
     * @param State $initialState The initial state at the start of the transition
     * @param GateEvaluationCollection|null $gateEvaluations Optional gate evaluations (for reconstruction)
     * @param ActionResultCollection|null $actionExecutions Optional action executions (for reconstruction)
     * @param ActionSkipCollection|null $actionSkips Optional action skips (for reconstruction)
     * @param State|null $currentState Optional current state (defaults to initial state)
     */
    public function __construct(
        State $initialState,
        ?GateEvaluationCollection $gateEvaluations = null,
        ?ActionResultCollection $actionExecutions = null,
        ?ActionSkipCollection $actionSkips = null,
        ?State $currentState = null
    ) {
        $this->initialState = $initialState;
        $this->currentState = $currentState ?? $initialState;
        $this->gateEvaluations = $gateEvaluations ?? GateEvaluationCollection::empty();
        $this->actionExecutions = $actionExecutions ?? ActionResultCollection::empty();
        $this->actionSkips = $actionSkips ?? ActionSkipCollection::empty();
    }

    /**
     * Get the initial state at the start of the transition.
     */
    public function getInitialState(): State
    {
        return $this->initialState;
    }

    /**
     * Get the current state (updated by action executions).
     */
    public function getCurrentState(): State
    {
        return $this->currentState;
    }

    /**
     * Update the current state and return a new history instance.
     *
     * @param State $newState The new current state
     * @return self New instance with updated state
     */
    public function updateCurrentState(State $newState): self
    {
        return new self(
            $this->initialState,
            $this->gateEvaluations,
            $this->actionExecutions,
            $this->actionSkips,
            $newState
        );
    }

    /**
     * Record a gate evaluation and return a new history instance.
     *
     * @param Gate $gate The gate that was evaluated
     * @param GateResult $result The result of the evaluation
     * @param bool $isActionGate Whether this is an action gate (vs. transition gate)
     * @return self New instance with recorded evaluation
     */
    public function recordGateEvaluation(Gate $gate, GateResult $result, bool $isActionGate): self
    {
        $evaluation = new GateEvaluation($gate, $result, $isActionGate);
        $newGateEvaluations = $this->gateEvaluations->with($evaluation);

        return new self(
            $this->initialState,
            $newGateEvaluations,
            $this->actionExecutions,
            $this->actionSkips,
            $this->currentState
        );
    }

    /**
     * Get all gate evaluations recorded during this transition.
     */
    public function getGateEvaluations(): GateEvaluationCollection
    {
        return $this->gateEvaluations;
    }

    /**
     * Record an action execution and return a new history instance.
     *
     * @param ActionResult $result The action execution result
     * @return self New instance with recorded execution
     */
    public function recordActionExecution(ActionResult $result): self
    {
        $newActionExecutions = $this->actionExecutions->with($result);

        return new self(
            $this->initialState,
            $this->gateEvaluations,
            $newActionExecutions,
            $this->actionSkips,
            $this->currentState
        );
    }

    /**
     * Get all action executions recorded during this transition.
     */
    public function getActionExecutions(): ActionResultCollection
    {
        return $this->actionExecutions;
    }

    /**
     * Record an action skip and return a new history instance.
     *
     * @param Action $action The action that was skipped
     * @param GateResult $gateResult The gate result that caused the skip
     * @return self New instance with recorded skip
     */
    public function recordActionSkip(Action $action, GateResult $gateResult): self
    {
        $skip = new ActionSkip($action, $gateResult);
        $newActionSkips = $this->actionSkips->with($skip);

        return new self(
            $this->initialState,
            $this->gateEvaluations,
            $this->actionExecutions,
            $newActionSkips,
            $this->currentState
        );
    }

    /**
     * Get all action skips recorded during this transition.
     */
    public function getActionSkips(): ActionSkipCollection
    {
        return $this->actionSkips;
    }

    /**
     * Get metadata from the last PAUSE or STOP action.
     *
     * Returns null if no PAUSE/STOP action has been executed or if the action had no metadata.
     *
     * @return mixed The metadata from the last PAUSE/STOP action, or null
     */
    public function getStatusMetadata(): mixed
    {
        // Find the last action that caused PAUSE or STOP
        foreach (array_reverse($this->actionExecutions->toArray()) as $actionResult) {
            if ($actionResult->executionState === ExecutionState::PAUSE
                || $actionResult->executionState === ExecutionState::STOP
            ) {
                return $actionResult->metadata;
            }
        }

        return null;
    }
}
