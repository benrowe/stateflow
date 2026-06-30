<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\ArrayDelta;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\State;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\StateWorker;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use BenRowe\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

class StateFlowTest extends TestCase
{
    use CreatesTestActions;
    use CreatesTestStates;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    public function testItCanBeInitialised(): void
    {
        $stateFlow = new StateFlow(fn () => Configuration::fromArray([], []));

        $this->assertInstanceOf(StateFlow::class, $stateFlow);
        $this->assertInstanceOf(
            StateWorker::class,
            $stateFlow->transition($this->createMock(State::class), new ArrayDelta([]))
        );
    }

    public function testItCanBeInitialisedWithExistingContext(): void
    {
        $stateFlow = new StateFlow(fn () => Configuration::fromArray([], []));
        $state = $this->createTestState(['foo' => 'bar']);
        $config = Configuration::fromArray([], [$this->createTestAction('myAction')]);
        $context = new TransitionContext($state, new ArrayDelta([]), $config);
        $worker = $stateFlow->fromContext($context);

        $this->assertInstanceOf(StateWorker::class, $worker);

        $this->assertSame($config, $worker->getContext()->getConfiguration());
    }

    public function testFromContextResumesYieldedActionAtItsOwnIndexRatherThanPastIt(): void
    {
        $yieldingAction = $this->createTestYieldableAction(
            'YieldingAction',
            ActionResult::yield(['checkId' => 'abc']),
            ActionResult::continue()
        );
        $secondAction = $this->createTestAction('SecondAction');
        $config = Configuration::fromArray([], [$yieldingAction, $secondAction]);

        $stateFlow = new StateFlow(fn () => $config);
        $state = $this->createTestState(['foo' => 'bar']);
        $context = $stateFlow->transition($state, new ArrayDelta([]))->execute();

        $this->assertTrue($context->executionStatus()->isYielded());
        $this->assertSame(['Action:YieldingAction'], $this->logger->log);

        // Simulate resuming from a freshly-deserialized context in a new request
        $resumedStateFlow = new StateFlow(fn () => $config);
        $worker = $resumedStateFlow->fromContext($context);
        $resumedContext = $worker->resumeWithResponse(['outcome' => 'approved']);

        $this->assertTrue($resumedContext->executionStatus()->isCompleted());
        $this->assertSame(
            ['Action:YieldingAction', 'Action:YieldingAction', 'Action:SecondAction'],
            $this->logger->log
        );
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
