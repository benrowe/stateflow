<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;

class StateWorker
{
    public function __construct(private TransitionContext $context, private Configuration $configuration)
    {

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
        foreach ($this->configuration->getActions() as $action) {
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
                    continue;
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

            // Stop execution if action paused or stopped
            if ($result->executionState === ExecutionState::PAUSE) {
                $this->context->markAsPaused();
                break;
            }

            if ($result->executionState === ExecutionState::STOP) {
                $this->context->markAsStopped();
                break;
            }
        }
    }

    private function skipAllActions(GateResult $reason): void
    {
        foreach ($this->configuration->getActions() as $action) {
            $this->context->addActionSkip($action, $reason);
        }
    }
}
