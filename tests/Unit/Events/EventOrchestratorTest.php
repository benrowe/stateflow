<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Events;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionContext;
use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\Action\ExecutionState;
use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Events\ActionExecuted;
use CoverGenius\StateFlow\Events\ActionExecuting;
use CoverGenius\StateFlow\Events\ActionSkipped;
use CoverGenius\StateFlow\Events\EventDispatcher;
use CoverGenius\StateFlow\Events\EventOrchestrator;
use CoverGenius\StateFlow\Events\GateEvaluated;
use CoverGenius\StateFlow\Events\GateEvaluating;
use CoverGenius\StateFlow\Events\LockAcquired;
use CoverGenius\StateFlow\Events\LockFailed;
use CoverGenius\StateFlow\Events\LockLost;
use CoverGenius\StateFlow\Events\LockReleased;
use CoverGenius\StateFlow\Events\LockRestored;
use CoverGenius\StateFlow\Events\TransitionCompleted;
use CoverGenius\StateFlow\Events\TransitionFailed;
use CoverGenius\StateFlow\Events\TransitionPaused;
use CoverGenius\StateFlow\Events\TransitionStopped;
use CoverGenius\StateFlow\Events\TransitionYielded;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateContext;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\Locking\LockState;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
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

    public function testTransitionYieldedDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $context = $this->createMock(TransitionContext::class);
        $metadata = ['checkId' => 'abc123'];
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state, $context, $metadata) {
                return $event instanceof TransitionYielded
                    && $event->currentState === $state
                    && $event->context === $context
                    && $event->metadata === $metadata;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->transitionYielded($state, $context, $metadata);
    }

    public function testLockAcquiredDispatchesCorrectEvent(): void
    {
        $lockState = new LockState('test-key', 1234567.89, 30);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($lockState) {
                return $event instanceof LockAcquired
                    && $event->lockKey === 'test-key'
                    && $event->lockState === $lockState;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->lockAcquired('test-key', $lockState);
    }

    public function testLockFailedDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state) {
                return $event instanceof LockFailed
                    && $event->lockKey === 'test-key'
                    && $event->state === $state
                    && $event->reason === 'Lock already held';
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->lockFailed('test-key', $state, 'Lock already held');
    }

    public function testLockReleasedDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state) {
                return $event instanceof LockReleased
                    && $event->lockKey === 'test-key'
                    && $event->state === $state;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->lockReleased('test-key', $state);
    }

    public function testLockRestoredDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state) {
                return $event instanceof LockRestored
                    && $event->lockKey === 'test-key'
                    && $event->state === $state;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->lockRestored('test-key', $state);
    }

    public function testLockLostDispatchesCorrectEvent(): void
    {
        $state = $this->createMock(State::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($state) {
                return $event instanceof LockLost
                    && $event->lockKey === 'test-key'
                    && $event->state === $state;
            }));

        $orchestrator = new EventOrchestrator($dispatcher);
        $orchestrator->lockLost('test-key', $state);
    }
}
