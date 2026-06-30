<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Action;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionCollection;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionExecutionService;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Action\Yieldable;
use BenRowe\StateFlow\Action\YieldResponse;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Events\EventOrchestrator;
use BenRowe\StateFlow\Exceptions\NonYieldableActionException;
use BenRowe\StateFlow\ExecutionStatus;
use BenRowe\StateFlow\Gate\GateEvaluationService;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use Exception;
use PHPUnit\Framework\TestCase;

interface GuardableAction extends Action, Guardable {}

interface YieldableAction extends Action, Yieldable {}

class ActionExecutionServiceTest extends TestCase
{
    public function testExecuteActionWithContinueState(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(Action::class);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $result = new ActionResult(ExecutionState::CONTINUE, null);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);

        $action->method('execute')->willReturn($result);

        $context->expects($this->once())->method('recordActionExecution')->with($result);

        $events->expects($this->once())->method('actionExecuting');
        $events->expects($this->once())->method('actionExecuted');

        $returnedState = $service->executeAction($action, $context);

        $this->assertSame(ExecutionState::CONTINUE, $returnedState);
    }

    public function testExecuteActionWithPauseState(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(Action::class);
        $context = $this->createMock(TransitionContext::class);
        $executionStatus = new ExecutionStatus();
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $metadata = ['reason' => 'waiting'];
        $result = new ActionResult(ExecutionState::PAUSE, null, $metadata);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->method('executionStatus')->willReturn($executionStatus);

        $action->method('execute')->willReturn($result);

        $context->expects($this->once())->method('recordActionExecution')->with($result);

        $events->expects($this->once())->method('actionExecuting');
        $events->expects($this->once())->method('actionExecuted');
        $events->expects($this->once())->method('transitionPaused')->with($state, $context, $metadata);

        $returnedState = $service->executeAction($action, $context);

        $this->assertSame(ExecutionState::PAUSE, $returnedState);
        $this->assertTrue($executionStatus->isPaused());
    }

    public function testExecuteActionWithStopState(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(Action::class);
        $context = $this->createMock(TransitionContext::class);
        $executionStatus = new ExecutionStatus();
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $metadata = ['reason' => 'cancelled'];
        $result = new ActionResult(ExecutionState::STOP, null, $metadata);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->method('executionStatus')->willReturn($executionStatus);

        $action->method('execute')->willReturn($result);

        $context->expects($this->once())->method('recordActionExecution')->with($result);

        $events->expects($this->once())->method('actionExecuting');
        $events->expects($this->once())->method('actionExecuted');
        $events->expects($this->once())->method('transitionStopped')->with($state, $context, $metadata);

        $returnedState = $service->executeAction($action, $context);

        $this->assertSame(ExecutionState::STOP, $returnedState);
        $this->assertTrue($executionStatus->isStopped());
    }

    public function testExecuteActionWithYieldState(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(YieldableAction::class);
        $context = $this->createMock(TransitionContext::class);
        $executionStatus = new ExecutionStatus();
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $metadata = ['checkId' => 'abc123'];
        $result = new ActionResult(ExecutionState::YIELD, null, $metadata);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->method('executionStatus')->willReturn($executionStatus);

        $action->method('execute')->willReturn($result);

        $context->expects($this->once())->method('recordActionExecution')->with($result);

        $events->expects($this->once())->method('actionExecuting');
        $events->expects($this->once())->method('actionExecuted');
        $events->expects($this->once())->method('transitionYielded')->with($state, $context, $metadata);

        $returnedState = $service->executeAction($action, $context);

        $this->assertSame(ExecutionState::YIELD, $returnedState);
        $this->assertTrue($executionStatus->isYielded());
    }

    public function testExecuteActionPassesPendingYieldResponseToActionContext(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(YieldableAction::class);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $responseData = ['outcome' => 'approved'];
        $result = new ActionResult(ExecutionState::CONTINUE, null);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->method('consumePendingYieldResponseAsObject')->willReturn(new YieldResponse($responseData));

        $action->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (ActionContext $actionContext) use ($responseData) {
                return $actionContext->hasYieldResponse() === true
                    && $actionContext->yieldResponse() === $responseData;
            }))
            ->willReturn($result);

        $service->executeAction($action, $context);
    }

    public function testExecuteActionThrowsWhenNonYieldableActionYields(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(Action::class);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $result = new ActionResult(ExecutionState::YIELD, null, ['checkId' => 'abc123']);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);

        $action->method('execute')->willReturn($result);

        $context->expects($this->never())->method('recordActionExecution');
        $events->expects($this->never())->method('actionExecuted');
        $events->expects($this->never())->method('transitionYielded');

        $this->expectException(NonYieldableActionException::class);

        $service->executeAction($action, $context);
    }

    public function testExecuteActionUpdatesStateWhenActionReturnsNewState(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(Action::class);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $newState = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $result = new ActionResult(ExecutionState::CONTINUE, $newState);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);

        $action->method('execute')->willReturn($result);

        $context->expects($this->once())->method('updateCurrentState')->with($newState);

        $service->executeAction($action, $context);
    }

    public function testExecuteActionSkipsWhenGuardDenies(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        // Create a mock that implements both Action and Guardable
        $action = $this->createMock(GuardableAction::class);
        $context = $this->createMock(TransitionContext::class);

        $gateEvaluator->method('evaluateGuardIfApplicable')->with($action, $context)->willReturn(GateResult::DENY);

        $context->expects($this->once())->method('recordActionSkip')->with($action, GateResult::DENY);
        $events->expects($this->once())->method('actionSkipped')->with($action, GateResult::DENY);

        // Action should not be executed
        $action->expects($this->never())->method('execute');

        $returnedState = $service->executeAction($action, $context);

        $this->assertSame(ExecutionState::CONTINUE, $returnedState);
    }

    public function testExecuteActionExecutesWhenGuardAllows(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        // Create a mock that implements both Action and Guardable
        $action = $this->createMock(GuardableAction::class);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $result = new ActionResult(ExecutionState::CONTINUE, null);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);

        $gateEvaluator->method('evaluateActionGuard')->with($action, $context)->willReturn(null);

        $action->method('execute')->willReturn($result);

        $context->expects($this->once())->method('recordActionExecution')->with($result);

        $returnedState = $service->executeAction($action, $context);

        $this->assertSame(ExecutionState::CONTINUE, $returnedState);
    }

    public function testExecuteActionDispatchesTransitionFailedOnException(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(Action::class);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $exception = new Exception('Test failure');

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);

        $action->method('execute')->willThrowException($exception);

        $events->expects($this->once())->method('actionExecuting');
        $events->expects($this->once())->method('transitionFailed')->with($state, $exception, $context);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Test failure');

        $service->executeAction($action, $context);
    }

    public function testSkipAllActionsAddsAllActionsAsSkipped(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);
        $actions = new ActionCollection([$action1, $action2]);
        $context = $this->createMock(TransitionContext::class);

        $context->expects($this->exactly(2))->method('recordActionSkip');
        $events->expects($this->exactly(2))->method('actionSkipped');

        $service->skipAllActions($actions, GateResult::DENY, $context);
    }
}
