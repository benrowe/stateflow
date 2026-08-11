<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Utils\Traits;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionContext;
use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\Action\Yieldable;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateContext;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\Gate\Guardable;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\Tests\Utils\ExecutionLogger;

/**
 * Trait for creating test Action implementations
 */
trait CreatesTestActions
{
    abstract protected function getLogger(): ExecutionLogger;

    private function createTestAction(string $name): Action
    {
        $logger = $this->getLogger();

        return new class ($name, $logger) implements Action
        {
            public function __construct(
                private string $name,
                private ExecutionLogger $logger
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return ActionResult::continue();
            }
        };
    }

    private function createTestActionWithState(string $name, State $newState): Action
    {
        $logger = $this->getLogger();

        return new class ($name, $newState, $logger) implements Action
        {
            public function __construct(
                private string $name,
                private State $newState,
                private ExecutionLogger $logger
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return ActionResult::continue($this->newState);
            }
        };
    }

    private function createTestActionWithResult(string $name, ActionResult $result): Action
    {
        $logger = $this->getLogger();

        return new class ($name, $result, $logger) implements Action
        {
            public function __construct(
                private string $name,
                private ActionResult $result,
                private ExecutionLogger $logger
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return $this->result;
            }
        };
    }

    private function createTestYieldableAction(
        string $name,
        ActionResult $firstResult,
        ?ActionResult $resumedResult = null
    ): Action {
        $logger = $this->getLogger();

        return new class ($name, $firstResult, $resumedResult, $logger) implements Action, Yieldable
        {
            public function __construct(
                private string $name,
                private ActionResult $firstResult,
                private ?ActionResult $resumedResult,
                private ExecutionLogger $logger
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                if ($context->hasYieldResponse()) {
                    assert($this->resumedResult !== null);

                    return $this->resumedResult;
                }

                return $this->firstResult;
            }
        };
    }

    private function createTestGuardedAction(string $name, GateResult $gateResult): Action
    {
        $logger = $this->getLogger();

        return new class ($name, $gateResult, $logger) implements Action, Guardable
        {
            public function __construct(
                private string $name,
                private GateResult $gateResult,
                private ExecutionLogger $logger
            ) {}

            public function gate(): Gate
            {
                return new class ($this->name, $this->gateResult, $this->logger) implements Gate
                {
                    public function __construct(
                        private string $actionName,
                        private GateResult $result,
                        private ExecutionLogger $logger
                    ) {}

                    public function evaluate(GateContext $context): GateResult
                    {
                        $this->logger->log[] = 'Gate:' . $this->actionName . 'Gate';

                        return $this->result;
                    }

                    public function message(): string
                    {
                        return $this->actionName . 'Gate';
                    }
                };
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return ActionResult::continue();
            }
        };
    }
}
