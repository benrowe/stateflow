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

class TransitionGateTest extends TestCase
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
     * Scenario 2.1: All gates allow transition
     * Tests happy path - all gates pass and all actions execute
     */
    public function testAllGatesAllowTransition(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 123]);

        // Create gates - ALL return ALLOW
        $gate1 = $this->createTestGate('Gate1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('Gate2', GateResult::ALLOW);
        $gate3 = $this->createTestGate('Gate3', GateResult::ALLOW);

        // Create actions that should execute
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2, $gate3],
            [$action1, $action2]
        ));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify all 3 gates were evaluated
        $gateEvaluations = $context->getGateEvaluations()->toArray();
        $this->assertCount(3, $gateEvaluations, 'All 3 gates should be evaluated');
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[1]->result);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[2]->result);

        // Verify all actions were executed
        $this->assertCount(2, $context->getActionExecutions(), 'All 2 actions should execute');

        // Verify no actions were skipped
        $this->assertCount(0, $context->getActionSkips(), 'No actions should be skipped');

        // Verify execution order: gates first, then actions
        $this->assertContains('Gate:Gate1', $this->logger->log);
        $this->assertContains('Gate:Gate2', $this->logger->log);
        $this->assertContains('Gate:Gate3', $this->logger->log);
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertContains('Action:Action2', $this->logger->log);

        // Verify gates executed in order
        $gate1Index = array_search('Gate:Gate1', $this->logger->log, true);
        $gate2Index = array_search('Gate:Gate2', $this->logger->log, true);
        $gate3Index = array_search('Gate:Gate3', $this->logger->log, true);
        $this->assertLessThan($gate2Index, $gate1Index, 'Gate1 should execute before Gate2');
        $this->assertLessThan($gate3Index, $gate2Index, 'Gate2 should execute before Gate3');

        // Verify all gates executed before any actions
        $action1Index = array_search('Action:Action1', $this->logger->log, true);
        $this->assertLessThan($action1Index, $gate3Index, 'All gates should execute before actions');
    }

    /**
     * Scenario 2.2: First gate denies transition
     * Tests short-circuit evaluation - only first gate should be evaluated
     */
    public function testFirstGateDeniesTransitionWithShortCircuit(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 123]);

        // Create gates - FIRST gate will deny
        $gate1 = $this->createTestGate('Gate1', GateResult::DENY);
        $gate2 = $this->createTestGate('Gate2', GateResult::ALLOW);
        $gate3 = $this->createTestGate('Gate3', GateResult::ALLOW);

        // Create actions that should not execute
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2, $gate3],
            [$action1, $action2]
        ));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify ONLY first gate was evaluated (short-circuit)
        $gateEvaluations = $context->getGateEvaluations()->toArray();
        $this->assertCount(1, $gateEvaluations, 'Only first gate should be evaluated (short-circuit)');
        $this->assertSame(GateResult::DENY, $gateEvaluations[0]->result);

        // Verify no actions were executed
        $this->assertCount(0, $context->getActionExecutions(), 'No actions should execute when gate denies');

        // Verify both actions were skipped
        $actionSkips = $context->getActionSkips();
        $this->assertCount(2, $actionSkips, 'Both actions should be skipped');

        // Verify execution log shows short-circuit behavior
        $this->assertContains('Gate:Gate1', $this->logger->log);
        $this->assertNotContains('Gate:Gate2', $this->logger->log, 'Gate2 should not be evaluated (short-circuit)');
        $this->assertNotContains('Gate:Gate3', $this->logger->log, 'Gate3 should not be evaluated (short-circuit)');
        $this->assertNotContains('Action:Action1', $this->logger->log);
        $this->assertNotContains('Action:Action2', $this->logger->log);
    }

    /**
     * Scenario 2.5: Gate with SKIP_IDEMPOTENT result
     * Tests that actions are skipped but transition succeeds
     */
    public function testGateWithSkipIdempotentResult(): void
    {
        $initialState = $this->createTestState(['status' => 'published', 'user_id' => 123]);

        // Create gate that returns SKIP_IDEMPOTENT (e.g., already in desired state)
        $idempotencyGate = $this->createTestGate('IdempotencyCheck', GateResult::SKIP_IDEMPOTENT);

        // Create actions that should be skipped
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$idempotencyGate],
            [$action1, $action2]
        ));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify the gate was evaluated
        $gateEvaluations = $context->getGateEvaluations()->toArray();
        $this->assertCount(1, $gateEvaluations, 'Gate should be evaluated');
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $gateEvaluations[0]->result);

        // Verify no actions were executed
        $this->assertCount(0, $context->getActionExecutions(), 'No actions should execute when gate returns SKIP_IDEMPOTENT');

        // Verify both actions were skipped with SKIP_IDEMPOTENT reason
        $actionSkips = $context->getActionSkips()->toArray();
        $this->assertCount(2, $actionSkips, 'Both actions should be skipped');
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $actionSkips[0]->gateResult);
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $actionSkips[1]->gateResult);

        // Verify execution log shows gate was evaluated but actions were not
        $this->assertContains('Gate:IdempotencyCheck', $this->logger->log);
        $this->assertNotContains('Action:Action1', $this->logger->log);
        $this->assertNotContains('Action:Action2', $this->logger->log);
    }

    /**
     * Scenario 2.3: Middle gate denies transition
     * Tests short-circuit evaluation when denial happens in the middle
     */
    public function testMiddleGateDeniesTransitionWithShortCircuit(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 123]);

        // Create gates - SECOND gate will deny
        $gate1 = $this->createTestGate('Gate1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('Gate2', GateResult::DENY);
        $gate3 = $this->createTestGate('Gate3', GateResult::ALLOW);

        // Create actions that should not execute
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2, $gate3],
            [$action1, $action2]
        ));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify gates 1 and 2 were evaluated, but NOT gate 3 (short-circuit)
        $gateEvaluations = $context->getGateEvaluations()->toArray();
        $this->assertCount(2, $gateEvaluations, 'Gates 1 and 2 should be evaluated');
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertSame(GateResult::DENY, $gateEvaluations[1]->result);

        // Verify no actions were executed
        $this->assertCount(0, $context->getActionExecutions(), 'No actions should execute when gate denies');

        // Verify both actions were skipped
        $actionSkips = $context->getActionSkips();
        $this->assertCount(2, $actionSkips, 'Both actions should be skipped');

        // Verify execution log shows short-circuit behavior
        $this->assertContains('Gate:Gate1', $this->logger->log);
        $this->assertContains('Gate:Gate2', $this->logger->log);
        $this->assertNotContains('Gate:Gate3', $this->logger->log, 'Gate3 should not be evaluated (short-circuit)');
        $this->assertNotContains('Action:Action1', $this->logger->log);
        $this->assertNotContains('Action:Action2', $this->logger->log);
    }

    /**
     * General test: Gate denial prevents action execution
     * Verifies that when any gate denies, all actions are skipped
     * Related to Scenarios 2.2, 2.3 (gate denial with short-circuit)
     */
    public function testGateDenialPreventsActionsFromExecuting(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 456]);

        // Create gates - second gate will deny
        $permissionGate = $this->createTestGate('PermissionCheck', GateResult::ALLOW);
        $validationGate = $this->createTestGate('ValidationGate', GateResult::DENY);

        // Create actions that should not execute
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$permissionGate, $validationGate],
            [$action1, $action2]
        ));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify gates were evaluated
        $gateEvaluations = $context->getGateEvaluations()->toArray();
        $this->assertCount(2, $gateEvaluations);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertSame(GateResult::DENY, $gateEvaluations[1]->result);

        // Verify no actions were executed (because gate denied)
        $this->assertCount(0, $context->getActionExecutions(), 'No actions should execute when gate denies');

        // Verify actions were skipped
        $actionSkips = $context->getActionSkips();
        $this->assertCount(2, $actionSkips, 'Both actions should be skipped');

        // Verify gates were evaluated but actions were not run
        $this->assertContains('Gate:PermissionCheck', $this->logger->log);
        $this->assertContains('Gate:ValidationGate', $this->logger->log);
        $this->assertNotContains('Action:Action1', $this->logger->log, 'Actions should not execute when gate denies');
        $this->assertNotContains('Action:Action2', $this->logger->log, 'Actions should not execute when gate denies');
    }

    /**
     * Scenario 2.4: Last gate denies transition
     * Tests short-circuit when the last gate denies
     */
    public function testLastGateDeniesTransition(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 123]);

        // Create gates - LAST gate will deny
        $gate1 = $this->createTestGate('Gate1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('Gate2', GateResult::ALLOW);
        $gate3 = $this->createTestGate('Gate3', GateResult::DENY);

        // Create actions that should not execute
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestAction('Action2');

        $stateFlow = new StateFlow(fn () => new Configuration(
            [$gate1, $gate2, $gate3],
            [$action1, $action2]
        ));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        // Verify all 3 gates were evaluated
        $gateEvaluations = $context->getGateEvaluations()->toArray();
        $this->assertCount(3, $gateEvaluations, 'All 3 gates should be evaluated');
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[1]->result);
        $this->assertSame(GateResult::DENY, $gateEvaluations[2]->result);

        // Verify no actions were executed
        $this->assertCount(0, $context->getActionExecutions(), 'No actions should execute when gate denies');

        // Verify both actions were skipped
        $actionSkips = $context->getActionSkips();
        $this->assertCount(2, $actionSkips, 'Both actions should be skipped');

        // Verify execution log shows all gates evaluated but no actions
        $this->assertContains('Gate:Gate1', $this->logger->log);
        $this->assertContains('Gate:Gate2', $this->logger->log);
        $this->assertContains('Gate:Gate3', $this->logger->log);
        $this->assertNotContains('Action:Action1', $this->logger->log);
        $this->assertNotContains('Action:Action2', $this->logger->log);
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
