<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ActionResultCollection;
use BenRowe\StateFlow\ActionSkip;
use BenRowe\StateFlow\ActionSkipCollection;
use BenRowe\StateFlow\ExecutionHistory;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\GateEvaluation;
use BenRowe\StateFlow\GateEvaluationCollection;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class ExecutionHistoryTest extends TestCase
{
    private State $mockState;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockState = $this->createStub(State::class);
    }

    // Construction Tests

    public function testConstructWithDefaultValues(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $this->assertSame($this->mockState, $history->getInitialState());
        $this->assertSame($this->mockState, $history->getCurrentState());
        $this->assertCount(0, $history->getGateEvaluations());
        $this->assertCount(0, $history->getActionExecutions());
        $this->assertCount(0, $history->getActionSkips());
    }

    public function testConstructWithInitialState(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $this->assertSame($this->mockState, $history->getInitialState());
        $this->assertSame($this->mockState, $history->getCurrentState(), 'Current state should default to initial state');
    }

    public function testConstructWithProvidedCollections(): void
    {
        $gate = $this->createStub(Gate::class);
        $action = $this->createStub(Action::class);
        $newState = $this->createStub(State::class);

        $gateEvaluations = GateEvaluationCollection::fromArray([
            new GateEvaluation($gate, GateResult::ALLOW, false),
        ]);
        $actionExecutions = ActionResultCollection::fromArray([
            ActionResult::continue(),
        ]);
        $actionSkips = ActionSkipCollection::fromArray([
            new ActionSkip($action, GateResult::DENY),
        ]);

        $history = new ExecutionHistory(
            $this->mockState,
            $gateEvaluations,
            $actionExecutions,
            $actionSkips,
            $newState
        );

        $this->assertSame($this->mockState, $history->getInitialState());
        $this->assertSame($newState, $history->getCurrentState());
        $this->assertCount(1, $history->getGateEvaluations());
        $this->assertCount(1, $history->getActionExecutions());
        $this->assertCount(1, $history->getActionSkips());
    }

    // State Management Tests

    public function testGetCurrentStateReturnsInitialStateByDefault(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $this->assertSame($this->mockState, $history->getCurrentState());
    }

    public function testUpdateCurrentStateChangesState(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $newState = $this->createStub(State::class);

        $updated = $history->updateCurrentState($newState);

        $this->assertSame($newState, $updated->getCurrentState());
    }

    public function testUpdateCurrentStateDoesNotAffectInitialState(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $newState = $this->createStub(State::class);

        $updated = $history->updateCurrentState($newState);

        $this->assertSame($this->mockState, $updated->getInitialState(), 'Initial state should never change');
        $this->assertSame($newState, $updated->getCurrentState());
    }

    // Gate Evaluation Recording Tests

    public function testRecordGateEvaluationCreatesNewEvaluation(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $gate = $this->createStub(Gate::class);

        $updated = $history->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $evaluations = $updated->getGateEvaluations()->toArray();
        $this->assertCount(1, $evaluations);
        $this->assertInstanceOf(GateEvaluation::class, $evaluations[0]);
        $this->assertSame($gate, $evaluations[0]->gate);
        $this->assertSame(GateResult::ALLOW, $evaluations[0]->result);
        $this->assertFalse($evaluations[0]->isActionGate);
    }

    public function testRecordGateEvaluationWithTransitionGate(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $gate = $this->createStub(Gate::class);

        $updated = $history->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $evaluations = $updated->getGateEvaluations()->toArray();
        $this->assertFalse($evaluations[0]->isActionGate);
    }

    public function testRecordGateEvaluationWithActionGate(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $gate = $this->createStub(Gate::class);

        $updated = $history->recordGateEvaluation($gate, GateResult::ALLOW, true);

        $evaluations = $updated->getGateEvaluations()->toArray();
        $this->assertTrue($evaluations[0]->isActionGate);
    }

    public function testGetGateEvaluationsReturnsEmptyCollectionByDefault(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $evaluations = $history->getGateEvaluations();

        $this->assertInstanceOf(GateEvaluationCollection::class, $evaluations);
        $this->assertCount(0, $evaluations);
    }

    public function testGetGateEvaluationsReturnsAllRecordedEvaluations(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $gate1 = $this->createStub(Gate::class);
        $gate2 = $this->createStub(Gate::class);

        $updated = $history
            ->recordGateEvaluation($gate1, GateResult::ALLOW, false)
            ->recordGateEvaluation($gate2, GateResult::DENY, true);

        $evaluations = $updated->getGateEvaluations()->toArray();
        $this->assertCount(2, $evaluations);
        $this->assertSame($gate1, $evaluations[0]->gate);
        $this->assertSame($gate2, $evaluations[1]->gate);
    }

    public function testRecordMultipleGateEvaluationsPreservesOrder(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $gate1 = $this->createStub(Gate::class);
        $gate2 = $this->createStub(Gate::class);
        $gate3 = $this->createStub(Gate::class);

        $updated = $history
            ->recordGateEvaluation($gate1, GateResult::ALLOW, false)
            ->recordGateEvaluation($gate2, GateResult::ALLOW, false)
            ->recordGateEvaluation($gate3, GateResult::DENY, true);

        $evaluations = $updated->getGateEvaluations()->toArray();
        $this->assertCount(3, $evaluations);
        $this->assertSame($gate1, $evaluations[0]->gate);
        $this->assertSame($gate2, $evaluations[1]->gate);
        $this->assertSame($gate3, $evaluations[2]->gate);
    }

    // Action Execution Recording Tests

    public function testRecordActionExecutionAddsResult(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $result = ActionResult::continue();

        $updated = $history->recordActionExecution($result);

        $executions = $updated->getActionExecutions()->toArray();
        $this->assertCount(1, $executions);
        $this->assertSame($result, $executions[0]);
    }

    public function testRecordMultipleActionExecutionsPreservesOrder(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();
        $result3 = ActionResult::stop();

        $updated = $history
            ->recordActionExecution($result1)
            ->recordActionExecution($result2)
            ->recordActionExecution($result3);

        $executions = $updated->getActionExecutions()->toArray();
        $this->assertCount(3, $executions);
        $this->assertSame($result1, $executions[0]);
        $this->assertSame($result2, $executions[1]);
        $this->assertSame($result3, $executions[2]);
    }

    public function testGetActionExecutionsReturnsEmptyCollectionByDefault(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $executions = $history->getActionExecutions();

        $this->assertInstanceOf(ActionResultCollection::class, $executions);
        $this->assertCount(0, $executions);
    }

    public function testGetActionExecutionsReturnsAllResults(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $result1 = ActionResult::continue();
        $result2 = ActionResult::pause();

        $updated = $history
            ->recordActionExecution($result1)
            ->recordActionExecution($result2);

        $executions = $updated->getActionExecutions()->toArray();
        $this->assertCount(2, $executions);
        $this->assertSame($result1, $executions[0]);
        $this->assertSame($result2, $executions[1]);
    }

    // Action Skip Recording Tests

    public function testRecordActionSkipAddsSkip(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $action = $this->createStub(Action::class);

        $updated = $history->recordActionSkip($action, GateResult::DENY);

        $skips = $updated->getActionSkips()->toArray();
        $this->assertCount(1, $skips);
        $this->assertInstanceOf(ActionSkip::class, $skips[0]);
        $this->assertSame($action, $skips[0]->action);
        $this->assertSame(GateResult::DENY, $skips[0]->gateResult);
    }

    public function testRecordMultipleActionSkipsPreservesOrder(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $action1 = $this->createStub(Action::class);
        $action2 = $this->createStub(Action::class);
        $action3 = $this->createStub(Action::class);

        $updated = $history
            ->recordActionSkip($action1, GateResult::DENY)
            ->recordActionSkip($action2, GateResult::SKIP_IDEMPOTENT)
            ->recordActionSkip($action3, GateResult::DENY);

        $skips = $updated->getActionSkips()->toArray();
        $this->assertCount(3, $skips);
        $this->assertSame($action1, $skips[0]->action);
        $this->assertSame($action2, $skips[1]->action);
        $this->assertSame($action3, $skips[2]->action);
    }

    public function testGetActionSkipsReturnsEmptyCollectionByDefault(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $skips = $history->getActionSkips();

        $this->assertInstanceOf(ActionSkipCollection::class, $skips);
        $this->assertCount(0, $skips);
    }

    public function testGetActionSkipsReturnsAllSkips(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $action1 = $this->createStub(Action::class);
        $action2 = $this->createStub(Action::class);

        $updated = $history
            ->recordActionSkip($action1, GateResult::DENY)
            ->recordActionSkip($action2, GateResult::SKIP_IDEMPOTENT);

        $skips = $updated->getActionSkips()->toArray();
        $this->assertCount(2, $skips);
        $this->assertSame($action1, $skips[0]->action);
        $this->assertSame($action2, $skips[1]->action);
    }

    // Status Metadata Tests

    public function testGetStatusMetadataReturnsNullWhenNoActions(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $this->assertNull($history->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsNullWhenOnlyContinueActions(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $updated = $history
            ->recordActionExecution(ActionResult::continue())
            ->recordActionExecution(ActionResult::continue());

        $this->assertNull($updated->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsPauseMetadata(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $metadata = ['reason' => 'waiting for approval'];

        $updated = $history
            ->recordActionExecution(ActionResult::continue())
            ->recordActionExecution(ActionResult::pause(null, $metadata));

        $this->assertSame($metadata, $updated->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsStopMetadata(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $metadata = ['error' => 'Payment failed'];

        $updated = $history
            ->recordActionExecution(ActionResult::continue())
            ->recordActionExecution(ActionResult::stop(null, $metadata));

        $this->assertSame($metadata, $updated->getStatusMetadata());
    }

    public function testGetStatusMetadataReturnsLastPauseOrStopMetadata(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $metadata1 = ['first' => 'pause'];
        $metadata2 = ['second' => 'stop'];

        $updated = $history
            ->recordActionExecution(ActionResult::pause(null, $metadata1))
            ->recordActionExecution(ActionResult::continue())
            ->recordActionExecution(ActionResult::stop(null, $metadata2));

        $this->assertSame($metadata2, $updated->getStatusMetadata(), 'Should return most recent PAUSE/STOP metadata');
    }

    public function testGetStatusMetadataReturnsNullWhenMetadataIsNull(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $updated = $history->recordActionExecution(ActionResult::pause(null, null));

        $this->assertNull($updated->getStatusMetadata());
    }

    // Immutability Tests

    public function testRecordGateEvaluationReturnsNewInstance(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $gate = $this->createStub(Gate::class);

        $updated = $history->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $this->assertNotSame($history, $updated, 'Should return new instance');
    }

    public function testRecordActionExecutionReturnsNewInstance(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $updated = $history->recordActionExecution(ActionResult::continue());

        $this->assertNotSame($history, $updated, 'Should return new instance');
    }

    public function testRecordActionSkipReturnsNewInstance(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $action = $this->createStub(Action::class);

        $updated = $history->recordActionSkip($action, GateResult::DENY);

        $this->assertNotSame($history, $updated, 'Should return new instance');
    }

    public function testUpdateCurrentStateReturnsNewInstance(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $newState = $this->createStub(State::class);

        $updated = $history->updateCurrentState($newState);

        $this->assertNotSame($history, $updated, 'Should return new instance');
    }

    public function testOriginalInstanceRemainsUnchangedAfterRecordingGate(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $gate = $this->createStub(Gate::class);

        $updated = $history->recordGateEvaluation($gate, GateResult::ALLOW, false);

        $this->assertCount(0, $history->getGateEvaluations(), 'Original should have no evaluations');
        $this->assertCount(1, $updated->getGateEvaluations(), 'Updated should have one evaluation');
    }

    public function testOriginalInstanceRemainsUnchangedAfterRecordingAction(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $updated = $history->recordActionExecution(ActionResult::continue());

        $this->assertCount(0, $history->getActionExecutions(), 'Original should have no executions');
        $this->assertCount(1, $updated->getActionExecutions(), 'Updated should have one execution');
    }

    public function testOriginalInstanceRemainsUnchangedAfterRecordingSkip(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $action = $this->createStub(Action::class);

        $updated = $history->recordActionSkip($action, GateResult::DENY);

        $this->assertCount(0, $history->getActionSkips(), 'Original should have no skips');
        $this->assertCount(1, $updated->getActionSkips(), 'Updated should have one skip');
    }

    public function testOriginalInstanceRemainsUnchangedAfterStateUpdate(): void
    {
        $history = new ExecutionHistory($this->mockState);
        $newState = $this->createStub(State::class);

        $updated = $history->updateCurrentState($newState);

        $this->assertSame($this->mockState, $history->getCurrentState(), 'Original should have original state');
        $this->assertSame($newState, $updated->getCurrentState(), 'Updated should have new state');
    }

    public function testCollectionsAreImmutable(): void
    {
        $history = new ExecutionHistory($this->mockState);

        $evaluations1 = $history->getGateEvaluations();
        $executions1 = $history->getActionExecutions();
        $skips1 = $history->getActionSkips();

        $evaluations2 = $history->getGateEvaluations();
        $executions2 = $history->getActionExecutions();
        $skips2 = $history->getActionSkips();

        $this->assertEquals($evaluations1, $evaluations2);
        $this->assertEquals($executions1, $executions2);
        $this->assertEquals($skips1, $skips2);
    }
}
