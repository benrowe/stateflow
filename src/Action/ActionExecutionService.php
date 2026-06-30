<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Action;

use BenRowe\StateFlow\Events\EventOrchestrator;
use BenRowe\StateFlow\Exceptions\NonYieldableActionException;
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
    ) {}

    /**
     * Execute a single action with guard checking and result processing
     *
     * @return ExecutionState The execution state after running this action
     *
     * @throws Throwable when action execute fails for any reason (runtime, static)
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

        // Create action context, attaching any pending yield response
        $hasYieldResponse = $context->hasPendingYieldResponse();
        $yieldResponseData = $context->consumePendingYieldResponse();

        $actionContext = new ActionContext(
            $context->getCurrentState(),
            $context->getDesiredDelta(),
            $context,
            $hasYieldResponse,
            $yieldResponseData
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

        // Yielding is an explicit opt-in capability; reject before anything is recorded
        if ($result->executionState === ExecutionState::YIELD && !$action instanceof Yieldable) {
            throw new NonYieldableActionException(
                'Action ' . $action::class . ' returned ActionResult::yield() but does not implement Yieldable'
            );
        }

        // Dispatch executed event
        $this->events->actionExecuted($action, $actionContext, $result);

        // Record result
        $context->recordActionExecution($result);

        // Update state if action returned a new state
        if ($result->newState !== null) {
            $context->updateCurrentState($result->newState);
        }

        // Handle pause/stop/yield
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
        } elseif ($result->executionState === ExecutionState::YIELD) {
            $context->executionStatus()->markYielded($result->metadata);
            $this->events->transitionYielded(
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
