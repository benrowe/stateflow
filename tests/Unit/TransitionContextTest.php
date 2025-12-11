<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ActionSkip;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\GateEvaluation;
use BenRowe\StateFlow\Locking\LockState;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class TransitionContextTest extends TestCase
{
    use CreatesTestGates;

    private State $mockState;

    private Configuration $mockConfiguration;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockState = $this->createStub(State::class);
        $this->mockConfiguration = new Configuration([], []);
        $this->logger = new ExecutionLogger();
    }

    public function testConstructWithoutDelta(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $this->assertSame($this->mockState, $context->getCurrentState());
        $this->assertSame($this->mockState, $context->getInitialState());
        $this->assertSame([], $context->getDesiredDelta()->asArray());
        $this->assertSame($this->mockConfiguration, $context->getConfiguration());
    }

    public function testConstructWithDelta(): void
    {
        $delta = ['status' => 'published', 'priority' => 'high'];
        $context = new TransitionContext($this->mockState, new ArrayDelta($delta), $this->mockConfiguration);

        $this->assertSame($this->mockState, $context->getCurrentState());
        $this->assertSame($this->mockState, $context->getInitialState());
        $this->assertSame($delta, $context->getDesiredDelta()->asArray());
    }

    public function testUpdateCurrentState(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);
        $newState = $this->createStub(State::class);

        $context->updateCurrentState($newState);

        $this->assertSame($newState, $context->getCurrentState());
        $this->assertSame($this->mockState, $context->getInitialState(), 'Initial state should remain unchanged');
    }

    public function testGetDesiredDelta(): void
    {
        $delta = ['foo' => 'bar', 'baz' => 123];
        $context = new TransitionContext($this->mockState, new ArrayDelta($delta), $this->mockConfiguration);

        $this->assertSame($delta, $context->getDesiredDelta()->asArray());
    }

    public function testActionExecutionsEmptyByDefault(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $this->assertSame([], $context->getActionExecutions());
    }

    public function testAddActionResult(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();

        $context->addActionResult($result1);
        $context->addActionResult($result2);

        $executions = $context->getActionExecutions();
        $this->assertCount(2, $executions);
        $this->assertSame($result1, $executions[0]);
        $this->assertSame($result2, $executions[1]);
    }

    public function testGateEvaluationsEmptyByDefault(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $this->assertSame([], $context->getGateEvaluations());
    }

    public function testAddGateEvaluation(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);
        $gate1 = $this->createStub(Gate::class);
        $gate2 = $this->createStub(Gate::class);

        $context->addGateEvaluation($gate1, GateResult::ALLOW, false);
        $context->addGateEvaluation($gate2, GateResult::DENY, true);

        $evaluations = $context->getGateEvaluations();
        $this->assertCount(2, $evaluations);

        $this->assertInstanceOf(GateEvaluation::class, $evaluations[0]);
        $this->assertSame($gate1, $evaluations[0]->gate);
        $this->assertSame(GateResult::ALLOW, $evaluations[0]->result);
        $this->assertFalse($evaluations[0]->isActionGate);

        $this->assertInstanceOf(GateEvaluation::class, $evaluations[1]);
        $this->assertSame($gate2, $evaluations[1]->gate);
        $this->assertSame(GateResult::DENY, $evaluations[1]->result);
        $this->assertTrue($evaluations[1]->isActionGate);
    }

    public function testActionSkipsEmptyByDefault(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $this->assertSame([], $context->getActionSkips());
    }

    public function testAddActionSkip(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);
        $action1 = $this->createStub(Action::class);
        $action2 = $this->createStub(Action::class);

        $context->addActionSkip($action1, GateResult::DENY);
        $context->addActionSkip($action2, GateResult::SKIP_IDEMPOTENT);

        $skips = $context->getActionSkips();
        $this->assertCount(2, $skips);

        $this->assertInstanceOf(ActionSkip::class, $skips[0]);
        $this->assertSame($action1, $skips[0]->action);
        $this->assertSame(GateResult::DENY, $skips[0]->gateResult);

        $this->assertInstanceOf(ActionSkip::class, $skips[1]);
        $this->assertSame($action2, $skips[1]->action);
        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $skips[1]->gateResult);
    }

    public function testStatusDefaultsToNull(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $this->assertFalse($context->isCompleted());
        $this->assertFalse($context->isPaused());
        $this->assertFalse($context->isStopped());
    }

    public function testMarkAsCompleted(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $context->markAsCompleted();

        $this->assertTrue($context->isCompleted());
        $this->assertFalse($context->isPaused());
        $this->assertFalse($context->isStopped());
    }

    public function testMarkAsPaused(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $context->markAsPaused();

        $this->assertFalse($context->isCompleted());
        $this->assertTrue($context->isPaused());
        $this->assertFalse($context->isStopped());
    }

    public function testMarkAsStopped(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $context->markAsStopped();

        $this->assertFalse($context->isCompleted());
        $this->assertFalse($context->isPaused());
        $this->assertTrue($context->isStopped());
    }

    public function testStatusCanBeChangedMultipleTimes(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        // Initially null
        $this->assertFalse($context->isCompleted());
        $this->assertFalse($context->isPaused());
        $this->assertFalse($context->isStopped());

        // Mark as paused
        $context->markAsPaused();
        $this->assertTrue($context->isPaused());

        // Change to completed
        $context->markAsCompleted();
        $this->assertTrue($context->isCompleted());
        $this->assertFalse($context->isPaused());

        // Change to stopped
        $context->markAsStopped();
        $this->assertTrue($context->isStopped());
        $this->assertFalse($context->isCompleted());
    }

    public function testCompleteWorkflow(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta(['status' => 'published']), $this->mockConfiguration);

        // Add gate evaluations
        $gate = $this->createStub(Gate::class);
        $context->addGateEvaluation($gate, GateResult::ALLOW, false);

        // Add action results
        $result = ActionResult::continue();
        $context->addActionResult($result);

        // Mark as completed
        $context->markAsCompleted();

        // Verify everything is tracked
        $this->assertCount(1, $context->getGateEvaluations());
        $this->assertCount(1, $context->getActionExecutions());
        $this->assertCount(0, $context->getActionSkips());
        $this->assertTrue($context->isCompleted());
        $this->assertSame(['status' => 'published'], $context->getDesiredDelta()->asArray());
    }

    public function testDidGatePassEmpty(): void
    {
        $config = new Configuration([], []);
        $context = new TransitionContext($this->mockState, new ArrayDelta(['status' => 'published']), $config);
        $this->assertTrue($context->didGatesPass());
    }

    public function testDidGatePassAllPass(): void
    {
        $gate1 = $this->createTestGate('gate 1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('gate 2', GateResult::ALLOW);
        $config = new Configuration([$gate1, $gate2], []);
        $context = new TransitionContext($this->mockState, new ArrayDelta(['status' => 'published']), $config);
        $context->addGateEvaluation($gate1, GateResult::ALLOW, false);
        $context->addGateEvaluation($gate2, GateResult::ALLOW, false);

        $this->assertTrue($context->didGatesPass());
    }

    public function testDidGatePassASomePass(): void
    {
        $gate1 = $this->createTestGate('gate 1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('gate 2', GateResult::DENY);
        $config = new Configuration([$gate1, $gate2], []);
        $context = new TransitionContext($this->mockState, new ArrayDelta(['status' => 'published']), $config);
        $context->addGateEvaluation($gate1, GateResult::ALLOW, false);
        $context->addGateEvaluation($gate2, GateResult::DENY, false);

        $this->assertFalse($context->didGatesPass());
    }

    public function testDidGatePassFail(): void
    {
        $gate1 = $this->createTestGate('gate 1', GateResult::ALLOW);
        $gate2 = $this->createTestGate('gate 2', GateResult::DENY);
        $config = new Configuration([$gate1, $gate2], []);
        $context = new TransitionContext($this->mockState, new ArrayDelta(['status' => 'published']), $config);
        $context->addGateEvaluation($gate1, GateResult::DENY, false);

        $this->assertFalse($context->didGatesPass());
    }

    public function testClearPauseStatusWhenPaused(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $context->markAsPaused();
        $this->assertTrue($context->isPaused());

        $context->clearPauseStatus();

        $this->assertFalse($context->isPaused());
        $this->assertFalse($context->isCompleted());
        $this->assertFalse($context->isStopped());
    }

    public function testClearPauseStatusWhenNotPausedDoesNothing(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $context->markAsCompleted();
        $this->assertTrue($context->isCompleted());

        $context->clearPauseStatus();

        // Should remain completed
        $this->assertTrue($context->isCompleted());
        $this->assertFalse($context->isPaused());
    }

    public function testGetLockStateReturnsDefaultEmptyState(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $lockState = $context->getLockState();

        $this->assertInstanceOf(LockState::class, $lockState);
        $this->assertFalse($lockState->isLocked());
    }

    public function testSetLockStateUpdatesState(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $lockState = new LockState('order:123', 1234567890.0, 30);
        $context->setLockState($lockState);

        $retrieved = $context->getLockState();
        $this->assertSame($lockState, $retrieved);
        $this->assertTrue($retrieved->isLocked());
        $this->assertSame('order:123', $retrieved->lockKey);
    }

    public function testWasSkippedDueToLockDefaultsToFalse(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $this->assertFalse($context->wasSkippedDueToLock());
    }

    public function testMarkAsSkippedDueToLockSetsFlag(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $context->markAsSkippedDueToLock();

        $this->assertTrue($context->wasSkippedDueToLock());
    }

    public function testGetStatusMetadataReturnsNullWhenNoActionsExecuted(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $this->assertNull($context->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsNullWhenNoStopOrPauseActions(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);
        $context->addActionResult(ActionResult::continue());
        $context->addActionResult(ActionResult::continue());

        $this->assertNull($context->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsPauseMetadata(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);
        $metadata = ['reason' => 'waiting for approval', 'approver' => 'manager@example.com'];

        $context->addActionResult(ActionResult::continue());
        $context->addActionResult(ActionResult::pause(null, $metadata));

        $this->assertSame($metadata, $context->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsStopMetadata(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);
        $metadata = ['error' => 'Payment failed', 'code' => 'CARD_DECLINED'];

        $context->addActionResult(ActionResult::continue());
        $context->addActionResult(ActionResult::stop(null, $metadata));

        $this->assertSame($metadata, $context->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsLastPauseOrStopMetadata(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);
        $metadata1 = ['first' => 'pause'];
        $metadata2 = ['second' => 'stop'];

        $context->addActionResult(ActionResult::pause(null, $metadata1));
        $context->addActionResult(ActionResult::continue());
        $context->addActionResult(ActionResult::stop(null, $metadata2));

        // Should return the most recent PAUSE/STOP metadata
        $this->assertSame($metadata2, $context->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsNullWhenMetadataIsNull(): void
    {
        $context = new TransitionContext($this->mockState, new ArrayDelta([]), $this->mockConfiguration);

        $context->addActionResult(ActionResult::pause(null, null));

        $this->assertNull($context->getStatusMetadata());
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
