<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Action;

use CoverGenius\StateFlow\Action\ActionResult;
use CoverGenius\StateFlow\Action\ExecutionState;
use CoverGenius\StateFlow\State;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Generator\Generator as MockGenerator;
use PHPUnit\Framework\TestCase;

class ActionResultTest extends TestCase
{
    #[DataProvider('provideContinueData')]
    public function testContinue(?State $state): void
    {
        $result = ActionResult::continue($state);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::CONTINUE, $result->executionState);
        $this->assertSame($state, $result->newState);
        $this->assertNull($result->metadata);
    }

    /**
     * @param ?mixed[] $metadata
     */
    #[DataProvider('providePauseData')]
    public function testPause(?State $state, ?array $metadata): void
    {
        $result = ActionResult::pause($state, $metadata);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::PAUSE, $result->executionState);
        $this->assertSame($state, $result->newState);
        $this->assertSame($metadata, $result->metadata);
    }

    /**
     * @param ?mixed[] $metadata
     */
    #[DataProvider('provideStopData')]
    public function testStop(?State $state, ?array $metadata): void
    {
        $result = ActionResult::stop($state, $metadata);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::STOP, $result->executionState);
        $this->assertSame($state, $result->newState);
        $this->assertSame($metadata, $result->metadata);
    }

    /**
     * @param ?mixed[] $metadata
     */
    #[DataProvider('provideYieldData')]
    public function testYield(?array $metadata): void
    {
        $result = ActionResult::yield($metadata);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::YIELD, $result->executionState);
        $this->assertNull($result->newState);
        $this->assertSame($metadata, $result->metadata);
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function provideContinueData(): array
    {
        return [
            'no state' => [null],
            'with state' => [self::mockState()],
        ];
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function providePauseData(): array
    {
        return [
            'no state or metadata' => [null, null],
            'with state' => [self::mockState(), null],
            'with metadata' => [null, ['foo' => 'bar']],
            'with state and metadata' => [self::mockState(), ['foo' => 'bar']],
        ];
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function provideStopData(): array
    {
        return [
            'with state' => [self::mockState(), null],
            'with metadata' => [null, ['foo' => 'bar']],
            'with state and metadata' => [self::mockState(), ['foo' => 'bar']],
        ];
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function provideYieldData(): array
    {
        return [
            'no metadata' => [null],
            'with metadata' => [['foo' => 'bar']],
        ];
    }

    private static function mockState(): State
    {
        $mock = (new MockGenerator())
            ->testDouble(State::class, true, [], [], '', false, false, true, false, false, null, false);
        assert($mock instanceof State);

        return $mock;

    }
}
