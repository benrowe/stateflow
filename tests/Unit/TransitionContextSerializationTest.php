<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\ActionFactory;
use BenRowe\StateFlow\ActionSkip;
use BenRowe\StateFlow\ArrayDelta;
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
use BenRowe\StateFlow\TransitionContextSerializer;
use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);

        $serialized = (new TransitionContextSerializer())->serialize($context);

        $this->assertJson($serialized);
    }

    public function testSerializeAndUnserializeMinimalContext(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertEquals($state->toArray(), $restored->getCurrentState()->toArray());
        $this->assertEquals($state->toArray(), $restored->getInitialState()->toArray());
        $this->assertSame($delta, $restored->getDesiredDelta()->asArray());
    }

    public function testSerializeAndUnserializeWithGates(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $config = Configuration::fromArray([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $evaluations = $restored->executionHistory()->getGateEvaluations()->toArray();
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
        $config = Configuration::fromArray([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $newState = new TestState(['status' => 'published']);
        $context->recordActionExecution(new ActionResult(ExecutionState::CONTINUE, $newState));

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $executions = $restored->executionHistory()->getActionExecutions()->toArray();
        $this->assertCount(1, $executions);
        $this->assertSame(ExecutionState::CONTINUE, $executions[0]->executionState);
        $this->assertEquals($newState->toArray(), $executions[0]->newState?->toArray());
    }

    public function testSerializeAndUnserializeWithPausedStatus(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->executionStatus()->markPaused();

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isCompleted());
        $this->assertFalse($restored->executionStatus()->isStopped());
    }

    public function testSerializeAndUnserializeWithStoppedStatus(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->executionStatus()->markStopped();

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restored->executionStatus()->isStopped());
        $this->assertFalse($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isCompleted());
    }

    public function testSerializeAndUnserializeWithLockState(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $lockState = new LockState('order:123', 1234567890.0, 30);
        $context->setLockState($lockState);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $restoredLock = $restored->lockState();
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
        $config = Configuration::fromArray([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::DENY);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $skips = $restored->executionHistory()->getActionSkips()->toArray();
        $this->assertCount(1, $skips);
        $this->assertInstanceOf(ActionSkip::class, $skips[0]);
        $this->assertInstanceOf(TestAction::class, $skips[0]->action);
        $this->assertSame(GateResult::DENY, $skips[0]->gateResult);
    }

    public function testSerializeAndUnserializeWithMetadata(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $metadata = ['reason' => 'waiting for approval', 'approver' => 'manager@example.com'];
        $context->recordActionExecution(ActionResult::pause(null, $metadata));

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $executions = $restored->executionHistory()->getActionExecutions()->toArray();
        $this->assertCount(1, $executions);
        $this->assertSame($metadata, $executions[0]->metadata);
        $this->assertSame($metadata, $restored->getStatusMetadata());
    }

    public function testSerializeAndUnserializeWithSkippedDueToLock(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->executionStatus()->markSkippedDueToLock();

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restored->executionStatus()->wasSkippedDueToLock());
    }

    public function testSerializeAndUnserializeCompleteWorkflow(): void
    {
        $initialState = new TestState(['status' => 'draft']);
        $currentState = new TestState(['status' => 'published']);
        $delta = ['status' => 'published'];

        $gate = new TestGate();
        $action = new TestAction();
        $config = Configuration::fromArray([$gate], [$action]);

        $context = new TransitionContext($initialState, new ArrayDelta($delta), $config);
        $context->updateCurrentState($currentState);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);
        $context->recordActionExecution(new ActionResult(ExecutionState::CONTINUE, $currentState));
        $context->executionStatus()->markCompleted();

        $lockState = new LockState('order:123', 1234567890.0, 30);
        $context->setLockState($lockState);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Verify state
        $this->assertEquals($initialState->toArray(), $restored->getInitialState()->toArray());
        $this->assertEquals($currentState->toArray(), $restored->getCurrentState()->toArray());
        $this->assertSame($delta, $restored->getDesiredDelta()->asArray());

        // Verify configuration
        $this->assertCount(1, $restored->getConfiguration()->transitionGates);
        $this->assertCount(1, $restored->getConfiguration()->actions);

        // Verify gate evaluations
        $this->assertCount(1, $restored->executionHistory()->getGateEvaluations());

        // Verify action executions
        $this->assertCount(1, $restored->executionHistory()->getActionExecutions());

        // Verify status
        $this->assertTrue($restored->executionStatus()->isCompleted());

        // Verify lock state
        $this->assertTrue($restored->lockState()->isLocked());
    }

    public function testUnserializeWithInvalidJsonThrowsException(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Invalid JSON data: expected object');

        (new TransitionContextSerializer())->unserialize(
            'null',
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );
    }

    public function testUnserializeWithMissingConfigurationData(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and remove configuration
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        unset($data['configuration']);
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should have empty configuration
        $this->assertCount(0, $restored->getConfiguration()->transitionGates);
        $this->assertCount(0, $restored->getConfiguration()->actions);
    }

    public function testUnserializeWithInvalidConfigurationData(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and set configuration to a non-array value
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        $data['configuration'] = 'invalid';
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should have empty configuration (defaults when not array)
        $this->assertCount(0, $restored->getConfiguration()->transitionGates);
        $this->assertCount(0, $restored->getConfiguration()->actions);
    }

    public function testUnserializeWithNullLockStateData(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and set lockState to null
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        $data['lockState'] = null;
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should have empty lock state
        $this->assertFalse($restored->lockState()->isLocked());
    }

    public function testUnserializeWithInvalidLockStateType(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and set lockState to a string (invalid type)
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        $data['lockState'] = 'invalid_string_value';
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should have empty lock state when invalid type is provided
        $this->assertFalse($restored->lockState()->isLocked());
    }

    public function testUnserializeWithMissingSkippedDueToLock(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and remove skippedDueToLock
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        unset($data['skippedDueToLock']);
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should default to false
        $this->assertFalse($restored->executionStatus()->wasSkippedDueToLock());
    }

    public function testUnserializeWithNullStatus(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and set status to null
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        $data['status'] = null;
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should have no status
        $this->assertFalse($restored->executionStatus()->isCompleted());
        $this->assertFalse($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isStopped());
    }

    public function testUnserializeWithInvalidStatusUsesDefault(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->executionStatus()->markCompleted();
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and modify executionStatus to have all flags false (simulating invalid/corrupted state)
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        if (!isset($data['executionStatus']) || !is_array($data['executionStatus'])) {
            $this->fail('executionStatus not found in serialized data');
        }
        $data['executionStatus']['completed'] = false;
        $data['executionStatus']['paused'] = false;
        $data['executionStatus']['stopped'] = false;
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should have no status (all flags false)
        $this->assertFalse($restored->executionStatus()->isCompleted());
        $this->assertFalse($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isStopped());
    }

    public function testUnserializeWithInvalidGateResultUsesDefault(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $config = Configuration::fromArray([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and set invalid gate result
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        /** @var array<string, mixed> $data */
        $rawGateEvaluations = $data['gateEvaluations'] ?? [];
        if (!is_array($rawGateEvaluations)) {
            $this->fail('gateEvaluations should be an array');
        }
        /** @var array<int, mixed> $gateEvaluations */
        $gateEvaluations = $rawGateEvaluations;
        $rawFirstEvaluation = $gateEvaluations[0] ?? [];
        if (!is_array($rawFirstEvaluation)) {
            $this->fail('First gate evaluation should be an array');
        }
        /** @var array<string, mixed> $firstEvaluation */
        $firstEvaluation = $rawFirstEvaluation;
        $firstEvaluation['result'] = 'INVALID_RESULT';
        $gateEvaluations[0] = $firstEvaluation;
        $data['gateEvaluations'] = $gateEvaluations;
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should use default ALLOW
        $evaluations = $restored->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(1, $evaluations);
        $this->assertSame(GateResult::ALLOW, $evaluations[0]->result);
    }

    public function testUnserializeWithInvalidActionExecutionStateUsesDefault(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionExecution(new ActionResult(ExecutionState::CONTINUE));
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and set invalid execution state
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        /** @var array<string, mixed> $data */
        $rawActionExecutions = $data['actionExecutions'] ?? [];
        if (!is_array($rawActionExecutions)) {
            $this->fail('actionExecutions should be an array');
        }
        /** @var array<int, mixed> $actionExecutions */
        $actionExecutions = $rawActionExecutions;
        $rawFirstExecution = $actionExecutions[0] ?? [];
        if (!is_array($rawFirstExecution)) {
            $this->fail('First action execution should be an array');
        }
        /** @var array<string, mixed> $firstExecution */
        $firstExecution = $rawFirstExecution;
        $firstExecution['executionState'] = 'INVALID_STATE';
        $actionExecutions[0] = $firstExecution;
        $data['actionExecutions'] = $actionExecutions;
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should use default CONTINUE
        $executions = $restored->executionHistory()->getActionExecutions()->toArray();
        $this->assertCount(1, $executions);
        $this->assertSame(ExecutionState::CONTINUE, $executions[0]->executionState);
    }

    public function testUnserializeWithInvalidActionSkipGateResultUsesDefault(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = Configuration::fromArray([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::DENY);
        $serialized = (new TransitionContextSerializer())->serialize($context);

        // Decode and set invalid gate result
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        /** @var array<string, mixed> $data */
        $rawActionSkips = $data['actionSkips'] ?? [];
        if (!is_array($rawActionSkips)) {
            $this->fail('actionSkips should be an array');
        }
        /** @var array<int, mixed> $actionSkips */
        $actionSkips = $rawActionSkips;
        $rawFirstSkip = $actionSkips[0] ?? [];
        if (!is_array($rawFirstSkip)) {
            $this->fail('First action skip should be an array');
        }
        /** @var array<string, mixed> $firstSkip */
        $firstSkip = $rawFirstSkip;
        $firstSkip['gateResult'] = 'INVALID_RESULT';
        $actionSkips[0] = $firstSkip;
        $data['actionSkips'] = $actionSkips;
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should use default ALLOW
        $skips = $restored->executionHistory()->getActionSkips()->toArray();
        $this->assertCount(1, $skips);
        $this->assertSame(GateResult::ALLOW, $skips[0]->gateResult);
    }

    public function testSerializeAndUnserializeWithGateDenyResult(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $config = Configuration::fromArray([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::DENY, false);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $evaluations = $restored->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(1, $evaluations);
        $this->assertSame(GateResult::DENY, $evaluations[0]->result);
    }

    public function testSerializeAndUnserializeWithGateSkipIdempotentResult(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $config = Configuration::fromArray([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::SKIP_IDEMPOTENT, false);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $evaluations = $restored->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(1, $evaluations);
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $evaluations[0]->result);
    }

    public function testSerializeAndUnserializeWithActionSkipAllowResult(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = Configuration::fromArray([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::ALLOW);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $skips = $restored->executionHistory()->getActionSkips()->toArray();
        $this->assertCount(1, $skips);
        $this->assertSame(GateResult::ALLOW, $skips[0]->gateResult);
    }

    public function testSerializeAndUnserializeWithActionSkipIdempotentResult(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = Configuration::fromArray([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::SKIP_IDEMPOTENT);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $skips = $restored->executionHistory()->getActionSkips()->toArray();
        $this->assertCount(1, $skips);
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $skips[0]->gateResult);
    }

    public function testSerializeAndUnserializeWithPauseExecutionState(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionExecution(new ActionResult(ExecutionState::PAUSE));

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $executions = $restored->executionHistory()->getActionExecutions()->toArray();
        $this->assertCount(1, $executions);
        $this->assertSame(ExecutionState::PAUSE, $executions[0]->executionState);
    }

    public function testSerializeAndUnserializeWithStopExecutionState(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionExecution(new ActionResult(ExecutionState::STOP));

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $executions = $restored->executionHistory()->getActionExecutions()->toArray();
        $this->assertCount(1, $executions);
        $this->assertSame(ExecutionState::STOP, $executions[0]->executionState);
    }

    public function testSerializePreservesBothConfigurationActionsAndActionExecutions(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = Configuration::fromArray([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionExecution(new ActionResult(ExecutionState::CONTINUE, new TestState(['status' => 'published'])));

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $data = json_decode($serialized, true);

        // Verify both keys exist in the serialized JSON
        $this->assertIsArray($data);
        /** @var array<string, mixed> $data */
        $this->assertArrayHasKey('configuration', $data);
        $rawConfigData = $data['configuration'] ?? [];
        if (!is_array($rawConfigData)) {
            $this->fail('configuration should be an array');
        }
        /** @var array<string, mixed> $configData */
        $configData = $rawConfigData;
        $this->assertArrayHasKey('actions', $configData, 'Configuration should have "actions" key with action class names');
        $this->assertArrayHasKey('actionExecutions', $data, 'Top level should have "actionExecutions" key with execution results');

        // Verify configuration actions contains class names
        $rawConfigActions = $configData['actions'] ?? [];
        if (!is_array($rawConfigActions)) {
            $this->fail('configuration.actions should be an array');
        }
        /** @var array<int, mixed> $configActions */
        $configActions = $rawConfigActions;
        $this->assertCount(1, $configActions);
        $rawFirstAction = $configActions[0] ?? null;
        $this->assertSame(TestAction::class, $rawFirstAction);

        // Verify actionExecutions contains execution state and metadata
        $rawActionExecutions = $data['actionExecutions'] ?? [];
        if (!is_array($rawActionExecutions)) {
            $this->fail('actionExecutions should be an array');
        }
        /** @var array<int, mixed> $actionExecutions */
        $actionExecutions = $rawActionExecutions;
        $this->assertCount(1, $actionExecutions);
        $rawFirstExecution = $actionExecutions[0] ?? [];
        if (!is_array($rawFirstExecution)) {
            $this->fail('First action execution should be an array');
        }
        /** @var array<string, mixed> $firstExecution */
        $firstExecution = $rawFirstExecution;
        $this->assertArrayHasKey('executionState', $firstExecution);
        $this->assertSame('CONTINUE', $firstExecution['executionState']);

        // Verify unserialization works correctly
        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertCount(1, $restored->getConfiguration()->actions);
        $this->assertInstanceOf(TestAction::class, $restored->getConfiguration()->actions->toArray()[0]);
        $this->assertCount(1, $restored->executionHistory()->getActionExecutions());
        $this->assertSame(ExecutionState::CONTINUE, $restored->executionHistory()->getActionExecutions()->toArray()[0]->executionState);
    }

    public function testSerializeIncludesGateEvaluationTimestamp(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $config = Configuration::fromArray([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $data = json_decode($serialized, true);

        $this->assertIsArray($data);
        /** @var array<string, mixed> $data */
        $this->assertArrayHasKey('gateEvaluations', $data);
        $rawGateEvaluations = $data['gateEvaluations'] ?? [];
        if (!is_array($rawGateEvaluations)) {
            $this->fail('gateEvaluations should be an array');
        }
        /** @var array<int, mixed> $gateEvaluations */
        $gateEvaluations = $rawGateEvaluations;
        $this->assertCount(1, $gateEvaluations);
        $rawFirstEvaluation = $gateEvaluations[0] ?? [];
        if (!is_array($rawFirstEvaluation)) {
            $this->fail('First gate evaluation should be an array');
        }
        /** @var array<string, mixed> $firstEvaluation */
        $firstEvaluation = $rawFirstEvaluation;
        $this->assertArrayHasKey('timestamp', $firstEvaluation);
        $rawTimestamp = $firstEvaluation['timestamp'] ?? null;
        $this->assertIsFloat($rawTimestamp);
        $this->assertGreaterThan(0, $rawTimestamp);
    }

    public function testUnserializeRestoresGateEvaluationTimestamp(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $config = Configuration::fromArray([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        /** @var array<string, mixed> $data */
        $rawGateEvaluations = $data['gateEvaluations'] ?? [];
        if (!is_array($rawGateEvaluations)) {
            $this->fail('gateEvaluations should be an array');
        }
        /** @var array<int, mixed> $gateEvaluations */
        $gateEvaluations = $rawGateEvaluations;
        $rawFirstEvaluation = $gateEvaluations[0] ?? [];
        if (!is_array($rawFirstEvaluation)) {
            $this->fail('First gate evaluation should be an array');
        }
        /** @var array<string, mixed> $firstEvaluation */
        $firstEvaluation = $rawFirstEvaluation;
        $originalTimestamp = $firstEvaluation['timestamp'];

        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $evaluations = $restored->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(1, $evaluations);
        $this->assertSame($originalTimestamp, $evaluations[0]->timestamp, 'Timestamp should be preserved exactly');
    }

    public function testSerializeIncludesActionSkipTimestamp(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = Configuration::fromArray([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::DENY);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $data = json_decode($serialized, true);

        $this->assertIsArray($data);
        /** @var array<string, mixed> $data */
        $this->assertArrayHasKey('actionSkips', $data);
        $rawActionSkips = $data['actionSkips'] ?? [];
        if (!is_array($rawActionSkips)) {
            $this->fail('actionSkips should be an array');
        }
        /** @var array<int, mixed> $actionSkips */
        $actionSkips = $rawActionSkips;
        $this->assertCount(1, $actionSkips);
        $rawFirstSkip = $actionSkips[0] ?? [];
        if (!is_array($rawFirstSkip)) {
            $this->fail('First action skip should be an array');
        }
        /** @var array<string, mixed> $firstSkip */
        $firstSkip = $rawFirstSkip;
        $this->assertArrayHasKey('timestamp', $firstSkip);
        $rawTimestamp = $firstSkip['timestamp'] ?? null;
        $this->assertIsFloat($rawTimestamp);
        $this->assertGreaterThan(0, $rawTimestamp);
    }

    public function testUnserializeRestoresActionSkipTimestamp(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = Configuration::fromArray([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::DENY);

        $serialized = (new TransitionContextSerializer())->serialize($context);
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        /** @var array<string, mixed> $data */
        $rawActionSkips = $data['actionSkips'] ?? [];
        if (!is_array($rawActionSkips)) {
            $this->fail('actionSkips should be an array');
        }
        /** @var array<int, mixed> $actionSkips */
        $actionSkips = $rawActionSkips;
        $rawFirstSkip = $actionSkips[0] ?? [];
        if (!is_array($rawFirstSkip)) {
            $this->fail('First action skip should be an array');
        }
        /** @var array<string, mixed> $firstSkip */
        $firstSkip = $rawFirstSkip;
        $originalTimestamp = $firstSkip['timestamp'];

        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $skips = $restored->executionHistory()->getActionSkips()->toArray();
        $this->assertCount(1, $skips);
        $this->assertSame($originalTimestamp, $skips[0]->timestamp, 'Timestamp should be preserved exactly');
    }

    public function testUnserializeBackwardCompatibleWithMissingTimestamp(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $action = new TestAction();
        $config = Configuration::fromArray([$gate], [$action]);

        // Create old-style serialized data without timestamps
        $oldSerializedData = [
            'initialState' => $state->toArray(),
            'currentState' => $state->toArray(),
            'desiredDelta' => $delta,
            'status' => null,
            'skippedDueToLock' => false,
            'lockState' => [],
            'configuration' => [
                'transitionGates' => [TestGate::class],
                'actions' => [TestAction::class],
            ],
            'gateEvaluations' => [
                [
                    'gate' => TestGate::class,
                    'result' => 'ALLOW',
                    'isActionGate' => false,
                    // NO timestamp field - old format
                ],
            ],
            'actionExecutions' => [],
            'actionSkips' => [
                [
                    'action' => TestAction::class,
                    'gateResult' => 'DENY',
                    // NO timestamp field - old format
                ],
            ],
        ];

        $oldSerialized = json_encode($oldSerializedData);
        if ($oldSerialized === false) {
            $this->fail('Failed to encode old format JSON');
        }

        // Should unserialize successfully and auto-generate timestamps
        $restored = (new TransitionContextSerializer())->unserialize(
            $oldSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $evaluations = $restored->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(1, $evaluations);
        $this->assertGreaterThan(0, $evaluations[0]->timestamp, 'Should auto-generate timestamp for old data');

        $skips = $restored->executionHistory()->getActionSkips()->toArray();
        $this->assertCount(1, $skips);
        $this->assertGreaterThan(0, $skips[0]->timestamp, 'Should auto-generate timestamp for old data');
    }

    public function testUnserializeLegacyFormatWithContinueStatus(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = Configuration::fromArray([], []);

        // Create legacy format manually with "status" field instead of "executionStatus" object
        $legacyData = [
            'initialState' => ['status' => 'draft'],
            'currentState' => ['status' => 'draft'],
            'desiredDelta' => ['status' => 'published'],
            'status' => 'CONTINUE',
            'skippedDueToLock' => false,
            'lockState' => [],
            'configuration' => [
                'transitionGates' => [],
                'actions' => [],
            ],
            'gateEvaluations' => [],
            'actionExecutions' => [],
            'actionSkips' => [],
        ];

        $legacySerialized = json_encode($legacyData);
        if ($legacySerialized === false) {
            $this->fail('Failed to encode legacy data');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $legacySerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restored->executionStatus()->isCompleted());
        $this->assertFalse($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isStopped());
        $this->assertFalse($restored->executionStatus()->wasSkippedDueToLock());
    }

    public function testUnserializeLegacyFormatWithPauseStatus(): void
    {
        $legacyData = [
            'initialState' => ['status' => 'draft'],
            'currentState' => ['status' => 'draft'],
            'desiredDelta' => ['status' => 'published'],
            'status' => 'PAUSE',
            'skippedDueToLock' => false,
            'lockState' => [],
            'configuration' => [
                'transitionGates' => [],
                'actions' => [],
            ],
            'gateEvaluations' => [],
            'actionExecutions' => [],
            'actionSkips' => [],
        ];

        $legacySerialized = json_encode($legacyData);
        if ($legacySerialized === false) {
            $this->fail('Failed to encode legacy data');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $legacySerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertFalse($restored->executionStatus()->isCompleted());
        $this->assertTrue($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isStopped());
    }

    public function testUnserializeLegacyFormatWithStopStatus(): void
    {
        $legacyData = [
            'initialState' => ['status' => 'draft'],
            'currentState' => ['status' => 'draft'],
            'desiredDelta' => ['status' => 'published'],
            'status' => 'STOP',
            'skippedDueToLock' => false,
            'lockState' => [],
            'configuration' => [
                'transitionGates' => [],
                'actions' => [],
            ],
            'gateEvaluations' => [],
            'actionExecutions' => [],
            'actionSkips' => [],
        ];

        $legacySerialized = json_encode($legacyData);
        if ($legacySerialized === false) {
            $this->fail('Failed to encode legacy data');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $legacySerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertFalse($restored->executionStatus()->isCompleted());
        $this->assertFalse($restored->executionStatus()->isPaused());
        $this->assertTrue($restored->executionStatus()->isStopped());
    }

    public function testUnserializeLegacyFormatWithSkippedDueToLock(): void
    {
        $legacyData = [
            'initialState' => ['status' => 'draft'],
            'currentState' => ['status' => 'draft'],
            'desiredDelta' => ['status' => 'published'],
            'status' => 'CONTINUE',
            'skippedDueToLock' => true,
            'lockState' => [],
            'configuration' => [
                'transitionGates' => [],
                'actions' => [],
            ],
            'gateEvaluations' => [],
            'actionExecutions' => [],
            'actionSkips' => [],
        ];

        $legacySerialized = json_encode($legacyData);
        if ($legacySerialized === false) {
            $this->fail('Failed to encode legacy data');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $legacySerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $this->assertTrue($restored->executionStatus()->wasSkippedDueToLock());
    }

    public function testUnserializeLegacyFormatWithInvalidStatus(): void
    {
        $legacyData = [
            'initialState' => ['status' => 'draft'],
            'currentState' => ['status' => 'draft'],
            'desiredDelta' => ['status' => 'published'],
            'status' => 'INVALID_STATUS_VALUE',
            'skippedDueToLock' => false,
            'lockState' => [],
            'configuration' => [
                'transitionGates' => [],
                'actions' => [],
            ],
            'gateEvaluations' => [],
            'actionExecutions' => [],
            'actionSkips' => [],
        ];

        $legacySerialized = json_encode($legacyData);
        if ($legacySerialized === false) {
            $this->fail('Failed to encode legacy data');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $legacySerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should have no status when invalid value is provided
        $this->assertFalse($restored->executionStatus()->isCompleted());
        $this->assertFalse($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isStopped());
    }

    public function testUnserializeWithNonArrayExecutionStatus(): void
    {
        $data = [
            'initialState' => ['status' => 'draft'],
            'currentState' => ['status' => 'draft'],
            'desiredDelta' => [],
            'executionStatus' => 'invalid_string_value',  // Not an array
            'lockState' => [],
            'configuration' => [
                'transitionGates' => [],
                'actions' => [],
            ],
            'gateEvaluations' => [],
            'actionExecutions' => [],
            'actionSkips' => [],
        ];

        $serialized = json_encode($data);
        if ($serialized === false) {
            $this->fail('Failed to encode data');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            new TestStateFactory(),
            new TestActionFactory(),
            new TestGateFactory()
        );

        // Should have default execution status (not completed, paused, or stopped)
        $this->assertFalse($restored->executionStatus()->isCompleted());
        $this->assertFalse($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isStopped());
    }

    public function testUnserializeWithMissingExecutionStatusAndStatus(): void
    {
        $data = [
            'initialState' => ['status' => 'draft'],
            'currentState' => ['status' => 'draft'],
            'desiredDelta' => [],
            // No executionStatus field at all
            // No status field at all
            'lockState' => [],
            'configuration' => [
                'transitionGates' => [],
                'actions' => [],
            ],
            'gateEvaluations' => [],
            'actionExecutions' => [],
            'actionSkips' => [],
        ];

        $serialized = json_encode($data);
        if ($serialized === false) {
            $this->fail('Failed to encode data');
        }

        $restored = (new TransitionContextSerializer())->unserialize(
            $serialized,
            new TestStateFactory(),
            new TestActionFactory(),
            new TestGateFactory()
        );

        // Should have default execution status
        $this->assertFalse($restored->executionStatus()->isCompleted());
        $this->assertFalse($restored->executionStatus()->isPaused());
        $this->assertFalse($restored->executionStatus()->isStopped());
    }
}

// Test implementations

class TestState implements State
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

        throw new RuntimeException("Unknown action class: {$className}");
    }
}

class TestGateFactory implements GateFactory
{
    public function fromClassName(string $className): Gate
    {
        if ($className === TestGate::class) {
            return new TestGate();
        }

        throw new RuntimeException("Unknown gate class: {$className}");
    }
}
