<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Action;

use CoverGenius\StateFlow\Events\EventOrchestrator;
use CoverGenius\StateFlow\Exceptions\NonYieldableActionException;
use CoverGenius\StateFlow\Gate\GateEvaluationService;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\TransitionContext;
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
        $guardResult = $this->gateEvaluator->evaluateGuardIfApplicable($action, $context);

        if ($guardResult !== null) {
            $context->recordActionSkip($action, $guardResult);
            $this->events->actionSkipped($action, $guardResult);

            return ExecutionState::CONTINUE;
        }

        // Create action context, attaching any pending yield response
        $actionContext = new ActionContext(
            $context->getCurrentState(),
            $context->getDesiredDelta(),
            $context,
            $context->consumePendingYieldResponseAsObject()
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

        $this->recordSuspension($result, $context);

        return $result->executionState;
    }

    /**
     * Guard against resuming a yielded transition whose action no longer opts in to yielding.
     *
     * @throws NonYieldableActionException if the action no longer implements Yieldable
     */
    public function assertYieldable(Action $action): void
    {
        if (!$action instanceof Yieldable) {
            throw new NonYieldableActionException(
                'Action ' . $action::class . ' no longer implements Yieldable; cannot resume yielded transition'
            );
        }
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

    /**
     * Record and dispatch the event for a pause/stop/yield result.
     */
    private function recordSuspension(ActionResult $result, TransitionContext $context): void
    {
        if ($result->executionState === ExecutionState::PAUSE) {
            $context->executionStatus()->markPaused($result->metadata);
            $this->events->transitionPaused($context->getCurrentState(), $context, $result->metadata);
        } elseif ($result->executionState === ExecutionState::STOP) {
            $context->executionStatus()->markStopped($result->metadata);
            $this->events->transitionStopped($context->getCurrentState(), $context, $result->metadata);
        } elseif ($result->executionState === ExecutionState::YIELD) {
            $context->executionStatus()->markYielded($result->metadata);
            $this->events->transitionYielded($context->getCurrentState(), $context, $result->metadata);
        }
    }
}
