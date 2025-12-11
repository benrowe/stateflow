<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Utils\Traits;

use BenRowe\StateFlow\State;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

/**
 * Trait for creating test State stubs
 *
 * @mixin TestCase
 */
trait CreatesTestStates
{
    /**
     * @param array<string, mixed> $data
     * @throws Exception
     */
    private function createTestState(array $data): State
    {
        $state = $this->createStub(State::class);
        $state->method('toArray')->willReturn($data);
        $state->method('with')->willReturnCallback(function (array $changes) use ($data) {
            /** @phpstan-ignore method.notFound */
            return $this->createTestState(array_merge($data, $changes));
        });

        return $state;
    }
}
