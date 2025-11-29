<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class StateFlowTest extends TestCase
{
    public function testCanExecuteConfiguredActions(): void
    {
        $stateFlow = new StateFlow(function (State $state, array $delta): Configuration {
            $action1 = $this
                ->createMock(Action::class);
            $action1->method('execute')->willReturnCallback(function () {
                return ActionResult::continue();
            });

            return new Configuration([], [$action1]);
        });
        $context = $stateFlow
            ->transition($this->createMock(State::class), [])
            ->execute();
        $this->assertInstanceOf(TransitionContext::class, $context);
        $this->assertCount(1, $context->getActionExecutions());
        $action = $context->getActionExecutions()[0];
        $this->assertInstanceOf(ActionResult::class, $action);
        $this->assertSame(ExecutionState::CONTINUE, $action->executionState);

    }
}
