<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

class ExecutionControlTest extends TestCase
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

        $stateFlow = new StateFlow(fn () => Configuration::fromArray([], [$action1, $action2, $action3]));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'approved']))
            ->execute();

        // Only actions 1 and 2 should execute, action 3 should NOT
        $this->assertCount(2, $context->executionHistory()->getActionExecutions(), 'Only 2 actions should execute (action 3 skipped due to pause)');
        $this->assertSame(ExecutionState::CONTINUE, $context->executionHistory()->getActionExecutions()->toArray()[0]->executionState);
        $this->assertSame(ExecutionState::PAUSE, $context->executionHistory()->getActionExecutions()->toArray()[1]->executionState);

        // Verify the context is marked as paused
        $this->assertTrue($context->executionStatus()->isPaused(), 'Context should be marked as paused');
        $this->assertFalse($context->executionStatus()->isCompleted(), 'Context should not be completed');
        $this->assertFalse($context->executionStatus()->isStopped(), 'Context should not be stopped');

        // Verify pause metadata is stored
        $this->assertSame(['reason' => 'awaiting approval'], $context->executionHistory()->getActionExecutions()->toArray()[1]->metadata);

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

        $stateFlow = new StateFlow(fn () => Configuration::fromArray([], [$action1, $action2, $action3]));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'failed']))
            ->execute();

        // Only actions 1 and 2 should execute, action 3 should NOT
        $this->assertCount(2, $context->executionHistory()->getActionExecutions(), 'Only 2 actions should execute (action 3 skipped due to stop)');
        $this->assertSame(ExecutionState::CONTINUE, $context->executionHistory()->getActionExecutions()->toArray()[0]->executionState);
        $this->assertSame(ExecutionState::STOP, $context->executionHistory()->getActionExecutions()->toArray()[1]->executionState);

        // Verify the context is marked as stopped
        $this->assertTrue($context->executionStatus()->isStopped(), 'Context should be marked as stopped');
        $this->assertFalse($context->executionStatus()->isCompleted(), 'Context should not be completed');
        $this->assertFalse($context->executionStatus()->isPaused(), 'Context should not be paused');

        // Verify stop metadata is stored
        $this->assertSame(['reason' => 'validation failed'], $context->executionHistory()->getActionExecutions()->toArray()[1]->metadata);

        // Verify execution log shows action 3 did NOT execute
        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertContains('Action:Action2', $this->logger->log);
        $this->assertNotContains('Action:Action3', $this->logger->log, 'Action3 should not execute after stop');
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
        $action1 = new class ($stateCaptures, $this->logger) implements Action
        {
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
                $newState = $context->currentState->with($context->desiredDelta->asArray());
                $this->stateCaptures['action1_returned'] = $newState->toArray();

                return ActionResult::continue($newState);
            }
        };

        // Action 2: Increments version (should receive state from action 1)
        $action2 = new class ($stateCaptures, $this->logger) implements Action
        {
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
        $action3 = new class ($stateCaptures, $this->logger) implements Action
        {
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

        $stateFlow = new StateFlow(fn () => Configuration::fromArray([], [$action1, $action2, $action3]));

        $context = $stateFlow
            ->transition($initialState, new ArrayDelta($delta))
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

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
