<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\ActionSkip;
use CoverGenius\StateFlow\ActionSkipCollection;
use CoverGenius\StateFlow\Gate\GateResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ActionSkipCollectionTest extends TestCase
{
    public function testConstructorWithBadActionSkip(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All elements must be instances of ' . ActionSkip::class);

        // @phpstan-ignore-next-line
        new ActionSkipCollection([stdClass::class]);
    }

    public function testConstructorWithNoSkips(): void
    {
        $collection = new ActionSkipCollection();

        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testEmptyStaticFactory(): void
    {
        $collection = ActionSkipCollection::empty();

        $this->assertInstanceOf(ActionSkipCollection::class, $collection);
        $this->assertCount(0, $collection);
        $this->assertSame([], $collection->toArray());
    }

    public function testFromArrayStaticFactory(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $skip1 = new ActionSkip($action1, GateResult::DENY);
        $skip2 = new ActionSkip($action2, GateResult::SKIP_IDEMPOTENT);

        $collection = ActionSkipCollection::fromArray([$skip1, $skip2]);

        $this->assertInstanceOf(ActionSkipCollection::class, $collection);
        $this->assertCount(2, $collection);
        $this->assertSame([$skip1, $skip2], $collection->toArray());
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $collection = ActionSkipCollection::fromArray([]);

        $this->assertCount(0, $collection);
    }

    public function testWithReturnsNewInstance(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $skip1 = new ActionSkip($action1, GateResult::DENY);
        $skip2 = new ActionSkip($action2, GateResult::SKIP_IDEMPOTENT);

        $original = new ActionSkipCollection([$skip1]);
        $new = $original->with($skip2);

        // Original should be unchanged
        $this->assertCount(1, $original);
        $this->assertSame([$skip1], $original->toArray());

        // New collection should have both
        $this->assertCount(2, $new);
        $this->assertSame([$skip1, $skip2], $new->toArray());

        // They should be different instances
        $this->assertNotSame($original, $new);
    }

    public function testToArray(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $skip1 = new ActionSkip($action1, GateResult::DENY);
        $skip2 = new ActionSkip($action2, GateResult::SKIP_IDEMPOTENT);

        $collection = new ActionSkipCollection([$skip1, $skip2]);
        $array = $collection->toArray();

        $this->assertCount(2, $array);
        $this->assertSame($skip1, $array[0]);
        $this->assertSame($skip2, $array[1]);
    }

    public function testCountable(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);
        $action3 = $this->createMock(Action::class);

        $skip1 = new ActionSkip($action1, GateResult::DENY);
        $skip2 = new ActionSkip($action2, GateResult::SKIP_IDEMPOTENT);
        $skip3 = new ActionSkip($action3, GateResult::DENY);

        $collection = new ActionSkipCollection([$skip1, $skip2, $skip3]);

        $this->assertCount(3, $collection);
        $this->assertSame(3, $collection->count());
    }

    public function testIterable(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);

        $skip1 = new ActionSkip($action1, GateResult::DENY);
        $skip2 = new ActionSkip($action2, GateResult::SKIP_IDEMPOTENT);

        $collection = new ActionSkipCollection([$skip1, $skip2]);

        $skips = [];
        foreach ($collection as $skip) {
            $skips[] = $skip;
        }

        $this->assertSame([$skip1, $skip2], $skips);
    }

    public function testSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of ActionSkip');

        $collection = new ActionSkipCollection();
        // @phpstan-ignore argument.type
        $collection->set(0, 'not an action skip');
    }

    public function testOffsetSetThrowsExceptionForWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be an instance of ActionSkip');

        $collection = new ActionSkipCollection();
        // @phpstan-ignore offsetAssign.valueType
        $collection[0] = 'not an action skip';
    }

    public function testChainedWiths(): void
    {
        $action1 = $this->createMock(Action::class);
        $action2 = $this->createMock(Action::class);
        $action3 = $this->createMock(Action::class);

        $skip1 = new ActionSkip($action1, GateResult::DENY);
        $skip2 = new ActionSkip($action2, GateResult::SKIP_IDEMPOTENT);
        $skip3 = new ActionSkip($action3, GateResult::DENY);

        $collection = ActionSkipCollection::empty()
            ->with($skip1)
            ->with($skip2)
            ->with($skip3);

        $this->assertCount(3, $collection);
        $this->assertSame([$skip1, $skip2, $skip3], $collection->toArray());
    }

    public function testCanSetWithArray(): void
    {
        $collection = new ActionSkipCollection();
        $action = $this->createMock(Action::class);
        $skip = new ActionSkip($action, GateResult::DENY);
        $collection[] = $skip;
        $this->assertCount(1, $collection);
        $this->assertSame([$skip], $collection->toArray());
    }
}
