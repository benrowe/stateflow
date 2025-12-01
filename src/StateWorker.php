<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\TransitionCompleted;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;

class StateWorker
{
    private int $nextActionIndex = 0;

    private readonly Configuration $configuration;

    public function __construct(private TransitionContext $context, private readonly EventDispatcher $eventDispatcher)
    {
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
        // Run transition gates first
        $gateResult = $this->evaluateGates();

        // Skip actions if gates denied or returned SKIP_IDEMPOTENT
        if ($gateResult->shouldSkipAction()) {
            $this->skipAllActions($gateResult);

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
    }

    private function evaluateGates(): GateResult
    {
        $gateContext = new GateContext(
            $this->context->getCurrentState(),
            $this->context->getDesiredDelta()
        );

        foreach ($this->configuration->getTransitionGates() as $gate) {
            $result = $gate->evaluate($gateContext);

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
            $gateResult = $gate->evaluate($gateContext);

            // Track the gate evaluation with isActionGate=true
            $this->context->addGateEvaluation($gate, $gateResult, true);

            // Skip action if gate denies or returns SKIP_IDEMPOTENT
            if ($gateResult->shouldSkipAction()) {
                $this->context->addActionSkip($action, $gateResult);

                return;
            }
        }

        // Execute the action
        $context = new ActionContext(
            $this->context->getCurrentState(),
            $this->context->getDesiredDelta(),
            $this->context
        );
        $result = $action->execute($context);
        $this->context->addActionResult($result);

        // Update current state if action returned a new state
        if ($result->newState !== null) {
            $this->context->updateCurrentState($result->newState);
        }

        // Update status if action paused or stopped
        if ($result->executionState === ExecutionState::PAUSE) {
            $this->context->markAsPaused();
        }

        if ($result->executionState === ExecutionState::STOP) {
            $this->context->markAsStopped();
        }
    }

    private function skipAllActions(GateResult $reason): void
    {
        foreach ($this->configuration->getActions() as $action) {
            $this->context->addActionSkip($action, $reason);
        }
    }
}
