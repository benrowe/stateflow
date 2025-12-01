<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Gate\Guardable;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

class StateFlowTest extends TestCase
{
    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    /**
     * Scenario 1.2: Execute transition with single action
     * Tests basic workflow execution with one action
     */
    public function testCanExecuteSimpleConfiguration(): void
    {
        $stateFlow = new StateFlow(function (State $state, array $delta): Configuration {
            $action1 = $this
                ->createMock(Action::class);
            $action1->method('execute')->willReturnCallback(function () {
                return ActionResult::continue();
            });

            return new Configuration([], [$action1]);
        });
        $context = $stateFlow
            ->transition($this->createMock(State::class), [])
            ->execute();
        $this->assertInstanceOf(TransitionContext::class, $context);
        $this->assertCount(1, $context->getActionExecutions());
        $action = $context->getActionExecutions()[0];
        $this->assertInstanceOf(ActionResult::class, $action);
        $this->assertSame(ExecutionState::CONTINUE, $action->executionState);
    }

    /**
     * Scenario 1.3: Execute transition with multiple actions
     * Tests workflow with multiple gates and actions executing in order
     */
    public function testCanExecuteWorkflowWithMultipleGatesAndActions(): void
    {
        // Create test state
        $initialState = $this->createTestState(['status' => 'draft', 'user_id' => 123, 'approved' => false]);

        // Create gates
        $permissionGate = $this->createTestGate('PermissionCheck', GateResult::ALLOW);
        $validationGate = $this->createTestGate('ValidationCheck', GateResult::ALLOW);
        $approvalGate = $this->createTestGate('ApprovalCheck', GateResult::ALLOW);

        // Create actions
        $updateStatusAction = $this->createTestAction('UpdateStatus');
        $sendNotificationAction = $this->createTestAction('SendNotification');
        $createAuditLogAction = $this->createTestAction('CreateAuditLog');

        // Configure StateFlow with multiple gates and actions
        $stateFlow = new StateFlow(function (State $state, array $delta) use (
            $permissionGate,
            $validationGate,
            $approvalGate,
            $updateStatusAction,
            $sendNotificationAction,
            $createAuditLogAction
        ): Configuration {
            // Configure gates and actions for this specific transition
            $gates = [$permissionGate, $validationGate, $approvalGate];
            $actions = [$updateStatusAction, $sendNotificationAction, $createAuditLogAction];

            return new Configuration($gates, $actions);
        });

        // Execute the transition
        $context = $stateFlow
            ->transition($initialState, ['status' => 'published', 'approved' => true])
            ->execute();

        // Verify execution
        $this->assertInstanceOf(TransitionContext::class, $context);

        // Verify all gates were evaluated
        $gateEvaluations = $context->getGateEvaluations();
        $this->assertCount(3, $gateEvaluations, 'All 3 gates should be evaluated');
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[1]->result);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[2]->result);

        // Verify all actions were executed
        $this->assertCount(3, $context->getActionExecutions());
        $actionResults = $context->getActionExecutions();
        $this->assertSame(ExecutionState::CONTINUE, $actionResults[0]->executionState);
        $this->assertSame(ExecutionState::CONTINUE, $actionResults[1]->executionState);
        $this->assertSame(ExecutionState::CONTINUE, $actionResults[2]->executionState);

        // Verify gates executed before actions
        $this->assertContains('Gate:PermissionCheck', $this->logger->log);
        $this->assertContains('Gate:ValidationCheck', $this->logger->log);
        $this->assertContains('Gate:ApprovalCheck', $this->logger->log);
        $this->assertContains('Action:UpdateStatus', $this->logger->log);
        $this->assertContains('Action:SendNotification', $this->logger->log);
        $this->assertContains('Action:CreateAuditLog', $this->logger->log);

        // Verify execution order: gates first, then actions
        $gate1Index = array_search('Gate:PermissionCheck', $this->logger->log, true);
        $gate2Index = array_search('Gate:ValidationCheck', $this->logger->log, true);
        $gate3Index = array_search('Gate:ApprovalCheck', $this->logger->log, true);
        $actionIndex1 = array_search('Action:UpdateStatus', $this->logger->log, true);
        $actionIndex2 = array_search('Action:SendNotification', $this->logger->log, true);
        $actionIndex3 = array_search('Action:CreateAuditLog', $this->logger->log, true);

        // All gates should execute before any actions
        $this->assertLessThan($actionIndex1, $gate1Index, 'Gates should execute before actions');
        $this->assertLessThan($actionIndex1, $gate2Index, 'Gates should execute before actions');
        $this->assertLessThan($actionIndex1, $gate3Index, 'Gates should execute before actions');

        // Actions should execute in order
        $this->assertLessThan($actionIndex2, $actionIndex1);
        $this->assertLessThan($actionIndex3, $actionIndex2);
    }

    /**
     * Scenario 3.1: Action returns new state
     * Tests that an action can return a new state and it's accessible via result.newState
     */
    public function testCanExecuteWorkflowWithActionReturningNewState(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'version' => 1]);
        $newState = $this->createTestState(['status' => 'published', 'version' => 2]);

        // Action that returns a new state
        $publishAction = $this->createTestActionWithState('PublishAction', $newState);

        $stateFlow = new StateFlow(fn () => new Configuration([], [$publishAction]));

        $context = $stateFlow
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        $this->assertCount(1, $context->getActionExecutions());
        $this->assertSame($newState, $context->getActionExecutions()[0]->newState);
        $this->assertContains('Action:PublishAction', $this->logger->log);
    }

    /**
     * Scenario 3.3: Action returns PAUSE
     * Tests that workflow pauses and subsequent actions don't execute
     */
    public function testActionReturnsPauseStopsExecution(): void
    {
        $initialState = $this->createTestState(['status' => 'pending']);

        // First action continues, second action pauses, third should NOT execute
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestActionWithResult('Action2', ActionResult::pause(null, ['reason' => 'awaiting approval']));
        $action3 = $this->createTestAction('Action3');

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action1, $action2, $action3]));

        $context = $stateFlow
            ->transition($initialState, ['status' => 'approved'])
            ->execute();

        // Only actions 1 and 2 should execute, action 3 should NOT
        $this->assertCount(2, $context->getActionExecutions(), 'Only 2 actions should execute (action 3 skipped due to pause)');
        $this->assertSame(ExecutionState::CONTINUE, $context->getActionExecutions()[0]->executionState);
        $this->assertSame(ExecutionState::PAUSE, $context->getActionExecutions()[1]->executionState);

        // Verify the context is marked as paused
        $this->assertTrue($context->isPaused(), 'Context should be marked as paused');
        $this->assertFalse($context->isCompleted(), 'Context should not be completed');
        $this->assertFalse($context->isStopped(), 'Context should not be stopped');

        // Verify pause metadata is stored
        $this->assertSame(['reason' => 'awaiting approval'], $context->getActionExecutions()[1]->metadata);

        // Verify execution log shows action 3 did NOT execute
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertContains('Action:Action2', $this->logger->log);
        $this->assertNotContains('Action:Action3', $this->logger->log, 'Action3 should not execute after pause');
    }

    /**
     * Scenario 3.4: Action returns STOP
     * Tests that workflow stops and subsequent actions don't execute
     */
    public function testActionReturnsStopHaltsExecution(): void
    {
        $initialState = $this->createTestState(['status' => 'processing']);

        // First action continues, second action stops, third should NOT execute
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestActionWithResult('Action2', ActionResult::stop(null, ['reason' => 'validation failed']));
        $action3 = $this->createTestAction('Action3');

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action1, $action2, $action3]));

        $context = $stateFlow
            ->transition($initialState, ['status' => 'failed'])
            ->execute();

        // Only actions 1 and 2 should execute, action 3 should NOT
        $this->assertCount(2, $context->getActionExecutions(), 'Only 2 actions should execute (action 3 skipped due to stop)');
        $this->assertSame(ExecutionState::CONTINUE, $context->getActionExecutions()[0]->executionState);
        $this->assertSame(ExecutionState::STOP, $context->getActionExecutions()[1]->executionState);

        // Verify the context is marked as stopped
        $this->assertTrue($context->isStopped(), 'Context should be marked as stopped');
        $this->assertFalse($context->isCompleted(), 'Context should not be completed');
        $this->assertFalse($context->isPaused(), 'Context should not be paused');

        // Verify stop metadata is stored
        $this->assertSame(['reason' => 'validation failed'], $context->getActionExecutions()[1]->metadata);

        // Verify execution log shows action 3 did NOT execute
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertContains('Action:Action2', $this->logger->log);
        $this->assertNotContains('Action:Action3', $this->logger->log, 'Action3 should not execute after stop');
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
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        // Verify all 3 gates were evaluated
        $gateEvaluations = $context->getGateEvaluations();
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
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        // Verify ONLY first gate was evaluated (short-circuit)
        $gateEvaluations = $context->getGateEvaluations();
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
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        // Verify the gate was evaluated
        $gateEvaluations = $context->getGateEvaluations();
        $this->assertCount(1, $gateEvaluations, 'Gate should be evaluated');
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $gateEvaluations[0]->result);

        // Verify no actions were executed
        $this->assertCount(0, $context->getActionExecutions(), 'No actions should execute when gate returns SKIP_IDEMPOTENT');

        // Verify both actions were skipped with SKIP_IDEMPOTENT reason
        $actionSkips = $context->getActionSkips();
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
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        // Verify gates 1 and 2 were evaluated, but NOT gate 3 (short-circuit)
        $gateEvaluations = $context->getGateEvaluations();
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
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        // Verify gates were evaluated
        $gateEvaluations = $context->getGateEvaluations();
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
     * Delta Access: Gates can access the desired delta
     * Verifies that gates receive the delta via GateContext::$desiredDelta
     */
    public function testGateCanAccessDelta(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'priority' => 'low']);
        $expectedDelta = ['status' => 'published', 'priority' => 'high'];

        $deltaCapture = null;
        $gate = new class ($deltaCapture, $this->logger) implements Gate {
            /** @phpstan-ignore property.onlyWritten */
            private mixed $deltaCapture;

            public function __construct(
                mixed &$deltaCapture,
                private ExecutionLogger $logger
            ) {
                $this->deltaCapture = &$deltaCapture;
            }

            public function evaluate(GateContext $context): GateResult
            {
                $this->logger->log[] = 'Gate:DeltaCheck';
                $this->deltaCapture = $context->desiredDelta;

                return GateResult::ALLOW;
            }

            public function message(): ?string
            {
                return 'DeltaCheck';
            }
        };

        $stateFlow = new StateFlow(fn () => new Configuration([$gate], []));

        $context = $stateFlow
            ->transition($initialState, $expectedDelta)
            ->execute();

        // Verify the gate received the delta
        $this->assertSame($expectedDelta, $deltaCapture, 'Gate should receive the delta');
        $this->assertSame($expectedDelta, $context->getDesiredDelta(), 'Context should store the delta');
    }

    /**
     * Delta Access: Actions can access the desired delta
     * Verifies that actions receive the delta via ActionContext::$desiredDelta
     * Enables actions to call $state->with($context->desiredDelta) for merging changes
     */
    public function testActionCanAccessDelta(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'version' => 1]);
        $expectedDelta = ['status' => 'published', 'version' => 2];

        $deltaCapture = null;
        $action = new class ($deltaCapture, $this->logger) implements Action {
            /** @phpstan-ignore property.onlyWritten */
            private mixed $deltaCapture;

            public function __construct(
                mixed &$deltaCapture,
                private ExecutionLogger $logger
            ) {
                $this->deltaCapture = &$deltaCapture;
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:DeltaCheck';
                $this->deltaCapture = $context->desiredDelta;

                return ActionResult::continue();
            }
        };

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action]));

        $context = $stateFlow
            ->transition($initialState, $expectedDelta)
            ->execute();

        // Verify the action received the delta
        $this->assertSame($expectedDelta, $deltaCapture, 'Action should receive the delta');
        $this->assertSame($expectedDelta, $context->getDesiredDelta(), 'Context should store the delta');
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
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        // Verify the action's gate was evaluated
        $gateEvaluations = $context->getGateEvaluations();
        $this->assertCount(1, $gateEvaluations, 'Action gate should be evaluated');
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertTrue($gateEvaluations[0]->isActionGate, 'Gate should be marked as action gate');

        // Verify the action executed
        $this->assertCount(1, $context->getActionExecutions(), 'Action should execute');
        $this->assertContains('Action:GuardedAction', $this->logger->log);

        // Verify no actions were skipped
        $this->assertCount(0, $context->getActionSkips(), 'No actions should be skipped');
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
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        // Verify the action's gate was evaluated
        $gateEvaluations = $context->getGateEvaluations();
        $this->assertCount(1, $gateEvaluations, 'Action gate should be evaluated');
        $this->assertSame(GateResult::DENY, $gateEvaluations[0]->result);
        $this->assertTrue($gateEvaluations[0]->isActionGate, 'Gate should be marked as action gate');

        // Verify the action did NOT execute
        $this->assertCount(0, $context->getActionExecutions(), 'Action should not execute');
        $this->assertNotContains('Action:GuardedAction', $this->logger->log);

        // Verify action was skipped
        $actionSkips = $context->getActionSkips();
        $this->assertCount(1, $actionSkips, 'Action should be skipped');
        $this->assertSame(GateResult::DENY, $actionSkips[0]->gateResult);
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
            ->transition($initialState, ['status' => 'published'])
            ->execute();

        // Verify all 3 action gates were evaluated
        $gateEvaluations = $context->getGateEvaluations();
        $this->assertCount(3, $gateEvaluations, 'All 3 action gates should be evaluated');
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[0]->result);
        $this->assertTrue($gateEvaluations[0]->isActionGate);
        $this->assertSame(GateResult::DENY, $gateEvaluations[1]->result);
        $this->assertTrue($gateEvaluations[1]->isActionGate);
        $this->assertSame(GateResult::ALLOW, $gateEvaluations[2]->result);
        $this->assertTrue($gateEvaluations[2]->isActionGate);

        // Verify actions 1 and 3 executed, action 2 did not
        $this->assertCount(2, $context->getActionExecutions(), 'Actions 1 and 3 should execute');
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertNotContains('Action:Action2', $this->logger->log, 'Action 2 should not execute');
        $this->assertContains('Action:Action3', $this->logger->log);

        // Verify action 2 was skipped
        $actionSkips = $context->getActionSkips();
        $this->assertCount(1, $actionSkips, 'Action 2 should be skipped');
    }

    /**
     * Scenario 3.5: Action updates state progressively
     * Tests that each action receives the state from the previous action,
     * and demonstrates using state.with(delta) to merge changes
     */
    public function testActionsUpdateStateProgressively(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'version' => 1, 'approved' => false]);
        $delta = ['status' => 'published', 'approved' => true];

        $stateCaptures = [];

        // Action 1: Uses state.with(delta) to merge changes
        $action1 = new class ($stateCaptures, $this->logger) implements Action {
            /** @var array<string, mixed> */
            private array $stateCaptures;

            /**
             * @param array<string, mixed> $stateCaptures
             */
            public function __construct(
                array &$stateCaptures,
                private ExecutionLogger $logger
            ) {
                $this->stateCaptures = &$stateCaptures;
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:MergeChanges';
                $this->stateCaptures['action1_received'] = $context->currentState->toArray();

                // Use state.with(delta) to merge the delta into current state
                $newState = $context->currentState->with($context->desiredDelta);
                $this->stateCaptures['action1_returned'] = $newState->toArray();

                return ActionResult::continue($newState);
            }
        };

        // Action 2: Increments version (should receive state from action 1)
        $action2 = new class ($stateCaptures, $this->logger) implements Action {
            /** @var array<string, mixed> */
            private array $stateCaptures;

            /**
             * @param array<string, mixed> $stateCaptures
             */
            public function __construct(
                array &$stateCaptures,
                private ExecutionLogger $logger
            ) {
                $this->stateCaptures = &$stateCaptures;
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:IncrementVersion';
                $this->stateCaptures['action2_received'] = $context->currentState->toArray();

                $currentData = $context->currentState->toArray();
                $newState = $context->currentState->with(['version' => $currentData['version'] + 1]);
                $this->stateCaptures['action2_returned'] = $newState->toArray();

                return ActionResult::continue($newState);
            }
        };

        // Action 3: Adds timestamp (should receive state from action 2)
        $action3 = new class ($stateCaptures, $this->logger) implements Action {
            /** @var array<string, mixed> */
            private array $stateCaptures;

            /**
             * @param array<string, mixed> $stateCaptures
             */
            public function __construct(
                array &$stateCaptures,
                private ExecutionLogger $logger
            ) {
                $this->stateCaptures = &$stateCaptures;
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:AddTimestamp';
                $this->stateCaptures['action3_received'] = $context->currentState->toArray();

                $newState = $context->currentState->with(['published_at' => '2024-01-01']);
                $this->stateCaptures['action3_returned'] = $newState->toArray();

                return ActionResult::continue($newState);
            }
        };

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action1, $action2, $action3]));

        $context = $stateFlow
            ->transition($initialState, $delta)
            ->execute();

        // Verify action 1 received initial state
        $this->assertSame(
            ['status' => 'draft', 'version' => 1, 'approved' => false],
            $stateCaptures['action1_received'],
            'Action 1 should receive initial state'
        );

        // Verify action 1 merged the delta
        $this->assertSame(
            ['status' => 'published', 'version' => 1, 'approved' => true],
            $stateCaptures['action1_returned'],
            'Action 1 should merge delta into state'
        );

        // Verify action 2 received state from action 1
        $this->assertSame(
            ['status' => 'published', 'version' => 1, 'approved' => true],
            $stateCaptures['action2_received'],
            'Action 2 should receive state from Action 1'
        );

        // Verify action 2 incremented version
        $this->assertSame(
            ['status' => 'published', 'version' => 2, 'approved' => true],
            $stateCaptures['action2_returned'],
            'Action 2 should increment version'
        );

        // Verify action 3 received state from action 2
        $this->assertSame(
            ['status' => 'published', 'version' => 2, 'approved' => true],
            $stateCaptures['action3_received'],
            'Action 3 should receive state from Action 2'
        );

        // Verify action 3 added timestamp
        $this->assertSame(
            ['status' => 'published', 'version' => 2, 'approved' => true, 'published_at' => '2024-01-01'],
            $stateCaptures['action3_returned'],
            'Action 3 should add timestamp'
        );

        // Verify getCurrentState() returns the final state
        $this->assertSame(
            ['status' => 'published', 'version' => 2, 'approved' => true, 'published_at' => '2024-01-01'],
            $context->getCurrentState()->toArray(),
            'getCurrentState() should return the final state after all actions'
        );
    }

    /**
     * @param array<string, mixed> $data
     * @throws Exception
     */
    private function createTestState(array $data): State
    {
        $state = $this->createStub(State::class);
        $state->method('toArray')->willReturn($data);
        $state->method('with')->willReturnCallback(function (array $changes) use ($data) {
            return $this->createTestState(array_merge($data, $changes));
        });

        return $state;
    }

    private function createTestGate(string $name, GateResult $result): Gate
    {
        return new class ($name, $result, $this->logger) implements Gate {
            public function __construct(
                private string $name,
                private GateResult $result,
                private ExecutionLogger $logger
            ) {
            }

            public function evaluate(GateContext $context): GateResult
            {
                $this->logger->log[] = 'Gate:' . $this->name;

                return $this->result;
            }

            public function message(): ?string
            {
                return $this->name;
            }
        };
    }

    private function createTestAction(string $name): Action
    {
        return new class ($name, $this->logger) implements Action {
            public function __construct(
                private string $name,
                private ExecutionLogger $logger
            ) {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return ActionResult::continue();
            }
        };
    }

    private function createTestActionWithState(string $name, State $newState): Action
    {
        return new class ($name, $newState, $this->logger) implements Action {
            public function __construct(
                private string $name,
                private State $newState,
                private ExecutionLogger $logger
            ) {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return ActionResult::continue($this->newState);
            }
        };
    }

    private function createTestActionWithResult(string $name, ActionResult $result): Action
    {
        return new class ($name, $result, $this->logger) implements Action {
            public function __construct(
                private string $name,
                private ActionResult $result,
                private ExecutionLogger $logger
            ) {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return $this->result;
            }
        };
    }

    private function createTestGuardedAction(string $name, GateResult $gateResult): Action
    {
        return new class ($name, $gateResult, $this->logger) implements Action, Guardable {
            public function __construct(
                private string $name,
                private GateResult $gateResult,
                private ExecutionLogger $logger
            ) {
            }

            public function gate(): Gate
            {
                return new class ($this->name, $this->gateResult, $this->logger) implements Gate {
                    public function __construct(
                        private string $actionName,
                        private GateResult $result,
                        private ExecutionLogger $logger
                    ) {
                    }

                    public function evaluate(GateContext $context): GateResult
                    {
                        $this->logger->log[] = 'Gate:' . $this->actionName . 'Gate';

                        return $this->result;
                    }

                    public function message(): ?string
                    {
                        return $this->actionName . 'Gate';
                    }
                };
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:' . $this->name;

                return ActionResult::continue();
            }
        };
    }
}
