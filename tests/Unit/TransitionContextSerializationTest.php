<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\ActionFactory;
use BenRowe\StateFlow\ActionSkip;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\GateEvaluation;
use BenRowe\StateFlow\GateFactory;
use BenRowe\StateFlow\Locking\LockState;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFactory;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class TransitionContextSerializationTest extends TestCase
{
    private TestStateFactory $stateFactory;

    private TestActionFactory $actionFactory;

    private TestGateFactory $gateFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateFactory = new TestStateFactory();
        $this->actionFactory = new TestActionFactory();
        $this->gateFactory = new TestGateFactory();
    }

    public function testSerializeMinimalContext(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, $delta, $config);

        $serialized = $context->serialize();

        $this->assertIsString($serialized);
        $this->assertJson($serialized);
    }

    public function testSerializeAndUnserializeMinimalContext(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, $delta, $config);
        $serialized = $context->serialize();

        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertEquals($state->toArray(), $restored->getCurrentState()->toArray());
        $this->assertEquals($state->toArray(), $restored->getInitialState()->toArray());
        $this->assertSame($delta, $restored->getDesiredDelta());
    }

    public function testSerializeAndUnserializeWithGates(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $config = new Configuration([$gate], []);

        $context = new TransitionContext($state, $delta, $config);
        $context->addGateEvaluation($gate, GateResult::ALLOW, false);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $evaluations = $restored->getGateEvaluations();
        $this->assertCount(1, $evaluations);
        $this->assertInstanceOf(GateEvaluation::class, $evaluations[0]);
        $this->assertInstanceOf(TestGate::class, $evaluations[0]->gate);
        $this->assertSame(GateResult::ALLOW, $evaluations[0]->result);
        $this->assertFalse($evaluations[0]->isActionGate);
    }

    public function testSerializeAndUnserializeWithActions(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, $delta, $config);
        $newState = new TestState(['status' => 'published']);
        $context->addActionResult(new ActionResult(ExecutionState::CONTINUE, $newState));

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $executions = $restored->getActionExecutions();
        $this->assertCount(1, $executions);
        $this->assertSame(ExecutionState::CONTINUE, $executions[0]->executionState);
        $this->assertEquals($newState->toArray(), $executions[0]->newState?->toArray());
    }

    public function testSerializeAndUnserializeWithPausedStatus(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, $delta, $config);
        $context->markAsPaused();

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restored->isPaused());
        $this->assertFalse($restored->isCompleted());
        $this->assertFalse($restored->isStopped());
    }

    public function testSerializeAndUnserializeWithStoppedStatus(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, $delta, $config);
        $context->markAsStopped();

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restored->isStopped());
        $this->assertFalse($restored->isPaused());
        $this->assertFalse($restored->isCompleted());
    }

    public function testSerializeAndUnserializeWithLockState(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, $delta, $config);
        $lockState = new LockState('order:123', 1234567890.0, 30);
        $context->setLockState($lockState);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $restoredLock = $restored->getLockState();
        $this->assertTrue($restoredLock->isLocked());
        $this->assertSame('order:123', $restoredLock->lockKey);
        $this->assertSame(1234567890.0, $restoredLock->acquiredAt);
        $this->assertSame(30, $restoredLock->ttl);
    }

    public function testSerializeAndUnserializeWithActionSkips(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, $delta, $config);
        $context->addActionSkip($action, GateResult::DENY);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $skips = $restored->getActionSkips();
        $this->assertCount(1, $skips);
        $this->assertInstanceOf(ActionSkip::class, $skips[0]);
        $this->assertInstanceOf(TestAction::class, $skips[0]->action);
        $this->assertSame(GateResult::DENY, $skips[0]->gateResult);
    }

    public function testSerializeAndUnserializeWithMetadata(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, $delta, $config);
        $metadata = ['reason' => 'waiting for approval', 'approver' => 'manager@example.com'];
        $context->addActionResult(ActionResult::pause(null, $metadata));

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $executions = $restored->getActionExecutions();
        $this->assertCount(1, $executions);
        $this->assertSame($metadata, $executions[0]->metadata);
        $this->assertSame($metadata, $restored->getStatusMetadata());
    }

    public function testSerializeAndUnserializeWithSkippedDueToLock(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, $delta, $config);
        $context->markAsSkippedDueToLock();

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restored->wasSkippedDueToLock());
    }

    public function testSerializeAndUnserializeCompleteWorkflow(): void
    {
        $initialState = new TestState(['status' => 'draft']);
        $currentState = new TestState(['status' => 'published']);
        $delta = ['status' => 'published'];

        $gate = new TestGate();
        $action = new TestAction();
        $config = new Configuration([$gate], [$action]);

        $context = new TransitionContext($initialState, $delta, $config);
        $context->updateCurrentState($currentState);
        $context->addGateEvaluation($gate, GateResult::ALLOW, false);
        $context->addActionResult(new ActionResult(ExecutionState::CONTINUE, $currentState));
        $context->markAsCompleted();

        $lockState = new LockState('order:123', 1234567890.0, 30);
        $context->setLockState($lockState);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Verify state
        $this->assertEquals($initialState->toArray(), $restored->getInitialState()->toArray());
        $this->assertEquals($currentState->toArray(), $restored->getCurrentState()->toArray());
        $this->assertSame($delta, $restored->getDesiredDelta());

        // Verify configuration
        $this->assertCount(1, $restored->getConfiguration()->getTransitionGates());
        $this->assertCount(1, $restored->getConfiguration()->getActions());

        // Verify gate evaluations
        $this->assertCount(1, $restored->getGateEvaluations());

        // Verify action executions
        $this->assertCount(1, $restored->getActionExecutions());

        // Verify status
        $this->assertTrue($restored->isCompleted());

        // Verify lock state
        $this->assertTrue($restored->getLockState()->isLocked());
    }
}

// Test implementations

class TestState implements State
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return static
     */
    public function with(array $changes): static
    {
        /** @var static */
        return new self(array_merge($this->data, $changes));
    }
}

class TestGate implements Gate
{
    public function evaluate(GateContext $context): GateResult
    {
        return GateResult::ALLOW;
    }

    public function message(): ?string
    {
        return 'Test gate';
    }
}

class TestAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        return ActionResult::continue();
    }
}

class TestStateFactory implements StateFactory
{
    public function fromArray(array $data): State
    {
        return new TestState($data);
    }
}

class TestActionFactory implements ActionFactory
{
    public function fromClassName(string $className): Action
    {
        if ($className === TestAction::class) {
            return new TestAction();
        }

        throw new \RuntimeException("Unknown action class: {$className}");
    }
}

class TestGateFactory implements GateFactory
{
    public function fromClassName(string $className): Gate
    {
        if ($className === TestGate::class) {
            return new TestGate();
        }

        throw new \RuntimeException("Unknown gate class: {$className}");
    }
}
