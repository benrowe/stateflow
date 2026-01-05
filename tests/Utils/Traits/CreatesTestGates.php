<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Utils\Traits;

use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateContext;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Tests\Utils\ExecutionLogger;

/**
 * Trait for creating test Gate implementations
 */
trait CreatesTestGates
{
    abstract protected function getLogger(): ExecutionLogger;

    private function createTestGate(string $name, GateResult $result): Gate
    {
        $logger = $this->getLogger();

        return new class ($name, $result, $logger) implements Gate
        {
            public function __construct(
                private string $name,
                private GateResult $result,
                private ExecutionLogger $logger
            ) {}

            public function evaluate(GateContext $context): GateResult
            {
                $this->logger->log[] = 'Gate:' . $this->name;

                return $this->result;
            }

            public function message(): string
            {
                return $this->name;
            }
        };
    }
}
