<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

class ActionGuardTest extends TestCase
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
     * Scenario 4.1: Action with gate that allows
     * Tests that an action's gate is evaluated before execution and allows execution
     */
    public function testActionWithGateThatAllows(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 123]);

        $action = $this->createTestGuardedAction('GuardedAction', GateResult::ALLOW);

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action]));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify the action's gate was evaluated
        $gateEvaluations = $context->executionHistory()->getGateEvaluations();
        $this->assertCount(1, $gateEvaluations, 'Action gate should be evaluated');
        $gateEval = $gateEvaluations[0];
        $this->assertNotNull($gateEval);
        $this->assertSame(GateResult::ALLOW, $gateEval->result);
        $this->assertTrue($gateEval->isActionGate, 'Gate should be marked as action gate');

        // Verify the action executed
        $this->assertCount(1, $context->executionHistory()->getActionExecutions(), 'Action should execute');
        $this->assertContains('Action:GuardedAction', $this->logger->log);

        // Verify no actions were skipped
        $this->assertCount(0, $context->executionHistory()->getActionSkips(), 'No actions should be skipped');
    }

    /**
     * Scenario 4.2: Action with gate that denies
     * Tests that an action's gate can deny execution and skip the action
     */
    public function testActionWithGateThatDenies(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 123]);

        $action = $this->createTestGuardedAction('GuardedAction', GateResult::DENY);

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action]));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify the action's gate was evaluated
        $gateEvaluations = $context->executionHistory()->getGateEvaluations();
        $this->assertCount(1, $gateEvaluations, 'Action gate should be evaluated');
        $gateEval = $gateEvaluations[0];
        $this->assertNotNull($gateEval);
        $this->assertSame(GateResult::DENY, $gateEval->result);
        $this->assertTrue($gateEval->isActionGate, 'Gate should be marked as action gate');

        // Verify the action did NOT execute
        $this->assertCount(0, $context->executionHistory()->getActionExecutions(), 'Action should not execute');
        $this->assertNotContains('Action:GuardedAction', $this->logger->log);

        // Verify action was skipped
        $actionSkips = $context->executionHistory()->getActionSkips();
        $this->assertCount(1, $actionSkips, 'Action should be skipped');
        $actionSkip = $actionSkips[0];
        $this->assertNotNull($actionSkip);
        $this->assertSame(GateResult::DENY, $actionSkip->gateResult);
    }

    /**
     * Scenario 4.3: Multiple actions with individual gates
     * Tests that each action's gate is evaluated independently
     */
    public function testMultipleActionsWithIndividualGates(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 123]);

        $action1 = $this->createTestGuardedAction('Action1', GateResult::ALLOW);
        $action2 = $this->createTestGuardedAction('Action2', GateResult::DENY);
        $action3 = $this->createTestGuardedAction('Action3', GateResult::ALLOW);

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action1, $action2, $action3]));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify all 3 action gates were evaluated
        $gateEvaluations = $context->executionHistory()->getGateEvaluations();
        $this->assertCount(3, $gateEvaluations, 'All 3 action gates should be evaluated');
        $gateEval0 = $gateEvaluations[0];
        $gateEval1 = $gateEvaluations[1];
        $gateEval2 = $gateEvaluations[2];
        $this->assertNotNull($gateEval0);
        $this->assertNotNull($gateEval1);
        $this->assertNotNull($gateEval2);
        $this->assertSame(GateResult::ALLOW, $gateEval0->result);
        $this->assertTrue($gateEval0->isActionGate);
        $this->assertSame(GateResult::DENY, $gateEval1->result);
        $this->assertTrue($gateEval1->isActionGate);
        $this->assertSame(GateResult::ALLOW, $gateEval2->result);
        $this->assertTrue($gateEval2->isActionGate);

        // Verify actions 1 and 3 executed, action 2 did not
        $this->assertCount(2, $context->executionHistory()->getActionExecutions(), 'Actions 1 and 3 should execute');
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertNotContains('Action:Action2', $this->logger->log, 'Action 2 should not execute');
        $this->assertContains('Action:Action3', $this->logger->log);

        // Verify action 2 was skipped
        $actionSkips = $context->executionHistory()->getActionSkips();
        $this->assertCount(1, $actionSkips, 'Action 2 should be skipped');
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
