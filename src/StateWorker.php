<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Configuration\Configuration;

class StateWorker
{
    public function __construct(private TransitionContext $context, private Configuration $configuration)
    {

    }

    public function execute(): TransitionContext
    {
        foreach ($this->configuration->getActions() as $action) {
            // execute each action
            $context = new ActionContext($this->context->getCurrentState(), [], $this->context);
            $this->context->addActionResult($action->execute($context));
        }

        return $this->context;
    }
}
