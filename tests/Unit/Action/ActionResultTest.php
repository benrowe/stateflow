<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Action;

use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private static function mockState(): State
    {
        return new class () implements State {
            public function toArray(): array
            {
                return [];
            }

            public function with(array $changes): State
            {
                return new static($changes);
            }
        };
    }
}
