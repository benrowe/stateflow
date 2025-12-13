<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

use BenRowe\StateFlow\Events\EventOrchestrator;
use BenRowe\StateFlow\Gate\GateEvaluationService;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;
use BenRowe\StateFlow\TransitionContext;
use Throwable;

/**
 * Handles action execution, guard checking, and result processing
 */
class ActionExecutionService
{
    public function __construct(
        private readonly EventOrchestrator $events,
        private readonly GateEvaluationService $gateEvaluator
    ) {
    }

    /**
     * Execute a single action with guard checking and result processing
     *
     * @return ExecutionState The execution state after running this action
     */
    public function executeAction(
        Action $action,
        TransitionContext $context
    ): ExecutionState {
        // Check action guard if present
        if ($action instanceof Guardable) {
            $guardResult = $this->gateEvaluator->evaluateActionGuard($action, $context);

            if ($guardResult !== null) {
                $context->recordActionSkip($action, $guardResult);
                $this->events->actionSkipped($action, $guardResult);

                return ExecutionState::CONTINUE;
            }
        }

        // Create action context
        $actionContext = new ActionContext(
            $context->getCurrentState(),
            $context->getDesiredDelta(),
            $context
        );

        // Dispatch executing event
        $this->events->actionExecuting($action, $actionContext);

        // Execute action
        try {
            $result = $action->execute($actionContext);
        } catch (Throwable $exception) {
            $this->events->transitionFailed(
                $context->getCurrentState(),
                $exception,
                $context
            );
            throw $exception;
        }

        // Dispatch executed event
        $this->events->actionExecuted($action, $actionContext, $result);

        // Record result
        $context->recordActionExecution($result);

        // Update state if action returned new state
        if ($result->newState !== null) {
            $context->updateCurrentState($result->newState);
        }

        // Handle pause/stop
        if ($result->executionState === ExecutionState::PAUSE) {
            $context->executionStatus()->markPaused($result->metadata);
            $this->events->transitionPaused(
                $context->getCurrentState(),
                $context,
                $result->metadata
            );
        } elseif ($result->executionState === ExecutionState::STOP) {
            $context->executionStatus()->markStopped($result->metadata);
            $this->events->transitionStopped(
                $context->getCurrentState(),
                $context,
                $result->metadata
            );
        }

        return $result->executionState;
    }

    /**
     * Skip all actions with a given reason
     */
    public function skipAllActions(
        ActionCollection $actions,
        GateResult $reason,
        TransitionContext $context
    ): void {
        foreach ($actions->toArray() as $action) {
            $context->recordActionSkip($action, $reason);
            $this->events->actionSkipped($action, $reason);
        }
    }
}
