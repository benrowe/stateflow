<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Integration\StateFlow;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\Action\ActionContext;
use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Configuration\Configuration;
use CoverGenius\StateFlow\Exceptions\InvalidConfigurationException;
use CoverGenius\StateFlow\Exceptions\InvalidGateResultException;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateContext;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\StateFlow;
use CoverGenius\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Tests for Scenario 12.* - Error Handling
 */
class ErrorHandlingTest extends TestCase
{
    use CreatesTestStates;

    /**
     * Scenario 12.1: Invalid configuration
     * Given a Configuration with invalid gates (not implementing Gate interface)
     * When I try to execute a transition
     * Then a clear error should be thrown
     */
    public function testInvalidGateThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Gate at index 0 must implement CoverGenius\StateFlow\Gate\Gate');

        $invalidGate = new stdClass();

        // This should throw immediately in the Configuration constructor
        // @phpstan-ignore argument.type
        Configuration::fromArray([$invalidGate], []);
    }

    public function testInvalidActionThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Action at index 0 must implement CoverGenius\StateFlow\Action\Action');

        $invalidAction = new stdClass();

        // This should throw immediately in the Configuration constructor
        // @phpstan-ignore argument.type
        Configuration::fromArray([], [$invalidAction]);
    }

    public function testMultipleInvalidGatesReportsFirstInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Gate at index 1 must implement CoverGenius\StateFlow\Gate\Gate');

        $validGate = $this->createStubGate('ValidGate', GateResult::ALLOW);
        $invalidGate = 'not a gate';

        // @phpstan-ignore argument.type
        Configuration::fromArray([$validGate, $invalidGate], []);
    }

    public function testMultipleInvalidActionsReportsFirstInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Action at index 2 must implement CoverGenius\StateFlow\Action\Action');

        $validAction1 = $this->createStubAction('Action1');
        $validAction2 = $this->createStubAction('Action2');
        $invalidAction = ['not' => 'an action'];

        // @phpstan-ignore argument.type
        Configuration::fromArray([], [$validAction1, $validAction2, $invalidAction]);
    }

    /**
     * Scenario 12.2: Action throws exception with no event dispatcher
     * Given no EventDispatcher configured
     * And an action that throws an exception
     * When the action executes
     * Then the exception should propagate normally
     * And the workflow should stop
     */
    public function testActionExceptionPropagatesWithoutEventDispatcher(): void
    {
        $state = $this->createTestState(['status' => 'pending']);
        $exception = new RuntimeException('Action failed');

        $action1 = $this->createStubAction('Action1');
        $action2 = $this->createThrowingAction('FailingAction', $exception);
        $action3 = $this->createStubAction('Action3');

        $config = Configuration::fromArray([], [$action1, $action2, $action3]);

        // StateFlow with no EventDispatcher (uses NullEventDispatcher by default)
        $stateFlow = new StateFlow(fn () => $config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Action failed');

        $worker = $stateFlow->transition($state, new ArrayDelta(['status' => 'processing']));
        $worker->execute();
    }

    public function testActionExceptionStopsWorkflow(): void
    {
        $state = $this->createTestState(['status' => 'pending']);
        $exception = new RuntimeException('Action 2 failed');

        $executionLog = [];

        $action1 = $this->createTrackingAction('Action1', $executionLog);
        $action2 = $this->createThrowingAction('Action2', $exception);
        $action3 = $this->createTrackingAction('Action3', $executionLog);

        $config = Configuration::fromArray([], [$action1, $action2, $action3]);
        $stateFlow = new StateFlow(fn () => $config);

        try {
            $worker = $stateFlow->transition($state, new ArrayDelta(['status' => 'processing']));
            $worker->execute();
            $this->fail('Expected exception to be thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Action 2 failed', $e->getMessage());
        }

        // Verify action 1 executed but action 3 did not
        $this->assertSame(['Action1'], $executionLog);
    }

    /**
     * Scenario 12.3: Gate returns invalid result
     * Given a gate that returns null or invalid value
     * When the gate is evaluated
     * Then an appropriate exception should be thrown
     */
    public function testGateReturningInvalidResultThrowsException(): void
    {
        $state = $this->createTestState(['status' => 'pending']);

        // Create a gate that returns null (bypassing type hints)
        $invalidGate = new class () implements Gate
        {
            public function evaluate(GateContext $context): GateResult
            {
                // @phpstan-ignore return.type
                return null;
            }

            public function message(): string
            {
                return 'Invalid Gate';
            }
        };

        $config = Configuration::fromArray([$invalidGate], []);
        $stateFlow = new StateFlow(fn () => $config);

        $this->expectException(InvalidGateResultException::class);

        $worker = $stateFlow->transition($state, new ArrayDelta(['status' => 'processing']));
        $worker->execute();
    }

    private function createStubGate(string $name, GateResult $result): Gate
    {
        $gate = $this->createStub(Gate::class);
        $gate->method('evaluate')->willReturn($result);
        $gate->method('message')->willReturn($name);

        return $gate;
    }

    private function createStubAction(string $name): Action
    {
        return new class ($name) implements Action
        {
            /**
             * @phpstan-ignore property.onlyWritten
             */
            public function __construct(private string $name) {}

            public function execute(ActionContext $context): ActionResult
            {
                return ActionResult::continue();
            }
        };
    }

    private function createThrowingAction(string $name, Throwable $exception): Action
    {
        return new class ($name, $exception) implements Action
        {
            public function __construct(
                /** @phpstan-ignore property.onlyWritten */
                private string $name,
                private Throwable $exception
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                throw $this->exception;
            }
        };
    }

    /**
     * @param array<string> $log
     */
    private function createTrackingAction(string $name, array &$log): Action
    {
        return new class ($name, $log) implements Action
        {
            /**
             * @param array<string> $log
             */
            public function __construct(
                private string $name,
                /** @phpstan-ignore property.onlyWritten */
                private array &$log
            ) {}

            public function execute(ActionContext $context): ActionResult
            {
                $this->log[] = $this->name;

                return ActionResult::continue();
            }
        };
    }
}
