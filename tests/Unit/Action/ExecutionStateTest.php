<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Action;

use CoverGenius\StateFlow\Action\ExecutionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExecutionStateTest extends TestCase
{
    #[DataProvider('provideIsYield')]
    public function testIsYield(ExecutionState $state, bool $expected): void
    {
        $this->assertSame($expected, $state->isYield());
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function provideIsYield(): array
    {
        return [
            'YIELD returns true' => [ExecutionState::YIELD, true],
            'CONTINUE returns false' => [ExecutionState::CONTINUE, false],
            'PAUSE returns false' => [ExecutionState::PAUSE, false],
            'STOP returns false' => [ExecutionState::STOP, false],
        ];
    }
}
