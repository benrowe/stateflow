<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Action\Yieldable;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\Event;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\LockReleased;
use BenRowe\StateFlow\Exceptions\TransitionException;
use BenRowe\StateFlow\Locking\LockConfiguration;
use BenRowe\StateFlow\Locking\LockContext;
use BenRowe\StateFlow\Locking\LockKeyProvider;
use BenRowe\StateFlow\Locking\LockProvider;
use BenRowe\StateFlow\Locking\LockStrategy;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ActionResult::yield() / StateWorker::resumeWithResponse()
 *
 * Covers issue #57's acceptance criteria: a single action suspending itself
 * mid-transition (without suspending the whole transition the way pause() does)
 * and being resumed with response data while keeping subsequent actions runnable
 * in the same transition.
 */
class WorkflowYieldTest extends TestCase
{
    use CreatesTestActions;
    use CreatesTestStates;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    public function testYieldSuspendsTransitionAndHoldsCursorWithoutRunningLaterActions(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestYieldableAction(
            'Action2',
            ActionResult::yield(['checkId' => 'abc']),
            ActionResult::continue()
        );
        $action3 = $this->createTestAction('Action3');

        $configuration = Configuration::fromArray([], [$action1, $action2, $action3]);

        $stateFlow = new StateFlow(fn () => $configuration);
        $context = $stateFlow
            ->transition($initialState, new ArrayDelta(['status' => 'pending']))
            ->execute();

        $this->assertTrue($context->executionStatus()->isYielded());
        $this->assertFalse($context->executionStatus()->isCompleted());
        $this->assertCount(2, $context->executionHistory()->getActionExecutions());
        $this->assertEquals(['checkId' => 'abc'], $context->getStatusMetadata());

        $this->assertContains('Action:Action1', $this->logger->log);
        $this->assertContains('Action:Action2', $this->logger->log);
        $this->assertNotContains('Action:Action3', $this->logger->log);
    }

    public function testResumeWithResponseReinvokesSameActionAndRunsRemainingActionsInSameTransition(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $action1 = $this->createTestAction('Action1');
        $action2 = $this->createTestYieldableAction(
            'Action2',
            ActionResult::yield(['checkId' => 'abc']),
            ActionResult::continue()
        );
        $action3 = $this->createTestAction('Action3');

        $configuration = Configuration::fromArray([], [$action1, $action2, $action3]);

        $stateFlow1 = new StateFlow(fn () => $configuration);
        $yieldedContext = $stateFlow1
            ->transition($initialState, new ArrayDelta(['status' => 'pending']))
            ->execute();

        $this->assertTrue($yieldedContext->executionStatus()->isYielded());

        // Resume from a fresh worker, simulating a new request/process
        $stateFlow2 = new StateFlow(fn () => $configuration);
        $worker2 = $stateFlow2->fromContext($yieldedContext);
        $completedContext = $worker2->resumeWithResponse(['outcome' => 'approved']);

        $this->assertTrue($completedContext->executionStatus()->isCompleted());
        $this->assertFalse($completedContext->executionStatus()->isYielded());

        // Action1 ran once, Action2 ran twice (yield + resumed continue), Action3 ran once
        $action1Count = count(array_keys($this->logger->log, 'Action:Action1', true));
        $action2Count = count(array_keys($this->logger->log, 'Action:Action2', true));
        $action3Count = count(array_keys($this->logger->log, 'Action:Action3', true));
        $this->assertSame(1, $action1Count);
        $this->assertSame(2, $action2Count);
        $this->assertSame(1, $action3Count);

        $this->assertSame($yieldedContext, $completedContext, 'Should return the same context instance');
    }

    public function testExecutionHistoryContainsBothTheYieldEntryAndTheResumedActionsFinalResult(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);

        $action1 = $this->createTestYieldableAction(
            'Action1',
            ActionResult::yield(['checkId' => 'abc']),
            ActionResult::stop(null, ['reason' => 'rejected'])
        );

        $configuration = Configuration::fromArray([], [$action1]);

        $stateFlow = new StateFlow(fn () => $configuration);
        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'pending']));
        $yieldedContext = $worker->execute();

        $this->assertCount(1, $yieldedContext->executionHistory()->getActionExecutions());

        $resumedContext = $worker->resumeWithResponse(['outcome' => 'rejected']);

        $executions = $resumedContext->executionHistory()->getActionExecutions()->toArray();
        $this->assertCount(2, $executions);
        $this->assertSame(ExecutionState::YIELD, $executions[0]->executionState);
        $this->assertSame(ExecutionState::STOP, $executions[1]->executionState);
        $this->assertTrue($resumedContext->executionStatus()->isStopped());
    }

    public function testResumeWithResponseOnNonYieldedContextThrows(): void
    {
        $initialState = $this->createTestState(['status' => 'draft']);
        $action1 = $this->createTestAction('Action1');
        $configuration = Configuration::fromArray([], [$action1]);

        $stateFlow = new StateFlow(fn () => $configuration);
        $worker = $stateFlow->transition($initialState, new ArrayDelta(['status' => 'pending']));
        $completedContext = $worker->execute();

        $this->assertTrue($completedContext->executionStatus()->isCompleted());

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('Cannot resume: transition is not yielded');

        $worker->resumeWithResponse(['outcome' => 'approved']);
    }

    public function testLockIsHeldAcrossYieldAndReleasedOnceTransitionCompletes(): void
    {
        // The worker re-runs lock acquisition both on the initial execute() and again
        // on resumeWithResponse(), mirroring how pause/resume already behaves.
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->exactly(2))
            ->method('acquire')
            ->with('order:123', 30)
            ->willReturn(true);
        $lockProvider
            ->expects($this->any())
            ->method('exists')
            ->with('order:123')
            ->willReturn(true);
        $lockProvider
            ->expects($this->once())
            ->method('release')
            ->with('order:123');

        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->exactly(2))
            ->method('getLockKey')
            ->willReturn('order:123');

        $dispatchedEvents = [];
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $mockDispatcher
            ->expects($this->any())
            ->method('dispatch')
            ->willReturnCallback(function (Event $event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = $event;
            });

        $action = new class () implements Action, Yieldable
        {
            public function execute(ActionContext $context): ActionResult
            {
                if ($context->hasYieldResponse()) {
                    return ActionResult::continue();
                }

                return ActionResult::yield(['checkId' => 'abc']);
            }
        };

        $lockConfig = new LockConfiguration(LockStrategy::FAIL_FAST, 30);
        $lockContext = new LockContext($lockProvider, $lockKeyProvider, $lockConfig);
        $config = Configuration::fromArray([], [$action]);

        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher, $lockContext);
        $state = $this->createTestState(['id' => '123', 'status' => 'pending']);

        $worker = $stateFlow->transition($state, new ArrayDelta(['status' => 'processing']));
        $yieldedContext = $worker->execute();

        $this->assertTrue($yieldedContext->lockState()->isLocked(), 'Lock should remain held when yielded');
        $this->assertTrue($yieldedContext->executionStatus()->isYielded());

        $lockReleasedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockReleased);
        $this->assertCount(0, $lockReleasedEvents, 'LockReleased event should NOT be dispatched while yielded');

        $completedContext = $worker->resumeWithResponse(['outcome' => 'approved']);

        $this->assertTrue($completedContext->executionStatus()->isCompleted());

        $lockReleasedEvents = array_filter($dispatchedEvents, fn ($e) => $e instanceof LockReleased);
        $this->assertCount(1, $lockReleasedEvents, 'LockReleased event should be dispatched once completed');
    }

    public function testResumeWithResponseIsSkippedWhenLockCannotBeAcquiredWithSkipStrategy(): void
    {
        $lockProvider = $this->createMock(LockProvider::class);
        $lockProvider
            ->expects($this->exactly(2))
            ->method('acquire')
            ->with('entity:1', 30)
            ->willReturnOnConsecutiveCalls(true, false);
        $lockProvider
            ->expects($this->any())
            ->method('exists')
            ->willReturn(true);
        $lockProvider
            ->expects($this->never())
            ->method('release');

        $lockKeyProvider = $this->createMock(LockKeyProvider::class);
        $lockKeyProvider
            ->expects($this->any())
            ->method('getLockKey')
            ->willReturn('entity:1');

        $actionInvocationCount = 0;
        $action = new class ($actionInvocationCount) implements Action, Yieldable
        {
            public function __construct(private int &$invocationCount) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->invocationCount++;

                if ($context->hasYieldResponse()) {
                    return ActionResult::continue();
                }

                return ActionResult::yield();
            }
        };

        $lockConfig = new LockConfiguration(LockStrategy::SKIP, 30);
        $lockContext = new LockContext($lockProvider, $lockKeyProvider, $lockConfig);
        $config = Configuration::fromArray([], [$action]);

        $stateFlow = new StateFlow(fn () => $config, null, $lockContext);
        $state = $this->createTestState(['id' => '1', 'status' => 'draft']);

        $worker = $stateFlow->transition($state, new ArrayDelta(['status' => 'pending']));
        $yieldedContext = $worker->execute();

        $this->assertTrue($yieldedContext->executionStatus()->isYielded());
        $this->assertSame(1, $actionInvocationCount);

        $resumedContext = $worker->resumeWithResponse(['outcome' => 'approved']);

        $this->assertTrue($resumedContext->executionStatus()->wasSkippedDueToLock());
        $this->assertFalse($resumedContext->executionStatus()->isCompleted());
        $this->assertFalse($resumedContext->executionStatus()->isYielded());
        $this->assertSame(1, $actionInvocationCount, 'Action should not be re-invoked when lock cannot be acquired');
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
