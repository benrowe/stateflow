<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Gate;

use BenRowe\StateFlow\Events\EventOrchestrator;
use BenRowe\StateFlow\Exceptions\InvalidGateResultException;
use BenRowe\StateFlow\TransitionContext;
use TypeError;

/**
 * Handles gate evaluation logic and tracking
 */
class GateEvaluationService
{
    public function __construct(
        private readonly EventOrchestrator $events
    ) {
    }

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
            $context->addGateEvaluation($gate, $result, false);

            if ($result->shouldSkipAction()) {
                return $result;
            }
        }

        return GateResult::ALLOW;
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
        $context->addGateEvaluation($gate, $result, true);

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
