<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ActionSkip;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\GateEvaluation;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class TransitionContextTest extends TestCase
{
    private State $mockState;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockState = $this->createStub(State::class);
    }

    public function testConstructWithoutDelta(): void
    {
        $context = new TransitionContext($this->mockState);

        $this->assertSame($this->mockState, $context->getCurrentState());
        $this->assertSame($this->mockState, $context->getInitialState());
        $this->assertSame([], $context->getDesiredDelta());
    }

    public function testConstructWithDelta(): void
    {
        $delta = ['status' => 'published', 'priority' => 'high'];
        $context = new TransitionContext($this->mockState, $delta);

        $this->assertSame($this->mockState, $context->getCurrentState());
        $this->assertSame($this->mockState, $context->getInitialState());
        $this->assertSame($delta, $context->getDesiredDelta());
    }

    public function testUpdateCurrentState(): void
    {
        $context = new TransitionContext($this->mockState);
        $newState = $this->createStub(State::class);

        $context->updateCurrentState($newState);

        $this->assertSame($newState, $context->getCurrentState());
        $this->assertSame($this->mockState, $context->getInitialState(), 'Initial state should remain unchanged');
    }

    public function testGetDesiredDelta(): void
    {
        $delta = ['foo' => 'bar', 'baz' => 123];
        $context = new TransitionContext($this->mockState, $delta);

        $this->assertSame($delta, $context->getDesiredDelta());
    }

    public function testActionExecutionsEmptyByDefault(): void
    {
        $context = new TransitionContext($this->mockState);

        $this->assertSame([], $context->getActionExecutions());
    }

    public function testAddActionResult(): void
    {
        $context = new TransitionContext($this->mockState);
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
        $context = new TransitionContext($this->mockState);

        $this->assertSame([], $context->getGateEvaluations());
    }

    public function testAddGateEvaluation(): void
    {
        $context = new TransitionContext($this->mockState);
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
        $context = new TransitionContext($this->mockState);

        $this->assertSame([], $context->getActionSkips());
    }

    public function testAddActionSkip(): void
    {
        $context = new TransitionContext($this->mockState);
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
        $context = new TransitionContext($this->mockState);

        $this->assertFalse($context->isCompleted());
        $this->assertFalse($context->isPaused());
        $this->assertFalse($context->isStopped());
    }

    public function testMarkAsCompleted(): void
    {
        $context = new TransitionContext($this->mockState);

        $context->markAsCompleted();

        $this->assertTrue($context->isCompleted());
        $this->assertFalse($context->isPaused());
        $this->assertFalse($context->isStopped());
    }

    public function testMarkAsPaused(): void
    {
        $context = new TransitionContext($this->mockState);

        $context->markAsPaused();

        $this->assertFalse($context->isCompleted());
        $this->assertTrue($context->isPaused());
        $this->assertFalse($context->isStopped());
    }

    public function testMarkAsStopped(): void
    {
        $context = new TransitionContext($this->mockState);

        $context->markAsStopped();

        $this->assertFalse($context->isCompleted());
        $this->assertFalse($context->isPaused());
        $this->assertTrue($context->isStopped());
    }

    public function testStatusCanBeChangedMultipleTimes(): void
    {
        $context = new TransitionContext($this->mockState);

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
        $context = new TransitionContext($this->mockState, ['status' => 'published']);

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
        $this->assertSame(['status' => 'published'], $context->getDesiredDelta());
    }
}
