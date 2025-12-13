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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);

        $serialized = $context->serialize();

        $this->assertIsString($serialized);
        $this->assertJson($serialized);
    }

    public function testSerializeAndUnserializeMinimalContext(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = $context->serialize();

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $newState = new TestState(['status' => 'published']);
        $context->recordActionExecution(new ActionResult(ExecutionState::CONTINUE, $newState));

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->executionStatus()->markPaused();

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->executionStatus()->markStopped();

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $lockState = new LockState('order:123', 1234567890.0, 30);
        $context->setLockState($lockState);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::DENY);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $metadata = ['reason' => 'waiting for approval', 'approver' => 'manager@example.com'];
        $context->recordActionExecution(ActionResult::pause(null, $metadata));

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->executionStatus()->markSkippedDueToLock();

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([$gate], [$action]);

        $context = new TransitionContext($initialState, new ArrayDelta($delta), $config);
        $context->updateCurrentState($currentState);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);
        $context->recordActionExecution(new ActionResult(ExecutionState::CONTINUE, $currentState));
        $context->executionStatus()->markCompleted();

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

        TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = $context->serialize();

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

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = $context->serialize();

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

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = $context->serialize();

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

        $restored = TransitionContext::unserialize(
            $modifiedSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        // Should have empty lock state
        $this->assertFalse($restored->lockState()->isLocked());
    }

    public function testUnserializeWithMissingSkippedDueToLock(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = $context->serialize();

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

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $serialized = $context->serialize();

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

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->executionStatus()->markCompleted();
        $serialized = $context->serialize();

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

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);
        $serialized = $context->serialize();

        // Decode and set invalid gate result
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        $data['gateEvaluations'][0]['result'] = 'INVALID_RESULT';
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionExecution(new ActionResult(ExecutionState::CONTINUE));
        $serialized = $context->serialize();

        // Decode and set invalid execution state
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        $data['actionExecutions'][0]['executionState'] = 'INVALID_STATE';
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::DENY);
        $serialized = $context->serialize();

        // Decode and set invalid gate result
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }
        $data['actionSkips'][0]['gateResult'] = 'INVALID_RESULT';
        $modifiedSerialized = json_encode($data);
        if ($modifiedSerialized === false) {
            $this->fail('Failed to encode JSON');
        }

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::DENY, false);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::SKIP_IDEMPOTENT, false);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::ALLOW);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::SKIP_IDEMPOTENT);

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionExecution(new ActionResult(ExecutionState::PAUSE));

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionExecution(new ActionResult(ExecutionState::STOP));

        $serialized = $context->serialize();
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionExecution(new ActionResult(ExecutionState::CONTINUE, new TestState(['status' => 'published'])));

        $serialized = $context->serialize();
        $data = json_decode($serialized, true);

        // Verify both keys exist in the serialized JSON
        $this->assertIsArray($data);
        $this->assertArrayHasKey('configuration', $data);
        $this->assertArrayHasKey('actions', $data['configuration'], 'Configuration should have "actions" key with action class names');
        $this->assertArrayHasKey('actionExecutions', $data, 'Top level should have "actionExecutions" key with execution results');

        // Verify configuration actions contains class names
        $this->assertCount(1, $data['configuration']['actions']);
        $this->assertSame(TestAction::class, $data['configuration']['actions'][0]);

        // Verify actionExecutions contains execution state and metadata
        $this->assertCount(1, $data['actionExecutions']);
        $this->assertArrayHasKey('executionState', $data['actionExecutions'][0]);
        $this->assertSame('CONTINUE', $data['actionExecutions'][0]['executionState']);

        // Verify unserialization works correctly
        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $serialized = $context->serialize();
        $data = json_decode($serialized, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('gateEvaluations', $data);
        $this->assertCount(1, $data['gateEvaluations']);
        $this->assertArrayHasKey('timestamp', $data['gateEvaluations'][0]);
        $this->assertIsFloat($data['gateEvaluations'][0]['timestamp']);
        $this->assertGreaterThan(0, $data['gateEvaluations'][0]['timestamp']);
    }

    public function testUnserializeRestoresGateEvaluationTimestamp(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $gate = new TestGate();
        $config = new Configuration([$gate], []);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $serialized = $context->serialize();
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }

        $originalTimestamp = $data['gateEvaluations'][0]['timestamp'];

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::DENY);

        $serialized = $context->serialize();
        $data = json_decode($serialized, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('actionSkips', $data);
        $this->assertCount(1, $data['actionSkips']);
        $this->assertArrayHasKey('timestamp', $data['actionSkips'][0]);
        $this->assertIsFloat($data['actionSkips'][0]['timestamp']);
        $this->assertGreaterThan(0, $data['actionSkips'][0]['timestamp']);
    }

    public function testUnserializeRestoresActionSkipTimestamp(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $action = new TestAction();
        $config = new Configuration([], [$action]);

        $context = new TransitionContext($state, new ArrayDelta($delta), $config);
        $context->recordActionSkip($action, GateResult::DENY);

        $serialized = $context->serialize();
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $this->fail('Failed to decode JSON');
        }

        $originalTimestamp = $data['actionSkips'][0]['timestamp'];

        $restored = TransitionContext::unserialize(
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
        $config = new Configuration([$gate], [$action]);

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
        $restored = TransitionContext::unserialize(
            $oldSerialized,
            $this->stateFactory,
            $this->actionFactory,
            $this->gateFactory
        );

        $evaluations = $restored->executionHistory()->getGateEvaluations()->toArray();
        $this->assertCount(1, $evaluations);
        $this->assertIsFloat($evaluations[0]->timestamp, 'Should auto-generate timestamp for old data');
        $this->assertGreaterThan(0, $evaluations[0]->timestamp);

        $skips = $restored->executionHistory()->getActionSkips()->toArray();
        $this->assertCount(1, $skips);
        $this->assertIsFloat($skips[0]->timestamp, 'Should auto-generate timestamp for old data');
        $this->assertGreaterThan(0, $skips[0]->timestamp);
    }

    public function testUnserializeLegacyFormatWithContinueStatus(): void
    {
        $state = new TestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $config = new Configuration([], []);

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

        $restored = TransitionContext::unserialize(
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

        $restored = TransitionContext::unserialize(
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

        $restored = TransitionContext::unserialize(
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

        $restored = TransitionContext::unserialize(
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

        $restored = TransitionContext::unserialize(
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
