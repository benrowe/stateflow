<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Delta;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Scenario 13.* - Complex Workflows
 */
class ComplexWorkflowTest extends TestCase
{
    use CreatesTestStates;

    /**
     * Scenario 13.1: Conditional branching based on state
     *
     * Given a ConfigurationProvider that returns different actions based on state
     * And state "draft" triggers approval workflow
     * And state "published" triggers notification workflow
     * When I transition from "draft" to "published"
     * Then the approval workflow actions should execute
     */
    public function testConditionalBranchingBasedOnState(): void
    {
        $executionLog = [];

        // Create actions for different workflows
        $checkApprovalAction = $this->createTrackingAction('CheckApproval', $executionLog);
        $sendApprovalNotificationAction = $this->createTrackingAction('SendApprovalNotification', $executionLog);
        $sendPublishedNotificationAction = $this->createTrackingAction('SendPublishedNotification', $executionLog);

        // Configuration provider that returns different actions based on state
        $configProvider = function (State $state, Delta $delta) use (
            $checkApprovalAction,
            $sendApprovalNotificationAction,
            $sendPublishedNotificationAction
        ): Configuration {
            $stateData = $state->toArray();

            // Draft -> Published: approval workflow
            if ($stateData['status'] === 'draft' && $delta->has('status') && $delta->get('status') === 'published') {
                return Configuration::fromArray([], [$checkApprovalAction, $sendApprovalNotificationAction]);
            }

            // Already published: just send notification
            if ($stateData['status'] === 'published') {
                return Configuration::fromArray([], [$sendPublishedNotificationAction]);
            }

            return Configuration::fromArray([], []);
        };

        $stateFlow = new StateFlow($configProvider);

        // Transition from draft to published - should execute approval workflow
        $draftState = $this->createTestState(['status' => 'draft', 'title' => 'My Document']);
        $worker = $stateFlow->transition($draftState, new ArrayDelta(['status' => 'published']));
        $context = $worker->execute();

        $this->assertTrue($context->executionStatus()->isCompleted());
        $this->assertSame(['CheckApproval', 'SendApprovalNotification'], $executionLog);

        // Reset log
        $executionLog = [];

        // Transition from published state - should execute published workflow
        $publishedState = $this->createTestState(['status' => 'published', 'title' => 'My Document']);
        $worker = $stateFlow->transition($publishedState, new ArrayDelta(['title' => 'Updated Document']));
        $context = $worker->execute();

        $this->assertTrue($context->executionStatus()->isCompleted());
        $this->assertSame(['SendPublishedNotification'], $executionLog);
    }

    /**
     * Scenario 13.2: Multi-step approval workflow
     *
     * Given a 3-step approval workflow
     * And step 1: check permissions (gate)
     * And step 2: create approval request (action)
     * And step 3: pause for manual approval (action returns PAUSE)
     * When I execute the workflow
     * Then the workflow should pause at step 3
     * And I can resume later with approval data
     * And step 4 (send notification) should execute on resume
     */
    public function testMultiStepApprovalWorkflow(): void
    {
        $executionLog = [];

        // Step 1: Permission gate (allows)
        $permissionGate = $this->createStubGate('PermissionGate', GateResult::ALLOW);

        // Step 2: Create approval request (action)
        $createApprovalAction = $this->createTrackingAction('CreateApprovalRequest', $executionLog);

        // Step 3: Pause for manual approval (action returns PAUSE with metadata)
        $waitForApprovalAction = $this->createPausingAction(
            'WaitForApproval',
            $executionLog,
            ['approval_id' => 'APR-123', 'status' => 'pending']
        );

        // Step 4: Send notification (action - should execute on resume)
        $sendNotificationAction = $this->createTrackingAction('SendNotification', $executionLog);

        $config = Configuration::fromArray(
            [$permissionGate],
            [$createApprovalAction, $waitForApprovalAction, $sendNotificationAction]
        );

        $stateFlow = new StateFlow(fn () => $config);

        // Execute the workflow - should pause at step 3
        $draftState = $this->createTestState(['status' => 'draft', 'title' => 'Document']);
        $worker = $stateFlow->transition($draftState, new ArrayDelta(['status' => 'pending_approval']));
        $context = $worker->execute();

        // Verify workflow paused
        $this->assertTrue($context->executionStatus()->isPaused());
        $this->assertFalse($context->executionStatus()->isCompleted());
        $this->assertSame(['CreateApprovalRequest', 'WaitForApproval'], $executionLog);

        // Verify we have 2 action executions (step 2 and step 3)
        $this->assertCount(2, $context->executionHistory()->getActionExecutions());

        // Resume the workflow - step 4 should execute
        $resumedWorker = $stateFlow->fromContext($context);
        $finalContext = $resumedWorker->execute();

        // Verify workflow completed
        $this->assertTrue($finalContext->executionStatus()->isCompleted());
        $this->assertFalse($finalContext->executionStatus()->isPaused());
        $this->assertSame(['CreateApprovalRequest', 'WaitForApproval', 'SendNotification'], $executionLog);

        // Verify all 3 actions executed
        $this->assertCount(3, $finalContext->executionHistory()->getActionExecutions());
    }

    /**
     * Scenario 13.4: Idempotent transitions
     *
     * Given a gate that checks if transition already occurred
     * And the transition was already completed
     * When I attempt the same transition again
     * Then the gate should return SKIP_IDEMPOTENT
     * And no actions should execute
     * And the workflow should complete successfully
     */
    public function testIdempotentTransitions(): void
    {
        $executionLog = [];
        $transitionHistory = [];

        // Gate that checks if transition already occurred
        $idempotencyGate = new class ($transitionHistory) implements Gate {
            /**
             * @param array<string> $history
             */
            public function __construct(private array &$history)
            {
            }

            public function evaluate(GateContext $context): GateResult
            {
                $state = $context->currentState->toArray();
                $delta = $context->desiredDelta;

                // Create a unique key for this transition
                $fromStatus = is_string($state['status'] ?? null) ? $state['status'] : 'unknown';
                $toStatus = is_string($delta->get('status')) ? $delta->get('status') : 'unknown';
                $key = sprintf('%s->%s', $fromStatus, $toStatus);

                // Check if we've seen this transition before
                if (in_array($key, $this->history, true)) {
                    return GateResult::SKIP_IDEMPOTENT;
                }

                // Record this transition
                $this->history[] = $key;

                return GateResult::ALLOW;
            }

            public function message(): string
            {
                return 'Idempotency check';
            }
        };

        $action = $this->createTrackingAction('ProcessTransition', $executionLog);

        $config = Configuration::fromArray([$idempotencyGate], [$action]);
        $stateFlow = new StateFlow(fn () => $config);

        // First transition: draft -> published
        $state = $this->createTestState(['status' => 'draft', 'title' => 'Document']);
        $worker = $stateFlow->transition($state, new ArrayDelta(['status' => 'published']));
        $context = $worker->execute();

        // Should complete successfully
        $this->assertTrue($context->executionStatus()->isCompleted());
        $this->assertSame(['ProcessTransition'], $executionLog);
        $this->assertCount(1, $context->executionHistory()->getActionExecutions());
        $this->assertCount(0, $context->executionHistory()->getActionSkips());

        // Reset log
        $executionLog = [];

        // Second transition: same transition (draft -> published)
        $state2 = $this->createTestState(['status' => 'draft', 'title' => 'Another Document']);
        $worker2 = $stateFlow->transition($state2, new ArrayDelta(['status' => 'published']));
        $context2 = $worker2->execute();

        // Should complete but skip actions due to idempotency
        $this->assertTrue($context2->executionStatus()->isCompleted());
        $this->assertSame([], $executionLog); // No actions executed
        $this->assertCount(0, $context2->executionHistory()->getActionExecutions());
        $this->assertCount(1, $context2->executionHistory()->getActionSkips()); // Action was skipped

        // Verify the skip reason is SKIP_IDEMPOTENT
        $skips = $context2->executionHistory()->getActionSkips();
        $skip = $skips[0];
        $this->assertNotNull($skip);
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $skip->gateResult);
    }

    private function createStubGate(string $name, GateResult $result): Gate
    {
        $gate = $this->createStub(Gate::class);
        $gate->method('evaluate')->willReturn($result);
        $gate->method('message')->willReturn($name);

        return $gate;
    }

    /**
     * @param array<string> $log
     */
    private function createTrackingAction(string $name, array &$log): Action
    {
        return new class ($name, $log) implements Action {
            /**
             * @param array<string> $log
             */
            public function __construct(
                private string $name,
                /** @phpstan-ignore property.onlyWritten */
                private array &$log
            ) {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->log[] = $this->name;

                return ActionResult::continue();
            }
        };
    }

    /**
     * @param array<string> $log
     * @param array<string, mixed> $metadata
     */
    private function createPausingAction(string $name, array &$log, array $metadata = []): Action
    {
        return new class ($name, $log, $metadata) implements Action {
            /**
             * @param array<string> $log
             * @param array<string, mixed> $metadata
             */
            public function __construct(
                private string $name,
                /** @phpstan-ignore property.onlyWritten */
                private array &$log,
                private array $metadata
            ) {
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->log[] = $this->name;

                return ActionResult::pause(null, $this->metadata);
            }
        };
    }
}
