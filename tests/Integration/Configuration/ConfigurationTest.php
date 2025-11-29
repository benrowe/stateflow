<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\Configuration;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Configuration\CallableConfigurationProvider;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    public function testConfigurationProviderSupportsConditionalGatesBasedOnTransition(): void
    {
        $state = $this->createStubState(['status' => 'pending', 'user_id' => 123]);

        $permissionGate = $this->createStubGate('PermissionGate', GateResult::ALLOW);
        $validationGate = $this->createStubGate('ValidationGate', GateResult::ALLOW);

        $provider = new CallableConfigurationProvider(
            function (State $state, array $delta) use ($permissionGate, $validationGate) {
                // Different gates based on the transition type
                if (isset($delta['status']) && $delta['status'] === 'published') {
                    // Publishing requires permission check
                    return new Configuration([$permissionGate], []);
                }

                if (isset($delta['content'])) {
                    // Content changes require validation
                    return new Configuration([$validationGate], []);
                }

                return new Configuration([], []);
            }
        );

        // Test publishing transition
        $publishConfig = $provider->provide($state, ['status' => 'published']);
        $this->assertCount(1, $publishConfig->getTransitionGates());
        $this->assertSame($permissionGate, $publishConfig->getTransitionGates()[0]);

        // Test content update transition
        $contentConfig = $provider->provide($state, ['content' => 'New content']);
        $this->assertCount(1, $contentConfig->getTransitionGates());
        $this->assertSame($validationGate, $contentConfig->getTransitionGates()[0]);

        // Test simple transition
        $simpleConfig = $provider->provide($state, ['priority' => 'high']);
        $this->assertCount(0, $simpleConfig->getTransitionGates());
    }

    public function testConfigurationProviderSupportsConditionalActionsBasedOnState(): void
    {
        $draftState = $this->createStubState(['status' => 'draft', 'version' => 1]);
        $publishedState = $this->createStubState(['status' => 'published', 'version' => 2]);

        $sendNotificationAction = $this->createStubAction('SendNotification');
        $incrementVersionAction = $this->createStubAction('IncrementVersion');
        $updateIndexAction = $this->createStubAction('UpdateSearchIndex');

        $provider = new CallableConfigurationProvider(
            function (State $state, array $delta) use (
                $sendNotificationAction,
                $incrementVersionAction,
                $updateIndexAction
            ) {
                $stateData = $state->toArray();
                $actions = [];

                // Always increment version on content changes
                if (isset($delta['content'])) {
                    $actions[] = $incrementVersionAction;
                }

                // Send notification when publishing
                if ($stateData['status'] === 'draft' && isset($delta['status']) && $delta['status'] === 'published') {
                    $actions[] = $sendNotificationAction;
                }

                // Update search index for published content
                if (isset($delta['status']) && $delta['status'] === 'published') {
                    $actions[] = $updateIndexAction;
                }

                return new Configuration([], $actions);
            }
        );

        // Test publishing from draft
        $publishConfig = $provider->provide($draftState, ['status' => 'published']);
        $this->assertCount(2, $publishConfig->getActions());
        $this->assertContains($sendNotificationAction, $publishConfig->getActions());
        $this->assertContains($updateIndexAction, $publishConfig->getActions());

        // Test content update on published
        $contentConfig = $provider->provide($publishedState, ['content' => 'Updated']);
        $this->assertCount(1, $contentConfig->getActions());
        $this->assertSame($incrementVersionAction, $contentConfig->getActions()[0]);
    }

    public function testConfigurationProviderSupportsComplexWorkflowScenarios(): void
    {
        $gate1 = $this->createStubGate('RequireApproval', GateResult::ALLOW);
        $gate2 = $this->createStubGate('CheckBudget', GateResult::ALLOW);
        $action1 = $this->createStubAction('CreateAuditLog');
        $action2 = $this->createStubAction('NotifyApprovers');
        $action3 = $this->createStubAction('ProcessPayment');

        $provider = new CallableConfigurationProvider(
            function (State $state, array $delta) use ($gate1, $gate2, $action1, $action2, $action3) {
                $stateData = $state->toArray();
                $gates = [];
                $actions = [];

                // Workflow: pending -> approved
                if ($stateData['status'] === 'pending' && isset($delta['status']) && $delta['status'] === 'approved') {
                    $gates[] = $gate1; // RequireApproval
                    $actions[] = $action1; // CreateAuditLog
                    $actions[] = $action2; // NotifyApprovers
                }

                // Workflow: approved -> paid
                if ($stateData['status'] === 'approved' && isset($delta['status']) && $delta['status'] === 'paid') {
                    $gates[] = $gate2; // CheckBudget
                    $actions[] = $action1; // CreateAuditLog
                    $actions[] = $action3; // ProcessPayment
                }

                return new Configuration($gates, $actions);
            }
        );

        // Test approval workflow
        $pendingState = $this->createStubState(['status' => 'pending', 'amount' => 1000]);
        $approvalConfig = $provider->provide($pendingState, ['status' => 'approved']);

        $this->assertCount(1, $approvalConfig->getTransitionGates());
        $this->assertSame($gate1, $approvalConfig->getTransitionGates()[0]);
        $this->assertCount(2, $approvalConfig->getActions());
        $this->assertContains($action1, $approvalConfig->getActions());
        $this->assertContains($action2, $approvalConfig->getActions());

        // Test payment workflow
        $approvedState = $this->createStubState(['status' => 'approved', 'amount' => 1000]);
        $paymentConfig = $provider->provide($approvedState, ['status' => 'paid']);

        $this->assertCount(1, $paymentConfig->getTransitionGates());
        $this->assertSame($gate2, $paymentConfig->getTransitionGates()[0]);
        $this->assertCount(2, $paymentConfig->getActions());
        $this->assertContains($action1, $paymentConfig->getActions());
        $this->assertContains($action3, $paymentConfig->getActions());
    }

    private function createStubState(array $data): State
    {
        $state = $this->createStub(State::class);
        $state->method('toArray')->willReturn($data);
        $state->method('with')->willReturnCallback(function (array $changes) use ($data) {
            return $this->createStubState(array_merge($data, $changes));
        });

        return $state;
    }

    private function createStubGate(string $name, GateResult $result): Gate
    {
        $gate = $this->createStub(Gate::class);
        $gate->method('evaluate')->willReturn($result);
        $gate->method('message')->willReturn($name);

        return $gate;
    }

    private function createStubAction(string $name): Action
    {
        return new class ($name) implements Action {
            public function __construct(private string $name)
            {
            }

            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::continue();
            }

            public function getName(): string
            {
                return $this->name;
            }
        };
    }
}
