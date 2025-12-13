<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

class StepByStepExecutionTest extends TestCase
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
     * Scenario 7.1: Run gates only
     * Tests that runGates() evaluates all gates but doesn't execute actions
     */
    public function testRunGatesOnly(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        // Create gates - all should be evaluated
        $gate1 = $this->createTestGate('Gate1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('Gate2', GateResult::ALLOW);
        $gate3 = $this->createTestGate('Gate3', GateResult::ALLOW);

        // Create actions - should NOT execute
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2, $gate3],
            [$action1, $action2]
        ));

        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));

        // Run gates only
        $result = $worker->runGates();

        // Verify the final result is ALLOW
        $this->assertSame(GateResult::ALLOW, $result);

        // Verify all gates were evaluated
        $context = $worker->getContext();
        $this->assertCount(3, $context->executionHistory()->getGateEvaluations(), 'All gates should be evaluated');
        $this->assertSame(GateResult::ALLOW, $context->executionHistory()->getGateEvaluations()->toArray()[0]->result);
        $this->assertSame(GateResult::ALLOW, $context->executionHistory()->getGateEvaluations()->toArray()[1]->result);
        $this->assertSame(GateResult::ALLOW, $context->executionHistory()->getGateEvaluations()->toArray()[2]->result);

        // Verify NO actions were executed
        $this->assertCount(0, $context->executionHistory()->getActionExecutions(), 'No actions should execute');

        // Verify gates were evaluated in log
        $this->assertContains('Gate:Gate1', $this->logger->log);
        $this->assertContains('Gate:Gate2', $this->logger->log);
        $this->assertContains('Gate:Gate3', $this->logger->log);

        // Verify actions were NOT executed
        $this->assertNotContains('Action:Action1', $this->logger->log);
        $this->assertNotContains('Action:Action2', $this->logger->log);
    }

    /**
     * Scenario 7.1 variant: Run gates only with denial
     * Tests that runGates() returns DENY and stops at the denying gate
     */
    public function testRunGatesOnlyWithDenial(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        // Second gate denies
        $gate1 = $this->createTestGate('Gate1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('Gate2', GateResult::DENY);
        $gate3 = $this->createTestGate('Gate3', GateResult::ALLOW);

        $action1 = $this->createTestAction('Action1');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2, $gate3],
            [$action1]
        ));

        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));
        $result = $worker->runGates();

        // Verify the final result is DENY
        $this->assertSame(GateResult::DENY, $result);

        // Verify only gates 1 and 2 were evaluated (short-circuit)
        $context = $worker->getContext();
        $this->assertCount(2, $context->executionHistory()->getGateEvaluations(), 'Only 2 gates should be evaluated (short-circuit)');
        $this->assertSame(GateResult::ALLOW, $context->executionHistory()->getGateEvaluations()->toArray()[0]->result);
        $this->assertSame(GateResult::DENY, $context->executionHistory()->getGateEvaluations()->toArray()[1]->result);

        // Verify NO actions were executed
        $this->assertCount(0, $context->executionHistory()->getActionExecutions());
    }

    /**
     * Scenario 7.2: Run gates then actions separately
     * Tests that gates and actions can be run in separate steps
     */
    public function testRunGatesThenActionsSeparately(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $gate1 = $this->createTestGate('Gate1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('Gate2', GateResult::ALLOW);

        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');
        $action3 = $this->createTestAction('Action3');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2],
            [$action1, $action2, $action3]
        ));

        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));

        // Step 1: Run gates
        $gateResult = $worker->runGates();
        $this->assertSame(GateResult::ALLOW, $gateResult, 'Gates should allow');

        // Verify gates were evaluated
        $context = $worker->getContext();
        $this->assertCount(2, $context->executionHistory()->getGateEvaluations());
        $this->assertCount(0, $context->executionHistory()->getActionExecutions(), 'No actions should execute yet');

        // Step 2: Run actions
        $actionContext = $worker->runActions();

        // Verify all actions were executed
        $this->assertCount(3, $actionContext->executionHistory()->getActionExecutions(), 'All actions should execute');
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertContains('Action:Action2', $this->logger->log);
        $this->assertContains('Action:Action3', $this->logger->log);

        // Verify gates executed before actions
        $gate1Index = array_search('Gate:Gate1', $this->logger->log, true);
        $action1Index = array_search('Action:Action1', $this->logger->log, true);
        $this->assertLessThan($action1Index, $gate1Index, 'Gates should execute before actions');
    }

    /**
     * Scenario 7.3: Run next action incrementally
     * Tests that actions can be executed one at a time with runNextAction()
     */
    public function testRunNextActionIncrementally(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');
        $action3 = $this->createTestAction('Action3');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [],
            [$action1, $action2, $action3]
        ));

        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));

        // First call: execute action 1
        $context1 = $worker->runNextAction();
        $this->assertCount(1, $context1->executionHistory()->getActionExecutions(), 'Action 1 should execute');
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertNotContains('Action:Action2', $this->logger->log);
        $this->assertNotContains('Action:Action3', $this->logger->log);

        // Second call: execute action 2
        $context2 = $worker->runNextAction();
        $this->assertCount(2, $context2->executionHistory()->getActionExecutions(), 'Actions 1 and 2 should be executed');
        $this->assertContains('Action:Action2', $this->logger->log);
        $this->assertNotContains('Action:Action3', $this->logger->log);

        // Third call: execute action 3
        $context3 = $worker->runNextAction();
        $this->assertCount(3, $context3->executionHistory()->getActionExecutions(), 'All 3 actions should be executed');
        $this->assertContains('Action:Action3', $this->logger->log);

        // Fourth call: no more actions (should return same context)
        $context4 = $worker->runNextAction();
        $this->assertCount(3, $context4->executionHistory()->getActionExecutions(), 'Still only 3 actions');
        $this->assertSame($context3, $context4, 'Should return same context when no more actions');
    }

    /**
     * Scenario 7.3 variant: runNextAction with action gates
     * Tests that runNextAction() respects action gates (Guardable)
     */
    public function testRunNextActionWithActionGates(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $action1 = $this->createTestGuardedAction('Action1', GateResult::ALLOW);
        $action2 = $this->createTestGuardedAction('Action2', GateResult::DENY);
        $action3 = $this->createTestGuardedAction('Action3', GateResult::ALLOW);

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action1, $action2, $action3]));

        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));

        // Action 1: gate allows, should execute
        $context1 = $worker->runNextAction();
        $this->assertCount(1, $context1->executionHistory()->getActionExecutions(), 'Action 1 should execute');
        $this->assertCount(0, $context1->executionHistory()->getActionSkips(), 'No skips yet');

        // Action 2: gate denies, should skip
        $context2 = $worker->runNextAction();
        $this->assertCount(1, $context2->executionHistory()->getActionExecutions(), 'Still only 1 action executed');
        $this->assertCount(1, $context2->executionHistory()->getActionSkips(), 'Action 2 should be skipped');

        // Action 3: gate allows, should execute
        $context3 = $worker->runNextAction();
        $this->assertCount(2, $context3->executionHistory()->getActionExecutions(), 'Actions 1 and 3 executed');
        $this->assertCount(1, $context3->executionHistory()->getActionSkips(), 'Action 2 skipped');
    }

    /**
     * Scenario 7.4: Execute is shorthand for gates + actions
     * Tests that execute() is equivalent to runGates() + runActions()
     */
    public function testExecuteIsShorthandForGatesAndActions(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $gate1 = $this->createTestGate('Gate1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('Gate2', GateResult::ALLOW);

        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        // Test 1: Using step-by-step execution
        $stateFlow1 = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2],
            [$action1, $action2]
        ));

        $worker1 = $stateFlow1->transition($initialState, new ArrayDelta(['status' => 'published']));
        $gateResult = $worker1->runGates();
        $this->assertSame(GateResult::ALLOW, $gateResult);

        $stepByStepContext = $worker1->runActions();

        // Test 2: Using execute() shorthand - reuse the same configuration
        $stateFlow2 = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2],
            [$action1, $action2]
        ));

        $executeContext = $stateFlow2
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify both approaches produce the same results
        $this->assertCount(
            count($stepByStepContext->executionHistory()->getGateEvaluations()),
            $executeContext->executionHistory()->getGateEvaluations(),
            'Same number of gate evaluations'
        );

        $this->assertCount(
            count($stepByStepContext->executionHistory()->getActionExecutions()),
            $executeContext->executionHistory()->getActionExecutions(),
            'Same number of action executions'
        );

        // Verify context is fully populated with execute()
        $this->assertCount(2, $executeContext->executionHistory()->getGateEvaluations());
        $this->assertCount(2, $executeContext->executionHistory()->getActionExecutions());
        $this->assertTrue($executeContext->executionStatus()->isCompleted());
    }

    /**
     * Scenario 7.4 variant: Execute stops at gate denial
     * Tests that execute() doesn't run actions when gates deny
     */
    public function testExecuteStopsAtGateDenial(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $gate1 = $this->createTestGate('Gate1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('Gate2', GateResult::DENY);

        $action1 = $this->createTestAction('Action1');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2],
            [$action1]
        ));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify gates were evaluated
        $this->assertCount(2, $context->executionHistory()->getGateEvaluations());
        $this->assertSame(GateResult::ALLOW, $context->executionHistory()->getGateEvaluations()->toArray()[0]->result);
        $this->assertSame(GateResult::DENY, $context->executionHistory()->getGateEvaluations()->toArray()[1]->result);

        // Verify no actions executed
        $this->assertCount(0, $context->executionHistory()->getActionExecutions());

        // Verify action was skipped
        $this->assertCount(1, $context->executionHistory()->getActionSkips());
    }

    /**
     * Integration test: execute() pauses, then runNextAction() continues
     * Tests that the action index is properly maintained when switching
     * between execute() and runNextAction()
     */
    public function testExecutePauseThenContinueWithRunNextAction(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        // Action 1 continues, Action 2 pauses, Action 3 should be runnable via runNextAction
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestActionWithResult('Action2', ActionResult::pause());
        $action3 = $this->createTestAction('Action3');

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action1, $action2, $action3]));

        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));

        // Execute - should run actions 1-2 and pause
        $context1 = $worker->execute();

        // Verify only actions 1 and 2 executed
        $this->assertCount(2, $context1->executionHistory()->getActionExecutions(), 'Execute should run actions 1-2 and pause');
        $this->assertTrue($context1->executionStatus()->isPaused(), 'Context should be paused');
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertContains('Action:Action2', $this->logger->log);
        $this->assertNotContains('Action:Action3', $this->logger->log, 'Action 3 should not execute yet');

        // Now call runNextAction - should execute action 3
        $context2 = $worker->runNextAction();

        // Verify action 3 executed
        $this->assertCount(3, $context2->executionHistory()->getActionExecutions(), 'After runNextAction, should have 3 actions executed');
        $this->assertContains('Action:Action3', $this->logger->log, 'Action 3 should execute');

        // Verify all actions are accounted for
        $this->assertSame($context1, $context2, 'Same context instance should be returned');
    }

    /**
     * Integration test: execute() stops, then runNextAction() does nothing
     * Tests that runNextAction() respects STOP state
     */
    public function testExecuteStopThenRunNextActionDoesNothing(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        // Action 1 continues, Action 2 stops, Action 3 should NOT run
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestActionWithResult('Action2', ActionResult::stop());
        $action3 = $this->createTestAction('Action3');

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action1, $action2, $action3]));

        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']));

        // Execute - should run actions 1-2 and stop
        $context1 = $worker->execute();

        // Verify only actions 1 and 2 executed
        $this->assertCount(2, $context1->executionHistory()->getActionExecutions(), 'Execute should run actions 1-2 and stop');
        $this->assertTrue($context1->executionStatus()->isStopped(), 'Context should be stopped');

        // Call runNextAction - should NOT execute action 3 (workflow is stopped)
        $context2 = $worker->runNextAction();

        // Verify action 3 did NOT execute
        $this->assertCount(2, $context2->executionHistory()->getActionExecutions(), 'runNextAction should not execute when stopped');
        $this->assertNotContains('Action:Action3', $this->logger->log, 'Action 3 should not execute when stopped');
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
