<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\Yieldable;
use BenRowe\StateFlow\ActionFactory;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\GateFactory;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFactory;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use BenRowe\StateFlow\TransitionContextSerializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration tests for TransitionContext serialization and workflow persistence
 */
class SerializationTest extends TestCase
{
    use CreatesTestStates;

    private ExecutionLogger $logger;

    private SerializationStateFactory $stateFactory;

    private SerializationActionFactory $actionFactory;

    private SerializationGateFactory $gateFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
        $this->stateFactory = new SerializationStateFactory();
        $this->actionFactory = new SerializationActionFactory($this->logger);
        $this->gateFactory = new SerializationGateFactory();
    }

    /**
     * Scenario: Serialize paused workflow, then deserialize and resume
     *
     * This simulates a real-world use case where a workflow pauses
     * (e.g., waiting for external approval), gets serialized to database,
     * then later gets loaded and resumed.
     */
    public function testSerializeAndResumePausedWorkflow(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'approvals' => 0]);

        // Create actions
        $action1 = new LoggingAction('PrepareDocument', $this->logger);
        $action2 = new PausingAction('RequestApproval', $this->logger, ['awaiting' => 'manager']);
        $action3 = new LoggingAction('PublishDocument', $this->logger);

        $config = Configuration::fromArray([], [$action1, $action2, $action3]);

        // ===== PART 1: Execute workflow until pause =====
        $stateFlow1 = new StateFlow(fn () => $config);
        $worker1 = $stateFlow1->transition($initialState, new ArrayDelta(['status' => 'pending']));
        $pausedContext = $worker1->execute();

        // Verify workflow paused
        $this->assertTrue($pausedContext->executionStatus()->isPaused());
        $this->assertFalse($pausedContext->executionStatus()->isCompleted());
        $this->assertCount(2, $pausedContext->executionHistory()->getActionExecutions());

        // Verify metadata was captured
        $this->assertEquals(
            ['awaiting' => 'manager'],
            $pausedContext->getStatusMetadata()
        );

        // Verify execution log
        $this->assertContains('Action:PrepareDocument', $this->logger->log);
        $this->assertContains('Action:RequestApproval', $this->logger->log);
        $this->assertNotContains('Action:PublishDocument', $this->logger->log);

        // ===== PART 2: Serialize context (e.g., save to database) =====
        $serialized = (new TransitionContextSerializer())->serialize($pausedContext);
        $this->assertJson($serialized);

        // ===== PART 3: Simulate loading from database and resuming =====
        // In real app, this might happen in a different request/process
        $restoredContext = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Verify context was restored correctly
        $this->assertTrue($restoredContext->executionStatus()->isPaused());
        $this->assertCount(2, $restoredContext->executionHistory()->getActionExecutions());
        $this->assertEquals(
            ['awaiting' => 'manager'],
            $restoredContext->getStatusMetadata()
        );

        // ===== PART 4: Resume the workflow =====
        $stateFlow2 = new StateFlow(fn () => $config);
        $worker2 = $stateFlow2->fromContext($restoredContext);
        $completedContext = $worker2->execute();

        // Verify workflow completed
        $this->assertTrue($completedContext->executionStatus()->isCompleted());
        $this->assertFalse($completedContext->executionStatus()->isPaused());
        $this->assertCount(3, $completedContext->executionHistory()->getActionExecutions());

        // Verify action 3 executed
        $this->assertContains('Action:PublishDocument', $this->logger->log);

        // Verify actions 1 and 2 only ran once (not repeated on resume)
        $action1Count = count(array_keys($this->logger->log, 'Action:PrepareDocument', true));
        $action2Count = count(array_keys($this->logger->log, 'Action:RequestApproval', true));
        $this->assertSame(1, $action1Count);
        $this->assertSame(1, $action2Count);
    }

    /**
     * Scenario: Serialize workflow with gates
     *
     * Tests that gate evaluations are preserved through serialization
     */
    public function testSerializeWorkflowWithGates(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'has_content' => true]);

        $gate1 = new SimpleGate('ContentGate', GateResult::ALLOW);
        $action1 = new LoggingAction('PublishAction', $this->logger);

        $config = Configuration::fromArray([$gate1], [$action1]);

        // Execute workflow
        $stateFlow = new StateFlow(fn () => $config);
        $context = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'published']))->execute();

        // Serialize and unserialize
        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Verify gate evaluations were preserved
        $evaluations = $restored->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(1, $evaluations);
        $this->assertInstanceOf(SimpleGate::class, $evaluations[0]->gate);
        $this->assertSame(GateResult::ALLOW, $evaluations[0]->result);
    }

    /**
     * Scenario: Serialize stopped workflow
     *
     * Tests that workflows that stopped (due to error or business logic)
     * can be serialized and examined later
     */
    public function testSerializeStoppedWorkflow(): void
    {
        $initialState = $this->createTestState(['status' => 'processing']);

        $action1 = new LoggingAction('ProcessPayment', $this->logger);
        $action2 = new StoppingAction(
            'ValidatePayment',
            $this->logger,
            ['error' => 'Card declined', 'code' => 'INSUFFICIENT_FUNDS']
        );
        $action3 = new LoggingAction('SendConfirmation', $this->logger);

        $config = Configuration::fromArray([], [$action1, $action2, $action3]);

        // Execute workflow
        $stateFlow = new StateFlow(fn () => $config);
        $context = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'completed']))->execute();

        // Verify workflow stopped
        $this->assertTrue($context->executionStatus()->isStopped());
        $this->assertCount(2, $context->executionHistory()->getActionExecutions());

        // Serialize
        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Verify stopped status and metadata preserved
        $this->assertTrue($restored->executionStatus()->isStopped());
        $this->assertEquals(
            ['error' => 'Card declined', 'code' => 'INSUFFICIENT_FUNDS'],
            $restored->getStatusMetadata()
        );

        // Action 3 should not have executed
        $this->assertNotContains('Action:SendConfirmation', $this->logger->log);
    }

    /**
     * Scenario: Serialize workflow with state changes
     *
     * Tests that state transitions through actions are preserved
     */
    public function testSerializeWorkflowWithStateChanges(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'version' => 1]);
        $updatedState = $this->createTestState(['status' => 'review', 'version' => 2]);

        $action1 = new StateChangingAction('ReviewAction', $this->logger, $updatedState);
        $action2 = new PausingAction('AwaitApproval', $this->logger);

        $config = Configuration::fromArray([], [$action1, $action2]);

        // Execute workflow
        $stateFlow = new StateFlow(fn () => $config);
        $context = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'review']))->execute();

        // Verify state was updated
        $this->assertEquals(
            ['status' => 'review', 'version' => 2],
            $context->getCurrentState()->toArray()
        );

        // Serialize and unserialize
        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Verify both initial and current states preserved
        $this->assertEquals(
            ['status' => 'draft', 'version' => 1],
            $restored->getInitialState()->toArray()
        );
        $this->assertEquals(
            ['status' => 'review', 'version' => 2],
            $restored->getCurrentState()->toArray()
        );
    }

    /**
     * Scenario: Serialize yielded workflow, then deserialize and resume with a response
     *
     * This simulates an action dispatching async work (e.g. an external API call),
     * the transition being persisted mid-flight, and later resumed from a fresh
     * request once the response is available.
     */
    public function testSerializeAndResumeYieldedWorkflow(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $action1 = new LoggingAction('PrepareDocument', $this->logger);
        $action2 = new YieldingAction('RequestExternalCheck', $this->logger, ['checkId' => 'abc']);
        $action3 = new LoggingAction('PublishDocument', $this->logger);

        $config = Configuration::fromArray([], [$action1, $action2, $action3]);

        // ===== PART 1: Execute workflow until yield =====
        $stateFlow1 = new StateFlow(fn () => $config);
        $worker1 = $stateFlow1->transition($initialState, new ArrayDelta(['status' => 'pending']));
        $yieldedContext = $worker1->execute();

        $this->assertTrue($yieldedContext->executionStatus()->isYielded());
        $this->assertFalse($yieldedContext->executionStatus()->isCompleted());
        $this->assertCount(2, $yieldedContext->executionHistory()->getActionExecutions());
        $this->assertEquals(['checkId' => 'abc'], $yieldedContext->getStatusMetadata());
        $this->assertNotContains('Action:PublishDocument', $this->logger->log);

        // ===== PART 2: Serialize context (e.g., save to database) =====
        $serialized = (new TransitionContextSerializer())->serialize($yieldedContext);
        $this->assertJson($serialized);

        // ===== PART 3: Simulate loading from database in a new request =====
        $restoredContext = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restoredContext->executionStatus()->isYielded());
        $this->assertCount(2, $restoredContext->executionHistory()->getActionExecutions());
        $this->assertEquals(['checkId' => 'abc'], $restoredContext->getStatusMetadata());

        // ===== PART 4: Resume the workflow with the external response =====
        $stateFlow2 = new StateFlow(fn () => $config);
        $worker2 = $stateFlow2->fromContext($restoredContext);
        $completedContext = $worker2->resumeWithResponse(['outcome' => 'approved']);

        $this->assertTrue($completedContext->executionStatus()->isCompleted());
        $this->assertFalse($completedContext->executionStatus()->isYielded());
        $this->assertCount(4, $completedContext->executionHistory()->getActionExecutions());

        // Verify action 3 executed after resume
        $this->assertContains('Action:PublishDocument', $this->logger->log);

        // Verify the yielding action ran exactly twice (initial yield + resumed continue)
        $action2Count = count(array_keys($this->logger->log, 'Action:RequestExternalCheck', true));
        $this->assertSame(2, $action2Count);
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}

// Test support classes

class LoggingAction implements Action
{
    public function __construct(
        private string $name,
        private ExecutionLogger $logger
    ) {}

    public function execute(ActionContext $context): ActionResult
    {
        $this->logger->log[] = 'Action:' . $this->name;

        return ActionResult::continue();
    }
}

class PausingAction implements Action
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        private string $name,
        private ExecutionLogger $logger,
        private ?array $metadata = null
    ) {}

    public function execute(ActionContext $context): ActionResult
    {
        $this->logger->log[] = 'Action:' . $this->name;

        return ActionResult::pause(null, $this->metadata);
    }
}

class StoppingAction implements Action
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        private string $name,
        private ExecutionLogger $logger,
        private ?array $metadata = null
    ) {}

    public function execute(ActionContext $context): ActionResult
    {
        $this->logger->log[] = 'Action:' . $this->name;

        return ActionResult::stop(null, $this->metadata);
    }
}

class YieldingAction implements Action, Yieldable
{
    public function __construct(
        private string $name,
        private ExecutionLogger $logger,
        private mixed $yieldMetadata = null
    ) {}

    public function execute(ActionContext $context): ActionResult
    {
        $this->logger->log[] = 'Action:' . $this->name;

        if ($context->hasYieldResponse()) {
            return ActionResult::continue();
        }

        return ActionResult::yield($this->yieldMetadata);
    }
}

class StateChangingAction implements Action
{
    public function __construct(
        private string $name,
        private ExecutionLogger $logger,
        private State $newState
    ) {}

    public function execute(ActionContext $context): ActionResult
    {
        $this->logger->log[] = 'Action:' . $this->name;

        return ActionResult::continue($this->newState);
    }
}

class SimpleGate implements Gate
{
    public function __construct(
        private string $name,
        private GateResult $result
    ) {}

    public function evaluate(GateContext $context): GateResult
    {
        return $this->result;
    }

    public function message(): ?string
    {
        return $this->name;
    }
}

// Factories for deserialization

class SerializationStateFactory implements StateFactory
{
    public function fromArray(array $data): State
    {
        return new class ($data) implements State
        {
            /**
             * @param array<string, mixed> $data
             */
            public function __construct(private array $data) {}

            public function toArray(): array
            {
                return $this->data;
            }

            public function with(array $changes): static
            {
                return new self(array_merge($this->data, $changes));
            }
        };
    }
}

class SerializationActionFactory implements ActionFactory
{
    public function __construct(private ExecutionLogger $logger) {}

    public function fromClassName(string $className): Action
    {
        // In a real implementation, you'd need to store enough data to reconstruct
        // actions with their original parameters. For this test, we create generic
        // instances that will work for serialization/deserialization testing.
        return match ($className) {
            LoggingAction::class => new LoggingAction('PublishDocument', $this->logger),
            PausingAction::class => new PausingAction('RequestApproval', $this->logger, ['awaiting' => 'manager']),
            StoppingAction::class => new StoppingAction('ValidatePayment', $this->logger),
            YieldingAction::class => new YieldingAction('RequestExternalCheck', $this->logger, ['checkId' => 'abc']),
            StateChangingAction::class => new StateChangingAction(
                'ReviewAction',
                $this->logger,
                (new SerializationStateFactory())->fromArray(['status' => 'review', 'version' => 2])
            ),
            default => throw new RuntimeException("Unknown action class: {$className}"),
        };
    }
}

class SerializationGateFactory implements GateFactory
{
    public function fromClassName(string $className): Gate
    {
        return match ($className) {
            SimpleGate::class => new SimpleGate('Restored', GateResult::ALLOW),
            default => throw new RuntimeException("Unknown gate class: {$className}"),
        };
    }
}
