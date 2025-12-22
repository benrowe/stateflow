<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit;

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
    use CreatesTestStates;
    use CreatesTestActions;

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

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
