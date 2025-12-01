<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Events\EventDispatcher;
use BenRowe\StateFlow\Events\TransitionCompleted;
use BenRowe\StateFlow\Events\TransitionStarting;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

class EventDispatchingTest extends TestCase
{
    use CreatesTestStates;
    use CreatesTestActions;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    public function testDispatchesTransitionStartingEvent(): void
    {
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = ['status' => 'review'];

        $mockDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (TransitionStarting $event) use ($initialState, $delta) {
                $this->assertInstanceOf(TransitionStarting::class, $event);
                $this->assertSame($initialState, $event->currentState);
                $this->assertSame($delta, $event->desiredDelta);

                return true;
            }));

        $stateFlow = new StateFlow(fn () => new Configuration([], []), $mockDispatcher);

        $stateFlow->transition($initialState, $delta);
    }

    public function testDispatchesTransitionCompletedEvent(): void
    {
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = ['status' => 'published'];
        $finalState = $this->createTestState(['status' => 'published']);

        $mockDispatcher
            ->expects($this->exactly(2)) // Expect two dispatches
            ->method('dispatch')
            ->willReturnOnConsecutiveCalls( // Define expectations for each call
                $this->callback(function (TransitionStarting $event) {
                    $this->assertInstanceOf(TransitionStarting::class, $event);

                    return true;
                }),
                $this->callback(function (TransitionCompleted $event) {
                    $this->assertInstanceOf(TransitionCompleted::class, $event);

                    return true;
                })
            );

        // Use the trait to create a simple action that updates the state
        $action = $this->createTestActionWithState('TestAction', $finalState);

        $config = new Configuration([], [$action]);

        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);

        $worker = $stateFlow->transition($initialState, $delta);
        $completedContext = $worker->execute();

        // Ensure the context was indeed completed and the final state is as expected
        $this->assertTrue($completedContext->isCompleted());
        $this->assertEquals($finalState, $completedContext->getCurrentState());
    }

    public function testTransitionCompletedEventDoesNotFireOnPause(): void
    {
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = ['status' => 'paused'];

        // Expect ONLY the starting event. If completed event fires, it will fail.
        $mockDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TransitionStarting::class));

        $action = $this->createTestActionWithResult('PauseAction', ActionResult::pause());
        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $pausedContext = $stateFlow->transition($initialState, $delta)->execute();

        $this->assertTrue($pausedContext->isPaused());
        $this->assertFalse($pausedContext->isCompleted());
    }

    public function testTransitionCompletedEventDoesNotFireOnStop(): void
    {
        $mockDispatcher = $this->createMock(EventDispatcher::class);
        $initialState = $this->createTestState(['status' => 'draft']);
        $delta = ['status' => 'stopped'];

        // Expect ONLY the starting event.
        $mockDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TransitionStarting::class));

        $action = $this->createTestActionWithResult('StopAction', ActionResult::stop());
        $config = new Configuration([], [$action]);
        $stateFlow = new StateFlow(fn () => $config, $mockDispatcher);
        $stoppedContext = $stateFlow->transition($initialState, $delta)->execute();

        $this->assertTrue($stoppedContext->isStopped());
        $this->assertFalse($stoppedContext->isCompleted());
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
