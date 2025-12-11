<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\ActionExecuted;
use BenRowe\StateFlow\Events\ActionExecuting;
use BenRowe\StateFlow\Events\ActionSkipped;
use BenRowe\StateFlow\Events\Event;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\GateEvaluated;
use BenRowe\StateFlow\Events\GateEvaluating;
use BenRowe\StateFlow\Events\TransitionCompleted;
use BenRowe\StateFlow\Events\TransitionFailed;
use BenRowe\StateFlow\Events\TransitionPaused;
use BenRowe\StateFlow\Events\TransitionStarting;
use BenRowe\StateFlow\Events\TransitionStopped;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class EventDispatchingTest extends TestCase
{
    use CreatesTestStates;
    use CreatesTestActions;
    use CreatesTestGates;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    /**
     * Scenario 10.1: Dispatch TransitionStarting event
     * Tests that TransitionStarting event is dispatched when a transition begins
     * and contains the current state and desired delta
     */
    public function testDispatchesTransitionStartingEvent(): void
    {
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'review']);

        $mockDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (TransitionStarting $event) use ($initialState, $delta) {
                $this->assertInstanceOf(TransitionStarting::class, $event);
                $this->assertSame($initialState, $event->currentState);
                $this->assertSame($delta, $event->desiredDelta);

                return true;
            }));

        $stateFlow = new StateFlow(fn () => new Configuration([], []), $mockDispatcher);

        $stateFlow->transition($initialState, $delta);
    }

    /**
     * Scenario 10.2: Dispatch TransitionCompleted event
     * Tests that TransitionCompleted event is dispatched when a transition completes successfully
     * and contains the final state and context
     */
    public function testDispatchesTransitionCompletedEvent(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'published']);
        $finalState = $this->createTestState(['status' => 'published']);

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Use the trait to create a simple action that updates the state
        $action = $this->createTestActionWithState('TestAction', $finalState);

        $config = new Configuration([], [$action]);

        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);

        $worker = $stateFlow->transition($initialState, $delta);
        $completedContext = $worker->execute();

        // Ensure the context was indeed completed and the final state is as expected
        $this->assertTrue($completedContext->isCompleted());
        $this->assertEquals($finalState, $completedContext->getCurrentState());

        // Assert correct events were dispatched in order
        $this->assertCount(4, $dispatchedEvents);
        $this->assertInstanceOf(TransitionStarting::class, $dispatchedEvents[0]);
        $this->assertInstanceOf(ActionExecuting::class, $dispatchedEvents[1]);
        $this->assertInstanceOf(ActionExecuted::class, $dispatchedEvents[2]);
        $this->assertInstanceOf(TransitionCompleted::class, $dispatchedEvents[3]);
    }

    /**
     * Scenario 10.2 (edge case): Verify TransitionCompleted does not fire on pause
     * Tests that TransitionCompleted is NOT dispatched when workflow is paused,
     * only TransitionStarting and TransitionPaused should fire
     */
    public function testTransitionCompletedEventDoesNotFireOnPause(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'paused']);

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $action = $this->createTestActionWithResult('PauseAction', ActionResult::pause());
        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $pausedContext = $stateFlow->transition($initialState, $delta)->execute();

        $this->assertTrue($pausedContext->isPaused());
        $this->assertFalse($pausedContext->isCompleted());

        // Assert correct events were dispatched
        $this->assertCount(4, $dispatchedEvents);
        $this->assertInstanceOf(TransitionStarting::class, $dispatchedEvents[0]);
        $this->assertInstanceOf(ActionExecuting::class, $dispatchedEvents[1]);
        $this->assertInstanceOf(ActionExecuted::class, $dispatchedEvents[2]);
        $this->assertInstanceOf(TransitionPaused::class, $dispatchedEvents[3]);

        // Ensure NO TransitionCompleted event
        foreach ($dispatchedEvents as $event) {
            $this->assertNotInstanceOf(TransitionCompleted::class, $event);
        }
    }

    /**
     * Scenario 10.2 (edge case): Verify TransitionCompleted does not fire on stop
     * Tests that TransitionCompleted is NOT dispatched when workflow is stopped,
     * TransitionStarting, ActionExecuting, ActionExecuted, and TransitionStopped should fire
     */
    public function testTransitionCompletedEventDoesNotFireOnStop(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'stopped']);

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $action = $this->createTestActionWithResult('StopAction', ActionResult::stop());
        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $stoppedContext = $stateFlow->transition($initialState, $delta)->execute();

        $this->assertTrue($stoppedContext->isStopped());
        $this->assertFalse($stoppedContext->isCompleted());

        // Assert correct events were dispatched
        $this->assertCount(4, $dispatchedEvents);
        $this->assertInstanceOf(TransitionStarting::class, $dispatchedEvents[0]);
        $this->assertInstanceOf(ActionExecuting::class, $dispatchedEvents[1]);
        $this->assertInstanceOf(ActionExecuted::class, $dispatchedEvents[2]);
        $this->assertInstanceOf(TransitionStopped::class, $dispatchedEvents[3]);

        // Ensure NO TransitionCompleted event
        foreach ($dispatchedEvents as $event) {
            $this->assertNotInstanceOf(TransitionCompleted::class, $event);
        }
    }

    /**
     * Scenario 10.3: Dispatch TransitionPaused event
     * Tests that TransitionPaused event is dispatched when execution pauses
     * and contains the current state, context, and metadata
     */
    public function testDispatchesTransitionPausedEvent(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'review']);
        $metadata = ['reason' => 'waiting for dependency'];

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $action = $this->createTestActionWithResult('PauseAction', ActionResult::pause(null, $metadata));
        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $pausedContext = $stateFlow->transition($initialState, $delta)->execute();

        $this->assertTrue($pausedContext->isPaused());

        // Assert correct events were dispatched
        $this->assertCount(4, $dispatchedEvents);
        $this->assertInstanceOf(TransitionStarting::class, $dispatchedEvents[0]);
        $this->assertInstanceOf(ActionExecuting::class, $dispatchedEvents[1]);
        $this->assertInstanceOf(ActionExecuted::class, $dispatchedEvents[2]);
        $this->assertInstanceOf(TransitionPaused::class, $dispatchedEvents[3]);

        // Verify TransitionPaused event details
        /** @var TransitionPaused $pausedEvent */
        $pausedEvent = $dispatchedEvents[3];
        $this->assertSame($initialState, $pausedEvent->currentState);
        $this->assertInstanceOf(TransitionContext::class, $pausedEvent->context);
        $this->assertSame($metadata, $pausedEvent->metadata);
    }

    /**
     * Scenario 10.4: Dispatch TransitionStopped event
     * Tests that TransitionStopped event is dispatched when execution stops
     * and contains the current state, context, and metadata
     */
    public function testDispatchesTransitionStoppedEvent(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'stopped']);
        $metadata = ['reason' => 'user cancelled'];

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $action = $this->createTestActionWithResult('StopAction', ActionResult::stop(null, $metadata));
        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $stoppedContext = $stateFlow->transition($initialState, $delta)->execute();

        $this->assertTrue($stoppedContext->isStopped());

        // Assert TransitionStopped event was dispatched
        $this->assertCount(4, $dispatchedEvents);
        $this->assertInstanceOf(TransitionStopped::class, $dispatchedEvents[3]);

        // Verify TransitionStopped event details
        /** @var TransitionStopped $stoppedEvent */
        $stoppedEvent = $dispatchedEvents[3];
        $this->assertSame($initialState, $stoppedEvent->currentState);
        $this->assertInstanceOf(TransitionContext::class, $stoppedEvent->context);
        $this->assertSame($metadata, $stoppedEvent->metadata);
    }

    /**
     * Scenario 10.5: Dispatch TransitionFailed event
     * Tests that TransitionFailed event is dispatched when an action throws an exception
     * and contains the exception and context
     */
    public function testDispatchesTransitionFailedEvent(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'failed']);
        $exception = new \RuntimeException('Test exception');

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        // Create an action that throws an exception
        $action = $this->createMock(Action::class);
        $action->method('execute')->willThrowException($exception);

        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);

        try {
            $stateFlow->transition($initialState, $delta)->execute();
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            // Expected
            $this->assertSame($exception, $e);
        }

        // Assert TransitionFailed event was dispatched
        $this->assertCount(3, $dispatchedEvents);
        $this->assertInstanceOf(TransitionStarting::class, $dispatchedEvents[0]);
        $this->assertInstanceOf(ActionExecuting::class, $dispatchedEvents[1]);
        $this->assertInstanceOf(TransitionFailed::class, $dispatchedEvents[2]);

        // Verify TransitionFailed event details
        /** @var TransitionFailed $failedEvent */
        $failedEvent = $dispatchedEvents[2];
        $this->assertSame($initialState, $failedEvent->currentState);
        $this->assertSame($exception, $failedEvent->exception);
        $this->assertInstanceOf(TransitionContext::class, $failedEvent->context);
    }

    /**
     * Scenario 10.6 & 10.7: Dispatch GateEvaluating and GateEvaluated events
     * Tests that gate events are dispatched for transition gates
     * and indicate they are not action gates (isActionGate=false)
     */
    public function testDispatchesGateEventsForTransitionGates(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'published']);

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $gate1 = $this->createTestGate('PermissionGate', GateResult::ALLOW);
        $gate2 = $this->createTestGate('ValidationGate', GateResult::ALLOW);
        $action = $this->createTestAction('TestAction');

        $config = new Configuration([$gate1, $gate2], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $stateFlow->transition($initialState, $delta)->execute();

        // Filter to gate events
        $gateEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof GateEvaluating || $e instanceof GateEvaluated);
        $this->assertCount(4, $gateEvents); // 2 gates × 2 events each

        // Verify GateEvaluating/GateEvaluated pairs
        $gateEventsArray = array_values($gateEvents);
        $this->assertInstanceOf(GateEvaluating::class, $gateEventsArray[0]);
        $this->assertInstanceOf(GateEvaluated::class, $gateEventsArray[1]);
        $this->assertInstanceOf(GateEvaluating::class, $gateEventsArray[2]);
        $this->assertInstanceOf(GateEvaluated::class, $gateEventsArray[3]);

        // Verify isActionGate is false for transition gates
        /** @var GateEvaluating $evaluating */
        $evaluating = $gateEventsArray[0];
        $this->assertFalse($evaluating->isActionGate);

        /** @var GateEvaluated $evaluated */
        $evaluated = $gateEventsArray[1];
        $this->assertFalse($evaluated->isActionGate);
        $this->assertSame(GateResult::ALLOW, $evaluated->result);
    }

    /**
     * Scenario 10.6 & 10.7: Dispatch GateEvaluating and GateEvaluated events for action gates
     * Tests that gate events are dispatched for action gates (Guardable interface)
     * and indicate they are action gates (isActionGate=true)
     */
    public function testDispatchesGateEventsForActionGates(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'published']);

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $action = $this->createTestGuardedAction('GuardedAction', GateResult::ALLOW);

        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $stateFlow->transition($initialState, $delta)->execute();

        // Filter to gate events
        $gateEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof GateEvaluating || $e instanceof GateEvaluated);
        $this->assertCount(2, $gateEvents); // 1 action gate × 2 events

        $gateEventsArray = array_values($gateEvents);
        $this->assertInstanceOf(GateEvaluating::class, $gateEventsArray[0]);
        $this->assertInstanceOf(GateEvaluated::class, $gateEventsArray[1]);

        // Verify isActionGate is true for action gates
        /** @var GateEvaluating $evaluating */
        $evaluating = $gateEventsArray[0];
        $this->assertTrue($evaluating->isActionGate);

        /** @var GateEvaluated $evaluated */
        $evaluated = $gateEventsArray[1];
        $this->assertTrue($evaluated->isActionGate);
    }

    /**
     * Scenario 10.8 & 10.9: Dispatch ActionExecuting and ActionExecuted events
     * Tests that action events are dispatched when an action executes
     * and contain the action, context, and result
     */
    public function testDispatchesActionExecutingAndExecutedEvents(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'published']);

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $action = $this->createTestAction('TestAction');
        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $stateFlow->transition($initialState, $delta)->execute();

        // Find ActionExecuting and ActionExecuted events
        $actionExecutingEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof ActionExecuting);
        $actionExecutedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof ActionExecuted);

        $this->assertCount(1, $actionExecutingEvents);
        $this->assertCount(1, $actionExecutedEvents);

        /** @var ActionExecuting $executing */
        $executing = array_values($actionExecutingEvents)[0];
        $this->assertSame($action, $executing->action);
        $this->assertInstanceOf(ActionContext::class, $executing->context);

        /** @var ActionExecuted $executed */
        $executed = array_values($actionExecutedEvents)[0];
        $this->assertSame($action, $executed->action);
        $this->assertInstanceOf(ActionContext::class, $executed->context);
        $this->assertInstanceOf(ActionResult::class, $executed->result);
    }

    /**
     * Scenario 10.10: Dispatch ActionSkipped event
     * Tests that ActionSkipped event is dispatched when a gate denies an action
     * and contains the GateResult reason
     */
    public function testDispatchesActionSkippedEventWhenGateDenies(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'published']);

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $action = $this->createTestGuardedAction('GuardedAction', GateResult::DENY);

        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $stateFlow->transition($initialState, $delta)->execute();

        // Find ActionSkipped event
        $actionSkippedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof ActionSkipped);
        $this->assertCount(1, $actionSkippedEvents);

        /** @var ActionSkipped $skipped */
        $skipped = array_values($actionSkippedEvents)[0];
        $this->assertSame($action, $skipped->action);
        $this->assertSame(GateResult::DENY, $skipped->gateResult);
    }

    /**
     * Scenario 10.10 (variant): Dispatch ActionSkipped events when transition gate denies
     * Tests that ActionSkipped events are dispatched for all actions
     * when a transition gate denies the entire transition
     */
    public function testDispatchesActionSkippedEventsWhenTransitionGateDenies(): void
    {
        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = new ArrayDelta(['status' => 'published']);

        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $transitionGate = $this->createTestGate('PermissionGate', GateResult::DENY);
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $config = new Configuration([$transitionGate], [$action1, $action2]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $stateFlow->transition($initialState, $delta)->execute();

        // Find ActionSkipped events
        $actionSkippedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof ActionSkipped);
        $this->assertCount(2, $actionSkippedEvents); // Both actions skipped

        // Verify both actions are marked as skipped with DENY reason
        $skippedArray = array_values($actionSkippedEvents);
        foreach ($skippedArray as $skipped) {
            $this->assertInstanceOf(ActionSkipped::class, $skipped);
            $this->assertSame(GateResult::DENY, $skipped->gateResult);
        }
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
