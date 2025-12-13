<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Events;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Events\ActionExecuted;
use BenRowe\StateFlow\Events\ActionExecuting;
use BenRowe\StateFlow\Events\ActionSkipped;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\EventOrchestrator;
use BenRowe\StateFlow\Events\GateEvaluated;
use BenRowe\StateFlow\Events\GateEvaluating;
use BenRowe\StateFlow\Events\TransitionCompleted;
use BenRowe\StateFlow\Events\TransitionFailed;
use BenRowe\StateFlow\Events\TransitionPaused;
use BenRowe\StateFlow\Events\TransitionStopped;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use Exception;
use PHPUnit\Framework\TestCase;

class EventOrchestratorTest extends TestCase
{
    public function testGateEvaluatingDispatchesCorrectEvent(): void
    {
        $gate = $this->createMock(Gate::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $gateContext = new GateContext($state, $delta);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($gate, $gateContext) {
                return $event instanceof GateEvaluating
                    && $event->gate === $gate
                    && $event->context === $gateContext
                    && $event->isActionGate === false;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->gateEvaluating($gate, $gateContext, false);
    }

    public function testGateEvaluatingWithActionGateFlag(): void
    {
        $gate = $this->createMock(Gate::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $gateContext = new GateContext($state, $delta);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof GateEvaluating && $event->isActionGate === true;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->gateEvaluating($gate, $gateContext, true);
    }

    public function testGateEvaluatedDispatchesCorrectEvent(): void
    {
        $gate = $this->createMock(Gate::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $gateContext = new GateContext($state, $delta);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($gate, $gateContext) {
                return $event instanceof GateEvaluated
                    && $event->gate === $gate
                    && $event->context === $gateContext
                    && $event->result === GateResult::ALLOW
                    && $event->isActionGate === false;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->gateEvaluated($gate, $gateContext, GateResult::ALLOW, false);
    }

    public function testActionExecutingDispatchesCorrectEvent(): void
    {
        $action = $this->createMock(Action::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $context = $this->createMock(TransitionContext::class);
        $actionContext = new ActionContext($state, $delta, $context);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($action, $actionContext) {
                return $event instanceof ActionExecuting
                    && $event->action === $action
                    && $event->context === $actionContext;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->actionExecuting($action, $actionContext);
    }

    public function testActionExecutedDispatchesCorrectEvent(): void
    {
        $action = $this->createMock(Action::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);
        $context = $this->createMock(TransitionContext::class);
        $actionContext = new ActionContext($state, $delta, $context);
        $result = new ActionResult(ExecutionState::CONTINUE, null);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($action, $actionContext, $result) {
                return $event instanceof ActionExecuted
                    && $event->action === $action
                    && $event->context === $actionContext
                    && $event->result === $result;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->actionExecuted($action, $actionContext, $result);
    }

    public function testActionSkippedDispatchesCorrectEvent(): void
    {
        $action = $this->createMock(Action::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($action) {
                return $event instanceof ActionSkipped
                    && $event->action === $action
                    && $event->gateResult === GateResult::DENY;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->actionSkipped($action, GateResult::DENY);
    }

    public function testTransitionCompletedDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $context = $this->createMock(TransitionContext::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state, $context) {
                return $event instanceof TransitionCompleted
                    && $event->finalState === $state
                    && $event->context === $context;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->transitionCompleted($state, $context);
    }

    public function testTransitionFailedDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $exception = new Exception('Test failure');
        $context = $this->createMock(TransitionContext::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state, $exception, $context) {
                return $event instanceof TransitionFailed
                    && $event->currentState === $state
                    && $event->exception === $exception
                    && $event->context === $context;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->transitionFailed($state, $exception, $context);
    }

    public function testTransitionPausedDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $context = $this->createMock(TransitionContext::class);
        $metadata = ['reason' => 'waiting for approval'];
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state, $context, $metadata) {
                return $event instanceof TransitionPaused
                    && $event->currentState === $state
                    && $event->context === $context
                    && $event->metadata === $metadata;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->transitionPaused($state, $context, $metadata);
    }

    public function testTransitionStoppedDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $context = $this->createMock(TransitionContext::class);
        $metadata = ['reason' => 'cancelled by user'];
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state, $context, $metadata) {
                return $event instanceof TransitionStopped
                    && $event->currentState === $state
                    && $event->context === $context
                    && $event->metadata === $metadata;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->transitionStopped($state, $context, $metadata);
    }
}
