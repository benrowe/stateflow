<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Integration\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionContext;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\StateFlow;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestActions;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestGates;
use BenRowe\StateFlow\Tests\Utils\Traits\CreatesTestStates;
use PHPUnit\Framework\TestCase;

class DeltaAccessTest extends TestCase
{
    use CreatesTestStates;
    use CreatesTestGates;
    use CreatesTestActions;

    private ExecutionLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ExecutionLogger();
    }

    /**
     * Delta Access: Gates can access the desired delta
     * Verifies that gates receive the delta via GateContext::$desiredDelta
     */
    public function testGateCanAccessDelta(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'priority' => 'low']);
        $expectedDelta = ['status' => 'published', 'priority' => 'high'];

        $deltaCapture = null;
        $gate = new class ($deltaCapture, $this->logger) implements Gate {
            /** @phpstan-ignore property.onlyWritten */
            private mixed $deltaCapture;

            public function __construct(
                mixed &$deltaCapture,
                private ExecutionLogger $logger
            ) {
                $this->deltaCapture = &$deltaCapture;
            }

            public function evaluate(GateContext $context): GateResult
            {
                $this->logger->log[] = 'Gate:DeltaCheck';
                $this->deltaCapture = $context->desiredDelta;

                return GateResult::ALLOW;
            }

            public function message(): ?string
            {
                return 'DeltaCheck';
            }
        };

        $stateFlow = new StateFlow(fn () => new Configuration([$gate], []));

        $context = $stateFlow
            ->transition($initialState, $expectedDelta)
            ->execute();

        // Verify the gate received the delta
        $this->assertSame($expectedDelta, $deltaCapture, 'Gate should receive the delta');
        $this->assertSame($expectedDelta, $context->getDesiredDelta(), 'Context should store the delta');
    }

    /**
     * Delta Access: Actions can access the desired delta
     * Verifies that actions receive the delta via ActionContext::$desiredDelta
     * Enables actions to call $state->with($context->desiredDelta) for merging changes
     */
    public function testActionCanAccessDelta(): void
    {
        $initialState = $this->createTestState(['status' => 'draft', 'version' => 1]);
        $expectedDelta = ['status' => 'published', 'version' => 2];

        $deltaCapture = null;
        $action = new class ($deltaCapture, $this->logger) implements Action {
            /** @phpstan-ignore property.onlyWritten */
            private mixed $deltaCapture;

            public function __construct(
                mixed &$deltaCapture,
                private ExecutionLogger $logger
            ) {
                $this->deltaCapture = &$deltaCapture;
            }

            public function execute(ActionContext $context): ActionResult
            {
                $this->logger->log[] = 'Action:DeltaCheck';
                $this->deltaCapture = $context->desiredDelta;

                return ActionResult::continue();
            }
        };

        $stateFlow = new StateFlow(fn () => new Configuration([], [$action]));

        $context = $stateFlow
            ->transition($initialState, $expectedDelta)
            ->execute();

        // Verify the action received the delta
        $this->assertSame($expectedDelta, $deltaCapture, 'Action should receive the delta');
        $this->assertSame($expectedDelta, $context->getDesiredDelta(), 'Context should store the delta');
    }

    protected function getLogger(): ExecutionLogger
    {
        return $this->logger;
    }
}
