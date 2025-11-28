<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests\Unit\Action;

use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\State;
use PHPUnit\Framework\TestCase;

class ActionResultTest extends TestCase
{
    public function testContinue(): void
    {
        $result = ActionResult::continue();
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::CONTINUE, $result->executionState);
        $this->assertNull($result->newState);
        $this->assertNull($result->metadata);
    }

    public function testContinueWithNewState(): void
    {
        $state = $this->mockState();
        $result = ActionResult::continue($state);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::CONTINUE, $result->executionState);
        $this->assertSame($state, $result->newState);
        $this->assertNull($result->metadata);
    }

    public function testPause(): void
    {
        $result = ActionResult::pause();
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::PAUSE, $result->executionState);
        $this->assertNull($result->newState);
        $this->assertNull($result->metadata);
    }

    public function testPauseWithNewState(): void
    {
        $state = $this->mockState();
        $result = ActionResult::pause($state);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::PAUSE, $result->executionState);
        $this->assertSame($state, $result->newState);
        $this->assertNull($result->metadata);
    }

    public function testPauseWithMetaData(): void
    {
        $result = ActionResult::pause(null, ['foo' => 'bar']);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::PAUSE, $result->executionState);
        $this->assertNull($result->newState);
        $this->assertSame(['foo' => 'bar'], $result->metadata);
    }

    public function testPauseWithStateAndMetadata(): void
    {
        $state = $this->mockState();
        $result = ActionResult::pause($state, ['foo' => 'bar']);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::PAUSE, $result->executionState);
        $this->assertSame($state, $result->newState);
        $this->assertSame(['foo' => 'bar'], $result->metadata);
    }

    public function testStopWithNewState(): void
    {
        $state = $this->mockState();
        $result = ActionResult::stop($state);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::STOP, $result->executionState);
        $this->assertSame($state, $result->newState);
        $this->assertNull($result->metadata);
    }

    public function testStopWithMetaData(): void
    {
        $result = ActionResult::stop(null, ['foo' => 'bar']);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::STOP, $result->executionState);
        $this->assertNull($result->newState);
        $this->assertSame(['foo' => 'bar'], $result->metadata);
    }

    public function testStopWithStateAndMetadata(): void
    {
        $state = $this->mockState();
        $result = ActionResult::stop($state, ['foo' => 'bar']);
        $this->assertInstanceOf(ActionResult::class, $result);
        $this->assertSame(ExecutionState::STOP, $result->executionState);
        $this->assertSame($state, $result->newState);
        $this->assertSame(['foo' => 'bar'], $result->metadata);
    }



    private function mockState(): State
    {
        return new class () implements State {
            public function toArray(): array
            {
                return [];
            }

            public function with(array $changes): static
            {
                return new static($changes);
            }
        };
    }
}
