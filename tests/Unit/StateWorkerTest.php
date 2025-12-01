<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\TransitionCompleted;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateWorker;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StateWorker
 */
class StateWorkerTest extends TestCase
{
    use CreatesTestStates;

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
        $config = new Configuration([$idempotentGate], [$action]);

        // Create state and context
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, ['status' => 'published'], $config);

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
        $this->assertTrue($resultContext->isCompleted(), 'Workflow should be marked as completed for SKIP_IDEMPOTENT');
        $this->assertFalse($resultContext->isPaused());
        $this->assertFalse($resultContext->isStopped());

        // Verify action was skipped
        $this->assertCount(0, $resultContext->getActionExecutions(), 'No actions should execute');
        $this->assertCount(1, $resultContext->getActionSkips(), 'Action should be skipped');

        // Verify the skip reason
        $skips = $resultContext->getActionSkips();
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
        $config = new Configuration([$denyGate], [$action]);

        // Create state and context
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, ['status' => 'published'], $config);

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
        $this->assertFalse($resultContext->isCompleted(), 'Workflow should NOT be completed for DENY');
        $this->assertFalse($resultContext->isPaused());
        $this->assertFalse($resultContext->isStopped());

        // Verify action was skipped
        $this->assertCount(0, $resultContext->getActionExecutions(), 'No actions should execute');
        $this->assertCount(1, $resultContext->getActionSkips(), 'Action should be skipped');

        // Verify the skip reason
        $skips = $resultContext->getActionSkips();
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
        $config = new Configuration([$allowGate], [$action]);

        // Create state and context
        $state = $this->createTestState(['status' => 'draft']);
        $context = new TransitionContext($state, ['status' => 'published'], $config);

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
        $this->assertTrue($resultContext->isCompleted(), 'Workflow should be completed after successful execution');
        $this->assertFalse($resultContext->isPaused());
        $this->assertFalse($resultContext->isStopped());

        // Verify action executed
        $this->assertCount(1, $resultContext->getActionExecutions(), 'Action should execute');
        $this->assertCount(0, $resultContext->getActionSkips(), 'No actions should be skipped');

        // Verify TransitionCompleted event was dispatched
        $this->assertTrue($completedEventDispatched, 'TransitionCompleted event should be dispatched');
    }

    private function createStubAction(string $name): Action
    {
        return new class ($name) implements Action {
            /**
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private string $name)
            {
            }

            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::continue();
            }
        };
    }
}
