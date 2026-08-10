<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Integration\StateFlow;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\Action\ExecutionState;
use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Configuration\Configuration;
use CoverGenius\StateFlow\Delta;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\StateFlow;
use CoverGenius\StateFlow\Tests\Utils\ExecutionLogger;
use CoverGenius\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use CoverGenius\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use CoverGenius\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use CoverGenius\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class BasicExecutionTest extends TestCase
{
    use CreatesTestActions;
    use CreatesTestGates;
    use CreatesTestStates;

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
        $stateFlow = new StateFlow(function (State $state, Delta $delta): Configuration {
            $action1 = $this
                ->createMock(Action::class);
            $action1->method('execute')->willReturnCallback(function () {
                return ActionResult::continue();
            });

            return Configuration::fromArray([], [$action1]);
        });
        $context = $stateFlow
            ->transition($this->createMock(State::class), new ArrayDelta([]))
            ->execute();
        $this->assertInstanceOf(TransitionContext::class, $context);
        $this->assertCount(1, $context->executionHistory()->getActionExecutions());
        $action = $context->executionHistory()->getActionExecutions()->toArray()[0];
        $this->assertNotNull($action);
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
        $stateFlow = new StateFlow(function (State $state, Delta $delta) use (
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

            return Configuration::fromArray($gates, $actions);
        });

        // Execute the transition
        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published', 'approved' => true]))
            ->execute();

        // Verify execution
        $this->assertInstanceOf(TransitionContext::class, $context);

        // Verify all gates were evaluated
        $gateEvaluations = $context->executionHistory()->getGateEvaluations();
        $this->assertCount(3, $gateEvaluations, 'All 3 gates should be evaluated');
        $gateEval0 = $gateEvaluations[0];
        $gateEval1 = $gateEvaluations[1];
        $gateEval2 = $gateEvaluations[2];
        $this->assertNotNull($gateEval0);
        $this->assertNotNull($gateEval1);
        $this->assertNotNull($gateEval2);
        $this->assertSame(GateResult::ALLOW, $gateEval0->result);
        $this->assertSame(GateResult::ALLOW, $gateEval1->result);
        $this->assertSame(GateResult::ALLOW, $gateEval2->result);

        // Verify all actions were executed
        $this->assertCount(3, $context->executionHistory()->getActionExecutions());
        $actionResults = $context->executionHistory()->getActionExecutions();
        $actionResult0 = $actionResults[0];
        $actionResult1 = $actionResults[1];
        $actionResult2 = $actionResults[2];
        $this->assertNotNull($actionResult0);
        $this->assertNotNull($actionResult1);
        $this->assertNotNull($actionResult2);
        $this->assertSame(ExecutionState::CONTINUE, $actionResult0->executionState);
        $this->assertSame(ExecutionState::CONTINUE, $actionResult1->executionState);
        $this->assertSame(ExecutionState::CONTINUE, $actionResult2->executionState);

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

        $stateFlow = new StateFlow(fn () => Configuration::fromArray([], [$publishAction]));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'published']))
            ->execute();

        $this->assertCount(1, $context->executionHistory()->getActionExecutions());
        $this->assertSame($newState, $context->executionHistory()->getActionExecutions()->toArray()[0]->newState);
        $this->assertContains('Action:PublishAction', $this->logger->log);
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
