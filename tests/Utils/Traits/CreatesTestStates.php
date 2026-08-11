<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Utils\Traits;

use CoverGenius\StateFlow\State;
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
     *
     * @throws Exception
     */
    private function createTestState(array $data): State
    {
        $state = $this->createStub(State::class);
        $state->method('toArray')->willReturn($data);
        $state->method('with')->willReturnCallback(function (array $changes) use ($data): State {
            /** @var array<string, mixed> $mergedData */
            $mergedData = array_merge($data, $changes);

            /** @phpstan-ignore method.notFound */
            return $this->createTestState($mergedData);
        });

        return $state;
    }
}
