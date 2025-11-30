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

    public function testCanExecuteWorkflowWithActionsPausingExecution(): void
    {
        $initialState = $this->createTestState(['status' => 'pending']);

        // First action continues, second action pauses
        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestActionWithResult('Action2', ActionResult::pause(null, ['reason' => 'awaiting approval']));
        $action3 = $this->createTestAction('Action3');

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action1, $action2, $action3]));

        $context = $stateFlow
            ->transition($initialState, ['status' => 'approved'])
            ->execute();

        // All actions should execute (pausing doesn't stop execution in this implementation)
        $this->assertCount(3, $context->getActionExecutions());
        $this->assertSame(ExecutionState::CONTINUE, $context->getActionExecutions()[0]->executionState);
        $this->assertSame(ExecutionState::PAUSE, $context->getActionExecutions()[1]->executionState);
        $this->assertSame(ExecutionState::CONTINUE, $context->getActionExecutions()[2]->executionState);

        // Verify metadata on paused action
        $this->assertSame(['reason' => 'awaiting approval'], $context->getActionExecutions()[1]->metadata);
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
}
