<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Action;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionCollection;
use BenRowe\StateFlow\Action\ActionExecutionService;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Events\EventOrchestrator;
use BenRowe\StateFlow\Gate\GateEvaluationService;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use Exception;
use PHPUnit\Framework\TestCase;

interface GuardableAction extends Action, Guardable
{
}

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

        $context->expects($this->once())->method('addActionResult')->with($result);

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
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $metadata = ['reason' => 'waiting'];
        $result = new ActionResult(ExecutionState::PAUSE, null, $metadata);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);

        $action->method('execute')->willReturn($result);

        $context->expects($this->once())->method('addActionResult')->with($result);
        $context->expects($this->once())->method('markAsPaused')->with($metadata);

        $events->expects($this->once())->method('actionExecuting');
        $events->expects($this->once())->method('actionExecuted');
        $events->expects($this->once())->method('transitionPaused')->with($state, $context, $metadata);

        $returnedState = $service->executeAction($action, $context);

        $this->assertSame(ExecutionState::PAUSE, $returnedState);
    }

    public function testExecuteActionWithStopState(): void
    {
        $events = $this->createMock(EventOrchestrator::class);
        $gateEvaluator = $this->createMock(GateEvaluationService::class);
        $service = new ActionExecutionService($events, $gateEvaluator);

        $action = $this->createMock(Action::class);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $metadata = ['reason' => 'cancelled'];
        $result = new ActionResult(ExecutionState::STOP, null, $metadata);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);

        $action->method('execute')->willReturn($result);

        $context->expects($this->once())->method('addActionResult')->with($result);
        $context->expects($this->once())->method('markAsStopped')->with($metadata);

        $events->expects($this->once())->method('actionExecuting');
        $events->expects($this->once())->method('actionExecuted');
        $events->expects($this->once())->method('transitionStopped')->with($state, $context, $metadata);

        $returnedState = $service->executeAction($action, $context);

        $this->assertSame(ExecutionState::STOP, $returnedState);
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

        $gateEvaluator->method('evaluateActionGuard')->with($action, $context)->willReturn(GateResult::DENY);

        $context->expects($this->once())->method('addActionSkip')->with($action, GateResult::DENY);
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

        $context->expects($this->once())->method('addActionResult')->with($result);

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
        $actions = new ActionCollection($action1, $action2);
        $context = $this->createMock(TransitionContext::class);

        $context->expects($this->exactly(2))->method('addActionSkip');
        $events->expects($this->exactly(2))->method('actionSkipped');

        $service->skipAllActions($actions, GateResult::DENY, $context);
    }
}
