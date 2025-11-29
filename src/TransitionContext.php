<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\ActionResult;

class TransitionContext
{
    /**
     * @var ActionResult[]
     */
    private array $actions = [];

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
}
