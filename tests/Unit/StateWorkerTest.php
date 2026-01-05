<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\ActionExecuted;
use BenRowe\StateFlow\Events\ActionExecuting;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\GateEvaluated;
use BenRowe\StateFlow\Events\GateEvaluating;
use BenRowe\StateFlow\Events\NullEventDispatcher;
use BenRowe\StateFlow\Events\TransitionCompleted;
use BenRowe\StateFlow\Exceptions\InvalidGateResultException;
use BenRowe\StateFlow\Exceptions\TransitionException;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;
use BenRowe\StateFlow\GateEvaluation;
use BenRowe\StateFlow\StateWorker;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StateWorker
 */
class StateWorkerTest extends TestCase
{
    use CreatesTestGates;
    use CreatesTestStates;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    /**
     * Test that when a gate returns SKIP_IDEMPOTENT:
     * 1. The workflow is marked as completed
     * 2. Actions are skipped
     * 3. TransitionCompleted event is dispatched
     *
     * This tests the specific logic added in StateWorker::execute() lines 84-87
     */
    public function testSkipIdempotentMarksWorkflowAsCompletedAndDispatchesEvent(): void
    {
        // Create a gate that returns SKIP_IDEMPOTENT
        $idempotentGate = $this->createMock(Gate::class);
        $idempotentGate
            ->method('evaluate')
            ->willReturn(GateResult::SKIP_IDEMPOTENT);
        $idempotentGate
            ->method('message')
            ->willReturn('Idempotent check');

        // Create an action that should NOT execute
        $action = $this->createStubAction('TestAction');

        // Create configuration
        $config = Configuration::fromArray([$idempotentGate], [$action]);

        // Create state and context
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        // Create mock event dispatcher to verify TransitionCompleted is dispatched
        $completedEventDispatched = false;
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->exactly(4)) // GateEvaluating, GateEvaluated, ActionSkipped, TransitionCompleted
            ->method('dispatch')
            ->willReturnCallback(function ($event) use ($state, &$completedEventDispatched) {
                if ($event instanceof TransitionCompleted) {
                    // Verify the TransitionCompleted event was dispatched
                    $this->assertSame($state, $event->finalState);
                    $completedEventDispatched = true;
                }
            });

        // Create worker and execute
        $worker = new StateWorker($context, $mockDispatcher);
        $resultContext = $worker->execute();

        // Verify workflow is marked as completed
        $this->assertTrue($resultContext->executionStatus()->isCompleted(), 'Workflow should be marked as completed for SKIP_IDEMPOTENT');
        $this->assertFalse($resultContext->executionStatus()->isPaused());
        $this->assertFalse($resultContext->executionStatus()->isStopped());

        // Verify action was skipped
        $this->assertCount(0, $resultContext->executionHistory()->getActionExecutions(), 'No actions should execute');
        $this->assertCount(1, $resultContext->executionHistory()->getActionSkips(), 'Action should be skipped');

        // Verify the skip reason
        $skips = $resultContext->executionHistory()->getActionSkips()->toArray();
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $skips[0]->gateResult);

        // Verify TransitionCompleted event was dispatched
        $this->assertTrue($completedEventDispatched, 'TransitionCompleted event should be dispatched');
    }

    /**
     * Test that when a gate returns DENY (not SKIP_IDEMPOTENT):
     * 1. The workflow is NOT marked as completed
     * 2. Actions are skipped
     * 3. TransitionCompleted event is NOT dispatched
     *
     * This ensures the SKIP_IDEMPOTENT behavior is specific and doesn't affect DENY
     */
    public function testDenyDoesNotMarkWorkflowAsCompleted(): void
    {
        // Create a gate that returns DENY
        $denyGate = $this->createMock(Gate::class);
        $denyGate
            ->method('evaluate')
            ->willReturn(GateResult::DENY);
        $denyGate
            ->method('message')
            ->willReturn('Deny check');

        // Create an action that should NOT execute
        $action = $this->createStubAction('TestAction');

        // Create configuration
        $config = Configuration::fromArray([$denyGate], [$action]);

        // Create state and context
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        // Create mock event dispatcher to verify TransitionCompleted is NOT dispatched
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->exactly(3)) // GateEvaluating, GateEvaluated, ActionSkipped
            ->method('dispatch')
            ->willReturnCallback(function ($event) {
                // Ensure TransitionCompleted is never dispatched
                $this->assertNotInstanceOf(
                    TransitionCompleted::class,
                    $event,
                    'TransitionCompleted should NOT be dispatched for DENY'
                );
            });

        // Create worker and execute
        $worker = new StateWorker($context, $mockDispatcher);
        $resultContext = $worker->execute();

        // Verify workflow is NOT marked as completed
        $this->assertFalse($resultContext->executionStatus()->isCompleted(), 'Workflow should NOT be completed for DENY');
        $this->assertFalse($resultContext->executionStatus()->isPaused());
        $this->assertFalse($resultContext->executionStatus()->isStopped());

        // Verify action was skipped
        $this->assertCount(0, $resultContext->executionHistory()->getActionExecutions(), 'No actions should execute');
        $this->assertCount(1, $resultContext->executionHistory()->getActionSkips(), 'Action should be skipped');

        // Verify the skip reason
        $skips = $resultContext->executionHistory()->getActionSkips()->toArray();
        $this->assertSame(GateResult::DENY, $skips[0]->gateResult);
    }

    /**
     * Test that when all gates return ALLOW and actions complete:
     * 1. The workflow is marked as completed
     * 2. Actions execute
     * 3. TransitionCompleted event is dispatched
     *
     * This is a baseline test to ensure normal flow still works
     */
    public function testAllowMarksWorkflowAsCompletedAfterActions(): void
    {
        // Create a gate that returns ALLOW
        $allowGate = $this->createMock(Gate::class);
        $allowGate
            ->method('evaluate')
            ->willReturn(GateResult::ALLOW);
        $allowGate
            ->method('message')
            ->willReturn('Allow check');

        // Create an action
        $action = $this->createStubAction('TestAction');

        // Create configuration
        $config = Configuration::fromArray([$allowGate], [$action]);

        // Create state and context
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        // Create mock event dispatcher to verify TransitionCompleted is dispatched
        $completedEventDispatched = false;
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$completedEventDispatched) {
                if ($event instanceof TransitionCompleted) {
                    $completedEventDispatched = true;
                }
            });

        // Create worker and execute
        $worker = new StateWorker($context, $mockDispatcher);
        $resultContext = $worker->execute();

        // Verify workflow is marked as completed
        $this->assertTrue($resultContext->executionStatus()->isCompleted(), 'Workflow should be completed after successful execution');
        $this->assertFalse($resultContext->executionStatus()->isPaused());
        $this->assertFalse($resultContext->executionStatus()->isStopped());

        // Verify action executed
        $this->assertCount(1, $resultContext->executionHistory()->getActionExecutions(), 'Action should execute');
        $this->assertCount(0, $resultContext->executionHistory()->getActionSkips(), 'No actions should be skipped');

        // Verify TransitionCompleted event was dispatched
        $this->assertTrue($completedEventDispatched, 'TransitionCompleted event should be dispatched');
    }

    public function testCanRunGatesSeparately(): void
    {
        $state = $this->createTestState(['status' => 'draft']);
        $gateAllow = $this->createTestGate('first gate', GateResult::ALLOW);
        $gateDeny = $this->createTestGate('second gate', GateResult::DENY);

        $config = Configuration::fromArray([$gateAllow, $gateDeny], []);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);
        // ensure we expect the gate events
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->exactly(4)) // GateEvaluating, GateEvaluated, ActionSkipped, TransitionCompleted
            ->method('dispatch')
            ->willReturnOnConsecutiveCalls()
            ->willReturnCallback(function ($event) {
                if ($event instanceof GateEvaluated) {
                    return ($event->gate->message() === 'first gate' && $event->result === GateResult::ALLOW) ||
                        ($event->gate->message() === 'second gate' && $event->result === GateResult::DENY);
                }
                if ($event instanceof GateEvaluating) {
                    return in_array($event->gate->message(), ['first gate', 'second gate'], true);
                }
                $this->fail('Unexpected event ' . $event::class);
            });
        $worker = new StateWorker($context, $mockDispatcher);
        $worker->runGates();

        $this->assertCount(2, $context->executionHistory()->getGateEvaluations());
        $this->assertSame(['first gate', 'second gate'], array_map(fn (GateEvaluation $eval) => $eval->gate->message(), $context->executionHistory()->getGateEvaluations()->toArray()));

    }

    public function testCanRunActionsSeparately(): void
    {
        $action = $this->createStubAction('TestAction');

        $config = Configuration::fromArray([], [$action]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->exactly(2)) // ActionExecuting, ActionExecuted
            ->method('dispatch')
            ->willReturnOnConsecutiveCalls()
            ->willReturnCallback(function (object $event) {
                if ($event instanceof ActionExecuting) {
                    return true;
                }
                if ($event instanceof ActionExecuted) {
                    return true;
                }
                $this->fail('Unexpected event ' . $event::class);
            });

        $worker = new StateWorker($context, $mockDispatcher);
        $worker->runActions();
    }

    public function testCanNotRunActionsWithoutGateBeingExecuted(): void
    {
        $action = $this->createStubAction('TestAction');
        $gate = $this->createTestGate('first gate', GateResult::ALLOW);

        $config = Configuration::fromArray([$gate], [$action]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $worker = new StateWorker($context, new NullEventDispatcher());
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('Gates not evaluated');
        $worker->runActions();
    }

    public function testRunNextActionExecutesSingleAction(): void
    {
        $action = $this->createStubAction('TestAction');

        $config = Configuration::fromArray([], [$action]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->exactly(2)) // ActionExecuting, ActionExecuted
            ->method('dispatch')
            ->willReturnCallback(function (object $event) {
                $this->assertTrue(
                    $event instanceof ActionExecuting || $event instanceof ActionExecuted,
                    'Expected ActionExecuting or ActionExecuted event'
                );
            });

        $worker = new StateWorker($context, $mockDispatcher);
        $resultContext = $worker->runNextAction();

        // Verify action was executed
        $this->assertCount(1, $resultContext->executionHistory()->getActionExecutions());
        $this->assertCount(0, $resultContext->executionHistory()->getActionSkips());
    }

    public function testRunNextActionReturnsContextImmediatelyWhenNoMoreActions(): void
    {
        $config = Configuration::fromArray([], []); // No actions
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $mockDispatcher = new NullEventDispatcher();

        $worker = new StateWorker($context, $mockDispatcher);
        $resultContext = $worker->runNextAction();

        // Should return context without executing anything
        $this->assertCount(0, $resultContext->executionHistory()->getActionExecutions());
        $this->assertSame($context, $resultContext);
    }

    public function testExecuteNextActionDoesNotExecuteWhenWorkflowIsStopped(): void
    {
        $action = $this->createStubAction('TestAction');

        $config = Configuration::fromArray([], [$action]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);
        $context->executionStatus()->markStopped(); // Mark as stopped

        $mockDispatcher = new NullEventDispatcher();

        $worker = new StateWorker($context, $mockDispatcher);
        $resultContext = $worker->runNextAction();

        // Action should not execute because workflow is stopped
        $this->assertCount(0, $resultContext->executionHistory()->getActionExecutions());
        $this->assertTrue($resultContext->executionStatus()->isStopped());
    }

    public function testExecuteActionsStopsWhenActionReturnsPause(): void
    {
        $pauseAction = new class () implements Action
        {
            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::pause(null, ['reason' => 'need approval']);
            }
        };
        $secondAction = $this->createStubAction('SecondAction');

        $config = Configuration::fromArray([], [$pauseAction, $secondAction]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $mockDispatcher = new NullEventDispatcher();

        $worker = new StateWorker($context, $mockDispatcher);
        $worker->runActions();

        // Only first action should execute, second should not
        $this->assertCount(1, $context->executionHistory()->getActionExecutions());
        $this->assertTrue($context->executionStatus()->isPaused());
    }

    public function testExecuteActionsStopsWhenActionReturnsStop(): void
    {
        $stopAction = new class () implements Action
        {
            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::stop(null, ['reason' => 'fatal error']);
            }
        };
        $secondAction = $this->createStubAction('SecondAction');

        $config = Configuration::fromArray([], [$stopAction, $secondAction]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $mockDispatcher = new NullEventDispatcher();

        $worker = new StateWorker($context, $mockDispatcher);
        $worker->runActions();

        // Only first action should execute, second should not
        $this->assertCount(1, $context->executionHistory()->getActionExecutions());
        $this->assertTrue($context->executionStatus()->isStopped());
    }

    public function testEvaluateGateThrowsInvalidGateResultExceptionOnTypeError(): void
    {
        // Create a gate that returns invalid type (not GateResult)
        $invalidGate = new class () implements Gate
        {
            public function evaluate(GateContext $context): GateResult
            {
                // @phpstan-ignore return.type
                return 'invalid'; // Returns string instead of GateResult
            }

            public function message(): string
            {
                return 'Invalid gate';
            }
        };

        $config = Configuration::fromArray([$invalidGate], []);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $worker = new StateWorker($context, new NullEventDispatcher());

        $this->expectException(InvalidGateResultException::class);
        $this->expectExceptionMessage('must return a');
        $worker->runGates();
    }

    public function testActionWithGuardThatDeniesSkipsAction(): void
    {
        $denyGate = $this->createTestGate('deny gate', GateResult::DENY);

        $guardedAction = new class ($denyGate) implements Action, Guardable
        {
            public function __construct(private Gate $gate) {}

            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::continue();
            }

            public function gate(): Gate
            {
                return $this->gate;
            }
        };

        $config = Configuration::fromArray([], [$guardedAction]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $mockDispatcher = new NullEventDispatcher();

        $worker = new StateWorker($context, $mockDispatcher);
        $worker->runActions();

        // Action should be skipped because guard denied
        $this->assertCount(0, $context->executionHistory()->getActionExecutions());
        $this->assertCount(1, $context->executionHistory()->getActionSkips());
        $this->assertSame(GateResult::DENY, $context->executionHistory()->getActionSkips()->toArray()[0]->gateResult);
    }

    public function testActionWithGuardThatReturnsSkipIdempotentSkipsAction(): void
    {
        $skipGate = $this->createTestGate('skip gate', GateResult::SKIP_IDEMPOTENT);

        $guardedAction = new class ($skipGate) implements Action, Guardable
        {
            public function __construct(private Gate $gate) {}

            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::continue();
            }

            public function gate(): Gate
            {
                return $this->gate;
            }
        };

        $config = Configuration::fromArray([], [$guardedAction]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $mockDispatcher = new NullEventDispatcher();

        $worker = new StateWorker($context, $mockDispatcher);
        $worker->runActions();

        // Action should be skipped because guard returned SKIP_IDEMPOTENT
        $this->assertCount(0, $context->executionHistory()->getActionExecutions());
        $this->assertCount(1, $context->executionHistory()->getActionSkips());
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $context->executionHistory()->getActionSkips()->toArray()[0]->gateResult);
    }

    public function testSetNextActionIndexAllowsJumpingToSpecificAction(): void
    {
        // Create 3 actions
        $action1 = $this->createStubAction('Action1');
        $action2 = $this->createStubAction('Action2');
        $action3 = $this->createStubAction('Action3');

        $config = Configuration::fromArray([], [$action1, $action2, $action3]);
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, new ArrayDelta(['status' => 'published']), $config);

        $mockDispatcher = new NullEventDispatcher();

        $worker = new StateWorker($context, $mockDispatcher);

        // Skip action1 and action2 by setting index to 2
        $worker->setNextActionIndex(2);

        // Execute next action (should be action3, not action1)
        $worker->runNextAction();

        // Verify only action3 executed
        $this->assertCount(1, $context->executionHistory()->getActionExecutions());

        // Execute next action again (should execute nothing since we're at the end)
        $worker->runNextAction();

        // Still only 1 action executed
        $this->assertCount(1, $context->executionHistory()->getActionExecutions());
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }

    private function createStubAction(string $name): Action
    {
        return new class ($name) implements Action
        {
            /**
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private string $name) {}

            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::continue();
            }
        };
    }
}
