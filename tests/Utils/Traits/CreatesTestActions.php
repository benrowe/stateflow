<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Utils\Traits;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;

/**
 * Trait for creating test Action implementations
 */
trait CreatesTestActions
{
    abstract protected function getLogger(): ExecutionLogger;

    private function createTestAction(string $name): Action
    {
        $logger = $this->getLogger();

        return new class ($name, $logger) implements Action {
            public function __construct(
                private string $name,
                private ExecutionLogger $logger
            ) {
            }

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

        return new class ($name, $newState, $logger) implements Action {
            public function __construct(
                private string $name,
                private State $newState,
                private ExecutionLogger $logger
            ) {
            }

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

        return new class ($name, $result, $logger) implements Action {
            public function __construct(
                private string $name,
                private ActionResult $result,
                private ExecutionLogger $logger
            ) {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return $this->result;
            }
        };
    }

    private function createTestGuardedAction(string $name, GateResult $gateResult): Action
    {
        $logger = $this->getLogger();

        return new class ($name, $gateResult, $logger) implements Action, Guardable {
            public function __construct(
                private string $name,
                private GateResult $gateResult,
                private ExecutionLogger $logger
            ) {
            }

            public function gate(): Gate
            {
                return new class ($this->name, $this->gateResult, $this->logger) implements Gate {
                    public function __construct(
                        private string $actionName,
                        private GateResult $result,
                        private ExecutionLogger $logger
                    ) {
                    }

                    public function evaluate(GateContext $context): GateResult
                    {
                        $this->logger->log[] = 'Gate:' . $this->actionName . 'Gate';

                        return $this->result;
                    }

                    public function message(): ?string
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
