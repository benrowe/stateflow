<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

class WorkflowResumeTest extends TestCase
{
    use CreatesTestStates;
    use CreatesTestGates;
    use CreatesTestActions;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    /**
     * Scenario 6.4: Resume paused workflow
     * Tests that a paused workflow can be resumed from context
     * and previously executed actions don't run again
     */
    public function testResumePausedWorkflow(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'step' => 0]);

        // Action 1: continues
        $action1 = $this->createTestAction('Action1');

        // Action 2: pauses with metadata
        $action2 = $this->createTestActionWithResult(
            'Action2',
            ActionResult::pause(null, ['awaiting' => 'approval'])
        );

        // Action 3: should execute on resume
        $action3 = $this->createTestAction('Action3');

        // Create configuration
        $configuration = Configuration::fromArray([], [$action1, $action2, $action3]);

        // Step 1: Initial execution - should pause after action 2
        $stateFlow1 = new StateFlow(fn () => $configuration);
        $worker1 = $stateFlow1->transition($initialState, new ArrayDelta(['status' => 'pending']));
        $pausedContext = $worker1->execute();

        // Verify the workflow paused
        $this->assertTrue($pausedContext->executionStatus()->isPaused(), 'Workflow should be paused');
        $this->assertFalse($pausedContext->executionStatus()->isCompleted(), 'Workflow should not be completed');
        $this->assertCount(2, $pausedContext->executionHistory()->getActionExecutions(), 'Actions 1 and 2 should have executed');

        // Verify execution log shows actions 1 and 2
        $this->assertContains('Action:Action1', $this->logger->log, 'Action 1 should have executed');
        $this->assertContains('Action:Action2', $this->logger->log, 'Action 2 should have executed');
        $this->assertNotContains('Action:Action3', $this->logger->log, 'Action 3 should not have executed yet');

        // Step 2: Resume from paused context
        $stateFlow2 = new StateFlow(fn () => $configuration);
        $worker2 = $stateFlow2->fromContext($pausedContext);
        $completedContext = $worker2->execute();

        // Verify actions 1 and 2 did NOT execute again
        // The log should only contain one instance of each from the first execution
        $action1Count = count(array_keys($this->logger->log, 'Action:Action1', true));
        $action2Count = count(array_keys($this->logger->log, 'Action:Action2', true));
        $this->assertSame(1, $action1Count, 'Action 1 should only execute once (not repeated on resume)');
        $this->assertSame(1, $action2Count, 'Action 2 should only execute once (not repeated on resume)');

        // Verify action 3 executed on resume
        $this->assertContains('Action:Action3', $this->logger->log, 'Action 3 should execute on resume');

        // Verify workflow is now completed
        $this->assertTrue($completedContext->executionStatus()->isCompleted(), 'Workflow should be completed after resume');
        $this->assertFalse($completedContext->executionStatus()->isPaused(), 'Workflow should not be paused');
        $this->assertCount(3, $completedContext->executionHistory()->getActionExecutions(), 'All 3 actions should be executed');

        // Verify same context instance
        $this->assertSame($pausedContext, $completedContext, 'Should return the same context instance');
    }

    /**
     * Variant: Resume paused workflow with state changes
     * Tests that resumed workflow receives the current state from paused context
     */
    public function testResumePausedWorkflowWithStateChanges(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'version' => 1]);

        // Action 1: updates state and continues
        $stateAfterAction1 = $this->createTestState(['status' => 'review', 'version' => 2]);
        $action1 = $this->createTestActionWithState('Action1', $stateAfterAction1);

        // Action 2: pauses
        $action2 = $this->createTestActionWithResult('Action2', ActionResult::pause());

        // Action 3: should receive state from action 1
        $stateAfterAction3 = $this->createTestState(['status' => 'published', 'version' => 3]);
        $action3 = $this->createTestActionWithState('Action3', $stateAfterAction3);

        $configuration = Configuration::fromArray([], [$action1, $action2, $action3]);

        // Initial execution
        $stateFlow1 = new StateFlow(fn () => $configuration);
        $pausedContext = $stateFlow1
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify state after pause
        $this->assertSame(
            ['status' => 'review', 'version' => 2],
            $pausedContext->getCurrentState()->toArray(),
            'State should be updated after action 1'
        );

        // Resume execution
        $stateFlow2 = new StateFlow(fn () => $configuration);
        $completedContext = $stateFlow2
            ->fromContext($pausedContext)
            ->execute();

        // Verify final state
        $this->assertSame(
            ['status' => 'published', 'version' => 3],
            $completedContext->getCurrentState()->toArray(),
            'State should be updated after action 3 on resume'
        );
    }

    /**
     * Edge case: Resume already completed workflow does nothing
     * Tests that resuming a completed workflow doesn't re-execute actions
     */
    public function testResumeCompletedWorkflowDoesNothing(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $configuration = Configuration::fromArray([], [$action1, $action2]);

        // Execute to completion
        $stateFlow1 = new StateFlow(fn () => $configuration);
        $completedContext = $stateFlow1
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        $this->assertTrue($completedContext->executionStatus()->isCompleted());
        $this->assertCount(2, $completedContext->executionHistory()->getActionExecutions());

        // Count executions before resume
        $action1CountBefore = count(array_keys($this->logger->log, 'Action:Action1', true));
        $action2CountBefore = count(array_keys($this->logger->log, 'Action:Action2', true));

        // Try to resume completed workflow
        $stateFlow2 = new StateFlow(fn () => $configuration);
        $resumedContext = $stateFlow2
            ->fromContext($completedContext)
            ->execute();

        // Verify actions did NOT execute again
        $action1CountAfter = count(array_keys($this->logger->log, 'Action:Action1', true));
        $action2CountAfter = count(array_keys($this->logger->log, 'Action:Action2', true));

        $this->assertSame($action1CountBefore, $action1CountAfter, 'Action 1 should not execute again');
        $this->assertSame($action2CountBefore, $action2CountAfter, 'Action 2 should not execute again');
        $this->assertTrue($resumedContext->executionStatus()->isCompleted());
        $this->assertSame($completedContext, $resumedContext);
    }

    /**
     * Edge case: Resume stopped workflow does nothing
     * Tests that a stopped workflow cannot be resumed
     */
    public function testResumeStoppedWorkflowDoesNothing(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestActionWithResult('Action2', ActionResult::stop());
        $action3 = $this->createTestAction('Action3');

        $configuration = Configuration::fromArray([], [$action1, $action2, $action3]);

        // Execute until stopped
        $stateFlow1 = new StateFlow(fn () => $configuration);
        $stoppedContext = $stateFlow1
            ->transition($initialState, new ArrayDelta(['status' => 'failed']))
            ->execute();

        $this->assertTrue($stoppedContext->executionStatus()->isStopped());
        $this->assertCount(2, $stoppedContext->executionHistory()->getActionExecutions());
        $this->assertNotContains('Action:Action3', $this->logger->log);

        // Try to resume stopped workflow
        $stateFlow2 = new StateFlow(fn () => $configuration);
        $resumedContext = $stateFlow2
            ->fromContext($stoppedContext)
            ->execute();

        // Verify action 3 did NOT execute
        $this->assertNotContains('Action:Action3', $this->logger->log, 'Action 3 should not execute on stopped workflow');
        $this->assertTrue($resumedContext->executionStatus()->isStopped(), 'Should remain stopped');
        $this->assertCount(2, $resumedContext->executionHistory()->getActionExecutions(), 'Should still have only 2 actions');
    }

    public function testResumedWorkflowUsesOriginalConfiguration(): void
    {
        // V1 config
        $actionA = $this->createTestActionWithResult('ActionA', ActionResult::pause());
        $actionB = $this->createTestAction('ActionB');
        $configV1 = Configuration::fromArray([], [$actionA, $actionB]);

        // V2 config
        $actionC = $this->createTestAction('ActionC');
        $actionD = $this->createTestAction('ActionD');
        $configV2 = Configuration::fromArray([], [$actionC, $actionD]);

        // Config provider returns config based on state version
        $configProvider = function ($state) use ($configV1, $configV2) {
            return $state->toArray()['version'] === 1 ? $configV1 : $configV2;
        };

        // Start with V1 state
        $initialState = $this->createTestState(['status' => 'draft', 'version' => 1]);

        // Step 1: Initial execution. Should use config V1 and pause after ActionA
        $stateFlow1 = new StateFlow($configProvider);
        $pausedContext = $stateFlow1->transition($initialState, new ArrayDelta([]))->execute();

        $this->assertTrue($pausedContext->executionStatus()->isPaused());
        $this->assertContains('Action:ActionA', $this->logger->log);
        $this->assertNotContains('Action:ActionB', $this->logger->log);

        // Step 2: "Externally" update the state to V2 while paused.
        // The bug is that on resume, the config provider will be called again with this new state.
        $modifiedState = $this->createTestState(['status' => 'draft', 'version' => 2]);
        // To simulate this, we pass a new state into the context. In a real app,
        // you might load the entity from the DB again before resuming.
        $pausedContext->updateCurrentState($modifiedState);

        // Step 3: Resume the workflow
        // The current (buggy) implementation will regenerate the config using the modified state (V2)
        // and try to run ActionC and ActionD.
        // The correct implementation should use the original config (V1) and run ActionB.
        $stateFlow2 = new StateFlow($configProvider);
        $completedContext = $stateFlow2->fromContext($pausedContext)->execute();

        // Assert that the original workflow (V1) completed
        $this->assertTrue($completedContext->executionStatus()->isCompleted());
        $this->assertContains('Action:ActionB', $this->logger->log, 'ActionB from original config should have run');

        // Assert that the new workflow (V2) did NOT run
        $this->assertNotContains('Action:ActionC', $this->logger->log, 'ActionC from new config should NOT have run');
        $this->assertNotContains('Action:ActionD', $this->logger->log, 'ActionD from new config should NOT have run');
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
