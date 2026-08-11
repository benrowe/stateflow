<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Gate;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Events\EventOrchestrator;
use CoverGenius\StateFlow\Exceptions\InvalidGateResultException;
use CoverGenius\StateFlow\TransitionContext;
use TypeError;

/**
 * Handles gate evaluation logic and tracking
 */
class GateEvaluationService
{
    public function __construct(
        private readonly EventOrchestrator $events
    ) {}

    /**
     * Evaluate all transition gates, returning first denial/skip or ALLOW
     */
    public function evaluateTransitionGates(
        GateCollection $gates,
        TransitionContext $context
    ): GateResult {
        $gateContext = new GateContext(
            $context->getCurrentState(),
            $context->getDesiredDelta()
        );

        foreach ($gates->toArray() as $gate) {
            $this->events->gateEvaluating($gate, $gateContext, false);

            $result = $this->evaluate($gate, $gateContext);

            $this->events->gateEvaluated($gate, $gateContext, $result, false);
            $context->recordGateEvaluation($gate, $result, false);

            if ($result->shouldSkipAction()) {
                return $result;
            }
        }

        return GateResult::ALLOW;
    }

    /**
     * Evaluate an action's guard gate if it opts in via Guardable; null if not applicable.
     */
    public function evaluateGuardIfApplicable(
        Action $action,
        TransitionContext $context
    ): ?GateResult {
        if (!$action instanceof Guardable) {
            return null;
        }

        return $this->evaluateActionGuard($action, $context);
    }

    /**
     * Evaluate an action guard gate
     */
    public function evaluateActionGuard(
        Guardable $action,
        TransitionContext $context
    ): ?GateResult {
        $gate = $action->gate();
        $gateContext = new GateContext(
            $context->getCurrentState(),
            $context->getDesiredDelta()
        );

        $this->events->gateEvaluating($gate, $gateContext, true);

        $result = $this->evaluate($gate, $gateContext);

        $this->events->gateEvaluated($gate, $gateContext, $result, true);
        $context->recordGateEvaluation($gate, $result, true);

        return $result->shouldSkipAction() ? $result : null;
    }

    private function evaluate(Gate $gate, GateContext $context): GateResult
    {
        try {
            return $gate->evaluate($context);
        } catch (TypeError $exception) {
            throw new InvalidGateResultException(
                sprintf(
                    'Gate %s must return a %s, invalid return type encountered',
                    get_debug_type($gate),
                    GateResult::class
                ),
                0,
                $exception
            );
        }
    }
}
