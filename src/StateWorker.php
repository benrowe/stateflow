<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;

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

        return $this->context;
    }

    private function evaluateGates(): GateResult
    {
        $gateContext = new GateContext(
            $this->context->getCurrentState(),
            []  // TODO: Pass actual delta when implemented
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
            $context = new ActionContext($this->context->getCurrentState(), [], $this->context);
            $this->context->addActionResult($action->execute($context));
        }
    }

    private function skipAllActions(GateResult $reason): void
    {
        foreach ($this->configuration->getActions() as $action) {
            $this->context->addActionSkip($action, $reason);
        }
    }
}
