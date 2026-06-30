<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

/**
 * Scenario 14.2: Document approval workflow with pause integration
 */
class DocumentApprovalWorkflowTest extends TestCase
{
    use CreatesTestStates;

    public function testDocumentApprovalWorkflowWithPauseAndResume(): void
    {
        $log = [];

        $approvalMetadata = [
            'approval_request_id' => 'APR-2026-001',
            'requested_by' => 'user-42',
            'status' => 'pending_approval',
        ];

        $permissionGate = $this->createPermissionGate($log);
        $validateFormatAction = $this->createValidateFormatAction($log);
        $createApprovalRequestAction = $this->createApprovalRequestAction($log, $approvalMetadata);
        $publishDocumentAction = $this->createPublishDocumentAction($log);
        $sendNotificationsAction = $this->createSendNotificationsAction($log);

        $configuration = Configuration::fromArray(
            [$permissionGate],
            [$validateFormatAction, $createApprovalRequestAction, $publishDocumentAction, $sendNotificationsAction]
        );

        $stateFlow = new StateFlow(fn () => $configuration);

        $draftState = $this->createTestState(['status' => 'draft', 'title' => 'Q3 Financial Report']);

        // Step 1: transition draft -> published, expect pause after the approval request action
        $worker = $stateFlow->transition($draftState, new ArrayDelta(['status' => 'published']));
        $pausedContext = $worker->execute();

        $this->assertSame(['PermissionGate', 'ValidateFormat', 'CreateApprovalRequest'], $log);
        $this->assertTrue($pausedContext->executionStatus()->isPaused());
        $this->assertFalse($pausedContext->executionStatus()->isCompleted());
        $this->assertCount(2, $pausedContext->executionHistory()->getActionExecutions());
        $this->assertSame($approvalMetadata, $pausedContext->getStatusMetadata());
        $this->assertSame('draft', $pausedContext->getCurrentState()->toArray()['status']);

        // Step 2: resume with approval granted - publish and send notifications should run
        $resumedWorker = $stateFlow->fromContext($pausedContext);
        $completedContext = $resumedWorker->execute();

        $this->assertSame(
            ['PermissionGate', 'ValidateFormat', 'CreateApprovalRequest', 'PermissionGate', 'PublishDocument', 'SendNotifications'],
            $log
        );
        $this->assertTrue($completedContext->executionStatus()->isCompleted());
        $this->assertFalse($completedContext->executionStatus()->isPaused());
        $this->assertCount(4, $completedContext->executionHistory()->getActionExecutions());
        $this->assertSame('published', $completedContext->getCurrentState()->toArray()['status']);
        $this->assertSame($pausedContext, $completedContext);
    }

    /**
     * @param array<string> $log
     */
    private function createPermissionGate(array &$log): Gate
    {
        return new class ($log) implements Gate
        {
            /**
             * @param array<string> $log
             */
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private array &$log
            ) {}

            public function evaluate(GateContext $context): GateResult
            {
                $this->log[] = 'PermissionGate';

                return GateResult::ALLOW;
            }

            public function message(): string
            {
                return 'User has permission to publish';
            }
        };
    }

    /**
     * @param array<string> $log
     */
    private function createValidateFormatAction(array &$log): Action
    {
        return new class ($log) implements Action
        {
            /**
             * @param array<string> $log
             */
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private array &$log
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->log[] = 'ValidateFormat';

                return ActionResult::continue();
            }
        };
    }

    /**
     * @param array<string> $log
     * @param array<string, mixed> $metadata
     */
    private function createApprovalRequestAction(array &$log, array $metadata): Action
    {
        return new class ($log, $metadata) implements Action
        {
            /**
             * @param array<string> $log
             * @param array<string, mixed> $metadata
             */
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private array &$log,
                private array $metadata
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->log[] = 'CreateApprovalRequest';

                return ActionResult::pause(null, $this->metadata);
            }
        };
    }

    /**
     * @param array<string> $log
     */
    private function createPublishDocumentAction(array &$log): Action
    {
        return new class ($log) implements Action
        {
            /**
             * @param array<string> $log
             */
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private array &$log
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->log[] = 'PublishDocument';

                return ActionResult::continue($context->currentState->with(['status' => 'published']));
            }
        };
    }

    /**
     * @param array<string> $log
     */
    private function createSendNotificationsAction(array &$log): Action
    {
        return new class ($log) implements Action
        {
            /**
             * @param array<string> $log
             */
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private array &$log
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->log[] = 'SendNotifications';

                return ActionResult::continue();
            }
        };
    }
}
