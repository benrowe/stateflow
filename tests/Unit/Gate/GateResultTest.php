<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Gate;

use BenRowe\StateFlow\Gate\GateResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GateResultTest extends TestCase
{
    #[DataProvider('provideShouldStopTransitionProvider')]
    public function testShouldStopTransition(GateResult $result, bool $expected): void
    {
        $this->assertSame($expected, $result->shouldStopTransition());
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function provideShouldStopTransitionProvider(): array
    {
        return [
            'DENY should stop transition' => [GateResult::DENY, true],
            'ALLOW should not stop transition' => [GateResult::ALLOW, false],
            'SKIP_IDEMPOTENT should not stop transition' => [GateResult::SKIP_IDEMPOTENT, false],
        ];
    }

    #[DataProvider('provideShouldSkipActionProvider')]
    public function testShouldSkipAction(GateResult $result, bool $expected): void
    {
        $this->assertSame($expected, $result->shouldSkipAction());
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function provideShouldSkipActionProvider(): array
    {
        return [
            'DENY should skip action' => [GateResult::DENY, true],
            'SKIP_IDEMPOTENT should skip action' => [GateResult::SKIP_IDEMPOTENT, true],
            'ALLOW should not skip action' => [GateResult::ALLOW, false],
        ];
    }
}
